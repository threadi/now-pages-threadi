<?php
/**
 * This file contains the advisor: it turns "what I would like to show" into sources.
 *
 * The user describes a wish in own words. An AI model, reached through the
 * WordPress AI Client (wp_ai_client_prompt(), in core since 7.0), picks
 * matching presets and providers from the catalog of this website.
 *
 * The model only ever proposes. What it returns is treated like any other
 * untrusted input: only known presets, providers and fields survive, every
 * value is cleaned up by its field type, and each proposal is run against
 * the real service before it is offered (see Source_Builder::check()). A
 * proposal the provider cannot fetch is not offered at all.
 *
 * Secrets never pass the model: fields holding one are stripped from its
 * answer, the user enters them in the source afterwards.
 *
 * This class never names a specific AI provider or model. The site owner
 * connects one under Settings > Connectors, the AI Client routes to it.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Sources;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use easySettingsForWordPress\Fields\Checkbox;
use easySettingsForWordPress\Tab;
use NowPagesThreadi\Helper\Plugin;
use NowPagesThreadi\Helper\Singleton_Interface;
use NowPagesThreadi\Helper\Singleton_Trait;
use NowPagesThreadi\NowPages\Abilities;
use NowPagesThreadi\Plugin\Log;
use NowPagesThreadi\Plugin\Settings;
use NowPagesThreadi\Presets\Preset_Interface;
use NowPagesThreadi\Presets\Presets;
use NowPagesThreadi\Providers\Provider_Interface;
use NowPagesThreadi\Providers\Providers;
use WP_Error;

/**
 * Object to propose sources for a wish with the help of an AI model.
 */
class Advisor implements Singleton_Interface {
	use Singleton_Trait;

	/**
	 * The value of the advisor in the list of presets of the setup.
	 *
	 * @var string
	 */
	public const SLUG = 'advisor';

	/**
	 * Option: whether the advisor is offered (default: yes).
	 *
	 * @var string
	 */
	public const OPTION_ENABLED = 'now_pages_threadi_advisor_enabled';

	/**
	 * The kind of question which comes with an answer: the user may replace it, or leave it.
	 *
	 * Used for values which are published as the words of the user, see
	 * the setting "review" of a field.
	 *
	 * @var string
	 */
	public const QUESTION_SUGGESTION = 'suggestion';

	/**
	 * How many proposals are asked for and offered.
	 *
	 * @var int
	 */
	private const MAX_PROPOSALS = 3;

	/**
	 * How long a wish may be, in characters.
	 *
	 * @var int
	 */
	private const MAX_WISH_LENGTH = 500;

	/**
	 * How long the advice for a wish is kept, in seconds.
	 *
	 * It has to survive from the step showing the proposals to the step
	 * creating the source, and it saves a second request if the user steps
	 * back and forth.
	 *
	 * @var int
	 */
	private const CACHE_LIFETIME = HOUR_IN_SECONDS;

	/**
	 * How long a request with web search may take, in seconds.
	 *
	 * @var float
	 */
	private const SEARCH_TIMEOUT = 45.0;

	/**
	 * Whether an AI service for text is connected. Remembered once it is, as it is asked several times per request.
	 *
	 * @var bool|null
	 */
	private ?bool $has_ai_client = null;

