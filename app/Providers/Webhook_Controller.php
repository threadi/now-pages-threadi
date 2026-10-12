<?php
/**
 * This file contains the generic push/webhook add endpoint.
 *
 * Push-based counterpart to the pull-based providers (like RSS_Provider):
 * instead of us fetching data on a schedule, external systems (Discord
 * bots, a self-hosted n8n / Make / Zapier flow, Mastodon webhooks, ...)
 * send already-normalized entries to this endpoint. This means we do not
 * need a dedicated client for every possible source - only one endpoint,
 * plus the connection logic lives in the external automation tool.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Providers;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use Exception;
use ThreadiNowPages\Helper\Languages;
use ThreadiNowPages\Helper\Singleton_Trait;
use ThreadiNowPages\NowPages\Categories;
use ThreadiNowPages\NowPages\Entries;
use ThreadiNowPages\NowPages\Entry;
use ThreadiNowPages\Plugin\Crypt;
use ThreadiNowPages\Plugin\Log;
use ThreadiNowPages\Plugin\Settings;
use ThreadiNowPages\Sources\Sources;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Registers and handles the REST route used to push entries into now pages.
 */
class Webhook_Controller {
	use Singleton_Trait;

	/**
	 * Route namespace.
	 */
	public const NAMESPACE = 'threadi-now-pages/v1';

	/**
	 * Route path.
	 */
	public const ROUTE = '/add';

	/**
	 * Option name for token value.
	 */
	public const OPTION_TOKEN = 'threadi_now_pages_webhook_token';

	/**
	 * Number of failed attempts per hour before a client is blocked.
	 *
	 * @var int
	 */
	private const MAX_FAILURES = 20;

	/**
	 * Maximum length of the fields a webhook request may send, in characters.
	 */
	private const MAX_TEXT_LENGTH        = 2000;
	private const MAX_EXTERNAL_ID_LENGTH = 191;
	private const MAX_URL_LENGTH         = 2048;
	private const MAX_SOURCE_LENGTH      = 100;
	private const MAX_LANGUAGE_LENGTH    = 35;
	private const MAX_DATE_LENGTH        = 64;

	/**
	 * Seconds a submitted date may lie in the future (clock differences).
	 *
	 * @var int
	 */
	private const MAX_FUTURE_SECONDS = 5 * MINUTE_IN_SECONDS;

	/**
	 * The oldest date accepted for an entry.
	 *
	 * @var string
	 */
	private const MIN_DATE = '2000-01-01T00:00:00+00:00';

	/**
	 * Minimal length of a configured token (global or per source).
	 */
	public const MIN_TOKEN_LENGTH = 32;

	/**
	 * Constructor, not used as this a Singleton object.
	 */
	private function __construct() {}

