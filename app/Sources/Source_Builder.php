<?php
/**
 * This file contains the one place a source is built from a wish.
 *
 * Three callers want the same thing: the setup (a chosen preset), the
 * abilities (an assistant) and the advisor (a proposal of an AI model).
 * Each of them names a preset or a provider plus some values - and each
 * of them must end up with exactly the same, validated source.
 *
 * The way is always:
 * 1. resolve() - turn the wish into a provider and a clean configuration.
 * 2. check()   - ask the provider for entries without storing anything.
 * 3. create()  - store the source.
 *
 * Values given to resolve() are never trusted: only fields the provider
 * declares are used, and each one is cleaned up by its own field type.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Sources;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Helper\Format;
use ThreadiNowPages\Helper\Languages;
use ThreadiNowPages\Helper\Singleton_Interface;
use ThreadiNowPages\Helper\Singleton_Trait;
use ThreadiNowPages\Helper\Url;
use ThreadiNowPages\NowPages\Categories;
use ThreadiNowPages\Plugin\Crypt;
use ThreadiNowPages\Presets\Preset_Interface;
use ThreadiNowPages\Presets\Presets;
use ThreadiNowPages\Providers\Provider_Context;
use ThreadiNowPages\Providers\Provider_Interface;
use ThreadiNowPages\Providers\Providers;
use Throwable;
use WP_Error;

/**
 * Object to resolve, check and create a source.
 *
 * @phpstan-type Resolved array{provider:Provider_Interface,preset:Preset_Interface|null,config:array<string,mixed>,missing:array<int,array<string,mixed>>,missing_secrets:array<int,array<string,mixed>>,title:string,duplicate_of:int}
 * @phpstan-type Check array{status:string,url:string,entries:int,samples:array<int,array{text:string,date:string}>,message:string}
 */
class Source_Builder implements Singleton_Interface {
	use Singleton_Trait;

	/**
	 * Check result: the provider delivered entries.
	 */
	public const CHECK_OK = 'ok';

	/**
	 * Check result: the provider answered, but has no entries right now.
	 */
	public const CHECK_EMPTY = 'empty';

	/**
	 * Check result: the provider ran into an error.
	 */
	public const CHECK_FAILED = 'failed';

	/**
	 * Check result: the address points to a local or private network.
	 */
	public const CHECK_UNSAFE = 'unsafe';

	/**
	 * Check result: required values are missing, nothing was requested.
	 */
	public const CHECK_INCOMPLETE = 'incomplete';

	/**
	 * Check result: this provider fetches nothing, so there is nothing to check.
	 */
	public const CHECK_NOT_CHECKABLE = 'not_checkable';

	/**
	 * How many entries a check returns as samples.
	 */
	private const SAMPLES = 3;

	/**
	 * The handlers of the provider errors while a check is running.
	 *
	 * @var mixed
	 */
	private mixed $error_handlers = null;

	/**
	 * Constructor, not used as this a Singleton object.
	 */
	private function __construct() {}

