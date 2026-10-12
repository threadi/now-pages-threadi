<?php
/**
 * This file contains helper for language tasks.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Helper;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Object with helper for this plugin.
 */
class Languages {
	/**
	 * Return the current front-end language as complete string (e.g., 'de_DE').
	 *
	 * Deliberately does NOT translate anything itself - it only answers
	 * "which language should be used right now". Multilingual plugins
	 * (WPML, Polylang, ...) hook into the filter to provide their own
	 * detection; without one, this falls back to the site's own locale.
	 *
	 * @return string
	 */
	public static function get_current_language(): string {
		/**
		 * Filter the current front-end language (2-letter code).
		 *
		 * Example: add_filter( 'threadi_now_pages_current_language', function () {
		 *     return substr( apply_filters( 'wpml_current_language', get_locale() ), 0, 5 );
		 * } );
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param string $language The detected language. Default: the site's own locale.
		 */
		$language = (string) apply_filters( 'threadi_now_pages_current_language', self::normalize_locale( get_locale() ) );

		// use the same form as for the stored languages, also for values from the filter (e.g. "de" from WPML).
		return self::normalize_locale( $language );
	}

	/**
	 * Normalize any BCP-47-ish language tag (e.g. from an RSS/Atom feed) into
	 * the form this plugin uses for every stored and queried language:
	 * language and region in WordPress' locale format ("de_DE", "en_US", "pt_BR", ...).
	 *
	 * Variants like "formal" are removed, so "de_DE_formal" becomes "de_DE". A bare
	 * language like "de" gets the region of the site's locale or of an installed one.
	 *
	 * @param string $raw The raw tag, e.g. "en-us", "de-DE", "fr", "de_DE_formal".
	 *
	 * @return string Empty string if $raw could not be parsed at all.
	 */
	public static function normalize_locale( string $raw ): string {
		// get language and region.
		$locale = self::reduce_locale( $raw );

		// bail if nothing could be parsed or a region is given.
		if ( '' === $locale || str_contains( $locale, '_' ) ) {
			return $locale;
		}

		// use the region guessed before in this request, as the installed languages are read from disk.
		static $guesses = array();
		$cache_key      = $locale . '|' . get_locale();
		if ( isset( $guesses[ $cache_key ] ) ) {
			return $guesses[ $cache_key ];
		}

		// only a bare language given (e.g. "de") - guess the region from the site's
		// locale and what's actually installed, so it matches a real WP locale where possible.
		$guesses[ $cache_key ] = $locale;
		foreach ( array_merge( array( get_locale() ), get_available_languages(), array( 'en_US' ) ) as $known_locale ) {
			// reduce the known locale to language and region.
			$known_locale = self::reduce_locale( $known_locale );

			// use it if it has a region and the same language.
			if ( str_starts_with( $known_locale, $locale . '_' ) ) {
				$guesses[ $cache_key ] = $known_locale;
				break;
			}
		}

		return $guesses[ $cache_key ];
	}

	/**
	 * Reduce a language tag to its language and, if given, its region (e.g. "de_DE").
	 *
	 * Script and variants are removed. No region is guessed here.
	 *
	 * @param string $raw The raw tag, e.g. "en-us", "de_DE_formal", "zh-Hant-TW".
	 *
	 * @return string Empty string if $raw could not be parsed at all.
	 */
	private static function reduce_locale( string $raw ): string {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return '';
		}

		// preferred: let PHP's intl extension do proper BCP-47 -> locale canonicalization.
		if ( class_exists( '\Locale' ) ) {
			$canonical = \Locale::canonicalize( $raw );

			if ( $canonical ) {
				$raw = $canonical;
			}
		}

		// parse "language[-script][-region][-variant]" by hand.
		$parts    = preg_split( '/[-_]/', $raw );
		$parts    = is_array( $parts ) ? $parts : array();
		$language = strtolower( $parts[0] ?? '' );

		if ( '' === $language ) {
			return '';
		}

		// skip a script (e.g. "Hant" in "zh-Hant-TW").
		$region = $parts[1] ?? '';
		if ( 1 === preg_match( '/^[a-z]{4}$/i', $region ) ) {
			$region = $parts[2] ?? '';
		}

		// use the region only if it is one (e.g. "DE" or "419"), not a variant like "formal".
		if ( 1 === preg_match( '/^([a-z]{2}|[0-9]{3})$/i', $region ) ) {
			return $language . '_' . strtoupper( $region );
		}

		return $language;
	}

	/**
	 * Return the meta query to find entries or sources in the given language.
	 *
	 * Matches the normalized language and values stored before the normalization
	 * was used everywhere: with a variant (e.g. "de_DE_formal") or without region (e.g. "de").
	 *
	 * @param string $language The language, e.g. "de_DE".
	 *
	 * @return array<int|string,mixed>
	 */
	public static function get_meta_query( string $language ): array {
		$language = self::normalize_locale( $language );

		// bail if no language is given.
		if ( '' === $language ) {
			return array();
		}

		// get the exact values to match.
		$values = array( $language );
		if ( str_contains( $language, '_' ) ) {
			$values[] = strstr( $language, '_', true );
		}

		// return the query for the exact values and the values with a variant.
		return array(
			'relation' => 'OR',
			array(
				'key'     => 'language',
				'value'   => $values,
				'compare' => 'IN',
			),
			array(
				'key'     => 'language',
				'value'   => $language . '_',
				'compare' => 'LIKE',
			),
		);
	}

	/**
	 * Return whether the given stored language matches the requested one.
	 *
	 * Uses the same rules as get_meta_query().
	 *
	 * @param string $stored   The stored language, e.g. "de_DE_formal".
	 * @param string $language The requested language, e.g. "de_DE".
	 *
	 * @return bool
	 */
	public static function matches( string $stored, string $language ): bool {
		$language = self::normalize_locale( $language );

		// bail if no language is requested.
		if ( '' === $language ) {
			return true;
		}

		// bail if the stored language is empty.
		if ( '' === $stored ) {
			return false;
		}

		return $language === $stored
			|| ( str_contains( $language, '_' ) && strstr( $language, '_', true ) === $stored )
			|| str_contains( $stored, $language . '_' );
	}

	/**
	 * Return the readable name of a locale.
	 *
	 * Uses the intl extension if available, otherwise the names WordPress knows.
	 *
	 * @param string $locale The locale, e.g. "de_DE".
	 *
	 * @return string
	 */
	public static function get_display_name( string $locale ): string {
		// use the intl extension if it is available.
		if ( function_exists( 'locale_get_display_name' ) ) {
			$name = (string) \locale_get_display_name( $locale, get_user_locale() );

			if ( '' !== $name ) {
				return $name;
			}
		}

		// en_US is not part of the translation list of WordPress.
		if ( 'en_US' === $locale ) {
			return 'English (United States)';
		}

		// load the list only once per request, it may need an HTTP request.
		static $translations = null;
		if ( null === $translations ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
			$translations = wp_get_available_translations();
		}

		// fall back to the name WordPress knows, or the locale itself.
		return ! empty( $translations[ $locale ]['native_name'] ) ? (string) $translations[ $locale ]['native_name'] : $locale;
	}
}
