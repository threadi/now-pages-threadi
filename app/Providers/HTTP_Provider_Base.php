<?php
/**
 * This file contains the base for providers which talk to a remote API.
 *
 * Keeps timeouts, the user agent, conditional requests and error logging
 * in one place instead of repeating them in every provider.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Providers;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Helper\Plugin;
use ThreadiNowPages\Helper\Url;
use WP_Error;

/**
 * Object which holds the shared HTTP behavior of remote providers.
 */
abstract class HTTP_Provider_Base extends Provider_Base {
	/**
	 * Maximum size of a response body in bytes.
	 *
	 * Protects the import against huge or endless responses. Can be changed
	 * per request via the filter "threadi_now_pages_provider_request_args"
	 * (key "limit_response_size").
	 */
	private const MAX_RESPONSE_SIZE = 5 * MB_IN_BYTES;

	/**
	 * Perform a GET request and return the decoded JSON body.
	 *
	 * Returns null on any error - the error is reported through
	 * threadi_now_pages_provider_error, so the caller only has to bail out.
	 *
	 * @param string               $url     The URL to request.
	 * @param array<string,string> $headers Additional request headers.
	 * @param Provider_Context     $context The context of this run.
	 *
	 * @return array<mixed>|null Null on error or on an unchanged resource.
	 */
	protected function get_json( string $url, array $headers, Provider_Context $context ): ?array {
		$response = $this->request( $url, $headers, $context );

		// bail if the request failed or nothing changed.
		if ( null === $response ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );

		// bail if the body is empty.
		if ( '' === $body ) {
			return null;
		}

		$data = json_decode( $body, true );

		// bail if the body is not valid JSON.
		if ( ! is_array( $data ) ) {
			$this->report_error(
				new WP_Error(
					'threadi_now_pages_invalid_json',
					sprintf(
						/* translators: %1$s will be replaced by an error message. */
						__( 'The response could not be decoded as JSON: %1$s', 'threadi-now-pages' ),
						json_last_error_msg()
					)
				)
			);

			return null;
		}

		return $data;
	}

	/**
	 * Perform a GET request with our shared defaults.
	 *
	 * Sends If-None-Match when an ETag from an earlier run is known and
	 * remembers the new one, so a provider gets conditional requests for free.
	 * The new ETag is stored only when the run of the source completes (see
	 * Provider_Context::mark_as_run()), so an aborted run cannot lose entries.
	 *
	 * @param string               $url     The URL to request.
	 * @param array<string,string> $headers Additional request headers.
	 * @param Provider_Context     $context The context of this run.
	 *
	 * @return array<mixed>|null Null on error or when the resource is unchanged.
	 */
	protected function request( string $url, array $headers, Provider_Context $context ): ?array {
		$etag_key = $this->get_etag_key( $url );
		$etag     = '' !== $etag_key ? (string) $context->get_state( $etag_key, '' ) : '';

		// ask the server to answer only if something changed.
		if ( '' !== $etag ) {
			$headers['If-None-Match'] = $etag;
		}

		$args = array(
			'timeout'             => 15,
			'user-agent'          => 'NowPages/' . NOWPAGES_PLUGIN_VERSION . '; ' . home_url(),
			'headers'             => $headers,
			'limit_response_size' => self::MAX_RESPONSE_SIZE,
		);

		/**
		 * Filter the arguments of a provider request.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<string,mixed> $args The arguments for wp_remote_get().
		 * @param string              $url  The URL being requested.
		 * @param string              $slug The slug of the requesting provider.
		 */
		$args = apply_filters( 'threadi_now_pages_provider_request_args', $args, $url, $this->get_slug() );

		// use the safe variant: it rejects hosts in local or private networks (SSRF)
		// and validates every redirect target the same way.
		$response = wp_safe_remote_get( $url, $args );

		// bail if the request itself failed.
		if ( is_wp_error( $response ) ) {
			$this->report_error( $response );

			return null;
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );

		// nothing changed since the last run, there is no body to read.
		if ( 304 === $code ) {
			// keep the known ETag.
			if ( '' !== $etag ) {
				$context->set_pending_state( $etag_key, $etag );
			}

			return null;
		}

		// bail on any other non-successful status.
		if ( $code < 200 || $code >= 300 ) {
			$this->report_error(
				new WP_Error(
					'threadi_now_pages_http_error',
					sprintf(
						/* translators: %1$d will be replaced by a status code, %2$s by a URL. */
						__( 'The request to %2$s answered with status %1$d.', 'threadi-now-pages' ),
						$code,
						Url::redact( $url )
					),
					array( 'status' => $code )
				)
			);

			return null;
		}

		// bail if the body reached the size limit: it is cut off, and remembering
		// the ETag of the incomplete answer would keep it incomplete forever.
		$limit = absint( $args['limit_response_size'] ?? 0 );
		if ( $limit > 0 && strlen( wp_remote_retrieve_body( $response ) ) >= $limit ) {
			$this->report_error(
				new WP_Error(
					'threadi_now_pages_response_too_large',
					sprintf(
						/* translators: %1$s will be replaced by a URL, %2$s by a file size. */
						__( 'The answer of %1$s is larger than %2$s and has been ignored.', 'threadi-now-pages' ),
						Url::redact( $url ),
						size_format( $limit )
					)
				)
			);

			return null;
		}

		// remember the ETag for the next run, it is stored when the run completes.
		$new_etag = wp_remote_retrieve_header( $response, 'etag' );

		if ( '' !== $etag_key && is_string( $new_etag ) && '' !== $new_etag ) {
			$context->set_pending_state( $etag_key, $new_etag );
		}

		return $response;
	}

	/**
	 * Return the state key the ETag of the given URL is stored under.
	 *
	 * Providers whose URLs contain changing parameters should override this
	 * and build the key from a stable URL, or return an empty string to not
	 * use ETags at all.
	 *
	 * @param string $url The requested URL.
	 *
	 * @return string Empty string to not use ETags for this URL.
	 */
	protected function get_etag_key( string $url ): string {
		return Provider_Context::ETAG_PREFIX . md5( $url );
	}

	/**
	 * Report an error through the central provider error action.
	 *
	 * @param WP_Error $error The error.
	 *
	 * @return void
	 */
	protected function report_error( WP_Error $error ): void {
		/** This action is documented in app/Providers/RSS_Provider.php */
		do_action( 'threadi_now_pages_provider_error', $this->get_slug(), $error );
	}
}
