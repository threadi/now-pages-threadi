<?php
/**
 * File to test the trace a digest build leaves behind.
 *
 * The diagnostics panel reads nothing but this trace, so a wrong value here
 * silently disables parts of the panel without any visible error.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use NowPagesThreadi\Digests\Diagnostics;
use NowPagesThreadi\Digests\Digest_Builder;
use NowPagesThreadi\Digests\Display_Settings;
use NowPagesThreadi\Tests\NowPagesTestCase;

/**
 * Object to test Digest_Builder::get_trace() and Diagnostics.
 */
class DigestTraceTest extends NowPagesTestCase {
	/**
	 * Remove every global option before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		foreach ( Display_Settings::option_names() as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * Create an entry a given number of hours in the past.
	 *
	 * @param int    $hours_ago How many hours ago the entry was created.
	 * @param string $text      The text of the entry.
	 * @param string $language  The language of the entry.
	 *
	 * @return int
	 */
	private function create_entry( int $hours_ago, string $text = 'Ein Eintrag.', string $language = '' ): int {
		$date = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( $hours_ago * HOUR_IN_SECONDS ) );

		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'np_threadi_entry',
				'post_status'  => 'publish',
				'post_title'   => '',
				'post_content' => $text,
				'post_date'    => $date,
			)
		);

		update_post_meta( $post_id, 'language', '' !== $language ? $language : get_locale() );

		return $post_id;
	}

	/**
	 * Build a digest and return the builder, so the trace can be inspected.
	 *
	 * @param array<string,mixed> $args Additional args.
	 *
	 * @return Digest_Builder
	 */
	private function build( array $args = array() ): Digest_Builder {
		$builder = new Digest_Builder();

		$builder->build(
			array_merge(
				array(
					'period'      => 'live',
					'format'      => 'verbatim',
					'delay_hours' => 0,
				),
				$args
			)
		);

		return $builder;
	}

	/**
	 * Before build() has run the trace must be empty.
	 *
	 * @return void
	 */
	public function test_trace_is_empty_before_building(): void {
		$this->assertSame( array(), ( new Digest_Builder() )->get_trace() );
	}

	/**
	 * The trace must report how many entries were found.
	 *
	 * @return void
	 */
	public function test_trace_reports_the_number_of_found_entries(): void {
		$this->create_entry( 2, 'Erster Eintrag.' );
		$this->create_entry( 3, 'Zweiter Eintrag.' );

		$this->assertSame( 2, $this->build()->get_trace()['found'] );
	}

	/**
	 * An empty period must report zero found entries, not a missing key.
	 *
	 * @return void
	 */
	public function test_trace_reports_zero_for_an_empty_period(): void {
		$trace = $this->build()->get_trace();

		$this->assertArrayHasKey( 'found', $trace );
		$this->assertSame( 0, $trace['found'] );
	}

	/**
	 * The trace must carry the query args, so the panel can re-run them.
	 *
	 * @return void
	 */
	public function test_trace_contains_the_query_args(): void {
		$this->create_entry( 2 );

		$trace = $this->build()->get_trace();

		$this->assertArrayHasKey( 'query_args', $trace );
		$this->assertSame( 'np_threadi_entry', $trace['query_args']['post_type'] );
		$this->assertArrayHasKey( 'date_query', $trace['query_args'] );
	}

	/**
	 * The trace must carry the resolved settings including their origin.
	 *
	 * @return void
	 */
	public function test_trace_contains_the_resolved_settings(): void {
		update_option( 'now_pages_threadi_show_time', '1' );

		$builder = new Digest_Builder();

		$builder->build(
			array(
				'period' => 'live',
				'format' => 'verbatim',
			)
		);

		$trace = $builder->get_trace();

		$this->assertTrue( $trace['args']['show_time'] );
		$this->assertSame( 'option', $trace['args']['_origin']['show_time'] );
	}

	/**
	 * The delay must be reported as applied when it shortened the range.
	 *
	 * @return void
	 */
	public function test_trace_reports_an_applied_delay(): void {
		$this->create_entry( 1 );

		$trace = $this->build( array( 'delay_hours' => 6 ) )->get_trace();

		$this->assertTrue( $trace['delay_applied'] );
	}

	/**
	 * Without a delay it must be reported as not applied.
	 *
	 * @return void
	 */
	public function test_trace_reports_no_delay_when_disabled(): void {
		$this->create_entry( 1 );

		$this->assertFalse( $this->build( array( 'delay_hours' => 0 ) )->get_trace()['delay_applied'] );
	}

	/**
	 * The undelayed upper bound must be later than the delayed one.
	 *
	 * @return void
	 */
	public function test_trace_keeps_the_undelayed_upper_bound(): void {
		$this->create_entry( 1 );

		$trace = $this->build( array( 'delay_hours' => 6 ) )->get_trace();

		$this->assertGreaterThan( $trace['range'][1], $trace['before_raw'] );
	}

	/**
	 * An unknown format must be reported as an error in the trace.
	 *
	 * @return void
	 */
	public function test_trace_reports_an_unknown_format(): void {
		$trace = $this->build( array( 'format' => 'does-not-exist' ) )->get_trace();

		$this->assertSame( 'unknown_format', $trace['error'] );
	}

	/**
	 * A successful build must not leave an error in the trace.
	 *
	 * @return void
	 */
	public function test_trace_has_no_error_on_success(): void {
		$this->create_entry( 2 );

		$this->assertArrayNotHasKey( 'error', $this->build()->get_trace() );
	}

	/**
	 * A second build must start with a fresh trace.
	 *
	 * @return void
	 */
	public function test_trace_is_reset_between_builds(): void {
		$builder = new Digest_Builder();

		$builder->build( array( 'period' => 'live', 'format' => 'does-not-exist' ) );

		$this->assertArrayHasKey( 'error', $builder->get_trace() );

		$builder->build( array( 'period' => 'live', 'format' => 'verbatim', 'delay_hours' => 0 ) );

		$this->assertArrayNotHasKey( 'error', $builder->get_trace() );
	}

	/**
	 * The panel must report the entries the delay removed.
	 *
	 * @return void
	 */
	public function test_panel_reports_entries_hidden_by_the_delay(): void {
		$this->create_entry( 1, 'Gerade eben passiert.' );
		$this->create_entry( 12, 'Heute Morgen passiert.' );

		$trace = $this->build( array( 'delay_hours' => 6 ) )->get_trace();
		$panel = Diagnostics::get_instance()->render_panel( $trace );

		$this->assertStringContainsString( '1', $panel );
		$this->assertStringNotContainsString( 'No entries were removed', $panel );
	}

	/**
	 * The panel must stay empty when the builder never ran.
	 *
	 * @return void
	 */
	public function test_panel_is_empty_without_a_trace(): void {
		$this->assertSame( '', Diagnostics::get_instance()->render_panel( array() ) );
	}

	/**
	 * The panel must name the unknown format instead of staying silent.
	 *
	 * @return void
	 */
	public function test_panel_explains_an_unknown_format(): void {
		$trace = $this->build( array( 'format' => 'does-not-exist' ) )->get_trace();

		$this->assertStringContainsString( 'does-not-exist', Diagnostics::get_instance()->render_panel( $trace ) );
	}

	/**
	 * The panel must not be appended for visitors without the capability.
	 *
	 * @return void
	 */
	public function test_panel_is_not_added_for_visitors(): void {
		$_GET[ Diagnostics::QUERY_PARAM ] = '1';

		wp_set_current_user( 0 );

		$content = Diagnostics::get_instance()->add_diagnostics( '<p>Digest</p>', null );

		unset( $_GET[ Diagnostics::QUERY_PARAM ] );

		$this->assertSame( '<p>Digest</p>', $content );
	}

	/**
	 * Without the query parameter the panel must not be added at all.
	 *
	 * @return void
	 */
	public function test_panel_is_not_added_without_the_query_parameter(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( '<p>Digest</p>', Diagnostics::get_instance()->add_diagnostics( '<p>Digest</p>', null ) );
	}
}
