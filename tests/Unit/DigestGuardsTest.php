<?php
/**
 * File to test the two guards that keep the digest consistent.
 *
 * Both look like needless safety at first glance, and both cost an hour
 * of searching when they are missing — which is exactly why they need a
 * test that states the intention.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Tests\Unit;

use ThreadiNowPages\Digests\Digest_Builder;
use ThreadiNowPages\Digests\Display_Settings;
use ThreadiNowPages\Tests\NowPagesTestCase;

/**
 * Object to test the resolve guard and the build cache.
 */
class DigestGuardsTest extends NowPagesTestCase {
	/**
	 * Create an entry inside the live period.
	 *
	 * @param string $text The text of the entry.
	 *
	 * @return int
	 */
	private function create_entry( string $text ): int {
		return self::factory()->post->create(
			array(
				'post_type'     => 'threadi_np_entry',
				'post_status'   => 'publish',
				'post_title'    => '',
				'post_content'  => $text,
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 2 * HOUR_IN_SECONDS ) ),
			)
		);
	}

	/**
	 * Return the arguments a digest is built with here.
	 *
	 * @return array<string,mixed>
	 */
	private function get_args(): array {
		return array(
			'period'      => 'live',
			'format'      => 'verbatim',
			'delay_hours' => 0,
		);
	}

	/**
	 * Resolving twice must not turn every value into an explicit override.
	 *
	 * The second pass sees every key of defaults() in the arguments and
	 * would mark them all as explicit, which silently disables both the
	 * global settings and the per-user ones.
	 *
	 * @return void
	 */
	public function test_resolving_twice_keeps_the_origins(): void {
		update_option( 'threadi_now_pages_show_time', 1 );

		$once  = Display_Settings::resolve( array() );
		$twice = Display_Settings::resolve( $once );

		$this->assertSame( 'option', $once['_origin']['show_time'] );
		$this->assertSame( 'option', $twice['_origin']['show_time'] );
	}

	/**
	 * Resolving twice must not change any value either.
	 *
	 * @return void
	 */
	public function test_resolving_twice_changes_nothing(): void {
		update_option( 'threadi_now_pages_show_time', 1 );

		$once  = Display_Settings::resolve( array() );
		$twice = Display_Settings::resolve( $once );

		$this->assertSame( $once, $twice );
	}

	/**
	 * An explicit value must still beat the option on the first pass.
	 *
	 * The guard must not swallow a real override.
	 *
	 * @return void
	 */
	public function test_the_guard_does_not_swallow_a_real_override(): void {
		update_option( 'threadi_now_pages_show_time', 1 );

		$resolved = Display_Settings::resolve( array( 'show_time' => false ) );

		$this->assertFalse( $resolved['show_time'] );
		$this->assertSame( 'explicit', $resolved['_origin']['show_time'] );
	}

	/**
	 * Two builds with the same arguments must deliver the same result.
	 *
	 * @return void
	 */
	public function test_two_builds_deliver_the_same_result(): void {
		$this->create_entry( 'An entry' );

		$first  = ( new Digest_Builder() )->build( $this->get_args() );
		$second = ( new Digest_Builder() )->build( $this->get_args() );

		$this->assertSame( $first, $second );
		$this->assertStringContainsString( 'An entry', $first );
	}

	/**
	 * A cached build must carry its trace along.
	 *
	 * Without it the second caller — a page builder widget inside the page,
	 * for example — would show an empty diagnostics panel.
	 *
	 * @return void
	 */
	public function test_a_cached_build_carries_its_trace(): void {
		$this->create_entry( 'An entry' );

		$first = new Digest_Builder();
		$first->build( $this->get_args() );

		$second = new Digest_Builder();
		$second->build( $this->get_args() );

		$this->assertSame( $first->get_trace()['found'], $second->get_trace()['found'] );
		$this->assertNotEmpty( $second->get_trace() );
	}

	/**
	 * Different arguments must not share a cached result.
	 *
	 * @return void
	 */
	public function test_different_arguments_are_not_shared(): void {
		$this->create_entry( 'An entry' );

		$args = $this->get_args();

		$with_period = array_merge( $args, array( 'show_period' => true ) );
		$no_period   = array_merge( $args, array( 'show_period' => false ) );

		$first  = ( new Digest_Builder() )->build( $with_period );
		$second = ( new Digest_Builder() )->build( $no_period );

		$this->assertNotSame( $first, $second );
	}

	/**
	 * Resetting the cache must make a new entry visible.
	 *
	 * This is what NowPagesTestCase::set_up() relies on — without it a
	 * cached digest of one test would leak into the next one.
	 *
	 * @return void
	 */
	public function test_resetting_the_cache_shows_new_entries(): void {
		$this->create_entry( 'The first entry' );

		$first = ( new Digest_Builder() )->build( $this->get_args() );

		$this->assertStringContainsString( 'The first entry', $first );

		$this->create_entry( 'The second entry' );

		// without the reset the cached result would still be delivered.
		Digest_Builder::reset_cache();

		$second = ( new Digest_Builder() )->build( $this->get_args() );

		$this->assertStringContainsString( 'The second entry', $second );
	}

	/**
	 * Without a reset the cache must actually hold the old result.
	 *
	 * The counterpart to the test above: it proves the cache is doing
	 * something, so the reset is not testing an empty mechanism.
	 *
	 * @return void
	 */
	public function test_without_a_reset_the_old_result_is_kept(): void {
		$this->create_entry( 'The first entry' );

		( new Digest_Builder() )->build( $this->get_args() );

		$this->create_entry( 'The second entry' );

		$second = ( new Digest_Builder() )->build( $this->get_args() );

		$this->assertStringNotContainsString( 'The second entry', $second );
	}
}
