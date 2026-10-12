<?php
/**
 * This file contains the support for WpBakery.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Helper\Plugin;
use ThreadiNowPages\Helper\Singleton_Trait;
use ThreadiNowPages\PageBuilder\WpBakery\Digest;
use ThreadiNowPages\Plugin\Post_Types;

/**
 * Registers and handles the support for WpBakery.
 */
class WpBakery extends PageBuilder_Base {
	use Singleton_Trait;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'wpbakery';
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// use WpBakery hooks.
		add_action( 'vc_before_init', array( $this, 'register_widgets' ) );
		add_filter( 'vc_settings_exclude_post_type', array( $this, 'remove_cpts' ) );
	}

	/**
	 * Register widgets we support for WpBakery.
	 *
	 * @return void
	 */
	public function register_widgets(): void {
		new Digest();
	}

	/**
	 * Remove our own cpts from list of supported cpt in WpBakery.
	 *
	 * @param array<int,string> $post_types List of post types.
	 *
	 * @return array<int,string>
	 */
	public function remove_cpts( array $post_types ): array {
		return array_merge( $post_types, Post_Types::get_instance()->get_post_type_slugs() );
	}

	/**
	 * Return the content a fresh now page should start with.
	 *
	 * @return string
	 */
	public function get_page_content(): string {
		return '[vc_row][vc_column][threadi_now_pages_digest period="' . get_option( 'threadi_now_pages_frontend_period', 'live' ) . '" format="' . get_option( 'threadi_now_pages_frontend_format', 'verbatim' ) . '" override_display="no"][/vc_column][/vc_row]';
	}

	/**
	 * Return post meta a fresh now page needs for this page builder.
	 *
	 * @return array<string,mixed>
	 */
	public function get_page_meta(): array {
		return array(
			// without this WPBakery shows the classic editor instead of its builder.
			'_wpb_vc_js_status'       => 'true',
			'_wpb_post_custom_layout' => 'default',
		);
	}

	/**
	 * Return whether this component is active (true) or not (false).
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return Plugin::is_plugin_active( 'js_composer_salient/js_composer.php' ) || Plugin::is_plugin_active( 'js_composer/js_composer.php' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'WpBakery', 'threadi-now-pages' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 30;
	}
}
