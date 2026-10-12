<?php
/**
 * This file contains the day period.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Digests\Periods;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Digests\Period_Base;

/**
 * Object for the day period.
 */
class Day extends Period_Base {
	/**
	 * The slug of this period.
	 *
	 * @var string
	 */
	protected string $slug = 'day';

	/**
	 * Return the period label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Day', 'threadi-now-pages' );
	}

	/**
	 * Return a short description of what this period covers.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'One full day, by default today.', 'threadi-now-pages' );
	}

	/**
	 * Return the raw window this period covers.
	 *
	 * @param array<string,mixed> $args The resolved arguments.
	 *
	 * @return array{after:string,before:string}
	 */
	public function get_range( array $args ): array {
		$date = gmdate( 'Y-m-d', $this->get_timestamp( $args ) );

		return array(
			'after'  => $date . ' 00:00:00',
			'before' => $date . ' 23:59:59',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_note( array $args, array $range ): string {
		// name the day unless it is today.
		if ( gmdate( 'Y-m-d', $this->get_timestamp( $args ) ) === current_time( 'Y-m-d' ) ) {
			return __( 'From today', 'threadi-now-pages' );
		}

		return sprintf(
		/* translators: %1$s will be replaced by a date. */
			__( 'From %1$s', 'threadi-now-pages' ),
			mysql2date( (string) get_option( 'date_format' ), $range['after'] )
		);
	}
}
