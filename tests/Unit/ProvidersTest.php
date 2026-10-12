<?php
/**
 * File to test provider registration and enablement.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Tests\Unit;

use ThreadiNowPages\Providers\Provider_Interface;
use ThreadiNowPages\Providers\Providers;
use ThreadiNowPages\Tests\NowPagesTestCase;
use ThreadiNowPages\Tests\Unit\Stubs\Stub_Provider;

/**
 * Object to test Providers.
 */
class ProvidersTest extends NowPagesTestCase {
	/**
	 * Reset the provider enablement option before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( 'threadi_now_pages_provider_stub_enabled' );
		Providers::get_instance()->register_provider( new Stub_Provider() );
	}

	/**
	 * A registered provider must remain available through the complete registry.
	 *
	 * @return void
	 */
	public function test_registered_provider_is_returned_by_the_registry(): void {
		$provider = Providers::get_instance()->get_provider( 'stub' );

		$this->assertInstanceOf( Provider_Interface::class, $provider );
		$this->assertSame( 'stub', $provider->get_slug() );
	}

	/**
	 * Disabling a provider must not remove it from the registry.
	 *
	 * Existing source configurations still need access to their provider.
	 *
	 * @return void
	 */
	public function test_disabled_provider_remains_registered(): void {
		update_option( 'threadi_now_pages_provider_stub_enabled', 0 );

		$this->assertArrayHasKey( 'stub', Providers::get_instance()->get_providers() );
		$this->assertInstanceOf( Provider_Interface::class, Providers::get_instance()->get_provider( 'stub' ) );
	}

	/**
	 * The enabled-provider view must contain enabled providers only.
	 *
	 * @return void
	 */
	public function test_enabled_provider_view_excludes_disabled_providers(): void {
		update_option( 'threadi_now_pages_provider_stub_enabled', 0 );

		$providers = Providers::get_instance()->get_enabled_providers();

		$this->assertArrayNotHasKey( 'stub', $providers );
	}

	/**
	 * Enabling a provider must make it available in the enabled-provider view.
	 *
	 * @return void
	 */
	public function test_enabled_provider_view_contains_enabled_providers(): void {
		update_option( 'threadi_now_pages_provider_stub_enabled', 1 );

		$providers = Providers::get_instance()->get_enabled_providers();

		$this->assertArrayHasKey( 'stub', $providers );
		$this->assertSame( 'stub', $providers['stub']->get_slug() );
	}
}
