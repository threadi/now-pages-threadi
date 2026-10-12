<?php
/**
 * File containing a stub provider that may exist only once.
 *
 * @package threadi-now-pages
 */

namespace ThreadiNowPages\Tests\Unit\Stubs;

use ThreadiNowPages\NowPages\Entry;
use ThreadiNowPages\Providers\Provider_Base;
use ThreadiNowPages\Providers\Provider_Context;

/**
 * A provider without configuration that may be used only once.
 */
class Stub_Unique_Provider extends Provider_Base {
	/**
	 * Return the provider slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'stub_unique';
	}

	/**
	 * Return the provider label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return 'Stub that exists once';
	}

	/**
	 * Return whether this provider may only be configured once.
	 *
	 * @return bool
	 */
	public function is_unique(): bool {
		return true;
	}

	/**
	 * Return no entries, this provider is only used for configuration tests.
	 *
	 * @param array<string,mixed> $config  The configuration.
	 * @param Provider_Context    $context The context of this run.
	 *
	 * @return array<int,Entry>
	 */
	public function fetch( array $config, Provider_Context $context ): array {
		return array();
	}
}
