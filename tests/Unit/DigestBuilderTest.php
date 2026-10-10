<?php
/**
 * File to test which entries end up in a digest.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use NowPagesThreadi\Digests\Digest_Builder;
use NowPagesThreadi\Tests\NowPagesTestCase;

/**
 * Object to test Digest_Builder.
 */
class DigestBuilderTest extends NowPagesTestCase {
	/**
	 * Remove every global option before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		foreach ( \NowPagesThreadi\Digests\Display_Settings::option_names() as $option ) {
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
	 * Return the digest for the given args, without any delay.
	 *
	 * @param array<string,mixed> $args Additional args.
	 *
	 * @return string
	 */
	private function build( array $args = array() ): string {
		return ( new Digest_Builder() )->build(
			array_merge(
				array(
					'period'      => 'live',
					'format'      => 'verbatim',
					'delay_hours' => 0,
				),
				$args
			)
		);
	}

	/**
	 * An entry inside the period must appear in the digest.
	 *
	 * @return void
	 */
	public function test_entry_inside_the_period_is_shown(): void {
		$this->create_entry( 2, 'Bin einkaufen gewesen.' );

		$this->assertStringContainsString( 'Bin einkaufen gewesen.', $this->build() );
	}

	/**
	 * An entry older than the period must not appear.
	 *
	 * @return void
	 */
	public function test_entry_outside_the_period_is_not_shown(): void {
		$this->create_entry( 48, 'Vorgestern gewesen.' );

		$this->assertStringNotContainsString( 'Vorgestern gewesen.', $this->build() );
	}

	/**
	 * The delay must hide entries that are too recent.
	 *
	 * This is the privacy guard from the concept: without it the now page
	 * would tell a reader where its author is right now.
	 *
	 * @return void
	 */
	public function test_delay_hides_recent_entries(): void {
		$this->create_entry( 1, 'Gerade eben passiert.' );
		$this->create_entry( 12, 'Heute Morgen passiert.' );

		$digest = $this->build( array( 'delay_hours' => 6 ) );

		$this->assertStringNotContainsString( 'Gerade eben passiert.', $digest );
		$this->assertStringContainsString( 'Heute Morgen passiert.', $digest );
	}

	/**
	 * Without a delay the most recent entry must appear immediately.
	 *
	 * @return void
	 */
	public function test_without_delay_recent_entries_are_shown(): void {
		$this->create_entry( 1, 'Gerade eben passiert.' );

		$this->assertStringContainsString( 'Gerade eben passiert.', $this->build( array( 'delay_hours' => 0 ) ) );
	}

	/**
	 * Only entries of the requested language must be shown.
	 *
	 * @return void
	 */
	public function test_entries_of_another_language_are_excluded(): void {
		$this->create_entry( 2, 'Deutscher Eintrag.', 'de_DE' );
		$this->create_entry( 2, 'English entry.', 'en_GB' );

		$digest = $this->build( array( 'language' => 'de_DE' ) );

		$this->assertStringContainsString( 'Deutscher Eintrag.', $digest );
		$this->assertStringNotContainsString( 'English entry.', $digest );
	}

	/**
	 * Entries with a variant of the requested language must be shown.
	 *
	 * Sources could be saved with a locale like "de_DE_formal" before the
	 * languages were normalized everywhere.
	 *
	 * @return void
	 */
	public function test_entries_with_a_language_variant_are_included(): void {
		$this->create_entry( 2, 'Formeller Eintrag.', 'de_DE_formal' );
		$this->create_entry( 2, 'Schweizer Eintrag.', 'de_CH' );

		$digest = $this->build( array( 'language' => 'de_DE_formal' ) );

		$this->assertStringContainsString( 'Formeller Eintrag.', $digest );
		$this->assertStringNotContainsString( 'Schweizer Eintrag.', $digest );
	}

	/**
	 * An empty language must not filter anything out.
	 *
	 * The report email relies on this to show everything that arrived.
	 *
	 * @return void
	 */
	public function test_empty_language_does_not_filter(): void {
		$this->create_entry( 2, 'Deutscher Eintrag.', 'de_DE' );
		$this->create_entry( 2, 'English entry.', 'en_GB' );

		$digest = $this->build( array( 'language' => '' ) );

		$this->assertStringContainsString( 'Deutscher Eintrag.', $digest );
		$this->assertStringContainsString( 'English entry.', $digest );
	}

