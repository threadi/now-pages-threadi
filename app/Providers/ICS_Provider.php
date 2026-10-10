<?php
/**
 * This file contains the iCalendar provider.
 *
 * Reads any ICS feed - Google Calendar, Nextcloud, iCloud, Outlook, or a
 * conference schedule from pretalx or Sessionize.
 *
 * Calendar entries differ from every other provider: they are states over
 * a period, not points in a stream. An event that runs for three days
 * therefore produces one entry per day it is running, so it stays visible
 * on the now page for its whole duration. The date is part of the
 * external ID, which keeps the import idempotent no matter how often the
 * schedule runs.
 *
 * No support for recuring event.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Providers;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use NowPagesThreadi\Helper\ICS_Parser;
use NowPagesThreadi\NowPages\Entry;
use NowPagesThreadi\Plugin\Log;

/**
 * Object for the iCalendar provider.
 */
class ICS_Provider extends HTTP_Provider_Base {
	/**
	 * Return the provider slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'ics';
	}

	/**
	 * Return the provider label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Calendar (iCalendar/ICS)', 'now-pages-threadi' );
	}

	/**
	 * Return a short description shown when choosing this provider.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Reads an ICS calendar feed and shows what you are doing right now. Works with Google Calendar, Nextcloud, iCloud, Outlook and conference schedules from pretalx or Sessionize.', 'now-pages-threadi' );
	}

	/**
	 * Return the configuration fields for this provider.
	 *
	 * @return array<string,mixed>
	 */
	public function get_config_fields(): array {
		return array(
			'ics_url'            => array(
				'label'       => __( 'Calendar URL', 'now-pages-threadi' ),
				'type'        => 'url',
				'required'    => true,
				'description' => __( 'The ICS address of the calendar. webcal:// addresses work too. Careful with the "secret address" of a private Google calendar - anyone with database access can read it here.', 'now-pages-threadi' ),
				'placeholder' => 'https://example.com/calendar.ics',
			),
			'ics_include'        => array(
				'label'       => __( 'Only appointments containing', 'now-pages-threadi' ),
				'type'        => 'text',
				'required'    => false,
				'description' => __( 'Optional. Only appointments whose title contains this text are imported. Useful for shared calendars - for example "Europe" on the WordCamp Central feed.', 'now-pages-threadi' ),
				'placeholder' => __( 'WordCamp Europe', 'now-pages-threadi' ),
			),
			'ics_lookahead_days' => array(
				'label'       => __( 'Announce days ahead', 'now-pages-threadi' ),
				'type'        => 'number',
				'default'     => 0,
				'min'         => 0,
				'max'         => 30,
				'step'        => 1,
				'description' => __( 'Zero shows only what is running right now. A higher value also announces upcoming appointments, once each.', 'now-pages-threadi' ),
			),
			'ics_max_items'      => array(
				'label'   => __( 'Max. items per run', 'now-pages-threadi' ),
				'type'    => 'number',
				'default' => 10,
				'min'     => 1,
				'max'     => 50,
				'step'    => 1,
			),
		);
	}

	/**
	 * Fetch the calendar and turn the relevant occurrences into entries.
	 *
	 * @param array<string,mixed> $config  The configuration of the source.
	 * @param Provider_Context    $context The context of this run.
	 *
	 * @return array<int,Entry>
	 */
	public function fetch( array $config, Provider_Context $context ): array {
		$entries = array();
		$url     = trim( (string) ( $config['ics_url'] ?? '' ) );

		// bail if no calendar is configured.
		if ( '' === $url ) {
			return $entries;
		}

		// webcal is only a scheme for "subscribe to this", the transport is https.
		if ( str_starts_with( strtolower( $url ), 'webcal://' ) ) {
			$url = 'https://' . substr( $url, 9 );
		}

		$response = $this->request( $url, array( 'Accept' => 'text/calendar' ), $context );

		// bail if the request failed.
		if ( null === $response ) {
			return $entries;
		}

		$body = wp_remote_retrieve_body( $response );

		// bail if the calendar is empty.
		if ( '' === $body ) {
			return $entries;
		}

		$max_items = isset( $config['ics_max_items'] ) ? absint( $config['ics_max_items'] ) : 10;
		$lookahead = isset( $config['ics_lookahead_days'] ) ? absint( $config['ics_lookahead_days'] ) : 0;

		try {
			$now  = new DateTimeImmutable( 'now', wp_timezone() );
			$from = $now->modify( '-1 day' );
			$to   = $now->modify( '+' . max( 1, $lookahead ) . ' days' );
		} catch ( \Exception $e ) {
			// log this error.
			Log::get_instance()->add( __( 'Error during formatting of a timestamp from an ICS.', 'now-pages-threadi' ) . ' <code>' . esc_html( $e->getMessage() ) . '</code>', 'error', 'provider' );

			// do nothing more.
			return $entries;
		}

		foreach ( ICS_Parser::parse( $body ) as $event ) {
			// skip appointments the organizer canceled.
			if ( 'CANCELLED' === $event['status'] ) {
				continue;
			}

			// skip appointments without a title, there would be nothing to show.
			if ( '' === $event['summary'] ) {
				continue;
			}

			// skip appointments which do not match the include filter.
			$include = ! empty( $config['ics_include'] ) ? trim( $config['ics_include'] ) : '';
			if ( '' !== $include && ! str_contains( mb_strtolower( $event['summary'] ), mb_strtolower( $include ) ) ) {
				continue;
			}

			foreach ( ICS_Parser::get_occurrences( $event, $from, $to ) as $occurrence ) {
				$entry = $this->get_entry_from_occurrence( $event, $occurrence, $now, $lookahead );

				// skip occurrences outside what we want to show.
				if ( ! $entry instanceof Entry ) {
					continue;
				}

				// add entry to the list.
				$entries[] = $entry;

				// stop once we collected enough.
				if ( count( $entries ) >= $max_items ) {
					return $entries;
				}
			}
		}

		// return the entries.
		return $entries;
	}