	/**
	 * Return what a source can be created from on this website.
	 *
	 * Presets are services ("Mastodon"), which ask for a few details and
	 * derive the configuration from them. Providers are the technique
	 * behind them ("RSS feed"), with every field they know.
	 *
	 * @param bool $with_options Whether the choices of select fields are listed. They can
	 *                           name users of this website, so leave them out for anything
	 *                           that leaves it.
	 *
	 * @return array{presets:array<int,array<string,mixed>>,providers:array<int,array<string,mixed>>}
	 */
	public function get_catalog( bool $with_options = true ): array {
		$catalog = array(
			'presets'   => array(),
			'providers' => array(),
		);

		foreach ( Presets::get_instance()->get_available_presets() as $preset ) {
			$fields = array();

			foreach ( $preset->get_setup_fields() as $name => $field ) {
				// bail if the definition is unusable.
				if ( ! is_array( $field ) ) {
					continue;
				}

				$fields[] = array(
					'name'        => (string) $name,
					'label'       => (string) ( $field['label'] ?? $name ),
					'description' => $this->get_plain_text( (string) ( $field['description'] ?? '' ) ),
					'required'    => ! empty( $field['required'] ),
					'placeholder' => (string) ( $field['placeholder'] ?? '' ),
				);
			}

			$catalog['presets'][] = array(
				'slug'        => $preset->get_slug(),
				'label'       => $preset->get_label(),
				'description' => $this->get_plain_text( $preset->get_description() ),
				'provider'    => $preset->get_provider_slug(),
				'fields'      => $fields,
			);
		}

		foreach ( Providers::get_instance()->get_enabled_providers() as $provider ) {
			// bail if this provider cannot run on this website.
			if ( ! $provider->is_available() ) {
				continue;
			}

			$fields = array();

			foreach ( $provider->get_config_fields() as $name => $field ) {
				// bail if the definition is unusable.
				if ( ! is_array( $field ) || empty( $field['type'] ) ) {
					continue;
				}

				$data = array(
					'name'        => (string) $name,
					'label'       => (string) ( $field['label'] ?? $name ),
					'description' => $this->get_plain_text( (string) ( $field['description'] ?? '' ) ),
					'type'        => (string) $field['type'],
					'required'    => ! empty( $field['required'] ),
					'secret'      => $this->is_secret( $field ),
				);

				if ( isset( $field['default'] ) && is_scalar( $field['default'] ) ) {
					$data['default'] = $field['default'];
				}

				if ( $with_options && empty( $field['personal_options'] ) && isset( $field['options_callback'] ) && is_callable( $field['options_callback'] ) ) {
					$options = call_user_func( $field['options_callback'] );

					if ( is_array( $options ) ) {
						$data['options'] = array_map( 'strval', $options );
					}
				}

				// choices which are written down in the code say nothing about this
				// website, and without them nobody could know what to put in.
				if ( ! isset( $field['options_callback'] ) && isset( $field['options'] ) && is_array( $field['options'] ) ) {
					$data['options'] = array_map( 'strval', $field['options'] );
				}

				$fields[] = $data;
			}

			$catalog['providers'][] = array(
				'slug'               => $provider->get_slug(),
				'label'              => $provider->get_label(),
				'description'        => $this->get_plain_text( $this->get_provider_description( $provider ) ),
				'push'               => $provider->is_push(),
				'only_once'          => $provider->is_unique(),
				'already_configured' => $provider->is_unique() && Sources::get_instance()->get_duplicate( $provider, array() ) > 0,
				'fields'             => $fields,
			);
		}

		/**
		 * Filter what a source can be created from.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array{presets:array<int,array<string,mixed>>,providers:array<int,array<string,mixed>>} $catalog      The presets and providers.
		 * @param bool                                                                                  $with_options Whether choices of select fields are listed.
		 */
		return apply_filters( 'threadi_now_pages_source_catalog', $catalog, $with_options );
	}