	/**  * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_action_threadi_now_pages_generate_webhook_token', array( $this, 'generate_token' ) );
		add_filter( 'threadi_now_pages_validate_webhook_token', array( $this, 'validate_token' ), 10, 2 );
		add_filter( 'threadi_now_pages_log_categories', array( $this, 'add_log_category' ) );
	}

	/**
	 * Register the REST route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'text'             => array(
						'required' => false,
						'type'     => 'string',
					),
					'date'             => array(
						'required' => false,
						'type'     => 'string',
					),
					'source'           => array(
						'required' => false,
						'type'     => 'string',
					),
					'external_id'      => array(
						'required' => false,
						'type'     => 'string',
					),
					'url'              => array(
						'required' => false,
						'type'     => 'string',
					),
					'category_term_id' => array(
						'required' => false,
						'type'     => 'integer',
					),
					'language'         => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Check whether the request is authorized to push entries.
	 *
	 * Validation is deliberately filterable: once the per-source
	 * configuration (author, secret token, allowed category, ...) exists,
	 * hook into `threadi_now_pages_validate_webhook_token` there and look the
	 * token up against the matching automation config, instead of a
	 * single shared secret.
	 *
	 * @param WP_REST_Request $request The current request.
	 *
	 * @return bool|WP_Error
	 */
	public function check_permission( WP_REST_Request $request ): bool|WP_Error {
		// get the provider object.
		$provider = Providers::get_instance()->get_provider( 'webhook' );

		// bail if provider could not be found or is not enabled.
		if (
			! $provider instanceof Provider_Interface
			|| ! $provider->is_enabled()
		) {
			return new WP_Error( 'threadi_now_pages_webhook_disabled', __( 'The webhook is disabled.', 'threadi-now-pages' ), array( 'status' => 403 ) );
		}

		// check whether this client already failed too often. the check is
		// applied to failed requests only: a valid token always passes, so
		// behind a proxy or CDN (one shared address) attackers cannot lock
		// out the real integration. the 40-character token makes guessing
		// impossible anyway; the limit mainly protects the log.
		$is_blocked = $this->is_blocked();

		// get the required token from request.
		$token = $request->get_header( 'X-Now-Pages-Token' );

		if ( empty( $token ) ) {
			// bail without log entry if blocked - that is exactly what an attacker would use to flood the log.
			if ( $is_blocked ) {
				return $this->get_blocked_error();
			}

			// count request.
			$this->count_failure();

			// log this event.
			Log::get_instance()->add( __( 'A webhook request without a token was rejected.', 'threadi-now-pages' ), 'error', 'webhook' );

			// return error.
			return new WP_Error( 'threadi_now_pages_missing_token', __( 'Missing authentication token.', 'threadi-now-pages' ), array( 'status' => 401 ) );
		}

		// get the own token of the requested source, if it has one.
		$source_token = $this->get_source_token( $request );

		// bail if the source has an own token which can not be decrypted (e.g. changed
		// encryption key): falling back to the global token would open the source.
		if ( null === $source_token ) {
			Log::get_instance()->add( __( 'A webhook request was rejected because the token of its source could not be decrypted. Please save the token of this source again.', 'threadi-now-pages' ), 'error', 'webhook' );

			return new WP_Error( 'threadi_now_pages_invalid_token', __( 'Invalid authentication token.', 'threadi-now-pages' ), array( 'status' => 403 ) );
		}

		// bail if the token of the source is too short to be safe against guessing.
		if ( '' !== $source_token && ! $this->is_usable_token( $source_token ) ) {
			Log::get_instance()->add( __( 'A webhook request was rejected because the token of its source is too short. Please use a token with at least 32 characters.', 'threadi-now-pages' ), 'error', 'webhook' );

			return new WP_Error( 'threadi_now_pages_invalid_token', __( 'Invalid authentication token.', 'threadi-now-pages' ), array( 'status' => 403 ) );
		}

		$is_valid = false;
		if ( '' !== $source_token ) {
			// a source with its own token accepts only this token, not the global one.
			$is_valid = hash_equals( $source_token, (string) $token );
		} else {
			/**
			 * Filters whether a given webhook token is valid.
			 *
			 * Not used for requests to a source which has its own token.
			 *
			 * @since 1.0.0 Available since 1.0.0.
			 *
			 * @param bool             $is_valid Whether the token is valid. Default false.
			 * @param string           $token    The submitted token.
			 * @param WP_REST_Request  $request  The full request, e.g. to read source-specific data from the body.
			 *
			 * @noinspection PhpConditionAlreadyCheckedInspection
			 */
			$is_valid = apply_filters( 'threadi_now_pages_validate_webhook_token', $is_valid, $token, $request );
		}

		if ( ! $is_valid ) {
			// bail without log entry if blocked.
			if ( $is_blocked ) {
				return $this->get_blocked_error();
			}

			// count request.
			$this->count_failure();

			// log this event.
			Log::get_instance()->add( __( 'A webhook request with an invalid token was rejected.', 'threadi-now-pages' ), 'error', 'webhook' );

			// return error.
			return new WP_Error( 'threadi_now_pages_invalid_token', __( 'Invalid authentication token.', 'threadi-now-pages' ), array( 'status' => 403 ) );
		}

		// a valid request clears the counter of this client.
		if ( false !== get_transient( $this->get_rate_limit_key() ) ) {
			delete_transient( $this->get_rate_limit_key() );
		}

		// return true as request is valid.
		return true;
	}