	/**
	 * Turn a single occurrence into an entry.
	 *
	 * @param array<string,mixed>                                  $event      The event.
	 * @param array{start:DateTimeImmutable,end:DateTimeImmutable} $occurrence The occurrence.
	 * @param DateTimeImmutable                                    $now        The current time.
	 * @param int                                                  $lookahead  How many days ahead to announce.
	 *
	 * @return Entry|null Null if this occurrence should not produce an entry.
	 */
	private function get_entry_from_occurrence( array $event, array $occurrence, DateTimeImmutable $now, int $lookahead ): ?Entry {
		$is_running  = $occurrence['start'] <= $now && $occurrence['end'] >= $now;
		$is_upcoming = $occurrence['start'] > $now;

		// an appointment that already ended is none of our business, the days
		// it was running produced their own entries while it ran.
		if ( ! $is_running && ! $is_upcoming ) {
			return null;
		}

		// only announce upcoming appointments if the source asked for it.
		if ( $is_upcoming && 0 === $lookahead ) {
			return null;
		}

		$entry              = new Entry();
		$entry->source_slug = $this->get_slug();
		$entry->language    = null;

		if ( $is_running ) {
			// a running appointment gets one entry per day, dated to the start
			// of that day - or to its own start, if it began today.
			$day_start = $now->setTime( 0, 0 );

			$entry->date        = $occurrence['start'] > $day_start ? $occurrence['start'] : $day_start;
			$entry->text        = $this->limit_text_length( $this->get_running_text( $event ) );
			$entry->external_id = 'ics-' . md5( (string) $event['uid'] . '|' . $occurrence['start']->format( 'c' ) . '|' . $now->format( 'Y-m-d' ) );

			return $entry;
		}

		// an upcoming appointment is announced once, dated to now so it falls
		// inside the digest window.
		$entry->date        = $now;
		$entry->text        = $this->limit_text_length( $this->get_upcoming_text( $event, $occurrence['start'] ) );
		$entry->external_id = 'ics-announce-' . md5( (string) $event['uid'] . '|' . $occurrence['start']->format( 'c' ) );

		return $entry;
	}

	/**
	 * Return the text for a currently running appointment.
	 *
	 * @param array<string,mixed> $event The event.
	 *
	 * @return string
	 */
	private function get_running_text( array $event ): string {
		if ( '' !== $event['location'] ) {
			return sprintf(
				/* translators: %1$s will be replaced by an appointment title, %2$s by a location. */
				__( 'Currently at %1$s, %2$s', 'now-pages-threadi' ),
				$event['summary'],
				$event['location']
			);
		}

		return sprintf(
			/* translators: %1$s will be replaced by an appointment title. */
			__( 'Currently at %1$s', 'now-pages-threadi' ),
			$event['summary']
		);
	}

	/**
	 * Return the text for an upcoming appointment.
	 *
	 * @param array<string,mixed> $event The event.
	 * @param DateTimeImmutable   $start When the appointment starts.
	 *
	 * @return string
	 */
	private function get_upcoming_text( array $event, DateTimeImmutable $start ): string {
		$date = wp_date( (string) get_option( 'date_format' ), $start->getTimestamp() );

		if ( '' !== $event['location'] ) {
			return sprintf(
				/* translators: %1$s will be replaced by an appointment title, %2$s by a location, %3$s by a date. */
				__( 'Will be at %1$s, %2$s, on %3$s', 'now-pages-threadi' ),
				$event['summary'],
				$event['location'],
				$date
			);
		}

		return sprintf(
			/* translators: %1$s will be replaced by an appointment title, %2$s by a date. */
			__( 'Will be at %1$s on %2$s', 'now-pages-threadi' ),
			$event['summary'],
			$date
		);
	}

	/**
	 * Do not use ETags for calendars.
	 *
	 * Which appointments are running depends on the current time, not on a
	 * change of the calendar file. An unchanged calendar must therefore still
	 * produce its entries, but a 304 answer would leave nothing to parse.
	 *
	 * @param string $url The requested URL.
	 *
	 * @return string
	 */
	protected function get_etag_key( string $url ): string {
		return '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_identifying_fields(): array {
		return array( 'ics_url', 'ics_include' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_test_url( array $config ): string {
		$url = '';

		if ( ! empty( $config['ics_url'] ) ) {
			$url = esc_url_raw( (string) $config['ics_url'] );
		}

		return $url;
	}
}
