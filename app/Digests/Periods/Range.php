<?php
/**
 * This file contains the period for a freely chosen window.
 *
 * Meant for a shortcode or a widget inside a post: "this is what I did
 * in March". The window is fixed, so the same post shows the same
 * entries in two years - as long as they still exist.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Digests\Periods;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Digests\Period_Base;

/**
 * Object for a freely chosen period.
 */
class Range extends Period_Base {
	/**
	 * The slug of this period.
	 *
	 * @var string
	 */
	protected string $slug = 'range';

	/**
	 * Return the period label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Custom range', 'now-pages-threadi' );
	}

	/**
	 * Return a short description of what this period covers.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'A window you define yourself, for example a single month you write about in a post.', 'now-pages-threadi' );
	}

	/**
	 * This period needs a start and an end, which the settings do not ask
	 * for - it would silently behave like "day" there.
	 *
	 * @return bool
	 */
	public function is_selectable(): bool {
		return false;
	}

	/**
	 * Return the raw window this period covers.
	 *
	 * @param array<string,mixed> $args The resolved arguments.
	 *
	 * @return array{after:string,before:string}
	 */
	public function get_range( array $args ): array {
		$start = $this->get_boundary( $args, 'start', '00:00:00' );
		$end   = $this->get_boundary( $args, 'end', '23:59:59' );

		// bail if neither bound is usable: an empty digest is clearer than
		// silently showing a different period.
		if ( '' === $start && '' === $end ) {
			return $this->get_empty_range();
		}

		// only a start means "from then until now".
		if ( '' === $end ) {
			$end = current_time( 'mysql' );
		}

		// only an end means "from the beginning until then".
		if ( '' === $start ) {
			$start = '1970-01-01 00:00:00';
		}

		// a reversed window is a typo, not an empty period - swap it.
		if ( $start > $end ) {
			return array(
				'after'  => $end,
				'before' => $start,
			);
		}

		return array(
			'after'  => $start,
			'before' => $end,
		);
	}

	/**
	 * Return one bound of the window as a date.
	 *
	 * Accepts everything strtotime() understands, so "2026-03-01" works as
	 * well as "march 2026" or "-2 weeks". The value is read in the timezone
	 * of the website.
	 *
	 * @param array<string,mixed> $args    The resolved arguments.
	 * @param string              $name    The name of the argument.
	 * @param string              $default_time The time to use if none was given.
	 *
	 * @return string Empty string if nothing usable was given.
	 */
	private function get_boundary( array $args, string $name, string $default_time ): string {
		$value = '';

		if ( ! empty( $args[ $name ] ) && is_scalar( $args[ $name ] ) ) {
			$value = trim( (string) $args[ $name ] );
		}

		// bail if nothing was given.
		if ( '' === $value ) {
			return '';
		}

		// read the value in the timezone of the website, like the dates of the entries.
		// relative values like "today" are then based on the local day, and a value
		// with its own timezone is converted to the local time.
		$date = date_create_immutable( $value, wp_timezone() );

		// bail if the value could not be understood.
		if ( false === $date ) {
			return '';
		}

		$date = $date->setTimezone( wp_timezone() );

		// a value without a time covers the whole day.
		if ( ! $this->has_time( $value ) ) {
			return $date->format( 'Y-m-d' ) . ' ' . $default_time;
		}

		return $date->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Return whether the given value carries a time of day.
	 *
	 * @param string $value The value as it was given.
	 *
	 * @return bool
	 */
	private function has_time( string $value ): bool {
		return 1 === preg_match( '/\d{1,2}:\d{2}/', $value );
	}
}
