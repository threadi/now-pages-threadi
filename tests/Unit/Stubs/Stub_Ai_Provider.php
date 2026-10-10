<?php
/**
 * File containing a stub AI provider for the tests.
 *
 * Registered with the AI Client of WordPress, so a test reaches the model
 * the same way a real request does: through wp_ai_client_prompt(), the
 * feature detection and the model selection. Only the model itself is
 * replaced - it answers what the test told it to answer, and remembers
 * what it was asked.
 *
 * @package now-pages-threadi
 */

namespace NowPagesThreadi\Tests\Unit\Stubs;

use Throwable;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\AbstractProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * What the test controls, and what the model remembers.
 */
class Stub_Ai_State {
	/**
	 * Whether the provider is connected.
	 *
	 * @var bool
	 */
	public static bool $connected = false;

	/**
	 * Whether the model can search the web.
	 *
	 * @var bool
	 */
	public static bool $supports_search = true;

	/**
	 * Whether the model can be held to a schema.
	 *
	 * @var bool
	 */
	public static bool $supports_schema = true;

	/**
	 * The answer of the model.
	 *
	 * @var string
	 */
	public static string $answer = '';

	/**
	 * What the model throws when it is asked to search the web.
	 *
	 * @var Throwable|null
	 */
	public static ?Throwable $fail_on_search = null;

	/**
	 * Every request the model received.
	 *
	 * @var array<int,array{prompt:string,instruction:string,search:bool,schema:bool,mime:string}>
	 */
	public static array $calls = array();

	/**
	 * Reset the stub between tests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$connected       = false;
		self::$supports_search = true;
		self::$supports_schema = true;
		self::$answer          = '';
		self::$fail_on_search  = null;
		self::$calls           = array();
	}

	/**
	 * Register the provider with the AI Client and connect it.
	 *
	 * @return void
	 */
	public static function connect(): void {
		$registry = AiClient::defaultRegistry();

		if ( ! $registry->hasProvider( Stub_Ai_Provider::class ) ) {
			$registry->registerProvider( Stub_Ai_Provider::class );
		}

		self::$connected = true;
	}

	/**
	 * Return the metadata of the one model of this provider.
	 *
	 * @return ModelMetadata
	 */
	public static function get_model_metadata(): ModelMetadata {
		$options = array(
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
			new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::text() ) ) ),
		);

		if ( self::$supports_schema ) {
			$options[] = new SupportedOption( OptionEnum::outputMimeType(), array( 'text/plain', 'application/json' ) );
			$options[] = new SupportedOption( OptionEnum::outputSchema() );
		}

		if ( self::$supports_search ) {
			$options[] = new SupportedOption( OptionEnum::webSearch() );
		}

		return new ModelMetadata( 'stub-model', 'Stub model', array( CapabilityEnum::textGeneration() ), $options );
	}
}

/**
 * Whether the stub provider is connected.
 */
class Stub_Ai_Availability implements ProviderAvailabilityInterface {
	/**
	 * Return whether the provider is connected.
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		return Stub_Ai_State::$connected;
	}
}

/**
 * The models of the stub provider.
 */
class Stub_Ai_Directory implements ModelMetadataDirectoryInterface {
	/**
	 * Return all models.
	 *
	 * @return array<int,ModelMetadata>
	 */
	public function listModelMetadata(): array {
		return array( Stub_Ai_State::get_model_metadata() );
	}

	/**
	 * Return whether a model exists.
	 *
	 * @param string $model_id The ID of the model.
	 *
	 * @return bool
	 */
	public function hasModelMetadata( string $model_id ): bool {
		return 'stub-model' === $model_id;
	}

	/**
	 * Return a model.
	 *
	 * @param string $model_id The ID of the model.
	 *
	 * @return ModelMetadata
	 */
	public function getModelMetadata( string $model_id ): ModelMetadata {
		return Stub_Ai_State::get_model_metadata();
	}
}

/**
 * The model: answers what the test told it to.
 */
class Stub_Ai_Model implements ModelInterface, TextGenerationModelInterface {
	/**
	 * The configuration of the request.
	 *
	 * @var ModelConfig
	 */
	private ModelConfig $config;

	/**
	 * Constructor.
	 *
	 * @param ModelMetadata    $metadata          The metadata of the model.
	 * @param ProviderMetadata $provider_metadata The metadata of the provider.
	 */
	public function __construct( private ModelMetadata $metadata, private ProviderMetadata $provider_metadata ) {
		$this->config = new ModelConfig();
	}

	/**
	 * Return the metadata of the model.
	 *
	 * @return ModelMetadata
	 */
	public function metadata(): ModelMetadata {
		return $this->metadata;
	}

	/**
	 * Return the metadata of the provider.
	 *
	 * @return ProviderMetadata
	 */
	public function providerMetadata(): ProviderMetadata {
		return $this->provider_metadata;
	}

	/**
	 * Set the configuration of the request.
	 *
	 * @param ModelConfig $config The configuration.
	 *
	 * @return void
	 */
	public function setConfig( ModelConfig $config ): void {
		$this->config = $config;
	}

	/**
	 * Return the configuration of the request.
	 *
	 * @return ModelConfig
	 */
	public function getConfig(): ModelConfig {
		return $this->config;
	}

	/**
	 * Answer a prompt.
	 *
	 * @param array<int,mixed> $prompt The messages.
	 *
	 * @return GenerativeAiResult
	 * @throws Throwable If the test asked for a failure.
	 */
	public function generateTextResult( array $prompt ): GenerativeAiResult {
		$text = '';

		foreach ( $prompt as $message ) {
			foreach ( $message->getParts() as $part ) {
				$text .= (string) $part->getText();
			}
		}

		$search = null !== $this->config->getWebSearch();

		Stub_Ai_State::$calls[] = array(
			'prompt'      => $text,
			'instruction' => (string) $this->config->getSystemInstruction(),
			'search'      => $search,
			'schema'      => null !== $this->config->getOutputSchema(),
			'mime'        => (string) $this->config->getOutputMimeType(),
		);

		if ( $search && null !== Stub_Ai_State::$fail_on_search ) {
			throw Stub_Ai_State::$fail_on_search;
		}

		return new GenerativeAiResult(
			'stub-result',
			array( new Candidate( new ModelMessage( array( new MessagePart( Stub_Ai_State::$answer ) ) ), FinishReasonEnum::stop() ) ),
			new TokenUsage( 1, 1, 2 ),
			$this->provider_metadata,
			$this->metadata
		);
	}
}

/**
 * The stub provider.
 */
class Stub_Ai_Provider extends AbstractProvider {
	/**
	 * Return the metadata of this provider.
	 *
	 * @return ProviderMetadata
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata( 'now-pages-threadi-stub', 'Now Pages stub', ProviderTypeEnum::cloud() );
	}

	/**
	 * Return the availability of this provider.
	 *
	 * @return ProviderAvailabilityInterface
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new Stub_Ai_Availability();
	}

	/**
	 * Return the models of this provider.
	 *
	 * @return ModelMetadataDirectoryInterface
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new Stub_Ai_Directory();
	}

	/**
	 * Return a model.
	 *
	 * @param ModelMetadata    $model_metadata    The metadata of the model.
	 * @param ProviderMetadata $provider_metadata The metadata of the provider.
	 *
	 * @return ModelInterface
	 */
	protected static function createModel( ModelMetadata $model_metadata, ProviderMetadata $provider_metadata ): ModelInterface {
		return new Stub_Ai_Model( $model_metadata, $provider_metadata );
	}
}
