<?php
/**
 * File to test how entry text is normalized and turned into sentences.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use NowPagesThreadi\Digests\Summarizers\Template_Summarizer;
use NowPagesThreadi\Helper\Format;
use NowPagesThreadi\NowPages\Entries;
use NowPagesThreadi\Tests\NowPagesTestCase;
use WP_Post;

/**
 * Object to test Format::get_entry_text() and Template_Summarizer.
 */
class EntryTextTest extends NowPagesTestCase {
	/**
	 * Create an entry post and return it.
	 *
	 * @param string $content     The post content.
	 * @param int    $source_id   The parent source (0 = manual).
	 * @param string $external_id The external ID, empty for manual entries.
	 *
	 * @return WP_Post
	 */
	private function create_entry( string $content, int $source_id = 0, string $external_id = '' ): WP_Post {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'np_threadi_entry',
				'post_status'  => 'publish',
				'post_title'   => '',
				'post_content' => $content,
				'post_parent'  => $source_id,
			)
		);

		if ( '' !== $external_id ) {
			update_post_meta( $post_id, 'external_id', $external_id );
		}

		return get_post( $post_id );
	}

	/**
	 * Plain text from an import must pass through unchanged.
	 *
	 * @return void
	 */
	public function test_plain_text_is_unchanged(): void {
		$entry = $this->create_entry( 'Bin gerade einkaufen.' );

		$this->assertSame( 'Bin gerade einkaufen.', Format::get_entry_text( $entry ) );
	}

	/**
	 * Block markup from the editor must not leak into the output.
	 *
	 * @return void
	 */
	public function test_block_markup_is_removed(): void {
		$entry = $this->create_entry(
			"<!-- wp:paragraph -->\n<p>Ich bereite einen Vortrag vor.</p>\n<!-- /wp:paragraph -->"
		);

		$this->assertSame( 'Ich bereite einen Vortrag vor.', Format::get_entry_text( $entry ) );
	}

	/**
	 * Several blocks must be collapsed into one line.
	 *
	 * @return void
	 */
	public function test_multiple_blocks_are_collapsed_to_one_line(): void {
		$entry = $this->create_entry(
			"<!-- wp:paragraph -->\n<p>Erster Satz.</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:paragraph -->\n<p>Zweiter Satz.</p>\n<!-- /wp:paragraph -->"
		);

		$this->assertSame( 'Erster Satz. Zweiter Satz.', Format::get_entry_text( $entry ) );
	}

	/**
	 * Shortcodes must not be executed or shown.
	 *
	 * @return void
	 */
	public function test_shortcodes_are_removed(): void {
		add_shortcode( 'now_pages_threadi_test', static fn (): string => 'BOOM' );

		$entry = $this->create_entry( 'Ein Eintrag [now_pages_threadi_test] mit Shortcode.' );

		$text = Format::get_entry_text( $entry );

		remove_shortcode( 'now_pages_threadi_test' );

		$this->assertStringNotContainsString( 'BOOM', $text );
		$this->assertStringNotContainsString( '[now_pages_threadi_test]', $text );
	}

	/**
	 * An entry without a source and without an external ID is manual.
	 *
	 * @return void
	 */
	public function test_entry_without_source_and_external_id_is_manual(): void {
		$entry = $this->create_entry( 'Von Hand geschrieben.' );

		$this->assertTrue( Entries::get_instance()->is_manual( $entry ) );
	}

	/**
	 * An entry with a source is never manual.
	 *
	 * @return void
	 */
	public function test_entry_with_a_source_is_not_manual(): void {
		$source_id = self::factory()->post->create( array( 'post_type' => 'np_threadi_source' ) );

		$entry = $this->create_entry( 'Importiert.', $source_id, 'ext-1' );

		$this->assertFalse( Entries::get_instance()->is_manual( $entry ) );
	}

	/**
	 * A webhook entry has no source but an external ID, so it is not manual.
	 *
	 * The webhook currently passes source_id 0, which is why the external ID
	 * has to be part of the check.
	 *
	 * @return void
	 */
	public function test_webhook_entry_without_source_is_not_manual(): void {
		$entry = $this->create_entry( 'Per Webhook gekommen.', 0, 'hook-1' );

		$this->assertFalse( Entries::get_instance()->is_manual( $entry ) );
	}

	/**
	 * A manual entry must be rendered as written, without connector or
	 * lowercased first letter.
	 *
	 * @return void
	 */
	public function test_manual_entry_is_summarized_verbatim(): void {
		$first  = $this->create_entry( 'Importierter Eintrag.', 0, 'ext-1' );
		$second = $this->create_entry( 'Ich bereite einen Vortrag vor.' );

		$result = ( new Template_Summarizer() )->summarize( array( $first, $second ), get_locale() );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'Ich bereite einen Vortrag vor.', $result );
	}

	/**
	 * A sentence that already ends with punctuation must not get a second one.
	 *
	 * @return void
	 */
	public function test_manual_entry_keeps_its_own_punctuation(): void {
		$entry = $this->create_entry( 'Gerade dabei: Vortrag vorbereiten:' );

		$result = ( new Template_Summarizer() )->summarize( array( $entry ), get_locale() );

		$this->assertIsString( $result );
		$this->assertStringNotContainsString( ':.', $result );
	}

	/**
	 * An entry without any text must not produce an empty sentence.
	 *
	 * @return void
	 */
	public function test_empty_entry_produces_no_sentence(): void {
		$entry = $this->create_entry( '' );

		$result = ( new Template_Summarizer() )->summarize( array( $entry ), get_locale() );

		$this->assertSame( '', trim( (string) $result ) );
	}

	/**
	 * The REST API must not run the shortcodes of an imported entry, but keep its raw content.
	 *
	 * @return void
	 */
	public function test_rest_does_not_run_shortcodes_of_imported_entries(): void {
		add_shortcode( 'now_pages_threadi_test', static fn (): string => 'BOOM' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$entry = $this->create_entry( 'Importiert [now_pages_threadi_test] mit Shortcode.', 0, 'hook-2' );

		$request = new \WP_REST_Request( 'GET', '/wp/v2/np_threadi_entry/' . $entry->ID );
		$request->set_param( 'context', 'edit' );
		$data = rest_do_request( $request )->get_data();

		remove_shortcode( 'now_pages_threadi_test' );

		$this->assertIsArray( $data );
		$this->assertStringNotContainsString( 'BOOM', (string) $data['content']['rendered'] );
		$this->assertSame( 'Importiert [now_pages_threadi_test] mit Shortcode.', $data['content']['raw'] );
	}
}