	/**
	 * Turn a wish into a provider and a clean configuration.
	 *
	 * Either a preset or a provider has to be named. The values are the
	 * answers to the questions of the preset and/or the fields of the
	 * provider - everything else is ignored.
	 *
	 * @param string              $preset_slug   The slug of a preset, empty to use a provider directly.
	 * @param string              $provider_slug The slug of a provider, ignored if a preset is named.
	 * @param array<string,mixed> $values        The values, keyed by field name.
	 *
	 * @return array<string,mixed>|WP_Error The provider, the preset (or null), the config, what is missing, the title and a duplicate.
	 *
	 * @phpstan-return Resolved|WP_Error
	 */
	public function resolve( string $preset_slug, string $provider_slug, array $values ): array|WP_Error {
		$preset  = null;
		$config  = array();
		$missing = array();

		// a preset derives the configuration from its answers.
		if ( '' !== $preset_slug ) {
			$preset = Presets::get_instance()->get_preset( $preset_slug );

			// bail if this preset is unknown or its provider is switched off.
			if ( ! $preset instanceof Preset_Interface || ! $preset->is_available() ) {
				return new WP_Error(
					'threadi_now_pages_unknown_preset',
					/* translators: %1$s will be replaced by a slug, %2$s by a list of slugs. */
					sprintf( __( 'The preset "%1$s" is not available on this website. Available presets: %2$s', 'threadi-now-pages' ), $preset_slug, implode( ', ', array_keys( Presets::get_instance()->get_available_presets() ) ) ),
					array( 'status' => 400 )
				);
			}

			$provider_slug = $preset->get_provider_slug();
			$answers       = array();

			foreach ( $preset->get_setup_fields() as $name => $field ) {
				$answer = '';

				if ( isset( $values[ $name ] ) && is_scalar( $values[ $name ] ) ) {
					$answer = sanitize_text_field( (string) $values[ $name ] );
				}

				$answers[ (string) $name ] = $answer;

				// remember a question without an answer.
				if ( '' === $answer && is_array( $field ) && ! empty( $field['required'] ) ) {
					$missing[] = $this->get_missing_field( (string) $name, $field );
				}
			}

			// the recipe only works with every required answer.
			if ( empty( $missing ) ) {
				$config = $preset->get_config( $answers );

				// bail if the preset could not use its answers.
				if ( empty( $config ) && ! empty( $preset->get_setup_fields() ) ) {
					return new WP_Error(
						'threadi_now_pages_invalid_values',
						/* translators: %1$s will be replaced by the name of a service. */
						sprintf( __( 'The given details cannot be used for %1$s.', 'threadi-now-pages' ), $preset->get_label() ),
						array( 'status' => 400 )
					);
				}
			}
		}

		$provider = Providers::get_instance()->get_provider( $provider_slug );

		// bail if this provider is unknown.
		if ( ! $provider instanceof Provider_Interface ) {
			return new WP_Error(
				'threadi_now_pages_unknown_provider',
				/* translators: %1$s will be replaced by a slug, %2$s by a list of slugs. */
				sprintf( __( 'The provider "%1$s" does not exist. Available providers: %2$s', 'threadi-now-pages' ), $provider_slug, implode( ', ', array_keys( Providers::get_instance()->get_enabled_providers() ) ) ),
				array( 'status' => 400 )
			);
		}

		// bail if the administrator switched this provider off.
		if ( ! $provider->is_enabled() ) {
			return new WP_Error(
				'threadi_now_pages_provider_disabled',
				/* translators: %1$s will be replaced by the name of a provider. */
				sprintf( __( 'The provider %1$s is disabled in the settings of this website.', 'threadi-now-pages' ), $provider->get_label() ),
				array( 'status' => 400 )
			);
		}

		// bail if this provider cannot run here.
		if ( ! $provider->is_available() ) {
			$reason = $provider->get_unavailable_reason();

			return new WP_Error(
				'threadi_now_pages_provider_unavailable',
				'' !== $reason ? $this->get_plain_text( $reason ) : __( 'This provider cannot be used on this website.', 'threadi-now-pages' ),
				array( 'status' => 400 )
			);
		}

		$missing_secrets = array();

		foreach ( $provider->get_config_fields() as $name => $field ) {
			$name = (string) $name;

			// bail if the definition is unusable.
			if ( ! is_array( $field ) || empty( $field['type'] ) ) {
				continue;
			}

			// a given value wins over what the preset derived.
			if ( array_key_exists( $name, $values ) && ! array_key_exists( $name, null === $preset ? array() : $preset->get_setup_fields() ) ) {
				$value = $this->sanitize_value( $values[ $name ], $field );

				if ( null !== $value ) {
					$config[ $name ] = $value;
				}
			}

			// use the default of a field nobody set.
			if ( ! array_key_exists( $name, $config ) ) {
				$default = $this->get_default( $field );

				if ( null !== $default ) {
					$config[ $name ] = $default;
				}
			}

			// bail if this field is filled or optional.
			if ( empty( $field['required'] ) || ! $this->is_empty( $config[ $name ] ?? null ) ) {
				continue;
			}

			// a preset with open questions reports those, not the fields behind them.
			if ( ! empty( $missing ) && null !== $preset ) {
				continue;
			}

			if ( $this->is_secret( $field ) ) {
				$missing_secrets[] = $this->get_missing_field( $name, $field );
				continue;
			}

			$missing[] = $this->get_missing_field( $name, $field );
		}

		// an incomplete configuration cannot be compared with existing sources.
		$duplicate_of = 0;
		if ( empty( $missing ) ) {
			$duplicate_of = Sources::get_instance()->get_duplicate( $provider, $config );
		}

		return array(
			'provider'        => $provider,
			'preset'          => $preset,
			'config'          => $config,
			'missing'         => $missing,
			'missing_secrets' => $missing_secrets,
			'title'           => $this->get_default_title( $preset, $provider, $config ),
			'duplicate_of'    => $duplicate_of,
		);
	}

