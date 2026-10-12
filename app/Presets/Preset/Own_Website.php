<?php
/**
 * This file contains the preset for the website itself.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Presets\Preset;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Presets\Preset_Base;

/**
 * Object for the preset of the website itself.
 */
class Own_Website extends Preset_Base {
	/**
	 * Return the preset slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'own_website';
	}

	/**
	 * Return the preset label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'This website', 'threadi-now-pages' );
	}

	/**
	 * Return a short description shown on the card.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'What you publish here. Works right away - no account, no key, no external service.', 'threadi-now-pages' );
	}

	/**
	 * Return the group this preset belongs to.
	 *
	 * @return string
	 */
	public function get_group(): string {
		return 'own';
	}

	/**
	 * Return the slug of the provider this preset uses.
	 *
	 * @return string
	 */
	public function get_provider_slug(): string {
		return 'wordpress_site';
	}

	/**
	 * Turn the given answers into a configuration for the provider.
	 *
	 * @param array<string,string> $setup The answers to get_setup_fields().
	 *
	 * @return array<string,mixed>
	 */
	public function get_config( array $setup ): array {
		return array(
			'wp_post_types' => array( 'post' ),
			'wp_author'     => (string) get_current_user_id(),
			'wp_comments'   => 0,
			'wp_max_items'  => 10,
		);
	}

	/**
	 * Return the icon of this preset as inline SVG.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return '<span class="dashicons dashicons-wordpress"></span>';
	}
}
