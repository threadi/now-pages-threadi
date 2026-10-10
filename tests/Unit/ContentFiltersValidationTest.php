<?php
/**
 * File to test the check of content filter rules.
 *
 * A line which is no rule is ignored on import without a word, and a rule
 * on a field no entry has never matches. In the form both look like rules
 * which are in effect, so the user has to be told when they are saved.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use NowPagesThreadi\Dependencies\easyTransientsForWordPress\Transients;
use NowPagesThreadi\NowPages\Content_Filters;
use NowPagesThreadi\NowPages\Entry;
use NowPagesThreadi\Providers\Providers;
use NowPagesThreadi\Tests\NowPagesTestCase;

/**
 * Object to test Content_Filters::validate() and what is built on it.
 */
class ContentFiltersValidationTest extends NowPagesTestCase {
	/**
	 * The name of the hint.
	 */
	private const HINT = 'now_pages_threadi_content_filter_rules';

	/**
	 * Start without a hint.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Transients::get_instance()->get_transient_by_name( self::HINT )->delete();
	}

	/**
	 * Return the reasons found for the given lines, by line.
	 *
	 * @param string   $value  The rules.
	 * @param string[] $fields The available fields.
	 *
	 * @return array<string,string>
	 */
	private function get_reasons( string $value, array $fields = array() ): array {
		return wp_list_pluck( Content_Filters::validate( $value, $fields ), 'reason', 'line' );
	}

	/**
	 * Valid rules, blank lines and comments are nothing to report.
	 *
	 * @return void
	 */
	public function test_valid_rules_are_not_reported(): void {
		$rules = "# my rules\n\ninclude:text:contains:forum\nEXCLUDE:author:Equals:Jane Doe\ninclude:url:regex:^https://example\\.com/";

		$this->assertSame( array(), Content_Filters::validate( $rules, array( 'text', 'url', 'source_slug', 'author' ) ) );
	}

	/**
	 * The placeholder of the syntax, typed as it is written in the description, is no action.
	 *
	 * @return void
	 */
	public function test_an_unknown_action_is_reported(): void {
		$this->assertSame(
			array( 'action:link:contains:/support/forum/' => 'action' ),
			$this->get_reasons( 'action:link:contains:/support/forum/' )
		);
	}

	/**
	 * Every way a line can fail to be a rule must be named.
	 *
	 * @return void
	 */
	public function test_each_kind_of_invalid_line_is_named(): void {
		$reasons = $this->get_reasons( "include:text:forum\ninclude:text:enthält:forum\ninclude::contains:forum\ninclude:text:regex:(" );

		$this->assertSame(
			array(
				'include:text:forum'         => 'incomplete',
				'include:text:enthält:forum' => 'operator',
				'include::contains:forum'    => 'incomplete',
				'include:text:regex:('       => 'regex',
			),
			$reasons
		);
	}

	/**
	 * A rule on a field the entries do not have must be reported: it never matches.
	 *
	 * @return void
	 */
	public function test_an_unknown_field_is_reported(): void {
		$fields = array( 'text', 'url', 'source_slug', 'author' );

		$this->assertSame( array( 'include:link:contains:/support/' => 'field' ), $this->get_reasons( 'include:link:contains:/support/', $fields ) );

		// without a list of fields there is nothing to compare with.
		$this->assertSame( array(), $this->get_reasons( 'include:link:contains:/support/' ) );
	}

	/**
	 * What is reported must be exactly what the import does not use.
	 *
	 * @return void
	 */
	public function test_reported_lines_are_the_ones_the_parser_drops(): void {
		$rules = "action:text:contains:a\ninclude:text:contains:b\ninclude:text:nope:c\nexclude:text:equals:d\nbroken";

		$this->assertCount( 2, Content_Filters::parse_rules( $rules ) );
		$this->assertCount( 3, Content_Filters::validate( $rules ) );
	}

	/**
	 * The feed provider offers the fields of every entry and its own.
	 *
	 * @return void
	 */
	public function test_the_available_fields_depend_on_the_provider(): void {
		$rss = Providers::get_instance()->get_provider( 'rss' );

		$this->assertSame( array( 'text', 'url', 'source_slug', 'author' ), Content_Filters::get_instance()->get_available_fields( $rss ) );
		$this->assertContains( 'author', Content_Filters::get_instance()->get_available_fields() );
		$this->assertSame( array( 'text', 'url', 'source_slug' ), Content_Filters::get_instance()->get_available_fields( Providers::get_instance()->get_provider( 'github' ) ) );
	}

	/**
	 * Rules which do not work must leave a hint which names them.
	 *
	 * @return void
	 */
	public function test_broken_rules_leave_a_hint(): void {
		$rss = Providers::get_instance()->get_provider( 'rss' );

		$this->assertSame( 2, Content_Filters::get_instance()->report( "action:link:contains:/support/forum/\ninclude:link:contains:x\ninclude:url:contains:/support/topic/", $rss ) );

		$hint = Transients::get_instance()->get_transient_by_name( self::HINT );

		$this->assertTrue( $hint->is_set() );
		$this->assertStringContainsString( '<code>action:link:contains:/support/forum/</code>', $hint->get_message() );
		$this->assertStringContainsString( 'has to start with &quot;include&quot; or &quot;exclude&quot;', $hint->get_message() );
		$this->assertStringContainsString( 'there is no field &quot;link&quot;. Available are: text, url, source_slug, author.', $hint->get_message() );
		$this->assertStringNotContainsString( '/support/topic/', $hint->get_message() );
	}

	/**
	 * Rules which work must not leave a hint.
	 *
	 * @return void
	 */
	public function test_working_rules_leave_no_hint(): void {
		$this->assertSame( 0, Content_Filters::get_instance()->report( 'include:url:contains:/support/topic/', Providers::get_instance()->get_provider( 'rss' ) ) );
		$this->assertFalse( Transients::get_instance()->get_transient_by_name( self::HINT )->is_set() );
	}

	/**
	 * The hint must be gone once it has been shown: it is about the save which just happened.
	 *
	 * @return void
	 */
	public function test_the_hint_is_shown_once(): void {
		Content_Filters::get_instance()->report( 'action:text:contains:a' );

		ob_start();
		Transients::get_instance()->get_transient_by_name( self::HINT )->display();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'action:text:contains:a', $output );
		$this->assertFalse( Transients::get_instance()->get_transient_by_name( self::HINT )->is_set() );
	}

	/**
	 * Saving the site-wide rules must check them, and keep them as entered.
	 *
	 * @return void
	 */
	public function test_saving_the_site_wide_rules_checks_them(): void {
		$this->assertSame( 'action:text:contains:a', Content_Filters::get_instance()->report_site_rules( 'action:text:contains:a' ) );
		$this->assertStringContainsString( 'site-wide', Transients::get_instance()->get_transient_by_name( self::HINT )->get_message() );
	}

	/**
	 * The operator "word" must match the value as a whole word only.
	 *
	 * @return void
	 */
	public function test_the_word_operator_matches_whole_words(): void {
		update_option( Content_Filters::OPTION, 'include:text:word:art' );

		$entry              = new Entry();
		$entry->source_slug = 'rss';

		$entry->text = 'Visited an Art exhibition.';
		$this->assertTrue( Content_Filters::get_instance()->check( $entry, 0 ) );

		$entry->text = 'Went to a party.';
		$this->assertNotTrue( Content_Filters::get_instance()->check( $entry, 0 ) );

		$entry->text = 'Über Kunst (art) gesprochen';
		$this->assertTrue( Content_Filters::get_instance()->check( $entry, 0 ) );
	}
}
