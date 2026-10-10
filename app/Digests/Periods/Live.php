<?php
/**
 * This file contains the live period.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Digests\Periods;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Digests\Display_Settings;
use NowPagesThreadi\Digests\Period_Base;

/**
 * Object for the live period.
 */
class Live extends Period_Base {
	/**
	 * The slug of this period.
	 *
	 * @var string
	 */
	protected string $slug = 'live';

	/**
	 * Return the period label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Live', 'now-pages-threadi' );
	}

	/**
	 * Return a short description of what this period covers.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'The last 24 hours, counted backwards from now.', 'now-pages-threadi' );
	}

	/**
	 * Return the raw window this period covers.
	 *
	 * @param array<string,mixed> $args The resolved arguments.
	 *
	 * @return array{after:string,before:string}
	 */
	public function get_range( array $args ): array {
		$look_back = 24;

		if ( isset( $args['lookback_hours'] ) ) {
			$look_back = max( 1, Display_Settings::limit_hours( $args['lookback_hours'] ) );
		}

		$now = (int) strtotime( current_time( 'mysql' ) );

		return array(
			'after'  => gmdate( 'Y-m-d H:i:s', $now - ( $look_back * HOUR_IN_SECONDS ) ),
			'before' => gmdate( 'Y-m-d H:i:s', $now ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_note( array $args, array $range ): string {
		$look_back = 24;

		if ( isset( $args['lookback_hours'] ) ) {
			$look_back = max( 1, absint( $args['lookback_hours'] ) );
		}

		return sprintf(
			/* translators: %1$d will be replaced by a number of hours. */
			_n( 'From the last %1$d hour', 'From the last %1$d hours', $look_back, 'now-pages-threadi' ),
			$look_back
		);
	}
}