	/**
	 * Return the slug of the source a request is meant for.
	 *
	 * @param WP_REST_Request $request The current request.
	 *
	 * @return string
	 */
	private function get_source_slug( WP_REST_Request $request ): string {
		$source = $request->get_param( 'source' );

		// use the default if no usable source is given.
		if ( ! is_scalar( $source ) || '' === (string) $source ) {
			return 'webhook';
		}

		return sanitize_key( (string) $source );
	}

	/**
	 * Return the own token of the source a request is meant for.
	 *
	 * @param WP_REST_Request $request The current request.
	 *
	 * @return string|null Empty string if the source has no own token, null if it can not be decrypted.
	 */
	private function get_source_token( WP_REST_Request $request ): ?string {
		// get the source ID.
		$source_id = Sources::get_instance()->resolve_source( $this->get_source_slug( $request ) );

		// bail if no source is configured for this slug.
		if ( 0 === $source_id ) {
			return '';
		}

		// get the stored token.
		$encrypted = (string) get_post_meta( $source_id, 'webhook_token', true );

		// bail if the source has no own token.
		if ( '' === $encrypted ) {
			return '';
		}

		// get the decrypted token.
		$token = Crypt::get_instance()->decrypt( $encrypted );

		// return null if the stored token could not be decrypted.
		return '' === $token ? null : $token;
	}

	/**
	 * Return the error for a client which failed too often.
	 *
	 * @return WP_Error
	 */
	private function get_blocked_error(): WP_Error {
		return new WP_Error(
			'threadi_now_pages_too_many_attempts',
			__( 'Too many failed attempts. Try again later.', 'threadi-now-pages' ),
			array( 'status' => 429 )
		);
	}

