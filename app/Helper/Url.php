<?php
/**
 * This file contains URL-helper for this plugin.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Helper;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Object with URL-helper functions for this plugin.
 */
class Url {
	/**
	 * Return a URL which is safe to write into logs or show in dialogs.
	 *
	 * Removes credentials, the query string, the fragment and path segments
	 * which look like secret tokens (e.g. private calendar addresses), so API
	 * keys like "key=" or "api_key=" never end up in the log.
	 *
	 * @param string $url The URL.
	 *
	 * @return string
	 */
	public static function redact( string $url ): string {
		$parts = wp_parse_url( $url );

		// bail if the URL could not be parsed: show nothing of it.
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '[' . __( 'hidden URL', 'now-pages-threadi' ) . ']';
		}

		$result = ( ! empty( $parts['scheme'] ) ? $parts['scheme'] . '://' : '' ) . $parts['host'];

		if ( ! empty( $parts['port'] ) ) {
			$result .= ':' . absint( $parts['port'] );
		}

		if ( ! empty( $parts['path'] ) ) {
			$segments = explode( '/', $parts['path'] );
			foreach ( $segments as $index => $segment ) {
				// hide segments which look like tokens or hashes.
				if ( 1 === preg_match( '/[A-Za-z0-9_%\-]{20,}/', $segment ) ) {
					$segments[ $index ] = '***';
				}
			}
			$result .= implode( '/', $segments );
		}

		if ( ! empty( $parts['query'] ) ) {
			$result .= '?***';
		}

		return $result;
	}

	/**
	 * Return whether the URL may be requested by this plugin.
	 *
	 * Uses the same rules as wp_safe_remote_get(): only http(s), no
	 * credentials, and no hosts in local or private networks (unless
	 * allowed via the WordPress filter "http_request_host_is_external").
	 *
	 * @param string $url The URL.
	 *
	 * @return bool
	 */
	public static function is_safe_remote( string $url ): bool {
		return false !== wp_http_validate_url( $url );
	}
}
