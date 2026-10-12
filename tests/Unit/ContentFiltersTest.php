<?php
/**
 * File to test the generic content filter for imported entries.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Tests\Unit;

use ThreadiNowPages\NowPages\Content_Filters;
use ThreadiNowPages\NowPages\Entry;
use ThreadiNowPages\Tests\NowPagesTestCase;

/**
 * Object to test Content_Filters.
 */
class ContentFiltersTest extends NowPagesTestCase {
	/**
	 * Reset the site-wide rules before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( Content_Filters::OPTION );
	}

	/**
	 * Return an entry with the given text and meta.
	 *
	 * @param string               $text The text of the entry.
	 * @param array<string,string> $meta Additional, provider-specific attributes.
	 *
	 * @return Entry
	 */
	private function get_entry( string $text, array $meta = array() ): Entry {
		$entry = new Entry();

		$entry->text        = $text;
		$entry->source_slug = 'rss';
		$entry->external_id = 'test-1';
		$entry->meta        = $meta;

		return $entry;
	}

	/**
	 * An entry without any configured rule must pass.
	 *
	 * @return void
	 */
	public function test_entry_passes_without_configured_rules(): void {
		$entry = $this->get_entry( 'Bin gerade einkaufen.' );

		$this->assertTrue( Content_Filters::get_instance()->check( $entry, 0 ) );
	}

	/**
	 * An "include" rule must only let matching entries through.
	 *
	 * @return void
	 */
	public function test_include_rule_only_lets_matching_author_through(): void {
		update_option( Content_Filters::OPTION, 'include:author:equals:Jane Doe' );

		$matching    = $this->get_entry( 'Ein Beitrag.', array( 'author' => 'Jane Doe' ) );
		$not_matching = $this->get_entry( 'Ein anderer Beitrag.', array( 'author' => 'John Smith' ) );

		$this->assertTrue( Content_Filters::get_instance()->check( $matching, 0 ) );
		$this->assertNotTrue( Content_Filters::get_instance()->check( $not_matching, 0 ) );
	}

	/**
	 * Several "include" rules for the same field are combined with OR.
	 *
	 * @return void
	 */
	public function test_include_rules_for_the_same_field_are_combined_with_or(): void {
		update_option( Content_Filters::OPTION, "include:author:equals:Jane Doe\ninclude:author:equals:John Smith" );

		$this->assertTrue(
			Content_Filters::get_instance()->check( $this->get_entry( 'A', array( 'author' => 'John Smith' ) ), 0 )
		);
		$this->assertNotTrue(
			Content_Filters::get_instance()->check( $this->get_entry( 'B', array( 'author' => 'Someone Else' ) ), 0 )
		);
	}

	/**
	 * An "exclude" rule must block a matching entry.
	 *
	 * @return void
	 */
	public function test_exclude_rule_blocks_matching_entry(): void {
		update_option( Content_Filters::OPTION, 'exclude:event_type:equals:ForkEvent' );

		$blocked = $this->get_entry( 'Hat ein Repo geforkt.', array( 'event_type' => 'ForkEvent' ) );
		$allowed = $this->get_entry( 'Hat gepusht.', array( 'event_type' => 'PushEvent' ) );

		$this->assertNotTrue( Content_Filters::get_instance()->check( $blocked, 0 ) );
		$this->assertTrue( Content_Filters::get_instance()->check( $allowed, 0 ) );
	}

	/**
	 * 'contains' must match a substring, unlike 'equals'.
	 *
	 * @return void
	 */
	public function test_contains_operator_matches_substring(): void {
		update_option( Content_Filters::OPTION, 'exclude:text:contains:werbung' );

		$entry = $this->get_entry( 'Das hier ist reine Werbung.' );

		$this->assertNotTrue( Content_Filters::get_instance()->check( $entry, 0 ) );
	}

	/**
	 * The built-in 'text' field must work without any provider-specific meta.
	 *
	 * @return void
	 */
	public function test_text_field_is_always_available(): void {
		update_option( Content_Filters::OPTION, 'include:text:contains:wichtig' );

		$this->assertTrue( Content_Filters::get_instance()->check( $this->get_entry( 'Das ist wichtig!' ), 0 ) );
		$this->assertNotTrue( Content_Filters::get_instance()->check( $this->get_entry( 'Das ist es nicht.' ), 0 ) );
	}

