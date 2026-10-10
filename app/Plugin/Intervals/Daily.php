<?php
/**
 * File to handle the daily interval.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Plugin\Intervals;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Plugin\Interval_Base;

/**
 * Object to handle the daily interval.
 */
class Daily extends Interval_Base {

	/**
	 * Name of the method.
	 *
	 * @var string
	 */
	protected string $name = 'daily';

	/**
	 * Time of the interval.
	 *
	 * @var int
	 */
	protected int $time = DAY_IN_SECONDS;

	/**
	 * Instance of this object.
	 *
	 * @var ?Daily
	 */
	private static ?Daily $instance = null;

	/**
	 * Return the instance of this Singleton object.
	 */
	public static function get_instance(): Daily {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Return the title of this interval.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Once a day', 'now-pages-threadi' );
	}
}
