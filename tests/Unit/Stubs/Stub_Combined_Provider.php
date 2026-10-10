<?php
/**
 * File containing a stub provider identified by two fields together.
 *
 * @package now-pages-threadi
 */

namespace NowPagesThreadi\Tests\Unit\Stubs;

use NowPagesThreadi\NowPages\Entry;
use NowPagesThreadi\Providers\Provider_Base;
use NowPagesThreadi\Providers\Provider_Context;

/**
 * A provider whose identity consists of two fields.
 */
class Stub_Combined_Provider extends Provider_Base {
	/**
	 * Return the provider slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'stub_combined';
	}

	/**
	 * Return the provider label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return 'Stub with combined identity';
	}

	/**
	 * Return the configuration fields.
	 *
	 * @return array<string,mixed>
	 */
	public function get_config_fields(): array {
		return array(
			'stub_calendar' => array(
				'label'    => 'Calendar',
				'type'     => 'url',
				'required' => true,
			),
			'stub_filter'   => array(
				'label'    => 'Filter',
				'type'     => 'text',
				'required' => false,
			),
		);
	}

	/**
	 * Return the configuration fields which identify a source.
	 *
	 * @return array<int,string>
	 */
	public function get_identifying_fields(): array {
		return array( 'stub_calendar', 'stub_filter' );
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