	/**
	 * The built-in 'url' field must look at the address an entry links to.
	 *
	 * One feed can mix several kinds of activity which only differ in where
	 * they lead, e.g. forum replies and translations in a profile feed.
	 *
	 * @return void
	 */
	public function test_url_field_is_always_available(): void {
		update_option( Content_Filters::OPTION, 'include:url:contains:/support/topic/' );

		$reply             = $this->get_entry( 'Posted a reply to Fatal error, on the site WordPress.org Forums' );
		$reply->source_url = 'https://wordpress.org/support/topic/fatal-error/#post-1';

		$translation             = $this->get_entry( 'Translated 106 strings on translate.wordpress.org.' );
		$translation->source_url = 'https://translate.wordpress.org';

		$without_url = $this->get_entry( 'Something without a link' );

		$this->assertTrue( Content_Filters::get_instance()->check( $reply, 0 ) );
		$this->assertNotTrue( Content_Filters::get_instance()->check( $translation, 0 ) );
		$this->assertNotTrue( Content_Filters::get_instance()->check( $without_url, 0 ) );
	}

	/**
	 * An invalid regex must not match anything, and must not throw a fatal error.
	 *
	 * @return void
	 */
	public function test_invalid_regex_does_not_match_and_does_not_error(): void {
		update_option( Content_Filters::OPTION, 'include:text:regex:(' );

		$this->assertNotTrue( Content_Filters::get_instance()->check( $this->get_entry( 'Irgendein Text.' ), 0 ) );
	}

	/**
	 * An exclude rule with an invalid regex must block the entry: an error must not let it through.
	 *
	 * @return void
	 */
	public function test_invalid_regex_in_an_exclude_rule_blocks_the_entry(): void {
		update_option( Content_Filters::OPTION, 'exclude:text:regex:(' );

		$this->assertNotTrue( Content_Filters::get_instance()->check( $this->get_entry( 'Irgendein Text.' ), 0 ) );
	}

	/**
	 * A missing meta field must be treated as an empty string, not a PHP notice.
	 *
	 * @return void
	 */
	public function test_missing_meta_field_is_treated_as_empty_string(): void {
		update_option( Content_Filters::OPTION, 'include:author:equals:Jane Doe' );

		$entry = $this->get_entry( 'Ein Beitrag ohne Autor-Meta.' );

		$this->assertNotTrue( Content_Filters::get_instance()->check( $entry, 0 ) );
	}

	/**
	 * The rules of a source must extend the site-wide list, not replace it.
	 *
	 * @return void
	 */
	public function test_source_rules_extend_the_global_list(): void {
		update_option( Content_Filters::OPTION, 'exclude:text:contains:werbung' );

		$source_id = self::factory()->post->create(
			array(
				'post_type'  => 'threadi_np_source',
				'post_title' => 'Test source',
			)
		);

		update_post_meta( $source_id, Content_Filters::SOURCE_META, 'include:author:equals:Jane Doe' );

		$content_filters = Content_Filters::get_instance();

		// the source's own include rule applies.
		$this->assertNotTrue(
			$content_filters->check( $this->get_entry( 'Neutral.', array( 'author' => 'Someone Else' ) ), $source_id )
		);

		// the site-wide exclude rule still applies as well.
		$this->assertNotTrue(
			$content_filters->check( $this->get_entry( 'Reine Werbung.', array( 'author' => 'Jane Doe' ) ), $source_id )
		);

		// the source's own rule does not leak to other sources.
		$this->assertTrue(
			$content_filters->check( $this->get_entry( 'Neutral.' ), 0 )
		);
	}

	/**
	 * Malformed lines must be ignored, not cause an error.
	 *
	 * @return void
	 */
	public function test_malformed_lines_are_ignored(): void {
		$this->assertSame(
			array(),
			Content_Filters::parse_rules( "not-a-valid-line\ninclude:author:not-an-operator:value\n\n  \n" )
		);
	}

	/**
	 * A well-formed line must be parsed into its four parts.
	 *
	 * @return void
	 */
	public function test_valid_line_is_parsed_correctly(): void {
		$this->assertSame(
			array(
				array(
					'action'   => 'include',
					'field'    => 'author',
					'operator' => 'equals',
					'value'    => 'Jane Doe',
				),
			),
			Content_Filters::parse_rules( 'include:author:equals:Jane Doe' )
		);
	}

	/**
	 * Cleaning up rules must keep regex syntax and only remove control characters.
	 *
	 * @return void
	 */
	public function test_sanitize_rules_keeps_regex_syntax(): void {
		$this->assertSame(
			"exclude:text:regex:\\bprivat\\b\nexclude:text:regex:(?<=a)b\ninclude:text:regex:(?<name>x)\ninclude:url:contains:%20",
			Content_Filters::sanitize_rules( "  exclude:text:regex:\\bprivat\\b \r\nexclude:text:regex:(?<=a)b\x07\x00\ninclude:text:regex:(?<name>x)\ninclude:url:contains:%20" )
		);
	}
}
