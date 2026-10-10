<?php
/**
 * This file contains the base for any preset.
 *
 * Holds the defaults, so a simple preset only has to name itself and its
 * provider.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Presets;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Providers\Provider_Interface;
use NowPagesThreadi\Providers\Providers;

/**
 * Object which holds the shared behavior of all presets.
 */
abstract class Preset_Base implements Preset_Interface {
	/**
	 * Return a short description shown on the card.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return '';
	}

	/**
	 * Return the icon of this preset as inline SVG.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return '';
	}

	/**
	 * Return the group this preset belongs to.
	 *
	 * @return string
	 */
	public function get_group(): string {
		return 'other';
	}

	/**
	 * Return the questions asked before the provider can be configured.
	 *
	 * @return array<string,mixed>
	 */
	public function get_setup_fields(): array {
		return array();
	}

	/**
	 * Turn the given answers into a configuration for the provider.
	 *
	 * @param array<string,string> $setup The answers to get_setup_fields().
	 *
	 * @return array<string,mixed>
	 */
	public function get_config( array $setup ): array {
		return array();
	}

	/**
	 * Return whether this preset can be offered on this website.
	 *
	 * A preset is only useful if its provider exists and is enabled - the
	 * administrator may have switched it off in the settings.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		$provider = Providers::get_instance()->get_provider( $this->get_provider_slug() );

		if ( ! $provider instanceof Provider_Interface ) {
			return false;
		}

		if ( ! $provider->is_enabled() ) {
			return false;
		}

		return $provider->is_available();
	}
}
