<?php
/**
 * This file contains the hour period.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Digests\Periods;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Digests\Period_Base;

/**
 * Object for the hour period.
 */
class Hour extends Period_Base {
	/**
	 * The slug of this period.
	 *
	 * @var string
	 */
	protected string $slug = 'hour';

	/**
	 * Return the period label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Hour', 'now-pages-threadi' );
	}

	/**
	 * Return a short description of what this period covers.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'One full hour of a day, by default the current one.', 'now-pages-threadi' );
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
		$hour = (int) current_time( 'G' );

		if ( isset( $args['hour'] ) ) {
			$hour = max( 0, min( 23, absint( $args['hour'] ) ) );
		}

		return array(
			'after'  => sprintf( '%s %02d:00:00', $date, $hour ),
			'before' => sprintf( '%s %02d:59:59', $date, $hour ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_note( array $args, array $range ): string {
		// name the week unless it is today.
		if ( gmdate( 'Y-m-d', $this->get_timestamp( $args ) ) === current_time( 'Y-m-d' ) ) {
			return __( 'From this hour', 'now-pages-threadi' );
		}

		return sprintf(
		/* translators: %1$s will be replaced by a date. */
			__( 'From %1$s', 'now-pages-threadi' ),
			mysql2date( (string) get_option( 'date_format' ), $range['after'] )
		);
	}
}
