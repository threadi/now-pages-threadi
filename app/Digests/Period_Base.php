<?php
/**
 * This file contains the base for any period.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Digests;

// prevent direct access.
use NowPagesThreadi\NowPages\Entries;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Object which holds the shared behavior of all periods.
 */
abstract class Period_Base implements Period_Interface {
	/**
	 * The slug of this period.
	 *
	 * @var string
	 */
	protected string $slug = '';

	/**
	 * Return the period slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return $this->slug;
	}

	/**
	 * Return a short description of what this period covers.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return '';
	}

	/**
	 * Return whether this period can be chosen in the settings.
	 *
	 * @return bool
	 */
	public function is_selectable(): bool {
		return true;
	}

	/**
	 * Return the timestamp the given arguments point at.
	 *
	 * Most periods are relative to a day: "the week of", "the month of".
	 * Noon is used on purpose, so a shift of a few hours by a time zone
	 * never moves the result to another day.
	 *
	 * @param array<string,mixed> $args The resolved arguments.
	 *
	 * @return int
	 */
	protected function get_timestamp( array $args ): int {
		$date = current_time( 'Y-m-d' );

		if ( ! empty( $args['date'] ) ) {
			$date = (string) $args['date'];
		}

		$timestamp = strtotime( $date . ' 12:00:00' );

		// fall back to today if the given date is unusable.
		if ( false === $timestamp ) {
			$timestamp = (int) strtotime( current_time( 'Y-m-d' ) . ' 12:00:00' );
		}

		return (int) $timestamp;
	}

	/**
	 * Return an empty window.
	 *
	 * Used by periods which cannot be resolved with the given arguments.
	 * Both bounds are equal, so the query finds nothing.
	 *
	 * @return array{after:string,before:string}
	 */
	protected function get_empty_range(): array {
		$now = current_time( 'mysql' );

		return array(
			'after'  => $now,
			'before' => $now,
		);
	}

	/**
	 * Return the note telling a visitor which period is shown.
	 *
	 * Different from get_description(): that one explains the period in the
	 * settings, this one names the window actually being shown.
	 *
	 * @param array<string,mixed>               $args  The resolved arguments.
	 * @param array{after:string,before:string} $range The resolved window.
	 *
	 * @return string
	 */
	public function get_note( array $args, array $range ): string {
		$format = (string) get_option( 'date_format' );

		return sprintf(
		/* translators: %1$s will be replaced by a date, %2$s by a date. */
			__( 'Entries from %1$s to %2$s', 'now-pages-threadi' ),
			mysql2date( $format, $range['after'] ),
			mysql2date( $format, $range['before'] )
		);
	}

	/**
	 * Return whether this period has entries.
	 *
	 * @return bool
	 */
	public function has_entries(): bool {
		$range = $this->get_range( array() );

		$query = new WP_Query(
			array(
				'post_type'      => Entries::get_instance()->get_slug(),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'date_query'     => array(
					array(
						'after'     => $range['after'],
						'before'    => $range['before'],
						'inclusive' => true,
					),
				),
			)
		);

		return $query->have_posts();
	}
}
