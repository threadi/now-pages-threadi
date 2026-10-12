<?php
/**
 * File containing stub providers for the duplicate detection.
 *
 * Three shapes are needed: one that identifies itself by a single field,
 * one that needs two fields together, and one that may exist only once.
 *
 * @package threadi-now-pages
 */

namespace ThreadiNowPages\Tests\Unit\Stubs;

use ThreadiNowPages\NowPages\Entry;
use ThreadiNowPages\Providers\Provider_Base;
use ThreadiNowPages\Providers\Provider_Context;

/**
 * A provider identified by a single configuration field.
 */
class Stub_Identified_Provider extends Provider_Base {
	/**
	 * Return the provider slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'stub_identified';
	}

	/**
	 * Return the provider label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return 'Stub with identity';
	}

	/**
	 * Return the configuration fields.
	 *
	 * @return array<string,mixed>
	 */
	public function get_config_fields(): array {
		return array(
			'stub_account' => array(
				'label'    => 'Account',
				'type'     => 'text',
				'required' => true,
			),
			'stub_limit'   => array(
				'label'   => 'Limit',
				'type'    => 'number',
				'default' => 5,
			),
		);
	}

	/**
	 * Return the configuration fields which identify a source.
	 *
	 * @return array<int,string>
	 */
	public function get_identifying_fields(): array {
		return array( 'stub_account' );
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
