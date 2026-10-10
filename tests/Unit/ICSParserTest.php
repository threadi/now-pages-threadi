<?php
/**
 * File to test the iCalendar parser.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use NowPagesThreadi\Helper\ICS_Parser;
use NowPagesThreadi\Tests\NowPagesTestCase;

/**
 * Object to test ICS_Parser.
 */
class ICSParserTest extends NowPagesTestCase {
	/**
	 * Use a fixed, non-UTC time zone so shifts become visible.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( 'timezone_string', 'Europe/Berlin' );
	}

	/**
	 * Wrap the given VEVENT lines into a complete calendar.
	 *
	 * @param string $lines The lines between BEGIN:VEVENT and END:VEVENT.
	 *
	 * @return string
	 */
	private function get_calendar( string $lines ): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\n" . $lines . "\r\nEND:VEVENT\r\nEND:VCALENDAR";
	}

	/**
	 * Parse the given VEVENT lines and return the single event.
	 *
	 * @param string $lines The lines between BEGIN:VEVENT and END:VEVENT.
	 *
	 * @return array<string,mixed>
	 */
	private function parse_one( string $lines ): array {
		$events = ICS_Parser::parse( $this->get_calendar( $lines ) );

		$this->assertCount( 1, $events, 'The calendar did not produce exactly one event.' );

		return $events[0];
	}

	/**
	 * A calendar without any event must produce an empty list.
	 *
	 * @return void
	 */
	public function test_empty_calendar_produces_no_events(): void {
		$this->assertSame( array(), ICS_Parser::parse( "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR" ) );
	}

	/**
	 * Garbage input must not produce events either.
	 *
	 * @return void
	 */
	public function test_garbage_produces_no_events(): void {
		$this->assertSame( array(), ICS_Parser::parse( 'this is not a calendar at all' ) );
	}

	/**
	 * Several events in one calendar must all be returned.
	 *
	 * @return void
	 */
	public function test_multiple_events_are_parsed(): void {
		$ics = "BEGIN:VCALENDAR\r\n"
		       . "BEGIN:VEVENT\r\nUID:1\r\nDTSTART;VALUE=DATE:20260918\r\nDTEND;VALUE=DATE:20260919\r\nSUMMARY:WordCamp Bretagne 2026\r\nEND:VEVENT\r\n"
		       . "BEGIN:VEVENT\r\nUID:2\r\nDTSTART;VALUE=DATE:20261002\r\nDTEND;VALUE=DATE:20261003\r\nSUMMARY:WordCamp Wroclaw 2026\r\nEND:VEVENT\r\n"
		       . 'END:VCALENDAR';

		$this->assertCount( 2, ICS_Parser::parse( $ics ) );
	}

	/**
	 * A huge calendar must be cut off at the limit of events.
	 *
	 * @return void
	 */
	public function test_number_of_events_is_limited(): void {
		$ics = "BEGIN:VCALENDAR\r\n"
		       . str_repeat( "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20260918\r\nSUMMARY:x\r\nEND:VEVENT\r\n", ICS_Parser::MAX_EVENTS + 10 )
		       . 'END:VCALENDAR';

		$this->assertCount( ICS_Parser::MAX_EVENTS, ICS_Parser::parse( $ics ) );
	}

	/**
	 * An event without a start is unusable and must be dropped.
	 *
	 * @return void
	 */
	public function test_event_without_start_is_dropped(): void {
		$this->assertSame( array(), ICS_Parser::parse( $this->get_calendar( "UID:1\r\nSUMMARY:Without a date" ) ) );
	}

	/**
	 * The basic properties must end up in the result.
	 *
	 * @return void
	 */
	public function test_basic_properties_are_read(): void {
		$event = $this->parse_one(
			"UID:abc-123\r\nDTSTART:20260905T100000Z\r\nDTEND:20260905T113000Z\r\nSUMMARY:Talk about now pages\r\nLOCATION:Room 2\r\nSTATUS:CONFIRMED"
		);

		$this->assertSame( 'abc-123', $event['uid'] );
		$this->assertSame( 'Talk about now pages', $event['summary'] );
		$this->assertSame( 'Room 2', $event['location'] );
		$this->assertSame( 'CONFIRMED', $event['status'] );
		$this->assertFalse( $event['all_day'] );
	}

	/**
	 * A date-only start must be marked as an all-day event.
	 *
	 * @return void
	 */
	public function test_date_only_event_is_marked_as_all_day(): void {
		$event = $this->parse_one( "UID:1\r\nDTSTART;VALUE=DATE:20260918\r\nDTEND;VALUE=DATE:20260919\r\nSUMMARY:WordCamp Bretagne 2026" );

		$this->assertTrue( $event['all_day'] );
		$this->assertSame( '2026-09-18', $event['start']->format( 'Y-m-d' ) );
	}

	/**
	 * A single-day event with DTEND on the start date must still last a day.
	 *
	 * The WordCamp Central feed exports single-day events this way, which
	 * would otherwise be a zero-length appointment that never runs.
	 *
	 * @return void
	 */
	public function test_zero_length_event_lasts_the_whole_day(): void {
		$event = $this->parse_one( "UID:1\r\nDTSTART;VALUE=DATE:20261030\r\nDTEND;VALUE=DATE:20261030\r\nSUMMARY:WordCamp Johannesburg 2026" );

		$this->assertGreaterThan( $event['start'], $event['end'] );
	}

	/**
	 * An event without any DTEND must get a usable one.
	 *
	 * @return void
	 */
	public function test_event_without_end_gets_one(): void {
		$event = $this->parse_one( "UID:1\r\nDTSTART:20260905T100000Z\r\nSUMMARY:Open end" );

		$this->assertInstanceOf( DateTimeImmutable::class, $event['end'] );
		$this->assertGreaterThan( $event['start'], $event['end'] );
	}

	/**
	 * A trailing Z must be read as UTC.
	 *
	 * @return void
	 */
	public function test_utc_timestamp_is_read_as_utc(): void {
		$event = $this->parse_one( "UID:1\r\nDTSTART:20260905T100000Z\r\nDTEND:20260905T110000Z\r\nSUMMARY:In UTC" );

		$this->assertSame( '2026-09-05 10:00:00', $event['start']->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * A named time zone must be honoured.
	 *
	 * @return void
	 */
	public function test_named_time_zone_is_honoured(): void {
		$event = $this->parse_one(
			"UID:1\r\nDTSTART;TZID=America/New_York:20260905T100000\r\nDTEND;TZID=America/New_York:20260905T110000\r\nSUMMARY:In New York"
		);

		// 10:00 in New York is 14:00 UTC in September.
		$this->assertSame( '14:00', $event['start']->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'H:i' ) );
	}

	/**
	 * An unknown time zone must fall back to the one of the website.
	 *
	 * @return void
	 */
	public function test_unknown_time_zone_falls_back_to_the_website(): void {
		$event = $this->parse_one(
			"UID:1\r\nDTSTART;TZID=Middle Earth/Shire:20260905T100000\r\nDTEND;TZID=Middle Earth/Shire:20260905T110000\r\nSUMMARY:Somewhere"
		);

		// 10:00 in Berlin is 08:00 UTC in September.
		$this->assertSame( '08:00', $event['start']->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'H:i' ) );
	}

	/**
	 * A floating timestamp must be read in the time zone of the website.
	 *
	 * @return void
	 */
	public function test_floating_timestamp_uses_the_website_time_zone(): void {
		$event = $this->parse_one( "UID:1\r\nDTSTART:20260905T100000\r\nDTEND:20260905T110000\r\nSUMMARY:Floating" );

		$this->assertSame( '08:00', $event['start']->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'H:i' ) );
	}

	/**
	 * Escaped characters in a text value must be restored.
	 *
	 * @return void
	 */
	public function test_escaped_characters_are_restored(): void {
		$event = $this->parse_one( "UID:1\r\nDTSTART:20260905T100000Z\r\nSUMMARY:Meeting with Anna\\, Bob and Carl" );

		$this->assertSame( 'Meeting with Anna, Bob and Carl', $event['summary'] );
	}

	/**
	 * An escaped newline must not survive as a literal backslash.
	 *
	 * @return void
	 */
	public function test_escaped_newline_is_removed(): void {
		$event = $this->parse_one( "UID:1\r\nDTSTART:20260905T100000Z\r\nSUMMARY:First line\\nSecond line" );

		$this->assertStringNotContainsString( '\\n', $event['summary'] );
		$this->assertStringContainsString( 'Second line', $event['summary'] );
	}

	/**
	 * A folded line must be joined again before parsing.
	 *
	 * @return void
	 */
	public function test_folded_lines_are_unfolded(): void {
		$event = $this->parse_one( "UID:1\r\nDTSTART:20260905T100000Z\r\nSUMMARY:A very long appointment title that\r\n  continues here" );

		$this->assertSame( 'A very long appointment title that continues here', $event['summary'] );
	}

	/**
	 * Non-ASCII characters must survive.
	 *
	 * @return void
	 */
	public function test_non_ascii_titles_survive(): void {
		$event = $this->parse_one( "UID:1\r\nDTSTART;VALUE=DATE:20261002\r\nDTEND;VALUE=DATE:20261003\r\nSUMMARY:WordCamp Wrocław 2026" );

		$this->assertSame( 'WordCamp Wrocław 2026', $event['summary'] );
	}

	/**
	 * A single event must produce exactly one occurrence in its window.
	 *
	 * @return void
	 */
	public function test_single_event_produces_one_occurrence(): void {
		$event = $this->parse_one( "UID:1\r\nDTSTART:20260905T100000Z\r\nDTEND:20260905T110000Z\r\nSUMMARY:Once" );

		$occurrences = ICS_Parser::get_occurrences(
			$event,
			new DateTimeImmutable( '2026-09-01 00:00:00', new DateTimeZone( 'UTC' ) ),
			new DateTimeImmutable( '2026-09-30 00:00:00', new DateTimeZone( 'UTC' ) )
		);

		$this->assertCount( 1, $occurrences );
	}

	/**
	 * An event outside the window must produce nothing.
	 *
	 * @return void
	 */
	public function test_event_outside_the_window_produces_nothing(): void {
		$event = $this->parse_one( "UID:1\r\nDTSTART:20260905T100000Z\r\nDTEND:20260905T110000Z\r\nSUMMARY:Long ago" );

		$occurrences = ICS_Parser::get_occurrences(
			$event,
			new DateTimeImmutable( '2026-10-01 00:00:00', new DateTimeZone( 'UTC' ) ),
			new DateTimeImmutable( '2026-10-30 00:00:00', new DateTimeZone( 'UTC' ) )
		);

		$this->assertSame( array(), $occurrences );
	}

	/**
	 * A multi-day event overlapping the window must be returned.
	 *
	 * @return void
	 */
	public function test_multi_day_event_overlapping_the_window_is_returned(): void {
		$event = $this->parse_one( "UID:1\r\nDTSTART;VALUE=DATE:20260918\r\nDTEND;VALUE=DATE:20260921\r\nSUMMARY:Three days" );

		$occurrences = ICS_Parser::get_occurrences(
			$event,
			new DateTimeImmutable( '2026-09-19 08:00:00', new DateTimeZone( 'UTC' ) ),
			new DateTimeImmutable( '2026-09-19 09:00:00', new DateTimeZone( 'UTC' ) )
		);

		$this->assertCount( 1, $occurrences );
	}

	/**
	 * A weekly rule with COUNT must stop after that many occurrences.
	 *
	 * @return void
	 */
	public function test_weekly_rule_with_count_stops( ): void {
		$event = $this->parse_one(
			"UID:1\r\nDTSTART:20260907T100000Z\r\nDTEND:20260907T110000Z\r\nRRULE:FREQ=WEEKLY;COUNT=3\r\nSUMMARY:Weekly standup"
		);

		$occurrences = ICS_Parser::get_occurrences(
			$event,
			new DateTimeImmutable( '2026-09-01 00:00:00', new DateTimeZone( 'UTC' ) ),
			new DateTimeImmutable( '2026-12-31 00:00:00', new DateTimeZone( 'UTC' ) )
		);

		$this->assertCount( 3, $occurrences );
	}

	/**
	 * A rule with UNTIL must not produce anything after that date.
	 *
	 * @return void
	 */
	public function test_rule_with_until_stops_at_that_date(): void {
		$event = $this->parse_one(
			"UID:1\r\nDTSTART:20260907T100000Z\r\nDTEND:20260907T110000Z\r\nRRULE:FREQ=WEEKLY;UNTIL=20260921T100000Z\r\nSUMMARY:Weekly standup"
		);

		$occurrences = ICS_Parser::get_occurrences(
			$event,
			new DateTimeImmutable( '2026-09-01 00:00:00', new DateTimeZone( 'UTC' ) ),
			new DateTimeImmutable( '2026-12-31 00:00:00', new DateTimeZone( 'UTC' ) )
		);

		$this->assertCount( 3, $occurrences );
	}

	/**
	 * An INTERVAL must skip the steps in between.
	 *
	 * @return void
	 */
	public function test_interval_skips_steps(): void {
		$event = $this->parse_one(
			"UID:1\r\nDTSTART:20260901T100000Z\r\nDTEND:20260901T110000Z\r\nRRULE:FREQ=DAILY;INTERVAL=3;COUNT=4\r\nSUMMARY:Every third day"
		);

		$occurrences = ICS_Parser::get_occurrences(
			$event,
			new DateTimeImmutable( '2026-09-01 00:00:00', new DateTimeZone( 'UTC' ) ),
			new DateTimeImmutable( '2026-09-30 00:00:00', new DateTimeZone( 'UTC' ) )
		);

		$this->assertCount( 4, $occurrences );
		$this->assertSame( '2026-09-04', $occurrences[1]['start']->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d' ) );
	}

	/**
	 * BYDAY must expand a weekly rule into several weekdays.
	 *
	 * @return void
	 */
	public function test_byday_expands_into_several_weekdays(): void {
		$event = $this->parse_one(
			"UID:1\r\nDTSTART:20260907T100000Z\r\nDTEND:20260907T110000Z\r\nRRULE:FREQ=WEEKLY;BYDAY=MO,WE;COUNT=4\r\nSUMMARY:Twice a week"
		);

		$occurrences = ICS_Parser::get_occurrences(
			$event,
			new DateTimeImmutable( '2026-09-01 00:00:00', new DateTimeZone( 'UTC' ) ),
			new DateTimeImmutable( '2026-09-30 00:00:00', new DateTimeZone( 'UTC' ) )
		);

		$this->assertCount( 4, $occurrences );
	}

	/**
	 * A monthly rule with a position must produce that weekday of every month.
	 *
	 * @return void
	 */
	public function test_monthly_rule_with_a_position_produces_that_weekday(): void {
		$event = $this->parse_one(
			"UID:1\r\nDTSTART:20260908T100000Z\r\nDTEND:20260908T110000Z\r\nRRULE:FREQ=MONTHLY;BYDAY=2TU;COUNT=3\r\nSUMMARY:Second tuesday"
		);

		$occurrences = ICS_Parser::get_occurrences(
			$event,
			new DateTimeImmutable( '2026-09-01 00:00:00', new DateTimeZone( 'UTC' ) ),
			new DateTimeImmutable( '2026-12-31 00:00:00', new DateTimeZone( 'UTC' ) )
		);

		$dates = array_map( static fn ( array $occurrence ): string => $occurrence['start']->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d' ), $occurrences );

		$this->assertSame( array( '2026-09-08', '2026-10-13', '2026-11-10' ), $dates );
	}

	/**
	 * BYDAY on a daily rule must limit the days, not multiply them.
	 *
	 * @return void
	 */
	public function test_daily_rule_with_working_days_produces_each_day_once(): void {
		$event = $this->parse_one(
			"UID:1\r\nDTSTART:20260907T090000Z\r\nDTEND:20260907T100000Z\r\nRRULE:FREQ=DAILY;BYDAY=MO,TU,WE,TH,FR\r\nSUMMARY:Working day"
		);

		$occurrences = ICS_Parser::get_occurrences(
			$event,
			new DateTimeImmutable( '2026-09-07 00:00:00', new DateTimeZone( 'UTC' ) ),
			new DateTimeImmutable( '2026-09-13 23:00:00', new DateTimeZone( 'UTC' ) )
		);

		$dates = array_map( static fn ( array $occurrence ): string => $occurrence['start']->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d' ), $occurrences );

		$this->assertSame( array( '2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11' ), $dates );
	}

	/**
	 * COUNT of a daily rule with working days must also hold behind skipped steps.
	 *
	 * @return void
	 */
	public function test_daily_rule_with_working_days_counts_skipped_steps(): void {
		$event = $this->parse_one(
			"UID:1\r\nDTSTART:20260907T090000Z\r\nDTEND:20260907T100000Z\r\nRRULE:FREQ=DAILY;BYDAY=MO,TU,WE,TH,FR;COUNT=10\r\nSUMMARY:Ten working days"
		);

		$occurrences = ICS_Parser::get_occurrences(
			$event,
			new DateTimeImmutable( '2026-09-15 00:00:00', new DateTimeZone( 'UTC' ) ),
			new DateTimeImmutable( '2026-09-30 00:00:00', new DateTimeZone( 'UTC' ) )
		);

		$this->assertCount( 4, $occurrences );
	}

	/**
	 * A moved instance must replace its original date in the series.
	 *
	 * @return void
	 */
	public function test_moved_instance_is_not_shown_twice(): void {
		$events = ICS_Parser::parse(
			"BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
			. "BEGIN:VEVENT\r\nUID:series\r\nDTSTART:20260907T100000Z\r\nDTEND:20260907T110000Z\r\nRRULE:FREQ=WEEKLY;COUNT=3\r\nSUMMARY:Weekly standup\r\nEND:VEVENT\r\n"
			. "BEGIN:VEVENT\r\nUID:series\r\nRECURRENCE-ID:20260914T100000Z\r\nDTSTART:20260915T120000Z\r\nDTEND:20260915T130000Z\r\nSUMMARY:Weekly standup\r\nEND:VEVENT\r\n"
			. 'END:VCALENDAR'
		);

		$this->assertCount( 2, $events );

		$from = new DateTimeImmutable( '2026-09-01 00:00:00', new DateTimeZone( 'UTC' ) );
		$to   = new DateTimeImmutable( '2026-12-31 00:00:00', new DateTimeZone( 'UTC' ) );

		$this->assertCount( 2, ICS_Parser::get_occurrences( $events[0], $from, $to ) );
		$this->assertCount( 1, ICS_Parser::get_occurrences( $events[1], $from, $to ) );
	}

	/**
	 * An EXDATE must remove exactly that instance.
	 *
	 * @return void
	 */
	public function test_exdate_removes_one_instance(): void {
		$event = $this->parse_one(
			"UID:1\r\nDTSTART:20260907T100000Z\r\nDTEND:20260907T110000Z\r\nRRULE:FREQ=WEEKLY;COUNT=3\r\nEXDATE:20260914T100000Z\r\nSUMMARY:Weekly standup"
		);

		$occurrences = ICS_Parser::get_occurrences(
			$event,
			new DateTimeImmutable( '2026-09-01 00:00:00', new DateTimeZone( 'UTC' ) ),
			new DateTimeImmutable( '2026-12-31 00:00:00', new DateTimeZone( 'UTC' ) )
		);

		$this->assertCount( 2, $occurrences );
	}

	/**
	 * An unsupported frequency must produce nothing instead of guessing.
	 *
	 * @return void
	 */
	public function test_unsupported_frequency_produces_nothing(): void {
		$event = $this->parse_one(
			"UID:1\r\nDTSTART:20260907T100000Z\r\nDTEND:20260907T110000Z\r\nRRULE:FREQ=HOURLY;COUNT=3\r\nSUMMARY:Hourly"
		);

		$occurrences = ICS_Parser::get_occurrences(
			$event,
			new DateTimeImmutable( '2026-09-01 00:00:00', new DateTimeZone( 'UTC' ) ),
			new DateTimeImmutable( '2026-12-31 00:00:00', new DateTimeZone( 'UTC' ) )
		);

		$this->assertSame( array(), $occurrences );
	}

	/**
	 * A long-running daily series must still fill a window in the present.
	 *
	 * This is the expensive case: the expansion starts at the original
	 * DTSTART, so a series that began years ago needs many steps before it
	 * reaches the window. If this fails, the loop hits its hard limit and
	 * the parser needs to jump forward instead of walking.
	 *
	 * @return void
	 */
	public function test_long_running_daily_series_still_reaches_the_window(): void {
		$event = $this->parse_one(
			"UID:1\r\nDTSTART:20200101T100000Z\r\nDTEND:20200101T110000Z\r\nRRULE:FREQ=DAILY\r\nSUMMARY:Daily since 2020"
		);

		$occurrences = ICS_Parser::get_occurrences(
			$event,
			new DateTimeImmutable( '2026-09-01 00:00:00', new DateTimeZone( 'UTC' ) ),
			new DateTimeImmutable( '2026-09-03 00:00:00', new DateTimeZone( 'UTC' ) )
		);

		$this->assertNotEmpty( $occurrences, 'The series never reached the window — the expansion hit its hard limit.' );
	}
}
