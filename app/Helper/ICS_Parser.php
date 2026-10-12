<?php
/**
 * This file contains a minimal parser for iCalendar data.
 *
 * Deliberately small: it covers what calendars actually export for
 * everyday appointments, not the full RFC 5545. Supported recurrence
 * rules are FREQ with INTERVAL, COUNT, UNTIL and BYDAY (weekdays for
 * daily and weekly events, weekdays with an optional position like 2TU
 * for monthly events), plus EXDATE and moved single instances
 * (RECURRENCE-ID). Anything beyond that is ignored.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Helper;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use ThreadiNowPages\Plugin\Log;

/**
 * Object to parse iCalendar data.
 */
class ICS_Parser {
	/**
	 * Maximum number of occurrences returned for one event.
	 *
	 * Protects against crafted recurrence rules which would otherwise
	 * generate millions of occurrences.
	 */
	private const MAX_OCCURRENCES = 500;

	/**
	 * Maximum number of BYDAY values used from one rule.
	 */
	private const MAX_BYDAY = 31;

	/**
	 * The BYDAY codes with their ISO-8601 numbers.
	 */
	private const WEEKDAYS = array(
		'MO' => 1,
		'TU' => 2,
		'WE' => 3,
		'TH' => 4,
		'FR' => 5,
		'SA' => 6,
		'SU' => 7,
	);

	/**
	 * Maximum number of events parsed from one calendar.
	 *
	 * Protects against huge calendars: 5 MB of minimal events would otherwise
	 * produce more than 100,000 events and exhaust the memory of the import.
	 */
	public const MAX_EVENTS = 5000;

