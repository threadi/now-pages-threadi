<?php
/**
 * File to test the resolution of the display settings.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use NowPagesThreadi\Digests\Display_Settings;
use NowPagesThreadi\Plugin\Settings;
use NowPagesThreadi\Tests\NowPagesTestCase;

/**
 * Object to test Display_Settings.
 */
class DisplaySettingsTest extends NowPagesTestCase {
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
	 * Without any configuration the hard-coded defaults must be returned.
	 *
	 * @return void
	 */
	public function test_defaults_are_used_without_configuration(): void {
		$resolved = Display_Settings::resolve( array() );

		$this->assertFalse( $resolved['show_time'] );
		$this->assertTrue( $resolved['group_by_category'] );
		$this->assertSame( 'ASC', $resolved['order'] );
		$this->assertSame( 6, $resolved['delay_hours'] );
		$this->assertSame( 'default', $resolved['_origin']['show_time'] );
	}

	/**
	 * A global option must beat the default.
	 *
	 * The option holds the string "1", as written by the settings library —
	 * not a boolean. This is what silently broke before.
	 *
	 * @return void
	 */
	public function test_global_option_beats_the_default(): void {
		update_option( 'now_pages_threadi_show_time', '1' );

		$resolved = Display_Settings::resolve( array() );

		$this->assertTrue( $resolved['show_time'] );
		$this->assertSame( 'option', $resolved['_origin']['show_time'] );
	}

	/**
	 * User meta must beat the global option, but only for the targeted user.
	 *
	 * @return void
	 */
	public function test_user_meta_beats_the_global_option(): void {
		update_option( 'now_pages_threadi_show_time', '1' );

		$user_id = self::factory()->user->create();

		update_user_meta( $user_id, 'now_pages_threadi_show_time', 0 );

		// with a user in context the profile wins.
		$resolved = Display_Settings::resolve( array( 'user_id' => $user_id ) );

		$this->assertFalse( $resolved['show_time'] );
		$this->assertSame( 'user_meta', $resolved['_origin']['show_time'] );

		// without a user the global option still applies.
		$resolved = Display_Settings::resolve( array() );

		$this->assertTrue( $resolved['show_time'] );
		$this->assertSame( 'option', $resolved['_origin']['show_time'] );
	}

	/**
	 * An empty user meta means "inherit" and must not override anything.
	 *
	 * @return void
	 */
	public function test_empty_user_meta_inherits_the_global_option(): void {
		update_option( 'now_pages_threadi_show_time', '1' );

		$user_id = self::factory()->user->create();

		update_user_meta( $user_id, 'now_pages_threadi_show_time', '' );

		$resolved = Display_Settings::resolve( array( 'user_id' => $user_id ) );

		$this->assertTrue( $resolved['show_time'] );
		$this->assertSame( 'option', $resolved['_origin']['show_time'] );
	}

	/**
	 * An explicit argument must beat everything else.
	 *
	 * @return void
	 */
	public function test_explicit_argument_beats_everything(): void {
		update_option( 'now_pages_threadi_show_time', '1' );

		$user_id = self::factory()->user->create();

		update_user_meta( $user_id, 'now_pages_threadi_show_time', 1 );

		$resolved = Display_Settings::resolve(
			array(
				'user_id'   => $user_id,
				'show_time' => false,
			)
		);

		$this->assertFalse( $resolved['show_time'] );
		$this->assertSame( 'explicit', $resolved['_origin']['show_time'] );
	}

	/**
	 * The order must only ever be one of the two values WP_Query accepts.
	 *
	 * The value goes straight into WP_Query, so anything else has to be
	 * normalized away rather than passed through.
	 *
	 * @return void
	 */
	public function test_order_is_limited_to_asc_and_desc(): void {
		update_option( 'now_pages_threadi_order', 'desc' );

		$this->assertSame( 'DESC', Display_Settings::resolve( array() )['order'] );

		update_option( 'now_pages_threadi_order', 'RAND(); DROP TABLE' );

		$this->assertSame( 'ASC', Display_Settings::resolve( array() )['order'] );
	}

	/**
	 * A negative delay must be clamped to zero instead of shifting the range
	 * into the future.
	 *
	 * @return void
	 */
	public function test_delay_hours_is_never_negative(): void {
		update_option( 'now_pages_threadi_delay_hours', '-5' );

		$this->assertSame( 5, Display_Settings::resolve( array() )['delay_hours'] );

		$resolved = Display_Settings::resolve( array( 'delay_hours' => -5 ) );

		$this->assertSame( 5, $resolved['delay_hours'] );
	}

	/**
	 * Arguments the resolver does not know must survive untouched.
	 *
	 * Digest_Builder passes period, format and language through the same
	 * array, and the formats read them afterwards.
	 *
	 * @return void
	 */
	public function test_unknown_arguments_are_passed_through(): void {
		$resolved = Display_Settings::resolve(
			array(
				'period'   => 'week',
				'format'   => 'verbatim',
				'language' => 'de_DE',
			)
		);

		$this->assertSame( 'week', $resolved['period'] );
		$this->assertSame( 'verbatim', $resolved['format'] );
		$this->assertSame( 'de_DE', $resolved['language'] );
	}

	/**
	 * Every setting key must have a label for its origin, so the diagnostics
	 * panel never falls back to the raw key.
	 *
	 * @return void
	 */
	public function test_every_origin_has_a_label(): void {
		$labels   = Display_Settings::origin_labels();
		$resolved = Display_Settings::resolve( array() );

		foreach ( $resolved['_origin'] as $key => $origin ) {
			$this->assertArrayHasKey( $origin, $labels, 'No label for the origin of ' . $key );
		}
	}

	/**
	 * Boolean defaults must translate into the integers the library expects.
	 *
	 * @return void
	 */
	public function test_boolean_defaults_are_converted_for_the_library(): void {
		$this->assertSame( 0, Settings::get_instance()->get_display_default( 'show_time' ) );
		$this->assertSame( 1, Settings::get_instance()->get_display_default( 'group_by_category' ) );
		$this->assertSame( 6, Settings::get_instance()->get_display_default( 'delay_hours' ) );
		$this->assertSame( 'ASC', Settings::get_instance()->get_display_default( 'order' ) );
	}
}
