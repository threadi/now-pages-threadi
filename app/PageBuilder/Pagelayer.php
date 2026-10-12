<?php
/**
 * This file contains the support for Pagelayer.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Helper\Plugin;
use ThreadiNowPages\Helper\Singleton_Trait;
use ThreadiNowPages\PageBuilder\Pagelayer\Digest;

/**
 * Registers and handles the support for Pagelayer.
 *
 * Pagelayer knows its widgets as shortcodes, but stores pages as blocks
 * ("wp:pagelayer/<tag>") in the post content. Its own widgets are global
 * functions (pagelayer_sc_<tag>), which is why our widget hooks into the
 * filter "pagelayer_do_shortcode_el" instead.
 */
class Pagelayer extends PageBuilder_Base {
	use Singleton_Trait;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'pagelayer';
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// bail if Pagelayer is not active.
		if ( ! $this->is_active() ) {
			return;
		}

		// get the widget.
		$digest_obj = Digest::get_instance();

		// use Pagelayer hooks.
		add_action( 'pagelayer_load_custom_widgets', array( $digest_obj, 'register' ) );
		add_filter( 'pagelayer_do_shortcode_el', array( $digest_obj, 'prepare' ) );
		add_filter( 'pagelayer_valid_shortcode_tag', array( $this, 'add_valid_shortcode_tag' ) );
		add_action( 'pagelayer_custom_editor_enqueue', array( $this, 'add_editor_scripts' ) );
		add_filter( 'pagelayer_shortcode_params', array( $this, 'hide_classic_widget' ), 10, 2 );

		// use our own hooks.
		add_action( 'wp_ajax_threadi_now_pages_pagelayer_digest', array( $digest_obj, 'render_via_ajax' ) );
	}

	/**
	 * Allow our widget as shortcode in the editor of Pagelayer.
	 *
	 * Pagelayer stores its pages as blocks, but older content and the import
	 * can still contain the shortcode. The editor would leave it unrendered.
	 *
	 * @param array<int,string> $prefixes List of valid prefixes.
	 *
	 * @return array<int,string>
	 */
	public function add_valid_shortcode_tag( array $prefixes ): array {
		$prefixes[] = Digest::TAG;
		return $prefixes;
	}

	/**
	 * Hide our classic WordPress widget in the list of elements of Pagelayer.
	 *
	 * Pagelayer offers every classic widget as element of its group "WordPress".
	 * Ours would appear next to our own widget with the same name. It is only
	 * hidden from the list: pages which already use it are still rendered.
	 *
	 * @param mixed  $params The settings of the element.
	 * @param string $tag    The tag of the element.
	 *
	 * @return mixed
	 */
	public function hide_classic_widget( mixed $params, string $tag ): mixed {
		// bail if this is not our classic widget. Pagelayer uses its ID base,
		// which WordPress writes in lower case.
		if ( ! is_array( $params ) || 'pl_wp_nowpagesthreadidigest' !== $tag ) {
			return $params;
		}

		// hide it.
		$params['not_visible'] = 1;

		// return the resulting settings.
		return $params;
	}

	/**
	 * Add the script and styles our widget needs in the editor of Pagelayer.
	 *
	 * The editor renders the widget itself after each change of its settings:
	 * the script asks the server for the new output. The styles are loaded
	 * here as the digest might not be part of the page yet.
	 *
	 * @return void
	 */
	public function add_editor_scripts(): void {
		wp_enqueue_script(
			'threadi-now-pages-pagelayer',
			Plugin::get_plugin_url() . 'pagelayer/digest.js',
			array( 'jquery', 'pagelayer-editor' ),
			Plugin::get_file_version( Plugin::get_plugin_path() . 'pagelayer/digest.js' ),
			true
		);

		// add the data the script needs.
		wp_localize_script(
			'threadi-now-pages-pagelayer',
			'threadiNowPagesPagelayer',
			array(
				'action'  => 'threadi_now_pages_pagelayer_digest',
				'tag'     => Digest::TAG,
				'loading' => __( 'Loading entries …', 'threadi-now-pages' ),
				'error'   => __( 'The entries could not be loaded.', 'threadi-now-pages' ),
			)
		);

		// add the public CSS of the digest.
		wp_enqueue_style(
			'threadi-now-pages',
			Plugin::get_plugin_url() . 'css/style.css',
			array(),
			Plugin::get_file_version( Plugin::get_plugin_path() . 'css/style.css' )
		);
	}

	/**
	 * Return the content a fresh now page should start with.
	 *
	 * Pagelayer expects a row with a column, which holds our widget.
	 *
	 * @return string
	 */
	public function get_page_content(): string {
		// get the prefix Pagelayer uses for its blocks ("sp" in SitePad).
		$prefix = defined( 'PAGELAYER_BLOCK_PREFIX' ) ? (string) constant( 'PAGELAYER_BLOCK_PREFIX' ) : 'wp';

		$row = array(
			'stretch'       => 'auto',
			'col_gap'       => '10',
			'width_content' => 'auto',
			'row_height'    => 'default',
			'pagelayer-id'  => $this->get_element_id(),
		);

		$column = array(
			'pagelayer-id' => $this->get_element_id(),
		);

		$digest = array(
			'period'           => get_option( 'threadi_now_pages_frontend_period', 'live' ),
			'format'           => get_option( 'threadi_now_pages_frontend_format', 'verbatim' ),
			'override_display' => '',
			'pagelayer-id'     => $this->get_element_id(),
		);

		return '<!-- ' . $prefix . ':pagelayer/pl_row ' . wp_json_encode( $row ) . ' -->' . "\n"
			. '<!-- ' . $prefix . ':pagelayer/pl_col ' . wp_json_encode( $column ) . ' -->' . "\n"
			. '<!-- ' . $prefix . ':pagelayer/' . Digest::TAG . ' ' . wp_json_encode( $digest ) . ' /-->' . "\n"
			. '<!-- /' . $prefix . ':pagelayer/pl_col -->' . "\n"
			. '<!-- /' . $prefix . ':pagelayer/pl_row -->';
	}

	/**
	 * Return post meta a fresh now page needs for this page builder.
	 *
	 * @return array<string,mixed>
	 */
	public function get_page_meta(): array {
		return array(
			// without this Pagelayer does not recognize the page as its own.
			'pagelayer-data' => time(),
		);
	}

	/**
	 * Return an element ID in the format Pagelayer uses: 3 characters and 4 digits.
	 *
	 * @return string
	 */
	private function get_element_id(): string {
		// use the function of Pagelayer, if it is loaded.
		if ( function_exists( 'pagelayer_create_id' ) ) {
			return (string) pagelayer_create_id();
		}

		return strtolower( wp_generate_password( 3, false, false ) ) . wp_rand( 1000, 9999 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Pagelayer', 'threadi-now-pages' );
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
		return Plugin::is_plugin_active( 'pagelayer/pagelayer.php' );
	}
}