	/**
	 * Constructor, not used as this a Singleton object.
	 */
	private function __construct() {}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// after the abilities added the tab we share with them.
		add_action( 'init', array( $this, 'add_settings' ), 25 );
		add_action( 'admin_enqueue_scripts', array( $this, 'add_scripts' ) );
	}

	/**
	 * Return whether the advisor is switched on in the settings.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return 1 === absint( get_option( self::OPTION_ENABLED, 1 ) );
	}

	/**
	 * Return whether the advisor can be used right now.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		/**
		 * Filter whether the advisor is offered.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param bool $available True if it is enabled and an AI service for text is connected.
		 */
		return (bool) apply_filters( 'now_pages_threadi_advisor_is_available', $this->is_enabled() && $this->has_ai_client() );
	}

	/**
	 * Return whether an AI service which can write text is connected.
	 *
	 * @return bool
	 */
	private function has_ai_client(): bool {
		if ( true === $this->has_ai_client ) {
			return true;
		}

		// the AI Client came with WordPress 7.0.
		$has_ai_client = function_exists( 'wp_ai_client_prompt' ) && wp_ai_client_prompt()->is_supported_for_text_generation();

		// only a yes is remembered: WordPress hands the API keys to the AI Client at init:20,
		// a no from before that moment must not stick for the rest of the request.
		if ( $has_ai_client ) {
			$this->has_ai_client = true;
		}

		return $has_ai_client;
	}

	/**
	 * Return why the advisor cannot be used and how to change that.
	 *
	 * May contain simple HTML (links). Empty if it is available, if it was
	 * switched off on purpose or if this website does not use AI at all - in
	 * those cases there is nothing the user should be nudged towards.
	 *
	 * @return string
	 */
	public function get_unavailable_reason(): string {
		// bail if it is available or switched off in our settings.
		if ( ! $this->is_enabled() || $this->is_available() ) {
			return '';
		}

		// bail if the AI Client is missing, the plugin requires 7.0 anyway.
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return '';
		}

		// bail if AI was switched off on purpose, e.g. by the host.
		if ( function_exists( 'wp_supports_ai' ) && ! wp_supports_ai() ) {
			return '';
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return __( 'You could describe what you would like to show and get matching sources proposed, as soon as an administrator connects an AI service to this website.', 'now-pages-threadi' );
		}

		return sprintf(
			/* translators: %1$s will be replaced by a URL. */
			__( 'You could describe what you would like to show and get matching sources proposed. Connect an AI service under <a href="%1$s">Settings &rarr; Connectors</a> to use this.', 'now-pages-threadi' ),
			esc_url( admin_url( 'options-connectors.php' ) )
		);
	}

	/**
	 * Add our setting to the AI tab.
	 *
	 * @return void
	 */
	public function add_settings(): void {
		// bail if the settings are not needed in this request.
		if ( ! Plugin::is_settings_request() ) {
			return;
		}

		$settings_obj = Settings::get_instance()->get_settings_obj();

		// get the tab the abilities created.
		$tab = Settings::get_instance()->get_settings_page()->get_tab( Abilities::SETTINGS_TAB );

		// bail if the tab does not exist.
		if ( ! $tab instanceof Tab ) {
			return;
		}

		$section = $tab->add_section( 'now_pages_threadi_advisor_settings', 5 );
		$section->set_title( __( 'Advice for new sources', 'now-pages-threadi' ) );
		$section->set_callback( array( $this, 'show_intro' ) );

		// add setting.
		$setting = $settings_obj->add_setting( self::OPTION_ENABLED );
		$setting->set_section( $section );
		$setting->set_default( 1 );
		$field = new Checkbox( $settings_obj );
		$field->set_title( __( 'Offer advice', 'now-pages-threadi' ) );
		$field->set_description( __( 'Adds "Get advice" to the choice of a new source. Your description and the list of source types this website offers are sent to the AI service connected to this website. No keys, no entries and no existing sources are sent.', 'now-pages-threadi' ) );
		$setting->set_field( $field );
	}

	/**
	 * Explain the advisor and its state in the settings.
	 *
	 * @return void
	 */
	public function show_intro(): void {
		echo '<p>' . esc_html__( 'When you add a source, you can describe in your own words what you would like to show on your now page. An AI model proposes matching sources, and each proposal is tried against the real service before it is offered to you.', 'now-pages-threadi' ) . '</p>';

		$reason = $this->get_unavailable_reason();

		// bail if there is nothing to add.
		if ( '' === $reason ) {
			return;
		}

		echo '<p><span class="dashicons dashicons-info-outline" aria-hidden="true"></span> ' . wp_kses_post( $reason ) . '</p>';
	}

	/**
	 * Add the script which tells the user that the advice is on its way.
	 *
	 * The setup shows nothing while it waits for its next step, and asking
	 * a model and trying its proposals takes longer than any other step.
	 *
	 * @param string $hook The requested hook.
	 *
	 * @return void
	 */
	public function add_scripts( string $hook ): void {
		// bail on any other page than the setup.
		if ( ! str_ends_with( $hook, '_page_' . Settings::get_instance()->get_settings_obj()->get_menu_slug() . '-setup' ) ) {
			return;
		}

		// bail if the advisor is not offered.
		if ( ! $this->is_available() ) {
			return;
		}

		wp_enqueue_script(
			'now-pages-threadi-advisor',
			Plugin::get_plugin_url() . 'admin/advisor.js',
			array(),
			Plugin::get_file_version( Plugin::get_plugin_path() . 'admin/advisor.js' ),
			true
		);

		wp_localize_script(
			'now-pages-threadi-advisor',
			'nowPagesThreadiAdvisorVars',
			array(
				'waiting' => __( 'Looking for matching sources and trying them out. This can take up to a minute …', 'now-pages-threadi' ),
			)
		);
	}

	/**
	 * Return the wish as it is used: one line, limited in length.
	 *
	 * @param string $wish The wish as the user wrote it.
	 *
	 * @return string
	 */
	public function normalize_wish( string $wish ): string {
		$wish = trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( $wish ) ) );

		return mb_substr( $wish, 0, self::MAX_WISH_LENGTH );
	}

	/**
	 * Return the advice for a wish, if it has been given already.
	 *
	 * @param string $wish The wish of the user.
	 *
	 * @return array<string,mixed>|null The wish, the message of the model, the offered and the rejected proposals.
	 */
	public function get_cached_advice( string $wish ): ?array {
		$wish = $this->normalize_wish( $wish );

		// bail without a wish.
		if ( '' === $wish ) {
			return null;
		}

		$cached = get_transient( $this->get_cache_key( $wish ) );

		// bail if nothing usable is stored.
		if ( ! is_array( $cached ) || ! isset( $cached['proposals'], $cached['message'], $cached['rejected'] ) || ! is_array( $cached['proposals'] ) || ! is_array( $cached['rejected'] ) ) {
			return null;
		}

		return $cached;
	}

	/**
	 * Return the advice for a wish: proposals, each tried against its service.
	 *
	 * @param string $wish The wish of the user.
	 *
	 * @return array<string,mixed>|WP_Error The wish, the message of the model, the offered and the rejected proposals.
	 */
	public function get_advice( string $wish ): array|WP_Error {
		$wish = $this->normalize_wish( $wish );

		// bail without a wish.
		if ( '' === $wish ) {
			return new WP_Error( 'now_pages_threadi_advisor_no_wish', __( 'Please describe what you would like to show.', 'now-pages-threadi' ), array( 'status' => 400 ) );
		}

		// bail if the advisor cannot be used.
		if ( ! $this->is_available() ) {
			return new WP_Error( 'now_pages_threadi_advisor_unavailable', __( 'No AI service which can write texts is connected to this website.', 'now-pages-threadi' ), array( 'status' => 503 ) );
		}

		// use what has been found for this wish before.
		$cached = $this->get_cached_advice( $wish );
		if ( null !== $cached ) {
			return $cached;
		}

		$response = $this->request( $wish );

		// bail if the model could not be asked.
		if ( is_wp_error( $response ) ) {
			Log::get_instance()->add( __( 'The advice for a new source could not be requested:', 'now-pages-threadi' ) . ' <code>' . esc_html( $response->get_error_message() ) . '</code>', 'error', 'system' );

			return $response;
		}

		$data = $this->parse_response( $response );

		// bail if the answer could not be read.
		if ( is_wp_error( $data ) ) {
			Log::get_instance()->add( __( 'The advice for a new source could not be read, the AI service did not answer in the expected format.', 'now-pages-threadi' ), 'error', 'system' );

			return $data;
		}

		$advice = $this->get_advice_from_data( $wish, $data );

		set_transient( $this->get_cache_key( $wish ), $advice, self::CACHE_LIFETIME );

		return $advice;
	}

	/**
	 * Turn the decoded answer of the model into checked proposals.
	 *
	 * @param string              $wish The wish of the user.
	 * @param array<string,mixed> $data The decoded answer.
	 *
	 * @return array<string,mixed> The wish, the message of the model, the offered and the rejected proposals.
	 */
	public function get_advice_from_data( string $wish, array $data ): array {
		$advice = array(
			'wish'      => $wish,
			'message'   => $this->get_text( $data['message'] ?? '', 1000, true ),
			'proposals' => array(),
			'rejected'  => array(),
		);

		$raw_proposals = isset( $data['proposals'] ) && is_array( $data['proposals'] ) ? array_values( $data['proposals'] ) : array();

		foreach ( array_slice( $raw_proposals, 0, self::MAX_PROPOSALS ) as $raw ) {
			// bail if this is not a proposal at all.
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$proposal = $this->get_proposal( $raw );

			// remember why a proposal is not offered.
			if ( is_wp_error( $proposal ) ) {
				$advice['rejected'][] = array(
					'title'   => $this->get_text( $raw['title'] ?? '', 80 ),
					'message' => $proposal->get_error_message(),
				);

				continue;
			}

			// two ways to the same source are one proposal.
			if ( isset( $advice['proposals'][ $proposal['id'] ] ) ) {
				continue;
			}

			$advice['proposals'][ $proposal['id'] ] = $proposal;
		}

		/**
		 * Filter the advice for a wish before it is cached and shown.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<string,mixed> $advice The message, the offered and the rejected proposals.
		 * @param string              $wish   The wish of the user.
		 * @param array<string,mixed> $data   The decoded answer of the model.
		 */
		$filtered = apply_filters( 'now_pages_threadi_advice', $advice, $wish, $data );

		// a filter which returns something else must not break the setup.
		return isset( $filtered['proposals'], $filtered['message'], $filtered['rejected'] ) ? $filtered : $advice;
	}

	/**
	 * Turn a single proposal of the model into one we can offer.
	 *
	 * @param array<string,mixed> $raw The proposal as the model returned it.
	 *
	 * @return array<string,mixed>|WP_Error An error if it must not be offered.
	 */
	private function get_proposal( array $raw ): array|WP_Error {
		$slug = sanitize_key( (string) ( is_scalar( $raw['slug'] ?? null ) ? $raw['slug'] : '' ) );
		$kind = 'preset' === ( $raw['kind'] ?? '' ) ? 'preset' : 'provider';

		// a model mixes up the two kinds now and then, the slug decides.
		if ( 'provider' === $kind && ! Providers::get_instance()->get_provider( $slug ) instanceof Provider_Interface && Presets::get_instance()->get_preset( $slug ) instanceof Preset_Interface ) {
			$kind = 'preset';
		} elseif ( 'preset' === $kind && ! Presets::get_instance()->get_preset( $slug ) instanceof Preset_Interface && Providers::get_instance()->get_provider( $slug ) instanceof Provider_Interface ) {
			$kind = 'provider';
		}

		$preset_slug   = 'preset' === $kind ? $slug : '';
		$provider_slug = 'provider' === $kind ? $slug : '';

		// get the fields this proposal may fill, with the type of each one.
		$preset   = Presets::get_instance()->get_preset( $preset_slug );
		$provider = Providers::get_instance()->get_provider( $preset instanceof Preset_Interface ? $preset->get_provider_slug() : $provider_slug );
		$fields   = $this->get_fillable_fields( $preset, $provider );

		// split the values into the ones which are complete and the ones which wait for an answer.
		$plain_values    = array();
		$template_values = array();
		$questions       = array();
		$asked           = $this->get_pairs( $raw['questions'] ?? array(), array( 'label', 'help' ) );

		foreach ( $this->get_pairs( $raw['values'] ?? array(), array( 'value' ) ) as $name => $pair ) {
			$value = trim( $pair['value'] );

			// bail on anything this preset or provider does not know, and on secrets.
			if ( '' === $value || ! isset( $fields[ $name ] ) ) {
				continue;
			}

			$placeholders = $this->get_placeholders( $value );

			if ( empty( $placeholders ) ) {
				$plain_values[ $name ] = $value;
				continue;
			}

			$template_values[ $name ] = $value;

			foreach ( $placeholders as $placeholder ) {
				$questions[ $placeholder ] = array(
					'kind'        => 'placeholder',
					'label'       => '' !== ( $asked[ $placeholder ]['label'] ?? '' ) ? $this->get_text( $asked[ $placeholder ]['label'], 80 ) : ucfirst( str_replace( '_', ' ', $placeholder ) ),
					'help'        => $this->get_text( $asked[ $placeholder ]['help'] ?? '', 300 ),
					'placeholder' => '',
				);
			}
		}

		// a value which is published as the words of the user is shown to them before it is used.
		$suggestions = array();

		foreach ( $plain_values as $name => $value ) {
			$definition = $this->get_field_definition( $preset, $provider, (string) $name );

			// bail if this is a detail like an address, which is right or wrong but not a matter of wording.
			if ( empty( $definition['review'] ) ) {
				continue;
			}

			$suggestions[ $name ] = array(
				'kind'        => self::QUESTION_SUGGESTION,
				'label'       => (string) ( $definition['label'] ?? $name ),
				'help'        => __( 'This sentence is a proposal of the AI. Leave the field empty to use it, or write it in your own words.', 'now-pages-threadi' ),
				'placeholder' => $value,
				'value'       => $value,
			);
		}

		// let the builder decide whether this is a source at all, and what is still open.
		$resolved = Source_Builder::get_instance()->resolve( $preset_slug, $provider_slug, $plain_values );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		// bail if this source only receives entries: nothing can be tried, and without a tool
		// which sends them the user would end up with a source that stays empty.
		if ( $resolved['provider']->is_push() ) {
			return new WP_Error( 'now_pages_threadi_advisor_push', __( 'This would only work with a tool of your own which sends the entries to this website. Nothing can be fetched from the service itself.', 'now-pages-threadi' ) );
		}

		// a required field without any value is asked as it would be in the source.
		foreach ( $resolved['missing'] as $missing ) {
			// bail if this field is filled as soon as its placeholder is answered.
			if ( isset( $template_values[ $missing['name'] ] ) ) {
				continue;
			}

			$questions[ $missing['name'] ] = array(
				'kind'        => 'field',
				'label'       => $missing['label'],
				'help'        => $missing['description'],
				'placeholder' => $missing['placeholder'],
			);
		}

		$title = $this->get_text( $raw['title'] ?? '', 80 );

		$proposal = array(
			'id'        => substr( md5( (string) wp_json_encode( array( $preset_slug, $resolved['provider']->get_slug(), $plain_values, $template_values ) ) ), 0, 12 ),
			'title'     => '' !== $title ? $title : $resolved['title'],
			'reason'    => $this->get_text( $raw['reason'] ?? '', 300 ),
			'preset'    => $preset_slug,
			'provider'  => $resolved['provider']->get_slug(),
			'values'    => array_merge( $plain_values, $template_values ),
			'questions' => array_merge( $questions, $suggestions ),
			'secrets'   => $this->get_required_secrets( $resolved['provider'] ),
			'check'     => null,
		);

		// with open questions there is nothing to try yet, this happens when the source is created.
		// A suggestion is not open: it has a value, the user may only replace it.
		if ( ! empty( $questions ) ) {
			return $proposal;
		}

		// bail if this source exists already.
		if ( $resolved['duplicate_of'] > 0 ) {
			return new WP_Error(
				'now_pages_threadi_duplicate_source',
				/* translators: %1$s will be replaced by the title of a source. */
				sprintf( __( 'You already have this source: %1$s', 'now-pages-threadi' ), get_the_title( $resolved['duplicate_of'] ) )
			);
		}

		// try it against the real service.
		$check = Source_Builder::get_instance()->check( $resolved['provider'], $resolved['config'] );

		// bail if it does not work: a proposal which cannot deliver is none.
		if ( in_array( $check['status'], array( Source_Builder::CHECK_FAILED, Source_Builder::CHECK_UNSAFE ), true ) ) {
			return new WP_Error( 'now_pages_threadi_check_failed', $check['message'] );
		}

		$proposal['check'] = $check;

		return $proposal;
	}

	/**
	 * Check a proposal with the answers of the user and create its source.
	 *
	 * The source is created as a draft if the service cannot be read: the
	 * user gets something to correct instead of nothing.
	 *
	 * @param array<string,mixed>  $proposal The proposal, see get_advice().
	 * @param array<string,string> $answers  The answers to its questions, keyed by the name of the question.
	 *
	 * @return array{source_id:int,status:string,check:array<string,mixed>,secrets:array<int,string>}|WP_Error
	 */
	public function create_source( array $proposal, array $answers = array() ): array|WP_Error {
		$resolved = $this->resolve_proposal( $proposal, $answers );

		// bail if the proposal cannot be completed.
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$created = Source_Builder::get_instance()->create_checked(
			$resolved,
			array(
				'title'            => (string) ( $proposal['title'] ?? '' ),
				'draft_on_failure' => true,
			)
		);

		// bail if nothing was created.
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$created['secrets'] = wp_list_pluck( $resolved['missing_secrets'], 'label' );

		/**
		 * Fires after a source has been created from a proposal of the advisor.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param int                 $source_id The ID of the new source.
		 * @param array<string,mixed> $proposal  The proposal it was created from.
		 * @param string              $status    publish or draft.
		 */
		do_action( 'now_pages_threadi_advisor_source_created', $created['source_id'], $proposal, $created['status'] );

		return $created;
	}

	/**
	 * Complete a proposal with the answers of the user.
	 *
	 * @param array<string,mixed>  $proposal The proposal.
	 * @param array<string,string> $answers  The answers, keyed by the name of the question.
	 *
	 * @return array{provider:Provider_Interface,preset:Preset_Interface|null,config:array<string,mixed>,missing:array<int,array<string,mixed>>,missing_secrets:array<int,array<string,mixed>>,title:string,duplicate_of:int}|WP_Error
	 */
	public function resolve_proposal( array $proposal, array $answers = array() ): array|WP_Error {
		$preset_slug   = (string) ( $proposal['preset'] ?? '' );
		$provider_slug = (string) ( $proposal['provider'] ?? '' );
		$values        = isset( $proposal['values'] ) && is_array( $proposal['values'] ) ? $proposal['values'] : array();
		$questions     = isset( $proposal['questions'] ) && is_array( $proposal['questions'] ) ? $proposal['questions'] : array();

		$preset   = Presets::get_instance()->get_preset( $preset_slug );
		$provider = Providers::get_instance()->get_provider( $preset instanceof Preset_Interface ? $preset->get_provider_slug() : $provider_slug );
		$fields   = $this->get_fillable_fields( $preset, $provider );

		$replacements = array();

		foreach ( $questions as $name => $question ) {
			$answer = trim( sanitize_text_field( (string) ( $answers[ $name ] ?? '' ) ) );

			// a suggestion stays as it is unless the user wrote something else.
			if ( self::QUESTION_SUGGESTION === ( $question['kind'] ?? '' ) ) {
				if ( '' !== $answer ) {
					$values[ $name ] = $answer;
				}

				continue;
			}

			// bail if a question has not been answered.
			if ( '' === $answer ) {
				return new WP_Error(
					'now_pages_threadi_advisor_missing_answer',
					/* translators: %1$s will be replaced by the label of a field. */
					sprintf( __( 'This detail is missing: %1$s', 'now-pages-threadi' ), (string) ( $question['label'] ?? $name ) ),
					array( 'status' => 400 )
				);
			}

			if ( 'field' === ( $question['kind'] ?? '' ) ) {
				$values[ $name ] = $answer;
				continue;
			}

			$replacements[ (string) $name ] = $answer;
		}

		// put the answers where the model left a gap for them.
		foreach ( $values as $name => $value ) {
			$type = $fields[ $name ] ?? '';

			$values[ $name ] = $this->fill_placeholders( (string) $value, $replacements, 'url' === $type );
		}

		return Source_Builder::get_instance()->resolve( $preset_slug, $provider_slug, $values );
	}

	/**
	 * Return the fields a proposal may fill, with the type of each one.
	 *
	 * Secrets are left out: they never come from the model.
	 *
	 * @param Preset_Interface|null   $preset   The preset, if the proposal names one.
	 * @param Provider_Interface|null $provider The provider.
	 *
	 * @return array<string,string> Map of field name => type.
	 */
	private function get_fillable_fields( ?Preset_Interface $preset, ?Provider_Interface $provider ): array {
		$fields = array();

		if ( $provider instanceof Provider_Interface ) {
			foreach ( $provider->get_config_fields() as $name => $field ) {
				// bail on unusable definitions and on secrets.
				if ( ! is_array( $field ) || empty( $field['type'] ) || Source_Builder::get_instance()->is_secret( $field ) ) {
					continue;
				}

				$fields[ (string) $name ] = (string) $field['type'];
			}
		}

		// the questions of a preset are plain text, whatever a field of the same name is.
		if ( $preset instanceof Preset_Interface ) {
			foreach ( array_keys( $preset->get_setup_fields() ) as $name ) {
				$fields[ (string) $name ] = 'text';
			}
		}

		return $fields;
	}

	/**
	 * Return the definition of a field a proposal may fill.
	 *
	 * @param Preset_Interface|null   $preset   The preset, if the proposal names one.
	 * @param Provider_Interface|null $provider The provider.
	 * @param string                  $name     The name of the field.
	 *
	 * @return array<string,mixed> Empty if there is no such field.
	 */
	private function get_field_definition( ?Preset_Interface $preset, ?Provider_Interface $provider, string $name ): array {
		// the questions of a preset come first, they hide a field of the same name.
		if ( $preset instanceof Preset_Interface ) {
			$fields = $preset->get_setup_fields();

			if ( isset( $fields[ $name ] ) && is_array( $fields[ $name ] ) ) {
				return $fields[ $name ];
			}
		}

		if ( $provider instanceof Provider_Interface ) {
			$fields = $provider->get_config_fields();

			if ( isset( $fields[ $name ] ) && is_array( $fields[ $name ] ) ) {
				return $fields[ $name ];
			}
		}

		return array();
	}

	/**
	 * Return whether a proposal still needs something from the user.
	 *
	 * A suggestion does not count: it works as it is.
	 *
	 * @param array<string,mixed> $proposal The proposal.
	 *
	 * @return bool
	 */
	public function has_open_questions( array $proposal ): bool {
		$questions = isset( $proposal['questions'] ) && is_array( $proposal['questions'] ) ? $proposal['questions'] : array();

		foreach ( $questions as $question ) {
			if ( self::QUESTION_SUGGESTION !== ( $question['kind'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the labels of the secrets a provider cannot work without.
	 *
	 * @param Provider_Interface $provider The provider.
	 *
	 * @return array<int,string>
	 */
	private function get_required_secrets( Provider_Interface $provider ): array {
		$labels = array();

		foreach ( $provider->get_config_fields() as $name => $field ) {
			if ( is_array( $field ) && ! empty( $field['required'] ) && Source_Builder::get_instance()->is_secret( $field ) ) {
				$labels[] = (string) ( $field['label'] ?? $name );
			}
		}

		return $labels;
	}

	/**
	 * Return the placeholders of a value.
	 *
	 * @param string $value The value, e.g. "https://example.com/{username}/rss/".
	 *
	 * @return array<int,string> The names, e.g. "username".
	 */
	private function get_placeholders( string $value ): array {
		if ( ! preg_match_all( '/\{([a-z][a-z0-9_]{0,30})\}/', $value, $matches ) ) {
			return array();
		}

		return array_values( array_unique( $matches[1] ) );
	}

	/**
	 * Put the answers of the user into a value.
	 *
	 * People answer "username" with "@name" or with the whole address of
	 * their profile, so the answer is fitted to the place it lands in.
	 *
	 * @param string               $value        The value with its placeholders.
	 * @param array<string,string> $replacements The answers, keyed by placeholder name.
	 * @param bool                 $is_url       Whether the value is an address.
	 *
	 * @return string
	 */
	private function fill_placeholders( string $value, array $replacements, bool $is_url ): string {
		foreach ( $replacements as $name => $answer ) {
			$token = '{' . $name . '}';

			// bail if this value does not use this answer.
			if ( ! str_contains( $value, $token ) ) {
				continue;
			}

			// the answer is the whole value, use it as it is.
			if ( $token === $value ) {
				$value = $answer;
				continue;
			}

			$answer = ltrim( $answer, '@' );

			if ( $is_url ) {
				if ( str_contains( $value, '://' . $token ) ) {
					// the answer is the host: keep only what a host name can consist of.
					$answer = (string) preg_replace( '#^[a-z]+://#i', '', $answer );
					$answer = (string) preg_replace( '/[^A-Za-z0-9.\-:]/', '', (string) strtok( $answer, '/' ) );
				} else {
					// the answer is a part of the path or the query: use its last segment, encoded.
					$answer = trim( (string) preg_replace( '#^[a-z]+://[^/]+#i', '', $answer ), '/' );
					$parts  = explode( '/', $answer );
					$answer = rawurlencode( ltrim( (string) end( $parts ), '@' ) );
				}
			}

			$value = str_replace( $token, $answer, $value );
		}

		return $value;
	}

	/**
	 * Read a list of named entries from the answer of the model.
	 *
	 * The schema asks for a list of objects with a name, because that is
	 * the form every model can be held to. A model answering without the
	 * schema may return a plain map instead, which is accepted as well.
	 *
	 * @param mixed             $raw  The list as the model returned it.
	 * @param array<int,string> $keys The texts each entry holds beside its name.
	 *
	 * @return array<string,array<string,string>> The entries, keyed by name.
	 */
	private function get_pairs( mixed $raw, array $keys ): array {
		$pairs = array();

		// bail if this is no list at all.
		if ( ! is_array( $raw ) ) {
			return $pairs;
		}

		foreach ( $raw as $index => $entry ) {
			// a plain map of name => value.
			if ( is_string( $index ) && is_scalar( $entry ) ) {
				$entry = array(
					'name'   => $index,
					$keys[0] => $entry,
				);
			}

			// bail on anything else which is not an entry.
			if ( ! is_array( $entry ) || ! isset( $entry['name'] ) || ! is_scalar( $entry['name'] ) ) {
				continue;
			}

			$name = sanitize_key( (string) $entry['name'] );

			// bail if the entry has no usable name.
			if ( '' === $name ) {
				continue;
			}

			foreach ( $keys as $key ) {
				$pairs[ $name ][ $key ] = isset( $entry[ $key ] ) && is_scalar( $entry[ $key ] ) ? (string) $entry[ $key ] : '';
			}
		}

		return $pairs;
	}

	/**
	 * The pattern of a link as a model writes it: [label](address).
	 *
	 * A model which searched the web names its sources this way, whatever
	 * it was told about markup. Only http(s) addresses without brackets
	 * are taken as a link, everything else stays the text it is.
	 *
	 * @var string
	 */
	private const LINK_PATTERN = '/\[([^\]\[\n]{1,200})\]\((https?:\/\/[^\s()<>"\']{1,500})\)/';

	/**
	 * Return a text of the model as plain text of limited length.
	 *
	 * @param mixed $text       The text.
	 * @param int   $length     The maximum length in characters.
	 * @param bool  $keep_links Whether links are kept as the model wrote them, to be shown as links
	 *                          by format_message(). Otherwise only their label is kept.
	 *
	 * @return string
	 */
	private function get_text( mixed $text, int $length, bool $keep_links = false ): string {
		// bail if this is no text.
		if ( ! is_scalar( $text ) ) {
			return '';
		}

		// with links only the markup goes: sanitize_text_field() would also remove encoded characters from an address.
		$text = $keep_links
			? (string) preg_replace( '/\s+/u', ' ', wp_check_invalid_utf8( wp_strip_all_tags( (string) $text ) ) )
			: sanitize_text_field( (string) $text );

		// a text which is shown as plain text has no use for an address in brackets.
		if ( ! $keep_links ) {
			$text = (string) preg_replace( self::LINK_PATTERN, '$1', $text );
			$text = (string) preg_replace( '/\s*\(\s*\)/', '', $text );
		}

		return mb_substr( trim( $text ), 0, $length );
	}

	/**
	 * Return the message of the model as HTML, with its sources as links.
	 *
	 * Everything is escaped. A link is only created for an http(s) address
	 * written as [label](address), it opens in a new window and does not
	 * pass this website as its origin.
	 *
	 * @param string $message The message as get_advice() returned it.
	 *
	 * @return string
	 */
	public function format_message( string $message ): string {
		// bail if the message names no source.
		if ( ! preg_match_all( self::LINK_PATTERN, $message, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return esc_html( $message );
		}

		$html   = '';
		$offset = 0;

		foreach ( $matches as $match ) {
			$start = (int) $match[0][1];

			// add the text before this link.
			$html  .= esc_html( substr( $message, $offset, $start - $offset ) );
			$offset = $start + strlen( $match[0][0] );

			// the service the model searched with adds itself to the addresses it cites.
			$url = esc_url( remove_query_arg( array( 'utm_source', 'utm_medium', 'utm_campaign' ), html_entity_decode( $match[2][0], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), array( 'http', 'https' ) );

			// an address which cannot be used stays a label.
			if ( '' === $url ) {
				$html .= esc_html( $match[1][0] );
				continue;
			}

			$html .= '<a href="' . $url . '" target="_blank" rel="noopener noreferrer nofollow">' . esc_html( $match[1][0] ) . Plugin::get_a11n_window_hint() . '</a>';
		}

		return $html . esc_html( substr( $message, $offset ) );
	}

	/**
	 * Return the cache key of the advice for a wish.
	 *
	 * The key covers the user, the language of the answer and the wish. It
	 * must be the same at any time of a request, so it must not depend on
	 * anything that is still being set up while WordPress loads - the
	 * catalog is such a thing. A provider which is switched off after the
	 * advice was given is caught when the source is created.
	 *
	 * @param string $wish The normalized wish.
	 *
	 * @return string
	 */
	private function get_cache_key( string $wish ): string {
		return 'now_pages_threadi_advice_' . md5( get_current_user_id() . '|' . get_user_locale() . '|' . $wish );
	}

	/**
	 * Return the catalog the model chooses from.
	 *
	 * Without the choices of select fields - they can name users of this
	 * website - without providers which exist once and are in use, and
	 * without providers which only receive their entries.
	 *
	 * @return array{presets:array<int,array<string,mixed>>,providers:array<int,array<string,mixed>>}
	 */
	private function get_catalog(): array {
		$catalog = Source_Builder::get_instance()->get_catalog( false );

		// a provider which only receives entries is left out as well: it cannot be tried, and it is
		// of no use to someone who has no tool that sends them. Proposed as a way out for services
		// without a feed, it left the user with a source nothing ever arrives at.
		$catalog['providers'] = array_values(
			array_filter(
				$catalog['providers'],
				static fn( array $provider ): bool => empty( $provider['already_configured'] ) && empty( $provider['push'] )
			)
		);

		$used = wp_list_pluck( $catalog['providers'], 'slug' );

		// a preset is only as available as its provider.
		$catalog['presets'] = array_values(
			array_filter(
				$catalog['presets'],
				static fn( array $preset ): bool => in_array( $preset['provider'], $used, true )
			)
		);

		foreach ( $catalog['providers'] as $index => $provider ) {
			unset( $catalog['providers'][ $index ]['already_configured'], $catalog['providers'][ $index ]['only_once'], $catalog['providers'][ $index ]['push'] );
		}

		return $catalog;
	}

	/**
	 * Return the schema the answer of the model has to follow.
	 *
	 * Values and questions are lists of named entries instead of maps: a
	 * map with free keys cannot be enforced by every model.
	 *
	 * @return array<string,mixed>
	 */
	private function get_schema(): array {
		$text = array( 'type' => 'string' );

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'message'   => $text,
				'proposals' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'title'     => $text,
							'kind'      => array(
								'type' => 'string',
								'enum' => array( 'preset', 'provider' ),
							),
							'slug'      => $text,
							'reason'    => $text,
							'values'    => array(
								'type'  => 'array',
								'items' => array(
									'type'                 => 'object',
									'properties'           => array(
										'name'  => $text,
										'value' => $text,
									),
									'required'             => array( 'name', 'value' ),
									'additionalProperties' => false,
								),
							),
							'questions' => array(
								'type'  => 'array',
								'items' => array(
									'type'                 => 'object',
									'properties'           => array(
										'name'  => $text,
										'label' => $text,
										'help'  => $text,
									),
									'required'             => array( 'name', 'label', 'help' ),
									'additionalProperties' => false,
								),
							),
						),
						'required'             => array( 'title', 'kind', 'slug', 'reason', 'values', 'questions' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'message', 'proposals' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Return the instruction the model works by.
	 *
	 * Written in English on purpose and not translatable: it is not a text
	 * for the user, and a model follows it best in the language it was
	 * tested in. The language of the answer is named in the instruction.
	 *
	 * @param bool $with_search Whether the model can search the web.
	 * @param bool $with_schema Whether the form of the answer is enforced by a schema.
	 *
	 * @return string
	 */
	private function get_system_instruction( bool $with_search, bool $with_schema ): string {
		$lines = array(
			'You help the owner of a WordPress website to add a source to their "now page". A now page shows what a person is doing at the moment. A source is where its entries come from, for example the feed of a service the person uses.',
			'The user describes in their own words what they would like to show. Pick up to ' . self::MAX_PROPOSALS . ' ways to get exactly that from the catalog below, best first.',
			'',
			'Rules:',
			'1. Use only presets and providers from the catalog, and only their field names. Everything else is discarded.',
			'2. Prefer a preset if one matches the service. Use a provider for everything else: "rss" for a service with a public RSS or Atom feed, "ics" for a calendar.',
			'3. Propose a feed or calendar address only if you know that the service publishes one at exactly this address. ' . ( $with_search ? 'Use the web search to confirm it.' : 'If you are not sure, do not propose it.' ) . ' Every address is requested before it is shown to the user, and a wrong one is dropped. No proposal is better than a guess.',
			'4. Never invent an account name, an ID or an address of the user. If a value depends on something the user did not tell you, put a placeholder in curly braces into the value, for example "https://example.com/{username}/rss/", and describe it under "questions" with the same name. A placeholder name consists of lowercase letters, digits and underscores. Use as few placeholders as possible.',
			'5. Never fill a field marked as secret. The user enters it later.',
			'6. Leave out optional fields, unless the user asked for something they control.',
			'7. If nothing in the catalog can deliver what the user wants, return no proposals. Say so plainly in "message" and name the reason, for example that the service offers no public feed. Do not propose something else instead, and do not suggest a workaround which needs a tool, an account or technical knowledge the user did not mention. Telling the user that it is not possible is a good answer.',
			'8. Write "title", "reason", "message" and the texts under "questions" in the language with the code "' . get_user_locale() . '". Plain text without markup, short.',
		);

		// a rule for the provider which reads nothing, if it can be chosen at all.
		if ( in_array( 'recurring', wp_list_pluck( $this->get_catalog()['providers'], 'slug' ), true ) ) {
			$lines[] = '9. The provider "recurring" reads nothing: it repeats a sentence on a schedule. Propose it only if the user describes something they do regularly themselves and no service is involved, for example "I go running once a week". Never propose it for a service or an account, and never as a way out when rule 7 applies - a repeated sentence about a service which cannot be read would be made up. Use the provider, not a preset, and fill "recurring_text" with one short sentence in the language of the description, the way a person notes what they did, for example "Went running". Set "recurring_rhythm" only if the user named how often, and "recurring_weekdays" only if the user named days. Its "title" is the activity, for example "Running".';
		}

		$lines = array_merge(
			$lines,
			array(
				'',
				'"title" is the name of the service as the user knows it, for example "Letterboxd", not the technique behind it. "reason" says in one sentence what would appear on the now page. "message" is one or two sentences to the user about what you propose.',
			)
		);

		if ( ! $with_schema ) {
			$lines[] = '';
			$lines[] = 'Answer with a single JSON object and nothing else, in this form:';
			$lines[] = '{"message":"…","proposals":[{"title":"…","kind":"preset or provider","slug":"…","reason":"…","values":[{"name":"field name","value":"…"}],"questions":[{"name":"placeholder name","label":"…","help":"…"}]}]}';
		}

		$lines[] = '';
		$lines[] = 'Catalog:';
		$lines[] = (string) wp_json_encode( $this->get_catalog(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		/**
		 * Filter the instruction the model of the advisor works by.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param string $instruction The instruction, including the catalog.
		 * @param bool   $with_search Whether the model can search the web.
		 * @param bool   $with_schema Whether the form of the answer is enforced by a schema.
		 */
		return (string) apply_filters( 'now_pages_threadi_advisor_instruction', implode( "\n", $lines ), $with_search, $with_schema );
	}

	/**
	 * Ask the model.
	 *
	 * Starts with the most capable request the connected service supports
	 * and falls back to simpler ones: with web search and an enforced
	 * schema, with the schema only, and finally as plain text.
	 *
	 * @param string $wish The normalized wish.
	 *
	 * @return string|WP_Error The raw answer.
	 */
	private function request( string $wish ): string|WP_Error {
		/**
		 * Filter the answer of the model before it is requested.
		 *
		 * Return a string (the JSON answer) or a WP_Error to skip the
		 * request, e.g. to ask another service or in tests.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param string|WP_Error|null $response Null to ask the connected AI service.
		 * @param string               $wish     The wish of the user.
		 */
		$response = apply_filters( 'now_pages_threadi_advisor_pre_request', null, $wish );

		if ( is_string( $response ) || is_wp_error( $response ) ) {
			return $response;
		}

		// bail if the AI Client is missing.
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error( 'now_pages_threadi_advisor_unavailable', __( 'The WordPress AI Client requires WordPress 7.0 or later.', 'now-pages-threadi' ), array( 'status' => 503 ) );
		}

		/**
		 * Filter whether the model may search the web, if the connected service offers it.
		 *
		 * A model which can look up a feed address proposes fewer wrong
		 * ones, but the request takes longer and may cost more.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param bool $use_web_search True to use the web search.
		 */
		$use_web_search = (bool) apply_filters( 'now_pages_threadi_advisor_use_web_search', true ) && class_exists( '\WordPress\AiClient\Tools\DTO\WebSearch' );

		$variants = array(
			array(
				'search' => false,
				'schema' => true,
			),
			array(
				'search' => false,
				'schema' => false,
			),
		);

		if ( $use_web_search ) {
			array_unshift(
				$variants,
				array(
					'search' => true,
					'schema' => true,
				)
			);
		}

		$error = null;

		foreach ( $variants as $variant ) {
			$builder = wp_ai_client_prompt( $wish )->using_system_instruction( $this->get_system_instruction( $variant['search'], $variant['schema'] ) );

			if ( $variant['schema'] ) {
				$builder = $builder->as_json_response( $this->get_schema() );
			}

			if ( $variant['search'] && class_exists( '\WordPress\AiClient\Tools\DTO\WebSearch' ) ) {
				$builder = $builder->using_web_search( new \WordPress\AiClient\Tools\DTO\WebSearch() );

				// a model which searches the web needs longer than the default of the AI Client.
				if ( class_exists( '\WordPress\AiClient\Providers\Http\DTO\RequestOptions' ) ) {
					$options = new \WordPress\AiClient\Providers\Http\DTO\RequestOptions();
					$options->setTimeout( self::SEARCH_TIMEOUT );
					$builder = $builder->using_request_options( $options );
				}
			}

			// bail if the connected service cannot do this, the next variant asks for less.
			if ( ! $builder->is_supported_for_text_generation() ) {
				continue;
			}

			$result = $builder->generate_text();

			if ( ! is_wp_error( $result ) ) {
				return (string) $result;
			}

			$error = $result;

			// bail if asking for less would not help: the service refuses us.
			$data   = $result->get_error_data();
			$status = is_array( $data ) ? absint( $data['status'] ?? 0 ) : 0;

			if ( in_array( $status, array( 401, 403, 429 ), true ) ) {
				break;
			}

			// a request without web search which got no answer at all would not get one the next time either.
			// with web search it is worth another try: searching is what takes long and times out.
			if ( ! $variant['search'] && ( 'prompt_network_error' === $result->get_error_code() || $status >= 500 ) ) {
				break;
			}
		}

		if ( $error instanceof WP_Error ) {
			return $error;
		}

		return new WP_Error( 'now_pages_threadi_advisor_unavailable', __( 'No AI service which can write texts is connected to this website.', 'now-pages-threadi' ), array( 'status' => 503 ) );
	}

	/**
	 * Decode the answer of the model.
	 *
	 * @param string $response The raw answer.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private function parse_response( string $response ): array|WP_Error {
		$response = trim( $response );

		// a model answering without the schema likes to wrap its JSON in a code block or in a sentence.
		$start = strpos( $response, '{' );
		$end   = strrpos( $response, '}' );

		if ( false !== $start && false !== $end && $end > $start ) {
			$response = substr( $response, $start, $end - $start + 1 );
		}

		$data = json_decode( $response, true );

		// bail if this is not what we asked for.
		if ( ! is_array( $data ) || ! isset( $data['proposals'] ) || ! is_array( $data['proposals'] ) ) {
			return new WP_Error( 'now_pages_threadi_advisor_unreadable', __( 'The AI service did not answer in the expected format. Please try again.', 'now-pages-threadi' ), array( 'status' => 502 ) );
		}

		return $data;
	}
}
