<?php
/**
 * This file contains the WordPress.org profile preset.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Presets\Preset;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Presets\Preset_Base;

/**
 * Object for the WordPress.org profile preset.
 */
class WordPress_Org extends Preset_Base {
	/**
	 * Return the preset slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'wordpress_org';
	}

	/**
	 * Return the preset label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'WordPress.org profile', 'now-pages-threadi' );
	}

	/**
	 * Return a short description shown on the card.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Your activity in the WordPress project: forum replies, plugin releases, contributions.', 'now-pages-threadi' );
	}

	/**
	 * Return the group this preset belongs to.
	 *
	 * @return string
	 */
	public function get_group(): string {
		return 'code';
	}

	/**
	 * Return the slug of the provider this preset uses.
	 *
	 * @return string
	 */
	public function get_provider_slug(): string {
		return 'rss';
	}

	/**
	 * Return the questions asked before the provider can be configured.
	 *
	 * @return array<string,mixed>
	 */
	public function get_setup_fields(): array {
		return array(
			'wporg_username' => array(
				'label'       => __( 'Username', 'now-pages-threadi' ),
				'type'        => 'text',
				'required'    => true,
				/* translators: %1$s will be replaced by a URL. */
				'description' => sprintf( __( 'Go to <a href="%1$s" target="_blank">%1$s</a>, and if you are logged in, you will be redirected to your profile. There, you will find your username in the URL.', 'now-pages-threadi' ), 'https://profiles.wordpress.org' ),
				'placeholder' => 'your-wp-org-username',
			),
		);
	}

	/**
	 * Turn the given answers into a configuration for the provider.
	 *
	 * @param array<string,string> $setup The answers to get_setup_fields().
	 *
	 * @return array<string,mixed>
	 */
	public function get_config( array $setup ): array {
		$username = sanitize_user( trim( ! empty( $setup['wporg_username'] ) ? $setup['wporg_username'] : '' ) );

		// bail if the answer is unusable.
		if ( '' === $username ) {
			return array();
		}

		return array(
			'feed_url'  => 'https://profiles.wordpress.org/' . rawurlencode( $username ) . '/feed/',
			'max_items' => 10,
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