	/**
	 * Parse iCalendar data into a list of events.
	 *
	 * @param string $ics The raw iCalendar data.
	 *
	 * @return array<int,array<string,mixed>> One entry per VEVENT.
	 */
	public static function parse( string $ics ): array {
		$events = array();

		// normalize the line endings first, then unfold, see RFC 5545 section 3.1.
		$content = str_replace( array( "\r\n", "\r" ), array( "\n", "\n" ), $ics );
		$content = (string) preg_replace( "/\n[ \t]/", '', $content );

		// collect every VEVENT block. Uses strpos instead of a regular expression,
		// which could hit the PCRE backtrack limit on large calendars and then
		// silently return no events at all.
		$offset = 0;
		while ( false !== ( $begin = strpos( $content, 'BEGIN:VEVENT', $offset ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			// stop on the hard limit of events per calendar.
			if ( count( $events ) >= self::MAX_EVENTS ) {
				// log this event.
				/* translators: %1$d will be replaced by a number of events. */
				Log::get_instance()->add( sprintf( __( 'The calendar contains more than %1$d events, the remaining events have been ignored.', 'threadi-now-pages' ), self::MAX_EVENTS ), 'error', 'provider' );
				break;
			}

			$begin += strlen( 'BEGIN:VEVENT' );
			$end    = strpos( $content, 'END:VEVENT', $begin );

			// bail if the block is not closed.
			if ( false === $end ) {
				break;
			}

			$event = self::parse_event( substr( $content, $begin, $end - $begin ) );

			if ( ! empty( $event ) ) {
				$events[] = $event;
			}

			$offset = $end + strlen( 'END:VEVENT' );
		}

		return self::apply_moved_instances( $events );
	}

	/**
	 * Remove moved single instances from the series they belong to.
	 *
	 * A moved instance is an event of its own with the UID of the series and
	 * a RECURRENCE-ID: the original date. Without this, it would appear
	 * twice: once at its new and once at its original date.
	 *
	 * @param array<int,array<string,mixed>> $events The parsed events.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function apply_moved_instances( array $events ): array {
		$moved = array();

		foreach ( $events as $event ) {
			if ( '' !== $event['recurrence_id'] && '' !== $event['uid'] ) {
				$moved[ $event['uid'] ][] = $event['recurrence_id'];
			}
		}

		// bail if no instance has been moved.
		if ( empty( $moved ) ) {
			return $events;
		}

		foreach ( $events as $index => $event ) {
			// only the series itself loses its original instances.
			if ( '' !== $event['recurrence_id'] || ! isset( $moved[ $event['uid'] ] ) ) {
				continue;
			}

			$events[ $index ]['exdates'] = array_merge( $event['exdates'], $moved[ $event['uid'] ] );
		}

		return $events;
	}

	/**
	 * Return every occurrence of an event within the given window.
	 *
	 * @param array<string,mixed> $event The event, see parse().
	 * @param DateTimeImmutable   $from  Start of the window.
	 * @param DateTimeImmutable   $to    End of the window.
	 *
	 * @return array<int,array{start:DateTimeImmutable,end:DateTimeImmutable}>
	 */
	public static function get_occurrences( array $event, DateTimeImmutable $from, DateTimeImmutable $to ): array {
		$occurrences = array();

		if ( ! $event['start'] instanceof DateTimeImmutable || ! $event['end'] instanceof DateTimeImmutable ) {
			return $occurrences;
		}

		$duration = $event['start']->diff( $event['end'] );
		$rule     = is_array( $event['rrule'] ) ? $event['rrule'] : array();

		// a single occurrence, the common case.
		if ( empty( $rule['FREQ'] ) ) {
			if ( $event['end'] >= $from && $event['start'] <= $to ) {
				$occurrences[] = array(
					'start' => $event['start'],
					'end'   => $event['end'],
				);
			}

			return $occurrences;
		}

		$step = self::get_step( (string) $rule['FREQ'], absint( $rule['INTERVAL'] ?? 1 ) );

		// bail on a frequency we do not understand.
		if ( ! $step instanceof DateInterval ) {
			return $occurrences;
		}

		$until = isset( $rule['UNTIL'] ) ? self::parse_date( (string) $rule['UNTIL'], '' ) : null;
		$count = isset( $rule['COUNT'] ) ? absint( $rule['COUNT'] ) : 0;
		$days  = isset( $rule['BYDAY'] ) ? self::get_unique_days( (string) $rule['BYDAY'] ) : array();

		$interval  = max( 1, absint( $rule['INTERVAL'] ?? 1 ) );
		$freq      = strtoupper( (string) $rule['FREQ'] );
		$current   = $event['start'];
		$generated = 0;

		// a monthly step with weekdays covers its whole month: start on the first day,
		// so adding a month never skips a short one.
		if ( 'MONTHLY' === $freq && ! empty( $days ) ) {
			$current = $current->setDate( (int) $current->format( 'Y' ), (int) $current->format( 'n' ), 1 );
		}

		// skip the steps before the window instead of walking them.
		$skipped = self::get_steps_before( $current, $rule, $from );

		// with BYDAY, a weekly or monthly step holds a varying number of instances
		// (short first step, 4 or 5 weekdays per month): walk them when COUNT matters.
		if ( $count > 0 && ! empty( $days ) && 'DAILY' !== $freq ) {
			$skipped = 0;
		}

		if ( $skipped > 0 ) {
			$bulk = self::get_step( (string) $rule['FREQ'], $interval * $skipped );

			if ( $bulk instanceof DateInterval ) {
				$current = $current->add( $bulk );

				// the skipped instances still count towards COUNT. on a daily rule,
				// BYDAY only filters the days instead of adding instances.
				$generated += 'DAILY' === $freq && ! empty( $days )
					? self::count_matching_days( $event['start'], $interval, $skipped, $days )
					: $skipped * max( 1, count( $days ) );
			}
		}

		// walk forward until we leave the window, with a hard stop against runaway rules.
		for ( $i = 0; $i < 2000; $i++ ) {
			if ( $until instanceof DateTimeImmutable && $current > $until ) {
				break;
			}

			if ( $count > 0 && $generated >= $count ) {
				break;
			}

			if ( $current > $to ) {
				break;
			}

			foreach ( self::expand_step( $freq, $current, $days ) as $start ) {
				// skip instances before the start of the series or after its end.
				if ( $start < $event['start'] || ( $until instanceof DateTimeImmutable && $start > $until ) ) {
					continue;
				}

				// stop as soon as COUNT is reached, also within one step.
				if ( $count > 0 && $generated >= $count ) {
					break 2;
				}

				$end = $start->add( $duration );

				++$generated;

				// skip instances the calendar cancelled or moved.
				if ( in_array( self::get_instance_key( $start ), $event['exdates'], true ) ) {
					continue;
				}

				if ( $end >= $from && $start <= $to ) {
					$occurrences[] = array(
						'start' => $start,
						'end'   => $end,
					);

					// stop on the hard limit of occurrences per event.
					if ( count( $occurrences ) >= self::MAX_OCCURRENCES ) {
						return $occurrences;
					}
				}
			}

			$current = $current->add( $step );
		}

		return $occurrences;
	}

	/**
	 * Return the unique BYDAY values of a rule, limited to a sane amount.
	 *
	 * @param string $byday The raw BYDAY value, e.g. "MO,WE,FR".
	 *
	 * @return array<int,string>
	 */
	private static function get_unique_days( string $byday ): array {
		$days = array();
		foreach ( explode( ',', $byday ) as $day ) {
			$day = strtoupper( trim( $day ) );
			if ( '' === $day ) {
				continue;
			}
			$days[ $day ] = $day;
		}

		return array_slice( array_values( $days ), 0, self::MAX_BYDAY );
	}

	/**
	 * Parse a single VEVENT block.
	 *
	 * @param string $block The content between BEGIN and END.
	 *
	 * @return array<string,mixed> Empty array if the block is unusable.
	 */
	private static function parse_event( string $block ): array {
		$event = array(
			'uid'           => '',
			'summary'       => '',
			'location'      => '',
			'status'        => '',
			'start'         => null,
			'end'           => null,
			'all_day'       => false,
			'rrule'         => array(),
			'exdates'       => array(),
			'recurrence_id' => '',
		);

		// get the lines from the block.
		$lines = preg_split( '/\r\n|\n/', $block );
		if ( ! is_array( $lines ) ) {
			return $event;
		}

		foreach ( $lines as $line ) {
			$line = trim( $line );

			// bail on lines without a property.
			if ( ! str_contains( $line, ':' ) ) {
				continue;
			}

			list( $head, $value ) = explode( ':', $line, 2 );

			$parts    = explode( ';', $head );
			$property = strtoupper( (string) array_shift( $parts ) );
			$params   = self::parse_params( $parts );

			switch ( $property ) {
				case 'UID':
					$event['uid'] = $value;
					break;

				case 'SUMMARY':
					$event['summary'] = self::unescape( $value );
					break;

				case 'LOCATION':
					$event['location'] = self::unescape( $value );
					break;

				case 'STATUS':
					$event['status'] = strtoupper( $value );
					break;

				case 'DTSTART':
					$event['start']   = self::parse_date( $value, (string) ( $params['TZID'] ?? '' ) );
					$event['all_day'] = 'DATE' === ( $params['VALUE'] ?? '' );
					break;

				case 'DTEND':
					$event['end'] = self::parse_date( $value, (string) ( $params['TZID'] ?? '' ) );
					break;

				case 'RRULE':
					$event['rrule'] = self::parse_params( explode( ';', $value ) );
					break;

				case 'EXDATE':
					foreach ( explode( ',', $value ) as $exdate ) {
						$parsed = self::parse_date( $exdate, (string) ( $params['TZID'] ?? '' ) );

						if ( $parsed instanceof DateTimeImmutable ) {
							$event['exdates'][] = self::get_instance_key( $parsed );
						}
					}
					break;

				case 'RECURRENCE-ID':
					$parsed = self::parse_date( $value, (string) ( $params['TZID'] ?? '' ) );

					if ( $parsed instanceof DateTimeImmutable ) {
						$event['recurrence_id'] = self::get_instance_key( $parsed );
					}
					break;
			}
		}

		// bail if the event has no usable start.
		if ( ! $event['start'] instanceof DateTimeImmutable ) {
			return array();
		}

		// an event without a usable end lasts until the end of its start day.
		// some calendars set DTEND to the start date on single-day events,
		// which would otherwise be a zero-length appointment that never runs.
		if ( ! $event['end'] instanceof DateTimeImmutable || $event['end'] <= $event['start'] ) {
			$event['end'] = $event['all_day']
				? $event['start']->modify( '+1 day' )
				: $event['start']->modify( '+1 hour' );
		}

		return $event;
	}

	/**
	 * Turn "KEY=VALUE" segments into a map.
	 *
	 * @param array<int,string> $segments The segments.
	 *
	 * @return array<string,string>
	 */
	private static function parse_params( array $segments ): array {
		$params = array();

		foreach ( $segments as $segment ) {
			if ( ! str_contains( $segment, '=' ) ) {
				continue;
			}

			list( $key, $value ) = explode( '=', $segment, 2 );

			$params[ strtoupper( trim( $key ) ) ] = trim( $value, '"' );
		}

		return $params;
	}

	/**
	 * Parse an iCalendar date or date-time.
	 *
	 * @param string $value The raw value.
	 * @param string $tzid  The time zone from the TZID parameter, if any.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function parse_date( string $value, string $tzid ): ?DateTimeImmutable {
		$value = trim( $value );

		// bail on an empty value.
		if ( '' === $value ) {
			return null;
		}

		try {
			// a trailing Z means UTC and wins over any TZID.
			if ( str_ends_with( $value, 'Z' ) ) {
				return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
			}

			// a named time zone, if the server knows it.
			if ( '' !== $tzid ) {
				try {
					return new DateTimeImmutable( $value, new DateTimeZone( $tzid ) );
				} catch ( Exception $e ) {
					// fall through to the website time zone below.
					unset( $e );
				}
			}

			// a floating value is interpreted in the time zone of the website.
			return new DateTimeImmutable( $value, wp_timezone() );
		} catch ( Exception $e ) {
			unset( $e );

			return null;
		}
	}

	/**
	 * Return the interval one step of a frequency covers.
	 *
	 * @param string $freq     The FREQ value.
	 * @param int    $interval The INTERVAL value.
	 *
	 * @return DateInterval|null Null for a frequency we do not support.
	 */
	private static function get_step( string $freq, int $interval ): ?DateInterval {
		$interval = max( 1, $interval );

		try {
			return match ( strtoupper( $freq ) ) {
				'DAILY'   => new DateInterval( 'P' . $interval . 'D' ),
				'WEEKLY'  => new DateInterval( 'P' . $interval . 'W' ),
				'MONTHLY' => new DateInterval( 'P' . $interval . 'M' ),
				'YEARLY'  => new DateInterval( 'P' . $interval . 'Y' ),
				default   => null,
			};
		} catch ( Exception $e ) {
			unset( $e );

			return null;
		}
	}

	/**
	 * Return the key which identifies a single instance of a series.
	 *
	 * Uses UTC, so an EXDATE or RECURRENCE-ID in another time zone than
	 * DTSTART still matches.
	 *
	 * @param DateTimeImmutable $date The start of the instance.
	 *
	 * @return string
	 */
	private static function get_instance_key( DateTimeImmutable $date ): string {
		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis' );
	}

	/**
	 * Expand one step of a rule into its BYDAY instances.
	 *
	 * @param string            $freq  The FREQ value, in upper case.
	 * @param DateTimeImmutable $start The start of the current step.
	 * @param array<int,string> $days  The BYDAY values.
	 *
	 * @return array<int,DateTimeImmutable>
	 */
	private static function expand_step( string $freq, DateTimeImmutable $start, array $days ): array {
		// without BYDAY the step itself is the only occurrence.
		if ( empty( $days ) ) {
			return array( $start );
		}

		// on a daily rule, BYDAY only limits the days, e.g. to working days.
		if ( 'DAILY' === $freq ) {
			return in_array( (int) $start->format( 'N' ), self::get_weekday_numbers( $days ), true ) ? array( $start ) : array();
		}

		if ( 'MONTHLY' === $freq ) {
			return self::expand_monthdays( $start, $days );
		}

		return self::expand_weekdays( $start, $days );
	}

	/**
	 * Return the ISO-8601 numbers of the plain weekdays in BYDAY.
	 *
	 * @param array<int,string> $days The BYDAY values.
	 *
	 * @return array<int,int>
	 */
	private static function get_weekday_numbers( array $days ): array {
		$numbers = array();

		foreach ( $days as $day ) {
			if ( isset( self::WEEKDAYS[ $day ] ) ) {
				$numbers[] = self::WEEKDAYS[ $day ];
			}
		}

		return $numbers;
	}

	/**
	 * Return how many of the given steps of a daily rule fall on one of the weekdays.
	 *
	 * The weekdays repeat every seven steps, whatever the interval is.
	 *
	 * @param DateTimeImmutable $start    The start of the series.
	 * @param int               $interval The INTERVAL value.
	 * @param int               $steps    The number of steps.
	 * @param array<int,string> $days     The BYDAY values.
	 *
	 * @return int
	 */
	private static function count_matching_days( DateTimeImmutable $start, int $interval, int $steps, array $days ): int {
		$numbers   = self::get_weekday_numbers( $days );
		$first     = (int) $start->format( 'N' ) - 1;
		$per_week  = 0;
		$remainder = 0;

		for ( $k = 0; $k < 7; $k++ ) {
			$number = ( ( $first + $k * $interval ) % 7 ) + 1;

			if ( ! in_array( $number, $numbers, true ) ) {
				continue;
			}

			++$per_week;

			if ( $k < $steps % 7 ) {
				++$remainder;
			}
		}

		return intdiv( $steps, 7 ) * $per_week + $remainder;
	}

	/**
	 * Expand one step of a monthly rule into its BYDAY instances.
	 *
	 * Supports plain weekdays (every tuesday of the month) and weekdays with
	 * a position (2TU: the second tuesday, -1FR: the last friday).
	 *
	 * @param DateTimeImmutable $start The start of the current step.
	 * @param array<int,string> $days  The BYDAY values.
	 *
	 * @return array<int,DateTimeImmutable>
	 */
	private static function expand_monthdays( DateTimeImmutable $start, array $days ): array {
		$year   = (int) $start->format( 'Y' );
		$month  = (int) $start->format( 'n' );
		$length = (int) $start->format( 't' );
		$result = array();

		foreach ( $days as $day ) {
			// bail on anything which is not a weekday with an optional position.
			if ( ! preg_match( '/^([+-]?\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/', $day, $matches ) ) {
				continue;
			}

			$weekday  = self::WEEKDAYS[ $matches[2] ];
			$position = (int) $matches[1];

			// collect every date of this weekday in the month, keeping the time of the step.
			$candidates = array();
			for ( $date_day = 1; $date_day <= $length; $date_day++ ) {
				$date = $start->setDate( $year, $month, $date_day );

				if ( $weekday === (int) $date->format( 'N' ) ) {
					$candidates[] = $date;
				}
			}

			// without a position, every one of them is an instance.
			if ( 0 === $position ) {
				foreach ( $candidates as $candidate ) {
					$result[ $candidate->getTimestamp() ] = $candidate;
				}
				continue;
			}

			// a negative position counts from the end of the month.
			$index = $position > 0 ? $position - 1 : count( $candidates ) + $position;

			if ( isset( $candidates[ $index ] ) ) {
				$result[ $candidates[ $index ]->getTimestamp() ] = $candidates[ $index ];
			}
		}

		ksort( $result );

		return array_values( $result );
	}

	/**
	 * Expand one step of a weekly rule into its BYDAY instances.
	 *
	 * @param DateTimeImmutable $start The start of the current step.
	 * @param array<int,string> $days  The BYDAY values, e.g. MO, WE.
	 *
	 * @return array<int,DateTimeImmutable>
	 */
	private static function expand_weekdays( DateTimeImmutable $start, array $days ): array {
		// without BYDAY the step itself is the only occurrence.
		if ( empty( $days ) ) {
			return array( $start );
		}

		$map = array(
			'MO' => 'Monday',
			'TU' => 'Tuesday',
			'WE' => 'Wednesday',
			'TH' => 'Thursday',
			'FR' => 'Friday',
			'SA' => 'Saturday',
			'SU' => 'Sunday',
		);

		$result = array();

		// walk from the monday of this week, so the order stays stable.
		$week_start = $start->modify( 'monday this week' );

		foreach ( $days as $day ) {
			// only plain weekdays are supported, no positional prefixes like 2MO.
			$day = strtoupper( trim( $day ) );

			if ( ! isset( $map[ $day ] ) ) {
				continue;
			}

			$result[] = $week_start->modify( $map[ $day ] )->setTime(
				(int) $start->format( 'H' ),
				(int) $start->format( 'i' ),
				(int) $start->format( 's' )
			);
		}

		return $result;
	}

	/**
	 * Undo the escaping of an iCalendar text value.
	 *
	 * @param string $value The raw value.
	 *
	 * @return string
	 */
	private static function unescape( string $value ): string {
		return trim(
			str_replace(
				array( '\\n', '\\N', '\\,', '\\;', '\\\\' ),
				array( ' ', ' ', ',', ';', '\\' ),
				$value
			)
		);
	}

	/**
	 * Return how many steps of a rule lie completely before the window.
	 *
	 * Walking them one by one would hit the loop limit on long-running
	 * series, so they are skipped in one go.
	 *
	 * @param DateTimeImmutable   $start The start of the series.
	 * @param array<string,mixed> $rule  The parsed RRULE.
	 * @param DateTimeImmutable   $from  Start of the window.
	 *
	 * @return int
	 */
	private static function get_steps_before( DateTimeImmutable $start, array $rule, DateTimeImmutable $from ): int {
		// bail if the series starts inside or after the window.
		if ( $start >= $from ) {
			return 0;
		}

		$interval = max( 1, absint( $rule['INTERVAL'] ?? 1 ) );
		$days     = (int) $start->diff( $from )->days;
		$months   = ( (int) $from->format( 'Y' ) - (int) $start->format( 'Y' ) ) * 12
					+ ( (int) $from->format( 'n' ) - (int) $start->format( 'n' ) );

		$steps = match ( strtoupper( (string) $rule['FREQ'] ) ) {
			'DAILY'   => intdiv( $days, $interval ),
			'WEEKLY'  => intdiv( $days, 7 * $interval ),
			'MONTHLY' => intdiv( $months, $interval ),
			'YEARLY'  => intdiv( (int) $from->format( 'Y' ) - (int) $start->format( 'Y' ), $interval ),
			default   => 0,
		};

		// stay one step short, so nothing overlapping the window is lost.
		return max( 0, $steps - 1 );
	}
}
