<?php
/**
 * This file contains datetime-helper for this plugin.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Helper;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Object with datetime-helper functions for this plugin.
 */
class DateTime {
	/**
	 * Format a given datetime with WP-settings and functions.
	 *
	 * @param string $date The date as YYYY-MM-DD.
	 * @return string
	 */
	public static function get_format_date_time( string $date ): string {
		$dt = get_date_from_gmt( $date );
		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $dt ) );
	}

	/**
	 * Format a given datetime with WP-settings and functions.
	 *
	 * @param int $date The date.
	 * @return string
	 */
	public static function get_format_date_time_from_int( int $date ): string {
		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $date );
	}
}
