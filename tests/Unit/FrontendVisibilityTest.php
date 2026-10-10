<?php
/**
 * File to test when the now page is publicly visible.
 *
 * This is logic that looks like a bug from the outside: somebody reports
 * "/now is a 404" and the cause is an unfinished setup. The tests state
 * that this is intentional.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use NowPagesThreadi\NowPages\Frontend;
use NowPagesThreadi\Tests\NowPagesTestCase;

/**
 * Object to test Frontend::is_public().
 */
class FrontendVisibilityTest extends NowPagesTestCase {
	/**
	 * Start every test with both routes enabled.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( 'now_pages_threadi_frontend_global_url', 1 );
		update_option( 'now_pages_threadi_frontend_user_url', 1 );

		$this->set_setup_completed( true );
	}

	/**
	 * Remove our filter after every test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'now_pages_threadi_setup_is_completed' );
		remove_all_filters( 'now_pages_threadi_is_public' );

		parent::tear_down();
	}

	/**
	 * Pretend the setup has or has not been completed.
	 *
	 * The real marker lives in the setup library, which needs a full REST
	 * round trip to be set — the filter is the supported way in.
	 *
	 * @param bool $completed True to mark the setup as completed.
	 *
	 * @return void
	 */
	private function set_setup_completed( bool $completed ): void {
		remove_all_filters( 'now_pages_threadi_setup_is_completed' );

		add_filter(
			'now_pages_threadi_setup_is_completed',
			static function () use ( $completed ): bool {
				return $completed;
			}
		);
	}

	/**
	 * With the setup done and the route enabled the page is public.
	 *
	 * @return void
	 */
	public function test_a_finished_setup_makes_the_page_public(): void {
		$this->assertTrue( Frontend::get_instance()->is_public() );
	}

	/**
	 * Without a finished setup the page stays private.
	 *
	 * A fresh installation should not publish a page nobody configured.
	 *
	 * @return void
	 */
	public function test_an_unfinished_setup_keeps_the_page_private(): void {
		$this->set_setup_completed( false );

		$this->assertFalse( Frontend::get_instance()->is_public() );
	}

	/**
	 * A disabled global route makes the page private.
	 *
	 * @return void
	 */
	public function test_a_disabled_route_makes_the_page_private(): void {
		update_option( 'now_pages_threadi_frontend_global_url', 0 );

		$this->assertFalse( Frontend::get_instance()->is_public() );
	}

	/**
	 * The author route is judged by its own setting.
	 *
	 * @return void
	 */
	public function test_the_author_route_has_its_own_setting(): void {
		update_option( 'now_pages_threadi_frontend_user_url', 0 );

		$this->assertTrue( Frontend::get_instance()->is_public() );
		$this->assertFalse( Frontend::get_instance()->is_public( true ) );
	}

	/**
	 * A missing option keeps the author route closed, like the default of its setting.
	 *
	 * @return void
	 */
	public function test_a_missing_option_keeps_the_author_route_closed(): void {
		delete_option( 'now_pages_threadi_frontend_user_url' );

		$this->assertFalse( Frontend::get_instance()->is_public( true ) );
	}

	/**
	 * Disabling the global route must not disable the author route.
	 *
	 * @return void
	 */
	public function test_the_routes_are_independent(): void {
		update_option( 'now_pages_threadi_frontend_global_url', 0 );

		$this->assertFalse( Frontend::get_instance()->is_public() );
		$this->assertTrue( Frontend::get_instance()->is_public( true ) );
	}

	/**
	 * The decision must be filterable.
	 *
	 * An intranet installation may want the page never to be public.
	 *
	 * @return void
	 */
	public function test_the_decision_is_filterable(): void {
		add_filter( 'now_pages_threadi_is_public', '__return_false' );

		$this->assertFalse( Frontend::get_instance()->is_public() );
	}

	/**
	 * The filter must know which route is meant.
	 *
	 * @return void
	 */
	public function test_the_filter_knows_the_route(): void {
		add_filter(
			'now_pages_threadi_is_public',
			static function ( bool $is_public, bool $for_author ): bool {
				// close the author route, keep the global one.
				if ( $for_author ) {
					return false;
				}

				return $is_public;
			},
			10,
			2
		);

		$this->assertTrue( Frontend::get_instance()->is_public() );
		$this->assertFalse( Frontend::get_instance()->is_public( true ) );
	}