	/**
	 * Return the title a new source gets if nobody names one.
	 *
	 * The name of the service, as a rule. A provider without a service
	 * behind it can name its sources itself by offering the method
	 * get_source_title( array $config ): string - otherwise all of them
	 * would be called the same.
	 *
	 * @param Preset_Interface|null $preset   The preset, if one is used.
	 * @param Provider_Interface    $provider The provider.
	 * @param array<string,mixed>   $config   The configuration.
	 *
	 * @return string
	 */
	public function get_default_title( ?Preset_Interface $preset, Provider_Interface $provider, array $config ): string {
		if ( method_exists( $provider, 'get_source_title' ) ) {
			$title = trim( (string) $provider->get_source_title( $config ) );

			if ( '' !== $title ) {
				return $title;
			}
		}

		return null !== $preset ? $preset->get_label() : $provider->get_label();
	}

	/**
	 * Ask a provider for entries without storing anything.
	 *
	 * This is what tells a working configuration from one that merely
	 * looks right: the provider runs exactly as the import would run it,
	 * but without a source. Nothing it finds is saved, no state is kept and
	 * a failure is returned instead of being written to the log.
	 *
	 * @param Provider_Interface  $provider The provider.
	 * @param array<string,mixed> $config   The configuration, with secrets in plain text.
	 *
	 * @return array<string,mixed> The status, the requested url, the number of entries, samples and a message.
	 *
	 * @phpstan-return Check
	 */
	public function check( Provider_Interface $provider, array $config ): array {
		$result = array(
			'status'  => self::CHECK_NOT_CHECKABLE,
			'url'     => '',
			'entries' => 0,
			'samples' => array(),
			'message' => '',
		);

		// a provider which receives its entries has nothing to fetch.
		if ( $provider->is_push() ) {
			$result['message'] = __( 'This source receives its entries, it does not fetch anything. So there is nothing to check.', 'threadi-now-pages' );

			return $result;
		}

		// do not request anything with an incomplete configuration.
		$open = array();

		foreach ( $provider->get_config_fields() as $name => $field ) {
			if ( is_array( $field ) && ! empty( $field['required'] ) && $this->is_empty( $config[ $name ] ?? null ) ) {
				$open[] = (string) ( $field['label'] ?? $name );
			}
		}

		if ( ! empty( $open ) ) {
			$result['status'] = self::CHECK_INCOMPLETE;
			/* translators: %1$s will be replaced by a list of field names. */
			$result['message'] = sprintf( __( 'Not checked, these details are still missing: %1$s', 'threadi-now-pages' ), implode( ', ', $open ) );

			return $result;
		}

		// calendars are often given as webcal://, which is fetched via https.
		$url = (string) preg_replace( '#^webcal://#i', 'https://', $provider->get_test_url( $config ) );

		$problem = '';
		if ( '' !== $url ) {
			$result['url'] = Url::redact( $url );
			$problem       = $this->get_address_problem( $url );

			// bail if the address points to a local or private network.
			if ( 'unsafe' === $problem ) {
				$result['status']  = self::CHECK_UNSAFE;
				$result['message'] = __( 'This address points to a local or private network or uses a port that is not allowed. For security reasons it is neither checked nor imported.', 'threadi-now-pages' );

				return $result;
			}
		}

		// run the provider, and collect its errors instead of logging them.
		$errors  = $this->silence_provider_errors();
		$entries = array();

		try {
			$entries = $provider->fetch( $config, new Provider_Context( 0 ) );
		} catch ( Throwable $e ) {
			$errors->add( 'threadi_now_pages_check_exception', $e->getMessage() );
		} finally {
			$this->restore_provider_errors();
		}

		$result['entries'] = count( $entries );

		foreach ( array_slice( array_values( $entries ), 0, self::SAMPLES ) as $entry ) {
			$result['samples'][] = array(
				'text' => wp_html_excerpt( Format::html_to_plain_text( $entry->text ), 160, '…' ),
				'date' => $entry->date->format( DATE_ATOM ),
			);
		}

		// entries are the proof, whatever else happened on the way.
		if ( $result['entries'] > 0 ) {
			$result['status'] = self::CHECK_OK;
			/* translators: %1$d will be replaced by a number. */
			$result['message'] = sprintf( _n( 'Checked: %1$d entry found.', 'Checked: %1$d entries found.', $result['entries'], 'threadi-now-pages' ), $result['entries'] );

			return $result;
		}

		if ( $errors->has_errors() ) {
			$result['status']  = self::CHECK_FAILED;
			$result['message'] = $this->get_plain_text( $errors->get_error_message() );

			// an address nobody answers under is the most likely reason, and the one worth naming.
			if ( 'unresolvable' === $problem ) {
				$result['message'] = __( 'The address of this service could not be resolved. It probably does not exist, check it for a typo.', 'threadi-now-pages' );
			}

			return $result;
		}

		$result['status']  = self::CHECK_EMPTY;
		$result['message'] = __( 'Checked: the service answered, but it has no entries right now.', 'threadi-now-pages' );

		return $result;
	}

