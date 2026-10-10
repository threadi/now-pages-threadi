<?php
/**
 * File to test the common provider enablement behavior.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use NowPagesThreadi\Providers\Provider_Interface;
use NowPagesThreadi\Tests\NowPagesTestCase;
use NowPagesThreadi\Tests\Unit\Stubs\Stub_Provider;

/**
 * Object to test Provider_Base::is_enabled().
 */
class ProviderEnabledTest extends NowPagesTestCase {
	/**
	 * Remove the provider option before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( 'now_pages_threadi_provider_stub_enabled' );
	}

	/**
	 * The default state of a provider must be enabled.
	 *
	 * This mirrors the default configured in Provider_Base::add_settings().
	 *
	 * @return void
	 */
	public function test_provider_is_enabled_by_default(): void {
		$provider = new Stub_Provider();

		$this->assertInstanceOf( Provider_Interface::class, $provider );
		$this->assertTrue( $provider->is_enabled() );
	}

	/**
	 * An explicit zero must disable the provider.
	 *
	 * @return void
	 */
	public function test_zero_disables_provider(): void {
		update_option( 'now_pages_threadi_provider_stub_enabled', 0 );

		$this->assertFalse( ( new Stub_Provider() )->is_enabled() );
	}

	/**
	 * An explicit one must enable the provider.
	 *
	 * @return void
	 */
	public function test_one_enables_provider(): void {
		update_option( 'now_pages_threadi_provider_stub_enabled', 1 );

		$this->assertTrue( ( new Stub_Provider() )->is_enabled() );
	}
}
