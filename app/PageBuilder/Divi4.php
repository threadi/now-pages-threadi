<?php
/**
 * This file contains the support for Divi 4.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Helper\Plugin;
use NowPagesThreadi\Helper\Singleton_Trait;
use NowPagesThreadi\PageBuilder\Divi4\Extend_Divi;
use NowPagesThreadi\PageBuilder\Divi4\Render;

/**
 * Registers and handles the support for Divi 4.
 */
class Divi4 extends PageBuilder_Base {
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
		return 'divi4';
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// bail if Divi 4 is not enabled.
		if ( ! $this->is_active() ) {
			return;
		}

		// enable our own Divi 4 extension.
		add_action( 'divi_extensions_init', array( $this, 'init_extension' ) );
		add_filter( 'now_pages_threadi_divi4_widgets', array( $this, 'add_widgets' ) );
		add_action( 'wp_ajax_now_pages_threadi_digest', array( $this, 'get_entries' ) );
	}

	/**
	 * Initialize the divi extension with its modules.
	 *
	 * @return void
	 */
	public function init_extension(): void {
		new Extend_Divi();
	}

	/**
	 * Add widgets from Light.
	 *
	 * @param array<int,string> $divi_widgets List of Divi widgets.
	 *
	 * @return array<int,string>
	 */
	public function add_widgets( array $divi_widgets ): array {
		$divi_widgets[] = Plugin::get_plugin_path() . 'legacy-classes/Divi4/includes/modules/Digest/Digest.php';

		// return the resulting list of widgets.
		return $divi_widgets;
	}

	/**
	 * Return entries for an AJAX response.
	 *
	 * @return void
	 */
	public function get_entries(): void {
		// check for nonce.
		check_ajax_referer( 'et_admin_load_nonce', 'et_admin_load_nonce' );

		// bail if capability is not enough.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error();
		}

		$html = array();
		if ( isset( $_POST['attributes'] ) ) {
			$attributes = json_decode( sanitize_text_field( wp_unslash( $_POST['attributes'] ) ), true );

			// bail if attributes are not an array.
			if ( ! is_array( $attributes ) ) {
				wp_send_json( array( 'htmlString' => '' ) );
			}

			// get render-object.
			$render_obj = Render::get_instance();

			// get output.
			$html = $render_obj->get_entries( $attributes );
		}
		wp_send_json( array( 'htmlString' => $html ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_page_content(): string {
		return '[et_pb_section fb_built="1" _builder_version="4.27.8" _module_preset="default" global_colors_info="{}"][et_pb_row _builder_version="4.27.8" _module_preset="default" global_colors_info="{}"][et_pb_column type="4_4" _builder_version="4.27.8" _module_preset="default" global_colors_info="{}"][et_pb_text _builder_version="4.27.8" _module_preset="default" global_colors_info="{}"]<p>Your content goes here. Edit or remove this text inline or in the module Content settings. You can also style every aspect of this content in the module Design settings and even apply custom CSS to this text in the module Advanced settings.</p>[/et_pb_text][now_pages_threadi_digest period="' . get_option( 'now_pages_threadi_frontend_period', 'live' ) . '" show_time="on" show_period="off" group_by_category="off" _builder_version="4.27.8" _module_preset="default" global_colors_info="{}"][/now_pages_threadi_digest][/et_pb_column][/et_pb_row][/et_pb_section]';
	}

	/**
	 * Return post meta a fresh now page needs for this page builder.
	 *
	 * @return array<string,mixed>
	 */
	public function get_page_meta(): array {
		return array(
			// without this Divi shows the classic editor instead of its builder.
			'_et_pb_use_builder'         => 'on',
			'_et_pb_built_for_post_type' => 'page',
			// skip the "start from scratch or use a layout" dialog.
			'_et_pb_show_page_creation'  => 'off',
			'_et_pb_page_layout'         => 'et_right_sidebar',
			'_et_pb_side_nav'            => 'off',
			'_et_pb_post_hide_nav'       => 'default',
			'_et_pb_gutter_width'        => 3,
		);
	}

	/**
	 * Check if Divi < 5 is active.
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
	 * Run the check if Divi < 5 is active.
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
			if ( '' !== $plugin_version && version_compare( $plugin_version, '5.0.0', '<' ) ) {
				return true;
			}
		}

		// check for the theme.
		$theme = wp_get_theme();
		if ( 'Divi' === $theme->get( 'Name' ) ) {
			$version = substr( (string) $theme->get( 'Version' ), 0, 5 );
			return version_compare( $version, '5.0.0', '<' );
		}

		// check for the parent theme.
		if ( $theme->parent() && 'Divi' === $theme->parent()->get( 'Name' ) ) {
			$version = substr( (string) $theme->parent()->get( 'Version' ), 0, 5 );
			return version_compare( $version, '5.0.0', '<' );
		}

		// otherwise return false.
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Divi 4', 'now-pages-threadi' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 20;
	}
}
