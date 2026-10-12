<?php
/**
 * File to test the normalization of languages.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Tests\Unit;

use ThreadiNowPages\Helper\Languages;
use ThreadiNowPages\Tests\NowPagesTestCase;

/**
 * Object to test Languages.
 */
class LanguagesTest extends NowPagesTestCase {
	/**
	 * Every form of a locale must end up as language and region.
	 *
	 * @return void
	 */
	public function test_locales_are_normalized_to_language_and_region(): void {
		$this->assertSame( 'de_DE', Languages::normalize_locale( 'de_DE' ) );
		$this->assertSame( 'de_DE', Languages::normalize_locale( 'de_DE_formal' ) );
		$this->assertSame( 'de_DE', Languages::normalize_locale( 'de-de' ) );
		$this->assertSame( 'pt_BR', Languages::normalize_locale( 'pt-BR' ) );
		$this->assertSame( 'en_US', Languages::normalize_locale( 'en-us' ) );
		$this->assertSame( '', Languages::normalize_locale( '' ) );
	}

	/**
	 * A bare language must get the region of the site's locale, with or without intl.
	 *
	 * @return void
	 */
	public function test_bare_language_gets_the_region_of_the_site(): void {
		$this->assertSame( substr( get_locale(), 0, 5 ), Languages::normalize_locale( substr( get_locale(), 0, 2 ) ) );
	}

	/**
	 * The current language must be normalized, also if a filter returns a bare language.
	 *
	 * @return void
	 */
	public function test_current_language_is_normalized(): void {
		$callback = static function () {
			return 'en';
		};
		add_filter( 'threadi_now_pages_current_language', $callback );

		$this->assertSame( 'en_US', Languages::get_current_language() );

		remove_filter( 'threadi_now_pages_current_language', $callback );
	}

	/**
	 * Stored values from before the normalization must still match.
	 *
	 * @return void
	 */
	public function test_legacy_values_match(): void {
		$this->assertTrue( Languages::matches( 'de_DE', 'de_DE_formal' ) );
		$this->assertTrue( Languages::matches( 'de_DE_formal', 'de_DE' ) );
		$this->assertTrue( Languages::matches( 'de', 'de_DE' ) );
		$this->assertFalse( Languages::matches( 'de_CH', 'de_DE' ) );
		$this->assertFalse( Languages::matches( '', 'de_DE' ) );
	}
}
