<?php
/**
 * File to test the 1:1 list format.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Tests\Unit;

use ThreadiNowPages\Digests\Formats\Verbatim_Format;
use ThreadiNowPages\Tests\NowPagesTestCase;
use WP_Post;

/**
 * Object to test Verbatim_Format.
 */
class VerbatimFormatTest extends NowPagesTestCase {
	/**
	 * Create an entry post and return it.
	 *
	 * @param string $content   The post content.
	 * @param int    $source_id The parent source (0 = manual).
	 *
	 * @return WP_Post
	 */
	private function create_entry( string $content = 'Bin einkaufen.', int $source_id = 0 ): WP_Post {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'threadi_np_entry',
				'post_status'  => 'publish',
				'post_title'   => '',
				'post_content' => $content,
				'post_parent'  => $source_id,
				'post_date'    => '2026-01-15 10:30:00',
			)
		);

		return get_post( $post_id );
	}

	/**
	 * Create a source post.
	 *
	 * @param string $title The title of the source.
	 *
	 * @return int
	 */
	private function create_source( string $title = 'Mastodon' ): int {
		return self::factory()->post->create(
			array(
				'post_type'  => 'threadi_np_source',
				'post_title' => $title,
			)
		);
	}

	/**
	 * Render the given entries with the given settings.
	 *
	 * @param array<int,WP_Post>  $entries  The entries.
	 * @param array<string,mixed> $settings The settings.
	 *
	 * @return string
	 */
	private function render( array $entries, array $settings = array() ): string {
		return ( new Verbatim_Format() )->render(
			$entries,
			array_merge(
				array(
					'show_time'         => false,
					'show_source'       => false,
					'link_source'       => false,
					'group_by_category' => false,
				),
				$settings
			)
		);
	}

	/**
	 * An empty set of entries must produce nothing.
	 *
	 * @return void
	 */
	public function test_no_entries_produce_no_output(): void {
		$this->assertSame( '', $this->render( array() ) );
	}

	/**
	 * The text of an entry must appear in the output.
	 *
	 * @return void
	 */
	public function test_entry_text_is_rendered(): void {
		$output = $this->render( array( $this->create_entry( 'Bin gerade einkaufen.' ) ) );

		$this->assertStringContainsString( 'Bin gerade einkaufen.', $output );
	}

	/**
	 * Without show_time no time must be rendered.
	 *
	 * @return void
	 */
	public function test_time_is_hidden_by_default(): void {
		$output = $this->render( array( $this->create_entry() ) );

		$this->assertStringNotContainsString( '<time', $output );
	}

	/**
	 * With show_time a time element must be rendered.
	 *
	 * @return void
	 */
	public function test_time_is_rendered_when_enabled(): void {
		$output = $this->render( array( $this->create_entry() ), array( 'show_time' => true ) );

		$this->assertStringContainsString( '<time', $output );
	}

	/**
	 * An author who hides the times in the profile keeps them hidden in a digest of all users.
	 *
	 * @return void
	 */
	public function test_time_is_hidden_if_the_author_hides_it(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		update_user_meta( $author, 'threadi_now_pages_show_time', 0 );

		$entry = $this->create_entry();
		wp_update_post(
			array(
				'ID'          => $entry->ID,
				'post_author' => $author,
			)
		);

		$output = $this->render( array( get_post( $entry->ID ) ), array( 'show_time' => true ) );

		$this->assertStringNotContainsString( '<time', $output );
	}

	/**
	 * The time element must carry a machine-readable datetime attribute.
	 *
	 * @return void
	 */
	public function test_time_has_a_datetime_attribute(): void {
		$output = $this->render( array( $this->create_entry() ), array( 'show_time' => true ) );

		$this->assertStringContainsString( 'datetime="2026-01-15', $output );
	}

	/**
	 * Without show_source the source must not be named.
	 *
	 * @return void
	 */
	public function test_source_is_hidden_by_default(): void {
		$source_id = $this->create_source();

		$output = $this->render( array( $this->create_entry( 'Bin einkaufen.', $source_id ) ) );

		$this->assertStringNotContainsString( 'Mastodon', $output );
	}

	/**
	 * With show_source the source title must be rendered.
	 *
	 * @return void
	 */
	public function test_source_is_rendered_when_enabled(): void {
		$source_id = $this->create_source();

		$output = $this->render(
			array( $this->create_entry( 'Bin einkaufen.', $source_id ) ),
			array( 'show_source' => true )
		);

		$this->assertStringContainsString( 'Mastodon', $output );
	}

	/**
	 * A manual entry has no source, so nothing must be rendered for it.
	 *
	 * @return void
	 */
	public function test_no_source_is_rendered_without_a_parent(): void {
		$output = $this->render( array( $this->create_entry() ), array( 'show_source' => true ) );

		$this->assertStringNotContainsString( '<cite', $output );
	}

	/**
	 * Without link_source the source must be plain text.
	 *
	 * @return void
	 */
	public function test_source_is_not_linked_by_default(): void {
		$source_id = $this->create_source();
		$entry     = $this->create_entry( 'Bin einkaufen.', $source_id );

		update_post_meta( $entry->ID, 'source_url', 'https://example.com/note/1' );

		$output = $this->render( array( $entry ), array( 'show_source' => true ) );

		$this->assertStringNotContainsString( 'https://example.com/note/1', $output );
	}

	/**
	 * With link_source the stored URL must be used.
	 *
	 * @return void
	 */
	public function test_source_is_linked_when_enabled(): void {
		$source_id = $this->create_source();
		$entry     = $this->create_entry( 'Bin einkaufen.', $source_id );

		update_post_meta( $entry->ID, 'source_url', 'https://example.com/note/1' );

		$output = $this->render(
			array( $entry ),
			array(
				'show_source' => true,
				'link_source' => true,
			)
		);

		$this->assertStringContainsString( 'https://example.com/note/1', $output );
	}

	/**
	 * Without a stored URL the source must stay unlinked, not become an
	 * empty anchor.
	 *
	 * @return void
	 */
	public function test_source_without_url_is_not_linked(): void {
		$source_id = $this->create_source();

		$output = $this->render(
			array( $this->create_entry( 'Bin einkaufen.', $source_id ) ),
			array(
				'show_source' => true,
				'link_source' => true,
			)
		);

		$this->assertStringContainsString( 'Mastodon', $output );
		$this->assertStringNotContainsString( '<a href', $output );
	}

	/**
	 * The URL must be overridable via the filter.
	 *
	 * @return void
	 */
	public function test_source_url_can_be_filtered(): void {
		$source_id = $this->create_source();

		add_filter( 'threadi_now_pages_digest_source_url', static fn (): string => 'https://example.org/profile' );

		$output = $this->render(
			array( $this->create_entry( 'Bin einkaufen.', $source_id ) ),
			array(
				'show_source' => true,
				'link_source' => true,
			)
		);

		remove_all_filters( 'threadi_now_pages_digest_source_url' );

		$this->assertStringContainsString( 'https://example.org/profile', $output );
	}

	/**
	 * Block markup from the editor must not reach the output.
	 *
	 * @return void
	 */
	public function test_block_markup_is_not_rendered(): void {
		$entry = $this->create_entry( "<!-- wp:paragraph -->\n<p>Ein Vortrag.</p>\n<!-- /wp:paragraph -->" );

		$output = $this->render( array( $entry ) );

		$this->assertStringNotContainsString( 'wp:paragraph', $output );
		$this->assertStringContainsString( 'Ein Vortrag.', $output );
	}

	/**
	 * Every entry must carry its ID, so it can be found in the backend.
	 *
	 * @return void
	 */
	public function test_entries_carry_their_id(): void {
		$entry = $this->create_entry();

		$this->assertStringContainsString( 'data-entry="' . $entry->ID . '"', $this->render( array( $entry ) ) );
	}

	/**
	 * A source override must replace the entries of that group.
	 *
	 * @return void
	 */
	public function test_source_override_replaces_the_entries(): void {
		$source_id = $this->create_source();

		update_post_meta( $source_id, 'override_text', 'Ich war unterwegs.' );

		$output = $this->render( array( $this->create_entry( 'Peinlicher Eintrag.', $source_id ) ) );

		$this->assertStringContainsString( 'Ich war unterwegs.', $output );
		$this->assertStringNotContainsString( 'Peinlicher Eintrag.', $output );
	}

	/**
	 * The report needs the raw entries, so overrides must be skippable.
	 *
	 * @return void
	 */
	public function test_override_can_be_ignored(): void {
		$source_id = $this->create_source();

		update_post_meta( $source_id, 'override_text', 'Ich war unterwegs.' );

		$output = $this->render(
			array( $this->create_entry( 'Peinlicher Eintrag.', $source_id ) ),
			array( 'ignore_overrides' => true )
		);

		$this->assertStringContainsString( 'Peinlicher Eintrag.', $output );
		$this->assertStringNotContainsString( 'Ich war unterwegs.', $output );
	}
}