	/**
	 * Create a source from a resolved wish.
	 *
	 * @param array<string,mixed> $resolved The result of resolve().
	 * @param array<string,mixed> $args     Optional: title, status (publish|draft), language, category_term_id.
	 *
	 * @return int|WP_Error The ID of the new source.
	 *
	 * @phpstan-param Resolved $resolved
	 */
	public function create( array $resolved, array $args = array() ): int|WP_Error {
		$title = trim( sanitize_text_field( (string) ( $args['title'] ?? '' ) ) );

		if ( '' === $title ) {
			$title = $resolved['title'];
		}

		return $this->insert(
			$resolved['provider']->get_slug(),
			$resolved['config'],
			array(
				'title'            => $title,
				'status'           => $args['status'] ?? 'publish',
				'preset'           => null !== $resolved['preset'] ? $resolved['preset']->get_slug() : '',
				'language'         => $args['language'] ?? '',
				'category_term_id' => $args['category_term_id'] ?? 0,
			)
		);
	}

	/**
	 * Check a resolved wish and create the source if it holds up.
	 *
	 * A source is published if the provider answered. It is created as a
	 * draft if it cannot work yet, because a secret is missing which the
	 * user enters in the source itself. A draft is not imported.
	 *
	 * @param array<string,mixed> $resolved The result of resolve().
	 * @param array<string,mixed> $args     Optional: title, language, category_term_id and draft_on_failure
	 *                                      (create a draft instead of nothing if the check fails).
	 *
	 * @return array<string,mixed>|WP_Error The source_id, the status (publish|draft) and the result of the check.
	 *
	 * @phpstan-param Resolved $resolved
	 * @phpstan-return array{source_id:int,status:string,check:Check}|WP_Error
	 */
	public function create_checked( array $resolved, array $args = array() ): array|WP_Error {
		// bail if required details are missing.
		if ( ! empty( $resolved['missing'] ) ) {
			return new WP_Error(
				'threadi_now_pages_missing_values',
				/* translators: %1$s will be replaced by a list of field names. */
				sprintf( __( 'These details are missing: %1$s', 'threadi-now-pages' ), implode( ', ', wp_list_pluck( $resolved['missing'], 'name' ) ) ),
				array(
					'status'  => 400,
					'missing' => $resolved['missing'],
				)
			);
		}

		// bail if a source with exactly this configuration already exists.
		if ( $resolved['duplicate_of'] > 0 ) {
			return new WP_Error(
				'threadi_now_pages_duplicate_source',
				/* translators: %1$s will be replaced by the title of a source. */
				sprintf( __( 'A source with these settings already exists: %1$s', 'threadi-now-pages' ), get_the_title( $resolved['duplicate_of'] ) ),
				array(
					'status'       => 409,
					'duplicate_of' => $resolved['duplicate_of'],
				)
			);
		}

		$check = $this->check( $resolved['provider'], $resolved['config'] );

		// an address in a private network is never stored.
		if ( self::CHECK_UNSAFE === $check['status'] ) {
			return new WP_Error( 'threadi_now_pages_unsafe_address', $check['message'], array( 'status' => 400 ) );
		}

		$failed = self::CHECK_FAILED === $check['status'];

		// bail if the provider could not deliver and the caller wants nothing in that case.
		if ( $failed && empty( $args['draft_on_failure'] ) ) {
			return new WP_Error(
				'threadi_now_pages_check_failed',
				/* translators: %1$s will be replaced by an error message. */
				sprintf( __( 'The source was not created, because the check failed: %1$s', 'threadi-now-pages' ), $check['message'] ),
				array(
					'status' => 422,
					'check'  => $check,
				)
			);
		}

		$status = $failed || self::CHECK_INCOMPLETE === $check['status'] ? 'draft' : 'publish';

		$source_id = $this->create( $resolved, array_merge( $args, array( 'status' => $status ) ) );

		// bail if the source could not be stored.
		if ( is_wp_error( $source_id ) ) {
			return $source_id;
		}

		return array(
			'source_id' => $source_id,
			'status'    => $status,
			'check'     => $check,
		);
	}