	/**
	 * Handle an incoming push request: normalize it into an Entry and hand it off.
	 *
	 * We intentionally do not persist the entry here - that keeps this
	 * controller storage-agnostic and let's pull (RSS) and push (webhook)
	 * entries share the exact same downstream path (logging, keyword
	 * filter, dedupe, saving as now_page_entries) via one hook.
	 *
	 * @param WP_REST_Request $request The current request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		// get the limit for the text, which site owners may adjust.
		$max_text = absint( apply_filters( 'threadi_now_pages_webhook_max_text_length', self::MAX_TEXT_LENGTH ) );

		$limits = array(
			'text'        => $max_text,
			'external_id' => self::MAX_EXTERNAL_ID_LENGTH,
			'url'         => self::MAX_URL_LENGTH,
			'source'      => self::MAX_SOURCE_LENGTH,
			'language'    => self::MAX_LANGUAGE_LENGTH,
			'date'        => self::MAX_DATE_LENGTH,
		);

		foreach ( $limits as $field => $max ) {
			$value = $request->get_param( $field );

			// the REST arguments already ensure strings, so anything else is not our concern here.
			if ( is_string( $value ) && mb_strlen( $value ) > $max ) {
				return new WP_Error(
					'threadi_now_pages_field_too_long',
					sprintf(
					/* translators: %1$s will be replaced by a field name, %2$d by a number. */
						__( 'The "%1$s" field must not be longer than %2$d characters.', 'threadi-now-pages' ),
						$field,
						$max
					),
					array( 'status' => 400 )
				);
			}
		}

		// get the text from request.
		$text = trim( wp_strip_all_tags( (string) $request->get_param( 'text' ) ) );

		if ( '' === $text ) {
			return new WP_Error( 'threadi_now_pages_empty_text', __( 'The "text" field must not be empty.', 'threadi-now-pages' ), array( 'status' => 400 ) );
		}

		// get the given date from request.
		$date_param = $request->get_param( 'date' );

		try {
			$date = $date_param ? new DateTimeImmutable( (string) $date_param ) : new DateTimeImmutable();
		} catch ( Exception $e ) {
			// log this event.
			Log::get_instance()->add( __( 'The "date" field could not be parsed. Error:', 'threadi-now-pages' ) . ' <code>' . esc_html( $e->getMessage() ) . '</code>', 'error', 'system' );

			// return error.
			return new WP_Error( 'threadi_now_pages_invalid_date', __( 'The "date" field could not be parsed.', 'threadi-now-pages' ), array( 'status' => 400 ) );
		}

		// bail if the date is in the future: entries from the future would stay on top of the now page.
		if ( $date->getTimestamp() > time() + self::MAX_FUTURE_SECONDS ) {
			return new WP_Error( 'threadi_now_pages_future_date', __( 'The "date" field must not be in the future.', 'threadi-now-pages' ), array( 'status' => 400 ) );
		}

		// bail if the date is implausibly old.
		if ( $date < new DateTimeImmutable( self::MIN_DATE ) ) {
			return new WP_Error( 'threadi_now_pages_old_date', __( 'The "date" field is too old.', 'threadi-now-pages' ), array( 'status' => 400 ) );
		}

		// get the source from request.
		$source_slug = $this->get_source_slug( $request );

		// get the URL from request.
		$url_param = $request->get_param( 'url' );

		// get the category term ID from request.
		$term_id = absint( $request->get_param( 'category_term_id' ) );

		// bail if given term ID is not ours or does not exist.
		if ( $term_id > 0 && ! term_exists( $term_id, Categories::get_instance()->get_slug() ) ) {
			return new WP_Error(
				'threadi_now_pages_unknown_category',
				__( 'The given "category_term_id" does not exist in the now pages categories.', 'threadi-now-pages' ),
				array( 'status' => 400 )
			);
		}

		// get the source ID.
		$source_id = Sources::get_instance()->resolve_source( $source_slug );

		// log if source is unknown.
		if ( 0 === $source_id && 'webhook' !== $source_slug ) {
			Log::get_instance()->add(
				sprintf(
				/* translators: %1$s will be replaced by a slug. */
					__( 'No source is configured for the webhook slug %1$s. The entry was stored without a source.', 'threadi-now-pages' ),
					'<code>' . esc_html( $source_slug ) . '</code>'
				),
				'info',
				'webhook'
			);
		}

		// create the entry.
		$entry                   = new Entry();
		$entry->text             = $text;
		$entry->date             = $date;
		$entry->source_slug      = $source_slug;
		$entry->source_url       = $url_param ? esc_url_raw( (string) $url_param ) : null;
		$entry->category_term_id = $term_id;
		$language                = $request->get_param( 'language' );
		$entry->language         = $language ? Languages::normalize_locale( (string) $language ) : null;
		$external_id             = $request->get_param( 'external_id' );
		$entry->external_id      = $external_id ? sanitize_text_field( (string) $external_id ) : md5( $source_slug . $text . $date->format( 'c' ) );

		// forget any older result, so the outcome of this request can be read below.
		Entries::get_instance()->pull_last_add_result();

		/**
		 * Fires once a normalized entry has been received, from any provider (pull or push).
		 *
		 * The actual now_page_entries-post is created by whatever hooks into this
		 * (keyword-filter, dedupe-check, storage), so both add paths converge here.
		 *
		 * @param Entry            $entry   The normalized entry.
		 * @param int  $source_id The ID of the source.
		 */
		do_action( 'threadi_now_pages_entry_received', $entry, $source_id );

		// return the result.
		return $this->get_response( Entries::get_instance()->pull_last_add_result() );
	}

	/**
	 * Return the response for the outcome of storing an entry.
	 *
	 * 201 only if an entry has been created. An entry which was skipped on
	 * purpose (duplicate, deleted before, content filter) is answered with
	 * 200, so a sender does not retry it.
	 *
	 * @param array{added:bool,post_id:int,reason:string,message:string}|null $result The outcome, null if the handler for new entries did not run.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	private function get_response( ?array $result ): WP_REST_Response|WP_Error {
		// another handler took the entry, we cannot know what happened to it.
		if ( null === $result ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'added'   => false,
				),
				202
			);
		}

		// the entry has been created.
		if ( $result['added'] ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'added'   => true,
					'id'      => $result['post_id'],
				),
				201
			);
		}

		// the entry could not be stored.
		if ( 'insert_failed' === $result['reason'] ) {
			return new WP_Error( 'threadi_now_pages_insert_failed', __( 'The entry could not be stored.', 'threadi-now-pages' ), array( 'status' => 500 ) );
		}

		// the entry has been skipped on purpose.
		return new WP_REST_Response(
			array(
				'success' => true,
				'added'   => false,
				'reason'  => $result['reason'],
				'message' => $result['message'],
			),
			200
		);
	}

	/**
	 * Generate the Webhook token.
	 *
	 * @return void
	 */
	public function generate_token(): void {
		// check token.
		check_admin_referer( 'threadi-now-pages-generate-webhook-token', 'nonce' );

		// bail if capability is missing.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage webhooks.', 'threadi-now-pages' ), '', array( 'response' => 403 ) );
		}

		// use only alphanumeric characters, the value travels in an HTTP header.
		update_option( self::OPTION_TOKEN, wp_generate_password( 40, false ) );

		// enable the webhooks.
		update_option( 'threadi_now_pages_provider_webhook_enabled', 1 );

		// log this event.
		Log::get_instance()->add( __( 'A new webhook token has been generated.', 'threadi-now-pages' ), 'info', 'webhook' );

		wp_safe_redirect( Settings::get_instance()->get_url( 'threadi_now_pages_import' ) );
		exit;
	}

	/**
	 * Validate the token.
	 *
	 * @param bool   $is_valid Whether the token is valid.
	 * @param string $token The token.
	 *
	 * @return bool
	 */
	public function validate_token( bool $is_valid, string $token ): bool {
		// bail if another handler already accepted it.
		if ( $is_valid ) {
			return true;
		}

		// get the configured token.
		$configured = (string) get_option( self::OPTION_TOKEN, '' );

		// bail if no token is configured.
		if ( '' === $configured ) {
			return false;
		}

		// bail if the configured token is too short to be safe against guessing.
		if ( ! $this->is_usable_token( $configured ) ) {
			return false;
		}

		// return true if the tokens matching.
		return hash_equals( $configured, $token );
	}

	/**
	 * Add our own log category.
	 *
	 * @param array<string,string> $categories List of log categories.
	 *
	 * @return array<string,string>
	 */
	public function add_log_category( array $categories ): array {
		// add our category.
		$categories['webhook'] = __( 'Webhook', 'threadi-now-pages' );

		// return the resulting categories.
		return $categories;
	}

	/**
	 * Return the key under which failed attempts of the current client are counted.
	 *
	 * @return string
	 */
	private function get_rate_limit_key(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- validated as IP below.
		$ip = (string) filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ), FILTER_VALIDATE_IP );

		/**
		 * Filter the client identifier used for rate limiting.
		 *
		 * Behind a reverse proxy or a CDN, REMOTE_ADDR is the proxy - return
		 * the real client address from the appropriate header here.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param string $ip The client address, empty if it could not be determined.
		 */
		$ip = (string) apply_filters( 'threadi_now_pages_webhook_client_ip', $ip );

		return 'threadi_now_pages_webhook_fails_' . md5( $ip );
	}

	/**
	 * Return whether the current client is blocked after too many failures.
	 *
	 * @return bool
	 */
	private function is_blocked(): bool {
		return absint( get_transient( $this->get_rate_limit_key() ) ) >= self::MAX_FAILURES;
	}

	/**
	 * Count one failed attempt of the current client.
	 *
	 * @return void
	 */
	private function count_failure(): void {
		$key = $this->get_rate_limit_key();

		set_transient( $key, absint( get_transient( $key ) ) + 1, HOUR_IN_SECONDS );
	}

	/**
	 * Return whether a configured token is long enough to be used.
	 *
	 * @param string $token The configured token.
	 *
	 * @return bool
	 */
	private function is_usable_token( string $token ): bool {
		return strlen( $token ) >= self::MIN_TOKEN_LENGTH;
	}
}
