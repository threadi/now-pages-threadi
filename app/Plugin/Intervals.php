<?php
/**
 * File with a handler for our own intervals.
 *
 * Hint: we use our own intervals to prevent intervals of other plugins from being used.
 * This would be an unnecessary dependency that leads to missing execution of our own schedules.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Helper\Instance_Registry;

/**
 * Object for our own intervals.
 */
class Intervals {
	/**
	 * The pseudo interval used to disable a schedule. It is not a registered cron interval.
	 */
	public const DISABLED = 'threadi_now_pages_disable_check';

	/**
	 * The interval objects of this request.
	 *
	 * @var array<int,Interval_Base>
	 */
	private array $objects = array();

	/**
	 * Instance of this object.
	 *
	 * @var ?Intervals
	 */
	private static ?Intervals $instance = null;

	/**
	 * Constructor for this object.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning of this object.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Return the instance of this Singleton object.
	 */
	public static function get_instance(): Intervals {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize all schedules of this plugin.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'cron_schedules', array( $this, 'add_intervals' ) );
	}

	/**
	 * Return the list of our own intervals.
	 *
	 * @return array<int,class-string<Interval_Base>>
	 */
	private function get_intervals(): array {
		// add the list.
		$list = array(
			'\ThreadiNowPages\Plugin\Intervals\Minutly15',
			'\ThreadiNowPages\Plugin\Intervals\Minutly20',
			'\ThreadiNowPages\Plugin\Intervals\Minutly30',
			'\ThreadiNowPages\Plugin\Intervals\Hourly',
			'\ThreadiNowPages\Plugin\Intervals\Hourly2',
			'\ThreadiNowPages\Plugin\Intervals\Hourly3',
			'\ThreadiNowPages\Plugin\Intervals\Hourly4',
			'\ThreadiNowPages\Plugin\Intervals\Hourly6',
			'\ThreadiNowPages\Plugin\Intervals\Hourly12',
			'\ThreadiNowPages\Plugin\Intervals\Daily',
			'\ThreadiNowPages\Plugin\Intervals\Weekly',
		);

		/**
		 * Filter the list of possible intervals.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param array<int,class-string<Interval_Base>> $list List of our interval objects.
		 */
		return apply_filters( 'threadi_now_pages_intervals', $list );
	}

	/**
	 * Return the list of available intervals as objects.
	 *
	 * @return array<int,Interval_Base>
	 */
	public function get_intervals_as_objects(): array {
		if ( empty( $this->objects ) ) {
			$this->objects = Instance_Registry::get_instance()->get_new_instances_of( $this->get_intervals(), Interval_Base::class );
		}

		return $this->objects;
	}

	/**
	 * Return the list of intervals for settings.
	 *
	 * Sorted by its time.
	 *
	 * @param bool $without_disabled True to disable the first entry.
	 *
	 * @return array<string,string>
	 */
	public function get_intervals_for_settings( bool $without_disabled = false ): array {
		// define the list for the entries.
		$list = array();

		// add entry to disable the setting as first.
		if ( ! $without_disabled ) {
			$list[ self::DISABLED ] = __( 'Disable import', 'threadi-now-pages' );
		}

		// add each interval objects as entry.
		foreach ( $this->get_intervals_as_objects() as $obj ) {
			$list[ $this->get_prefix() . $obj->get_name() ] = $obj->get_title();
		}

		// return the resulting list.
		return $list;
	}

	/**
	 * Add our own intervals to the WordPress list of intervals.
	 *
	 * @param array<string,array<string,mixed>> $intervals List of intervals.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function add_intervals( array $intervals ): array {
		// loop through our own intervals and add them to the list.
		foreach ( $this->get_intervals_as_objects() as $interval_obj ) {
			$title = $interval_obj->get_name();

			// resolve the label only once translations are available: this
			// filter can fire during plugins_loaded, long before init.
			if ( did_action( 'init' ) > 0 ) {
				$title = $interval_obj->get_title();
			}

			$intervals[ $this->get_prefix() . $interval_obj->get_name() ] = array(
				'interval' => $interval_obj->get_time(),
				'display'  => $title,
			);
		}

		// return the resulting list of intervals.
		return $intervals;
	}

	/**
	 * Return the prefix we use for each our own interval names.
	 *
	 * @return string
	 */
	public function get_prefix(): string {
		return 'threadi_now_pages_';
	}
}
