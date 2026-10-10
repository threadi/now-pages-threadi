<?php
/**
 * File to test the signature of content filter rules.
 *
 * The signature tells whether two lists of rules filter the same way. It
 * decides whether two sources of the same feed are duplicates.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use NowPagesThreadi\NowPages\Content_Filters;
use NowPagesThreadi\Tests\NowPagesTestCase;

/**
 * Object to test Content_Filters::get_signature().
 */
class ContentFiltersSignatureTest extends NowPagesTestCase {
	/**
	 * No rules, and nothing but comments or invalid lines, are the same: nothing is filtered.
	 *
	 * @return void
	 */
	public function test_no_valid_rule_is_an_empty_signature(): void {
		$this->assertSame( '', Content_Filters::get_signature( '' ) );
		$this->assertSame( '', Content_Filters::get_signature( "# only a comment\n\nnot a rule" ) );
	}

	/**
	 * How the rules are written must not matter.
	 *
	 * @return void
	 */
	public function test_order_and_spelling_do_not_matter(): void {
		$one = Content_Filters::get_signature( "include:text:contains:forum\nexclude:text:contains:spam" );
		$two = Content_Filters::get_signature( " Exclude:text:CONTAINS:spam \r\n# comment\ninclude:text:contains:forum\ninclude:text:contains:forum" );

		$this->assertNotSame( '', $one );
		$this->assertSame( $one, $two );
	}

	/**
	 * What the rules look for must matter, including upper and lower case.
	 *
	 * @return void
	 */
	public function test_other_values_are_other_signatures(): void {
		$this->assertNotSame(
			Content_Filters::get_signature( 'include:text:contains:/support/' ),
			Content_Filters::get_signature( 'include:text:contains:translate.wordpress.org' )
		);
		$this->assertNotSame(
			Content_Filters::get_signature( 'include:text:regex:^Forum' ),
			Content_Filters::get_signature( 'include:text:regex:^forum' )
		);
		$this->assertNotSame(
			Content_Filters::get_signature( 'include:text:contains:forum' ),
			Content_Filters::get_signature( 'exclude:text:contains:forum' )
		);
	}
}