	/**
	 * A visitor must get a 404 while the page is not public.
	 *
	 * @return void
	 */
	public function test_a_visitor_gets_a_404(): void {
		$this->set_setup_completed( false );

		wp_set_current_user( 0 );

		$this->go_to( home_url( '/?' . Frontend::QUERY_VAR . '=1' ) );

		Frontend::get_instance()->prepare();

		$this->assertTrue( is_404() );
		$this->assertSame( '', Frontend::get_instance()->get_content() );
	}

	/**
	 * The author route does not tell a visitor which users may edit entries.
	 *
	 * @return void
	 */
	public function test_the_author_route_hides_users_without_published_entries(): void {
		$editor = self::factory()->user->create(
			array(
				'role'          => 'editor',
				'user_nicename' => 'np-editor',
			)
		);

		wp_set_current_user( 0 );

		$this->go_to( home_url( '/?' . Frontend::QUERY_VAR . '=1&author=' . $editor ) );

		Frontend::get_instance()->prepare();

		$this->assertTrue( is_404() );
		$this->assertSame( '', Frontend::get_instance()->get_content() );
	}

	/**
	 * An administrator must see the page before it is public.
	 *
	 * Otherwise nobody could check the result before finishing the setup.
	 *
	 * @return void
	 */
	public function test_an_administrator_sees_the_page(): void {
		$this->set_setup_completed( false );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		self::factory()->post->create(
			array(
				'post_type'     => 'np_threadi_entry',
				'post_status'   => 'publish',
				'post_title'    => '',
				'post_content'  => 'An entry',
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 2 * HOUR_IN_SECONDS ) ),
			)
		);

		$this->go_to( home_url( '/?' . Frontend::QUERY_VAR . '=1' ) );

		Frontend::get_instance()->prepare();

		$this->assertFalse( is_404() );
		$this->assertNotSame( '', Frontend::get_instance()->get_content() );
	}

	/**
	 * An administrator must be told why nobody else sees the page.
	 *
	 * @return void
	 */
	public function test_an_administrator_is_told_why(): void {
		$this->set_setup_completed( false );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->go_to( home_url( '/?' . Frontend::QUERY_VAR . '=1' ) );

		Frontend::get_instance()->prepare();

		$this->assertStringContainsString( 'now-pages-threadi-not-public', Frontend::get_instance()->get_content() );
	}

	/**
	 * The address must exist on a new website, where the setting has never been saved.
	 *
	 * The activation does not store this setting. Without a matching default the
	 * now page would be public, but its address would not be known to WordPress:
	 * a setup without a page ended in a 404.
	 *
	 * @return void
	 */
	public function test_the_address_exists_before_the_settings_are_saved(): void {
		global $wp_rewrite;

		delete_option( 'now_pages_threadi_frontend_global_url' );

		$wp_rewrite->extra_rules_top = array();

		Frontend::get_instance()->register_rewrites();

		$this->assertTrue( Frontend::get_instance()->is_public() );
		$this->assertArrayHasKey( '^now/?$', $wp_rewrite->extra_rules_top );
	}

	/**
	 * A switched off now page must not have an address.
	 *
	 * @return void
	 */
	public function test_a_switched_off_now_page_has_no_address(): void {
		global $wp_rewrite;

		update_option( 'now_pages_threadi_frontend_global_url', 0 );

		$wp_rewrite->extra_rules_top = array();

		Frontend::get_instance()->register_rewrites();

		$this->assertArrayNotHasKey( '^now/?$', $wp_rewrite->extra_rules_top );
	}

	/**
	 * Outside a now request nothing must happen at all.
	 *
	 * @return void
	 */
	public function test_outside_a_now_request_nothing_happens(): void {
		$this->go_to( home_url( '/' ) );

		$this->assertFalse( Frontend::get_instance()->is_now_request() );
	}
}
