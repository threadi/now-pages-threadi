<?php
/**
 * This file contains the week period.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Digests\Periods;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Digests\Period_Base;

/**
 * Object for the week period.
 */
class Week extends Period_Base {
	/**
	 * The slug of this period.
	 *
	 * @var string
	 */
	protected string $slug = 'week';

	/**
	 * Return the period label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Week', 'threadi-now-pages' );
	}

	/**
	 * Return a short description of what this period covers.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'From monday to sunday of the week a date falls into.', 'threadi-now-pages' );
	}

	/**
	 * Return the raw window this period covers.
	 *
	 * @param array<string,mixed> $args The resolved arguments.
	 *
	 * @return array{after:string,before:string}
	 */
	public function get_range( array $args ): array {
		$timestamp = $this->get_timestamp( $args );

		// 1 = monday … 7 = sunday.
		$weekday = (int) gmdate( 'N', $timestamp );

		$monday = $timestamp - ( ( $weekday - 1 ) * DAY_IN_SECONDS );
		$sunday = $monday + ( 6 * DAY_IN_SECONDS );

		return array(
			'after'  => gmdate( 'Y-m-d', $monday ) . ' 00:00:00',
			'before' => gmdate( 'Y-m-d', $sunday ) . ' 23:59:59',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_note( array $args, array $range ): string {
		// name the week unless it is today.
		if ( gmdate( 'Y-m-d', $this->get_timestamp( $args ) ) === current_time( 'Y-m-d' ) ) {
			return __( 'From this week', 'threadi-now-pages' );
		}

		return sprintf(
		/* translators: %1$s will be replaced by a date. */
			__( 'From %1$s', 'threadi-now-pages' ),
			mysql2date( (string) get_option( 'date_format' ), $range['after'] )
		);
	}
}
