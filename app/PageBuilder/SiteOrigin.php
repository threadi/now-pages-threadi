<?php
/**
 * This file contains the support for SiteOrigin.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Helper\Plugin;
use ThreadiNowPages\Helper\Singleton_Trait;
use ThreadiNowPages\Plugin\Post_Types;

/**
 * Registers and handles the support for SiteOrigin.
 */
class SiteOrigin extends PageBuilder_Base {
	use Singleton_Trait;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'siteorigin';
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// add support for this page builder.
		add_filter( 'siteorigin_widgets_widget_folders', array( $this, 'register_widgets' ) );
		add_filter( 'siteorigin_widgets_active_widgets', array( $this, 'enable_widgets' ) );
		add_filter( 'siteorigin_panels_settings_enabled_post_types', array( $this, 'remove_cpts' ) );
	}

	/**
	 * Register SiteOrigin widgets folder.
	 *
	 * @param array<int,string> $folders List of folders where widgets are reside.
	 *
	 * @return array<int,string>
	 */
	public function register_widgets( array $folders ): array {
		$folders[] = Plugin::get_plugin_path() . 'app/PageBuilder/SiteOrigin/';

		// return folders.
		return $folders;
	}

	/**
	 * Enable our own widgets.
	 *
	 * @param array<string,mixed> $active_widgets List of active widgets.
	 * @return array<string,mixed>
	 */
	public function enable_widgets( array $active_widgets ): array {
		$active_widgets['Digest'] = true;
		return $active_widgets;
	}

	/**
	 * Remove our own cpt from list of supported cpt in SiteOrigin.
	 *
	 * @param array<string,string> $post_types List of post types (slug => label).
	 *
	 * @return array<string,string>
	 */
	public function remove_cpts( array $post_types ): array {
		return array_diff_key( $post_types, array_flip( Post_Types::get_instance()->get_post_type_slugs() ) );
	}

	/**
	 * Return the content a fresh now page should start with.
	 *
	 * @return string
	 */
	public function get_page_content(): string {
		$panels_data = array(
			'widgets'    => array(
				array(
					'period'           => get_option( 'threadi_now_pages_frontend_period', 'live' ),
					'format'           => get_option( 'threadi_now_pages_frontend_format', 'verbatim' ),
					'override_display' => 'no',
					'panels_info'      => array(
						'class'     => 'ThreadiNowPages\\PageBuilder\\SiteOrigin\\Digest\\Digest',
						'grid'      => 0,
						'cell'      => 0,
						'id'        => 0,
						'widget_id' => wp_generate_uuid4(),
						'style'     => array(),
					),
				),
			),
			'grids'      => array(
				array(
					'cells' => 1,
					'style' => array(),
				),
			),
			'grid_cells' => array(
				array(
					'grid'   => 0,
					'index'  => 0,
					'weight' => 1,
					'style'  => array(),
				),
			),
		);

		return '<!-- wp:siteorigin-panels/layout-block ' . wp_json_encode( array( 'panelsData' => $panels_data ) ) . ' /-->';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'SiteOrigin', 'threadi-now-pages' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 30;
	}

	/**
	 * Return whether this page builder is active on this website.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return Plugin::is_plugin_active( 'siteorigin-panels/siteorigin-panels.php' );
	}
}
