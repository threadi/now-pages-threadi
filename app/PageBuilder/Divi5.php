<?php
/**
 * This file contains the support for Divi 5.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ET\Builder\Framework\DependencyManagement\DependencyTree;
use ET\Builder\Framework\Route\RESTRoute;
use ThreadiNowPages\Helper\Plugin;
use ThreadiNowPages\Helper\Singleton_Trait;
use ThreadiNowPages\PageBuilder\Divi5\Digest\Digest;
use ThreadiNowPages\PageBuilder\Divi5\Digest\Rest;

/**
 * Registers and handles the support for Divi 5.
 */
class Divi5 extends PageBuilder_Base {
	use Singleton_Trait;

	/**
	 * The result of the check whether this page builder is active, per request.
	 *
	 * @var bool|null
	 */
	private static ?bool $is_active = null;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'divi5';
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// bail if Divi 5 is not enabled.
		if ( ! $this->is_active() ) {
			return;
		}

		// add Divi 5 modules.
		add_action( 'divi_visual_builder_assets_before_enqueue_scripts', array( $this, 'enqueue_modules' ) );
		add_action( 'init', array( $this, 'register_rest_routes' ) );
		add_action( 'divi_module_library_modules_dependency_tree', array( $this, 'add_module_dependencies' ) );
	}

	/**
	 * Enqueue the Divi 5 module script.
	 *
	 * This script is built on release and contains all modules.
	 *
	 * @return void
	 */
	public function enqueue_modules(): void {
		// bail if the Divi 5 class is not available.
		if ( ! class_exists( '\ET\Builder\VisualBuilder\Assets\PackageBuildManager' ) ) {
			return;
		}

		\ET\Builder\VisualBuilder\Assets\PackageBuildManager::register_package_build(
			array(
				'name'    => 'threadi-now-pages-divi5',
				'version' => '1.0.0',
				'script'  => array(
					'src'                => Plugin::get_plugin_url() . 'divi5/build/index.js',
					'deps'               => array(
						'divi-module-library',
						'divi-vendor-wp-hooks',
						'react',
						'jquery-core',
						'divi-rest',
						'wp-hooks',
					),
					'enqueue_top_window' => false,
					'enqueue_app_window' => true,
				),
			)
		);
	}

	/**
	 * Register the REST routes for each module.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		// bail if the Divi 5 class is not available.
		if ( ! class_exists( '\ET\Builder\Framework\Route\RESTRoute' ) ) {
			return;
		}

		$route = new RESTRoute( 'threadi-now-pages-divi5/v1' );

		// add the route for each module.
		$route->prefix( '/module-data' )->get( '/digest', Rest::class );
	}

	/**
	 * Add module dependencies.
	 *
	 * @param DependencyTree $dependency_tree The dependency tree object.
	 *
	 * @return void
	 */
	public function add_module_dependencies( DependencyTree $dependency_tree ): void {
		$dependency_tree->add_dependency( new Digest() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_page_content(): string {
		$version = defined( 'ET_BUILDER_VERSION' ) ? ET_BUILDER_VERSION : '5.13';
		return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"' . $version . '"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}},"flexColumnStructure":{"desktop":{"value":"equal-columns_1"}}},"decoration":{"layout":{"desktop":{"value":{"flexWrap":"nowrap"}}}}},"builderVersion":"' . $version . '"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}},"decoration":{"sizing":{"desktop":{"value":{"flexType":"24_24"}}}}},"builderVersion":"' . $version . '"} -->
<!-- wp:threadi-now-pages-divi5/digest {"digest":{"advanced":{"overrideDisplay":{"desktop":{"value":"off"}},"showPeriod":{"desktop":{"value":"off"}},"groupByCategory":{"desktop":{"value":"off"}},"period":{"desktop":{"value":"' . get_option( 'threadi_now_pages_frontend_period', 'live' ) . '"}},"format":{"desktop":{"value":"verbatim"}}}},"builderVersion":"' . $version . '"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
	}

	/**
	 * Return post meta a fresh now page needs for this page builder.
	 *
	 * @return array<string,mixed>
	 */
	public function get_page_meta(): array {
		return array(
			'_et_pb_use_builder'                  => 'on',
			// this is what separates Divi 5 from Divi 4 on a page.
			'_et_pb_use_divi_5'                   => 'on',
			// skip the "start from scratch or use a layout" dialog.
			'_et_pb_show_page_creation'           => 'off',
			'_et_pb_page_layout'                  => 'et_right_sidebar',
			'_et_pb_side_nav'                     => 'off',
			'_et_pb_post_hide_nav'                => 'default',
			'_et_pb_page_gutter_width_is_default' => 1,
		);
	}

	/**
	 * Check if Divi 5.0 or newer is active.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		// return the result of the check in this request.
		if ( is_bool( self::$is_active ) ) {
			return self::$is_active;
		}

		// run the check.
		self::$is_active = $this->check_active();

		// return the result.
		return self::$is_active;
	}

	/**
	 * Run the check if Divi 5.0 or newer is active.
	 *
	 * @return bool
	 */
	private function check_active(): bool {
		// first check for the plugin.
		if ( Plugin::is_plugin_active( 'divi-builder/divi-builder.php' ) ) {
			// get the plugin version: from its constant, if the plugin is already loaded, otherwise from its file header.
			if ( defined( 'ET_BUILDER_PLUGIN_VERSION' ) ) {
				$plugin_version = (string) constant( 'ET_BUILDER_PLUGIN_VERSION' );
			} else {
				$plugin_data    = get_file_data( WP_PLUGIN_DIR . '/divi-builder/divi-builder.php', array( 'Version' => 'Version' ) );
				$plugin_version = (string) $plugin_data['Version'];
			}
			if ( '' !== $plugin_version && version_compare( $plugin_version, '5.0.0', '>=' ) ) {
				return true;
			}
		}

		// check for the theme.
		$theme = wp_get_theme();
		if ( 'Divi' === $theme->get( 'Name' ) ) {
			$version = substr( (string) $theme->get( 'Version' ), 0, 5 );
			return version_compare( $version, '5.0.0', '>=' );
		}

		// check for the parent theme.
		if ( $theme->parent() && 'Divi' === $theme->parent()->get( 'Name' ) ) {
			$version = substr( (string) $theme->parent()->get( 'Version' ), 0, 5 );
			return version_compare( $version, '5.0.0', '>=' );
		}

		// otherwise return false.
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Divi 5', 'threadi-now-pages' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 20;
	}
}
