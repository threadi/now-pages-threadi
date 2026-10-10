<?php
/**
 * File containing a stub provider for the tests.
 *
 * Lets the import be tested end to end without any network access: the
 * entries it returns are set from the test itself.
 *
 * @package now-pages-threadi
 */

namespace NowPagesThreadi\Tests\Unit\Stubs;

use NowPagesThreadi\NowPages\Entry;
use NowPagesThreadi\Providers\Provider_Base;
use NowPagesThreadi\Providers\Provider_Context;

/**
 * A provider that returns whatever the test told it to return.
 */
class Stub_Provider extends Provider_Base {
	/**
	 * The entries fetch() will return.
	 *
	 * @var array<int,Entry>
	 */
	public static array $entries = array();

	/**
	 * The config fetch() was called with, for assertions.
	 *
	 * @var array<string,mixed>
	 */
	public static array $received_config = array();

	/**
	 * How often fetch() was called.
	 *
	 * @var int
	 */
	public static int $fetch_count = 0;

	/**
	 * Reset the stub between tests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$entries         = array();
		self::$received_config = array();
		self::$fetch_count     = 0;
	}

	/**
	 * Return the provider slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'stub';
	}

	/**
	 * Return the provider label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return 'Stub provider';
	}

	/**
	 * Return the configuration fields.
	 *
	 * @return array<string,mixed>
	 */
	public function get_config_fields(): array {
		return array(
			'stub_url'   => array(
				'label'    => 'Stub URL',
				'type'     => 'url',
				'required' => true,
			),
			'stub_limit' => array(
				'label'   => 'Limit',
				'type'    => 'number',
				'default' => 5,
			),
		);
	}

	/**
	 * Never called: this provider is push-based, nothing is ever pulled.
	 *
	 *
	 * @param array<string,mixed> $config  The configuration.
	 * @param Provider_Context    $context The context of this run.
	 *
	 * @return array<string,Entry>
	 */
	public function fetch( array $config, Provider_Context $context): array {
		++self::$fetch_count;

		self::$received_config = $config;

		return self::$entries;
	}
}
