<?php
/**
 * Resolves display settings for digests with hierarchical priority:
 * shortcode / explicit args > user meta > global options > hard-coded defaults.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Digests;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Central place for digest output options (show time, source, grouping, delay, …).
 */
class Display_Settings {

	/**
	 * The highest number of hours a delay or a look-back may have: ten years.
	 *
	 * Without a limit the number of seconds exceeds the range of an integer,
	 * and the date functions refuse the float which results from it.
	 */
	public const MAX_HOURS = 87600;

	/**
	 * Option / user-meta keys and their hard-coded defaults (Konzept.md).
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'disabled'          => false,
			'show_time'         => false,
			'show_source'       => false,
			'link_source'       => false,
			'show_period'       => true,
			'group_by_category' => true,
			'order'             => 'ASC',
			'delay_hours'       => 6,
		);
	}

	/**
	 * Global option names (wp_options).
	 *
	 * @return array<string,string> map of setting key => option name
	 */
	public static function option_names(): array {
		return array(
			'disabled'          => 'now_pages_threadi_disable_now_page',
			'show_time'         => 'now_pages_threadi_show_time',
			'show_source'       => 'now_pages_threadi_show_source',
			'link_source'       => 'now_pages_threadi_link_source',
			'show_period'       => 'now_pages_threadi_show_period',
			'group_by_category' => 'now_pages_threadi_group_by_category',
			'order'             => 'now_pages_threadi_order',
			'delay_hours'       => 'now_pages_threadi_delay_hours',
		);
	}

	/**
	 * User-meta keys (same names as options for consistency).
	 *
	 * @return array<string,string>
	 */
	public static function user_meta_keys(): array {
		return self::option_names();
	}

	/**
	 * Return whether an author hides the times of their entries in the profile.
	 *
	 * The setting "Show times" in the profile is a privacy setting: it also
	 * applies to digests of all users and to AI summaries.
	 *
	 * @param int $author_id The ID of the author.
	 *
	 * @return bool
	 */
	public static function is_time_hidden_by_author( int $author_id ): bool {
		$value = get_user_meta( $author_id, self::user_meta_keys()['show_time'], true );

		// an empty value means the author inherits the setting.
		if ( '' === $value || false === $value ) {
			return false;
		}

		return ! filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Resolve the full set of display settings.
	 *
	 * Priority (highest wins):
	 * 1. Explicit values already present in $args (from shortcode / caller)
	 * 2. User meta of the resolved user (if any)
	 * 3. Global options
	 * 4. Hard-coded defaults
	 *
	 * @param array<string,mixed> $args Digest args; may already contain explicit overrides
	 *                                  and optionally 'user_id' (int).
	 *
	 * @return array<string,mixed> Resolved settings merged into a copy of $args.
	 */
	public static function resolve( array $args ): array {
		// bail if this array has already been resolved: running it twice
		// would mark every value as an explicit override.
		if ( isset( $args['_origin'] ) ) {
			return $args;
		}

		$defaults = self::defaults();
		$resolved = $defaults;

		// remember where each value came from, for the diagnostics panel.
		$origin = array_fill_keys( array_keys( $defaults ), 'default' );

		// 3. Global options.
		foreach ( self::option_names() as $key => $option ) {
			$value = get_option( $option, null );
			if ( null !== $value && '' !== $value ) {
				$resolved[ $key ] = self::cast( $key, $value );
				$origin[ $key ]   = 'option';
			}
		}

		// 2. User meta (only when a concrete user is targeted).
		$user_id = isset( $args['user_id'] ) ? absint( $args['user_id'] ) : 0;
		if ( $user_id > 0 ) {
			foreach ( self::user_meta_keys() as $key => $meta_key ) {
				$value = get_user_meta( $user_id, $meta_key, true );

				// older versions stored the order as "0": only ASC/DESC are real choices.
				if ( 'order' === $key && ! in_array( $value, array( 'ASC', 'DESC' ), true ) ) {
					continue;
				}

				// Empty string = "not set by user" => keep previous level.
				if ( '' !== $value && false !== $value ) {
					$resolved[ $key ] = self::cast( $key, $value );
					$origin[ $key ]   = 'user_meta';
				}
			}
		}

		// 1. Explicit overrides from caller / shortcode (only keys that are already set).
		foreach ( array_keys( $defaults ) as $key ) {
			if ( array_key_exists( $key, $args ) ) {
				$resolved[ $key ] = self::cast( $key, $args[ $key ] );
				$origin[ $key ]   = 'explicit';
			}
		}

		// expose the origins under a reserved key.
		$resolved['_origin'] = $origin;

		// Merge resolved display keys back into the full args array.
		return array_merge( $args, $resolved );
	}

	/**
	 * Cast a raw value to the correct type for the given setting key.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Raw value.
	 *
	 * @return mixed
	 */
	private static function cast( string $key, mixed $value ): mixed {
		if ( 'delay_hours' === $key ) {
			return self::limit_hours( $value );
		}

		// the sort order is a string with exactly two valid values.
		if ( 'order' === $key ) {
			return 'DESC' === strtoupper( (string) $value ) ? 'DESC' : 'ASC';
		}

		// Boolean settings.
		if ( is_bool( $value ) ) {
			return $value;
		}

		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Resolve a user identifier (ID, login or slug) to a user ID.
	 *
	 * @param string|int $user Raw user attribute from shortcode or caller.
	 *
	 * @return int 0 if not found / empty.
	 */
	public static function resolve_user_id( string|int $user ): int {
		if ( '' === $user || 0 === $user || '0' === $user ) {
			return 0;
		}

		if ( is_numeric( $user ) ) {
			$id = absint( $user );
			return get_userdata( $id ) ? $id : 0;
		}

		$user_obj = get_user_by( 'login', (string) $user );
		if ( ! $user_obj ) {
			$user_obj = get_user_by( 'slug', (string) $user );
		}

		return $user_obj ? (int) $user_obj->ID : 0;
	}

	/**
	 * Return human-readable labels for the origin of a resolved value.
	 *
	 * @return array<string,string>
	 */
	public static function origin_labels(): array {
		return array(
			'default'   => __( 'Plugin default', 'now-pages-threadi' ),
			'option'    => __( 'Site-wide setting', 'now-pages-threadi' ),
			'user_meta' => __( 'User profile', 'now-pages-threadi' ),
			'explicit'  => __( 'Shortcode or block attribute', 'now-pages-threadi' ),
		);
	}

	/**
	 * Return the given hours as integer within the allowed range.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return int
	 */
	public static function limit_hours( mixed $value ): int {
		return min( self::MAX_HOURS, absint( is_scalar( $value ) ? $value : 0 ) );
	}
}