	/**
	 * Only the entries of the requested author must be shown.
	 *
	 * @return void
	 */
	public function test_entries_are_limited_to_one_author(): void {
		$first  = self::factory()->user->create();
		$second = self::factory()->user->create();

		wp_update_post(
			array(
				'ID'          => $this->create_entry( 2, 'Vom ersten Autor.' ),
				'post_author' => $first,
			)
		);

		wp_update_post(
			array(
				'ID'          => $this->create_entry( 2, 'Vom zweiten Autor.' ),
				'post_author' => $second,
			)
		);

		$digest = $this->build( array( 'user_id' => $first ) );

		$this->assertStringContainsString( 'Vom ersten Autor.', $digest );
		$this->assertStringNotContainsString( 'Vom zweiten Autor.', $digest );
	}

	/**
	 * An unknown user must get an empty digest, not the entries of all users.
	 *
	 * @return void
	 */
	public function test_an_unknown_user_gets_an_empty_digest(): void {
		$this->create_entry( 2, 'Von irgendwem.' );

		$this->assertStringNotContainsString( 'Von irgendwem.', $this->build( array( 'user' => 'nobody-with-this-name' ) ) );
	}

	/**
	 * Entries must be ordered oldest first by default.
	 *
	 * @return void
	 */
	public function test_entries_are_ordered_oldest_first_by_default(): void {
		$this->create_entry( 2, 'Der neuere Eintrag.' );
		$this->create_entry( 10, 'Der ältere Eintrag.' );

		$digest = $this->build();

		$this->assertLessThan(
			strpos( $digest, 'Der neuere Eintrag.' ),
			strpos( $digest, 'Der ältere Eintrag.' )
		);
	}

	/**
	 * The order must be reversible via the setting.
	 *
	 * @return void
	 */
	public function test_order_can_be_reversed(): void {
		$this->create_entry( 2, 'Der neuere Eintrag.' );
		$this->create_entry( 10, 'Der ältere Eintrag.' );

		$digest = $this->build( array( 'order' => 'DESC' ) );

		$this->assertLessThan(
			strpos( $digest, 'Der ältere Eintrag.' ),
			strpos( $digest, 'Der neuere Eintrag.' )
		);
	}

	/**
	 * An unknown format must fall back to the default format instead of a fatal error.
	 *
	 * @return void
	 */
	public function test_unknown_format_falls_back_to_the_default(): void {
		update_option( 'now_pages_threadi_frontend_format', 'verbatim' );

		$this->create_entry( 2, 'Ein Eintrag.' );

		$this->assertStringContainsString( 'Ein Eintrag.', $this->build( array( 'format' => 'does-not-exist' ) ) );
	}

	/**
	 * An unknown format must produce nothing if even the default format is unknown.
	 *
	 * @return void
	 */
	public function test_unknown_default_format_returns_an_empty_digest(): void {
		update_option( 'now_pages_threadi_frontend_format', 'does-not-exist-either' );

		$this->create_entry( 2, 'Ein Eintrag.' );

		$this->assertSame( '', $this->build( array( 'format' => 'does-not-exist' ) ) );
	}

	/**
	 * A period without any entries must produce nothing.
	 *
	 * @return void
	 */
	public function test_empty_period_returns_an_empty_digest(): void {
		$this->assertSame( '', $this->build() );
	}

	/**
	 * The trace must report what the builder did, for the diagnostics panel.
	 *
	 * @return void
	 */
	public function test_trace_reports_the_applied_delay(): void {
		$this->create_entry( 1, 'Gerade eben passiert.' );

		$builder = new Digest_Builder();

		$builder->build(
			array(
				'period'      => 'live',
				'format'      => 'verbatim',
				'delay_hours' => 6,
			)
		);

		$trace = $builder->get_trace();

		$this->assertTrue( $trace['delay_applied'] );
		$this->assertSame( 0, $trace['found'] );
	}
}
