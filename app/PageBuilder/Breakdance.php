<?php
/**
 * This file contains the support for Breakdance.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Helper\Plugin;
use NowPagesThreadi\Helper\Singleton_Trait;
use function Breakdance\Util\getDirectoryPathRelativeToPluginFolder;
use function Breakdance\ElementStudio\registerSaveLocation;
use function Breakdance\Elements\registerCategory;

/**
 * Registers and handles the support for Breakdance.
 */
class Breakdance extends PageBuilder_Base {
	use Singleton_Trait;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'breakdance';
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// bail if Breakdance is not active.
		if ( ! $this->is_active() ) {
			return;
		}

		// use hooks.
		add_action( 'init', array( $this, 'register_category' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'add_styles' ) );
	}

	/**
	 * Add our custom Elements for Breakdance.
	 *
	 * @return void
	 */
	public function register_elements(): void {
		// bail if the functions of Breakdance are not available.
		if ( ! function_exists( 'Breakdance\ElementStudio\registerSaveLocation' ) || ! function_exists( 'Breakdance\Util\getDirectoryPathRelativeToPluginFolder' ) ) {
			return;
		}

		registerSaveLocation(
			getDirectoryPathRelativeToPluginFolder( untrailingslashit( Plugin::get_plugin_path() ) ) . '/legacy-classes/Breakdance',
			'NowPageBreakdanceElements',
			'element',
			'Now Pages',
			false
		);
	}

	/**
	 * Register the category for our elements.
	 *
	 * @return void
	 */
	public function register_category(): void {
		// bail if the function of Breakdance is not available.
		if ( ! function_exists( 'Breakdance\Elements\registerCategory' ) ) {
			return;
		}

		// register our own category.
		registerCategory(
			'now-pages-threadi',
			__( 'Now Pages', 'now-pages-threadi' )
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Breakdance', 'now-pages-threadi' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 30;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_page_content(): string {
		return '';
	}

	/**
	 * Embed styles for Breakdance.
	 *
	 * @return void
	 */
	public function add_styles(): void {
		// enqueue our own styles.
		wp_enqueue_style(
			'now-pages-threadi-breakdance-elements',
			Plugin::get_plugin_url() . 'css/style.css',
			array(),
			Plugin::get_file_version( Plugin::get_plugin_path() . '/css/style.css' ),
		);
	}

	/**
	 * Return post meta a fresh now page needs for this page builder.
	 *
	 * @return array<string,mixed>
	 */
	public function get_page_meta(): array {
		$tree = array(
			'root'        => array(
				'id'       => 1,
				'data'     => array(
					'type'       => 'root',
					'properties' => array(),
				),
				'children' => array(
					array(
						'id'        => 107,
						'data'      => array(
							'type'       => 'EssentialElements\\Section',
							'properties' => array(
								'design' => array(
									'layout_v2' => array(
										'layout' => 'vertical',
									),
								),
							),
						),
						'children'  => array(
							array(
								'id'        => 108,
								'data'      => array(
									'type'       => 'NowPageBreakdanceElements\\Digest',
									'properties' => array(
										'content' => array(
											'digest' => array(
												'period' => get_option( 'now_pages_threadi_frontend_period', 'live' ),
												'format' => get_option( 'now_pages_threadi_frontend_format', 'verbatim' ),
												'override_display' => false,
											),
										),
									),
								),
								'children'  => array(),
								'_parentId' => 107,
							),
						),
						'_parentId' => 1,
					),
				),
			),
			'_nextNodeId' => 109,
			'status'      => 'exported',
		);

		// the tree is JSON inside JSON: breakdance stores the encoded tree as
		// a string value of another object.
		return array(
			'_breakdance_data' => wp_json_encode(
				array(
					'tree_json_string' => wp_json_encode( $tree ),
				)
			),
		);
	}

	/**
	 * Check if Breakdance and its necessary plugins are active.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return Plugin::is_plugin_active( 'breakdance/plugin.php' );
	}

	/**
	 * Register our elements before Breakdance reads its save locations.
	 *
	 * Breakdance loads the elements of all save locations while it starts, which
	 * happens before "plugins_loaded".
	 *
	 * @return void
	 */
	public function early_init(): void {
		// bail if Breakdance is not active.
		if ( ! $this->is_active() ) {
			return;
		}

		// register the elements directly, if Breakdance has already been loaded.
		if ( did_action( 'breakdance_loaded' ) > 0 ) {
			$this->register_elements();
			return;
		}

		add_action( 'breakdance_loaded', array( $this, 'register_elements' ), 9 );
	}
}
