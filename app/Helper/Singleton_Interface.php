<?php
/**
 * File to our singleton interface object.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Helper;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The interface object.
 */
interface Singleton_Interface {
	/**
	 * Return the instance.
	 *
	 * @return static
	 */
	public static function get_instance(): static;
}
