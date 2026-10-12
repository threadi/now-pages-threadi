<?php
/**
 * This file contains the registry for all periods.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Digests;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Helper\Instance_Registry;
use ThreadiNowPages\Helper\Singleton_Interface;
use ThreadiNowPages\Helper\Singleton_Trait;

/**
 * Object which holds all known periods.
 */
class Periods implements Singleton_Interface {
	use Singleton_Trait;

	/**
	 * The period objects of this request.
	 *
	 * @var array<int,Period_Interface>
	 */
	private array $objects = array();

	/**
	 * Constructor, not used as this a Singleton object.
	 */
	private function __construct() {}

	/**
	 * Return the list of our periods.
	 *
	 * @return array<int,class-string<Period_Base>>
	 */
	private function get_period_classes(): array {
		$list = array(
			Periods\Live::class,
			Periods\Hour::class,
			Periods\Day::class,
			Periods\Week::class,
			Periods\Month::class,
			Periods\Months2::class,
			Periods\Months6::class,
			Periods\Range::class,
		);

		/**
		 * Filter the list of possible periods.
		 *
		 * Use this to add your own period to the ones a digest can cover.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<int,class-string<Period_Base>> $list List of our period objects.
		 */
		return apply_filters( 'threadi_now_pages_periods', $list );
	}

	/**
	 * Return the list of periods as objects.
	 *
	 * @return array<int,Period_Interface>
	 */
	public function get_periods(): array {
		if ( empty( $this->objects ) ) {
			$this->objects = Instance_Registry::get_instance()->get_new_instances_of( $this->get_period_classes(), Period_Base::class );
		}

		return $this->objects;
	}

	/**
	 * Return a single period by its slug.
	 *
	 * @param string $slug The slug of the period.
	 *
	 * @return Period_Interface|null
	 */
	public function get_period( string $slug ): ?Period_Interface {
		foreach ( $this->get_periods() as $period_obj ) {
			if ( $slug !== $period_obj->get_slug() ) {
				continue;
			}

			return $period_obj;
		}

		return null;
	}

	/**
	 * Return the periods for a list of options.
	 *
	 * @param bool $only_selectable True to leave out the periods needing
	 *                              values the settings do not ask for.
	 *
	 * @return array<string,string> Map of slug => label.
	 */
	public function get_periods_for_settings( bool $only_selectable = true ): array {
		$list = array();

		foreach ( $this->get_periods() as $period_obj ) {
			if ( $only_selectable && ! $period_obj->is_selectable() ) {
				continue;
			}

			$list[ $period_obj->get_slug() ] = $period_obj->get_label();
		}

		return $list;
	}

	/**
	 * Return a period by its slug, or the fallback if it is unknown.
	 *
	 * @param string $slug The slug of the period.
	 *
	 * @return Period_Interface
	 */
	public function get_period_or_fallback( string $slug ): Period_Interface {
		$period_obj = $this->get_period( $slug );

		if ( $period_obj instanceof Period_Interface ) {
			return $period_obj;
		}

		$period_obj = $this->get_period( 'day' );

		if ( $period_obj instanceof Period_Interface ) {
			return $period_obj;
		}

		// nothing is registered at all, so deliver one that always works.
		return new Periods\Day();
	}

	/**
	 * Return the slug of the smallest period with entries.
	 *
	 * @return string
	 */
	public function get_smallest_period_with_entries(): string {
		foreach ( $this->get_periods() as $period ) {
			if ( $period->has_entries() ) {
				return $period->get_slug();
			}
		}
		return 'live';
	}
}