	/**
	 * Store a source.
	 *
	 * The configuration is stored as given, apart from secrets: they are
	 * encrypted the same way the edit screen of a source encrypts them.
	 *
	 * @param string              $provider_slug The slug of the provider.
	 * @param array<string,mixed> $config        The configuration of the provider.
	 * @param array<string,mixed> $args          title, status (publish|draft), preset, language, category_term_id.
	 *
	 * @return int|WP_Error The ID of the new source.
	 */
	public function insert( string $provider_slug, array $config, array $args = array() ): int|WP_Error {
		$language = Languages::normalize_locale( (string) ( $args['language'] ?? '' ) );

		// the meta every source needs.
		$meta = array(
			'provider' => $provider_slug,
			'language' => '' !== $language ? $language : Languages::get_current_language(),
		);

		if ( ! empty( $args['preset'] ) ) {
			$meta[ Presets::SOURCE_META ] = (string) $args['preset'];
		}

		if ( ! empty( $args['category_term_id'] ) ) {
			$meta[ Categories::SOURCE_DEFAULT_META ] = absint( $args['category_term_id'] );
		}

		$provider = Providers::get_instance()->get_provider( $provider_slug );
		$fields   = $provider instanceof Provider_Interface ? $provider->get_config_fields() : array();

		// add the configuration.
		foreach ( $config as $name => $value ) {
			$field = $fields[ $name ] ?? null;

			// a secret is never stored in plain text.
			if ( is_array( $field ) && $this->is_secret( $field ) ) {
				// bail if there is nothing to store.
				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}

				$encrypted = Crypt::get_instance()->encrypt( $value );

				// bail if the encryption failed: better no value than a readable one.
				if ( '' === $encrypted ) {
					return new WP_Error( 'threadi_now_pages_encryption_failed', __( 'A secret value could not be encrypted, so the source was not created.', 'threadi-now-pages' ), array( 'status' => 500 ) );
				}

				$value = $encrypted;
			}

			$meta[ $name ] = $value;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => Sources::get_instance()->get_slug(),
				'post_status' => 'draft' === ( $args['status'] ?? 'publish' ) ? 'draft' : 'publish',
				'post_title'  => (string) ( $args['title'] ?? '' ),
				'post_author' => get_current_user_id(),
				'meta_input'  => $meta,
			),
			true
		);

		// bail if the source could not be created.
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		/**
		 * Fires after a source has been created by the setup, an ability or the advisor.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param int                 $post_id       The ID of the new source.
		 * @param string              $provider_slug The slug of its provider.
		 * @param array<string,mixed> $args          The arguments it was created with.
		 */
		do_action( 'threadi_now_pages_source_created', absint( $post_id ), $provider_slug, $args );

		return absint( $post_id );
	}

	/**
	 * Return whether a field holds a secret.
	 *
	 * @param array<string,mixed> $field The definition of the field.
	 *
	 * @return bool
	 */
	public function is_secret( array $field ): bool {
		$field_obj = Fields::get_instance()->get_field_by_slug( (string) ( $field['type'] ?? '' ) );

		return $field_obj instanceof Field_Base && $field_obj->is_secret();
	}

	/**
	 * Clean up a single value the way its field type does.
	 *
	 * @param mixed               $value The raw value.
	 * @param array<string,mixed> $field The definition of the field.
	 *
	 * @return mixed Null if this value cannot be used.
	 */
	private function sanitize_value( mixed $value, array $field ): mixed {
		$field_obj = Fields::get_instance()->get_field_by_slug( (string) $field['type'] );

		// a type without an object cannot be validated, so it is not accepted.
		if ( ! $field_obj instanceof Field_Base ) {
			return null;
		}

		$field_obj->set_settings( $field );

		// a list may arrive as text from callers which only know strings.
		if ( $field_obj->is_multiple() ) {
			if ( is_string( $value ) ) {
				$value = array_filter( array_map( 'trim', explode( ',', $value ) ), static fn( string $single ): bool => '' !== $single );
			}

			return $field_obj->sanitize( is_array( $value ) ? $value : array() );
		}

		// bail if a single value is not a single value.
		if ( null !== $value && ! is_scalar( $value ) ) {
			return null;
		}

		// "false" as text is not empty for PHP, but it is meant as no.
		if ( is_string( $value ) && in_array( strtolower( trim( $value ) ), array( 'false', 'no', 'off' ), true ) ) {
			$value = '';
		}

		if ( is_string( $value ) ) {
			$value = trim( $value );
		}

		return $field_obj->sanitize( $value );
	}

	/**
	 * Return the default of a field, if it has one.
	 *
	 * @param array<string,mixed> $field The definition of the field.
	 *
	 * @return mixed Null if this field has no default.
	 */
	private function get_default( array $field ): mixed {
		if ( isset( $field['default_callback'] ) && is_callable( $field['default_callback'] ) ) {
			return call_user_func( $field['default_callback'], 0 );
		}

		return $field['default'] ?? null;
	}

	/**
	 * Return whether a value counts as not given.
	 *
	 * Zero is a value: "announce 0 days ahead" is a decision, not a gap.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function is_empty( mixed $value ): bool {
		return null === $value || '' === $value || array() === $value;
	}

	/**
	 * Return what is wrong with an address before it is requested.
	 *
	 * WordPress treats an address it cannot resolve like an unsafe one. For
	 * the user these are two different things: a typo and a blocked network.
	 *
	 * @param string $url The address.
	 *
	 * @return string Empty if nothing is wrong, "unresolvable" or "unsafe" otherwise.
	 */
	private function get_address_problem( string $url ): string {
		$host = trim( (string) wp_parse_url( $url, PHP_URL_HOST ), '[]' );

		// bail if there is no host to resolve.
		if ( '' === $host ) {
			return 'unresolvable';
		}

		// an IP address needs no resolving, and is always checked for real.
		if ( false === filter_var( $host, FILTER_VALIDATE_IP ) ) {
			/**
			 * Filter what is wrong with the address of a source, before its host is looked up.
			 *
			 * @since 1.0.0 Available since 1.0.0.
			 *
			 * @param string|null $problem Return "", "unresolvable" or "unsafe" to skip the lookup, null to run it.
			 * @param string      $url     The address.
			 */
			$pre = apply_filters( 'threadi_now_pages_pre_address_problem', null, $url );
			if ( is_string( $pre ) ) {
				return $pre;
			}

			if ( gethostbyname( $host ) === $host ) {
				return 'unresolvable';
			}
		}

		return Url::is_safe_remote( $url ) ? '' : 'unsafe';
	}

	/**
	 * Return how a missing field is described to the caller.
	 *
	 * @param string              $name  The name of the field.
	 * @param array<string,mixed> $field The definition of the field.
	 *
	 * @return array{name:string,label:string,description:string,placeholder:string}
	 */
	private function get_missing_field( string $name, array $field ): array {
		return array(
			'name'        => $name,
			'label'       => (string) ( $field['label'] ?? $name ),
			'description' => $this->get_plain_text( (string) ( $field['description'] ?? '' ) ),
			'placeholder' => (string) ( $field['placeholder'] ?? '' ),
		);
	}

	/**
	 * Return the description of a provider.
	 *
	 * The description is not part of the interface, so a provider of
	 * another plugin may not have one.
	 *
	 * @param Provider_Interface $provider The provider.
	 *
	 * @return string
	 */
	private function get_provider_description( Provider_Interface $provider ): string {
		if ( ! method_exists( $provider, 'get_description' ) ) {
			return '';
		}

		return (string) $provider->get_description();
	}

	/**
	 * Return a text without any markup.
	 *
	 * @param string $text The text, may contain links.
	 *
	 * @return string
	 */
	private function get_plain_text( string $text ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Collect the errors of providers instead of handling them.
	 *
	 * A check asks on purpose whether something fails. Its failures belong
	 * to whoever asked, not into the log of the website.
	 *
	 * @return WP_Error The object the errors are collected in.
	 */
	private function silence_provider_errors(): WP_Error {
		global $wp_filter;

		$errors = new WP_Error();

		// take every handler away for the moment, they are restored afterwards.
		$this->error_handlers = $wp_filter['threadi_now_pages_provider_error'] ?? null;
		unset( $wp_filter['threadi_now_pages_provider_error'] );

		add_action(
			'threadi_now_pages_provider_error',
			static function ( mixed $slug, mixed $error ) use ( $errors ): void {
				if ( $error instanceof WP_Error ) {
					$errors->add( (string) $error->get_error_code(), $error->get_error_message() );
				}
			},
			10,
			2
		);

		return $errors;
	}

	/**
	 * Give the provider errors back to their handlers.
	 *
	 * @return void
	 */
	private function restore_provider_errors(): void {
		global $wp_filter;

		unset( $wp_filter['threadi_now_pages_provider_error'] );

		if ( null !== $this->error_handlers ) {
			$wp_filter['threadi_now_pages_provider_error'] = $this->error_handlers; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restores what silence_provider_errors() took away.
		}

		$this->error_handlers = null;
	}
}
