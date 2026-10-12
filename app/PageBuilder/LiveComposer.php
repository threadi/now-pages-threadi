<?php
/**
 * This file contains the support for Live Composer.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Helper\Plugin;
use ThreadiNowPages\Helper\Singleton_Trait;

/**
 * Registers and handles the support for Live Composer.
 */
class LiveComposer extends PageBuilder_Base {
	use Singleton_Trait;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'live_composer';
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// bail if Live Composer is not active.
		if ( ! $this->is_active() ) {
			return;
		}

		// use hooks.
		add_action( 'dslc_hook_register_modules', array( $this, 'register_module' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'add_styles' ) );
	}

	/**
	 * Add our own module to Live Composer.
	 *
	 * @return void
	 */
	public function register_module(): void {
		// bail if the API is not there. the hook is ours only if Live
		// Composer fired it, but a third party could fire it as well.
		if ( ! function_exists( 'dslc_register_module' ) || ! class_exists( 'DSLC_Module' ) ) {
			return;
		}

		// the module extends DSLC_Module, which Live Composer includes while
		// loading. Requiring the file any earlier would be a fatal, so it is
		// pulled in here, inside the hook Live Composer fires during its own
		// module registration.
		require_once Plugin::get_plugin_path() . '/legacy-classes/LiveComposer/module.php';

		dslc_register_module( 'ThreadiNowPages_DSLC_Digest' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Live Composer', 'threadi-now-pages' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 30;
	}

	/**
	 * Return the content a fresh now page needs for this page builder.
	 *
	 * Live Composer keeps its page code in the post meta 'dslc_code', the
	 * post content itself stays empty.
	 *
	 * @return string
	 */
	public function get_page_content(): string {
		return '';
	}

	/**
	 * Return post meta a fresh now page needs for this page builder.
	 *
	 * @return array<string,mixed>
	 */
	public function get_page_meta(): array {
		$code = array(
			array(
				'element_type'                   => 'row',
				'type'                           => 'wrapper',
				'section_instance_id'            => $this->get_instance_id(),
				'columns_spacing'                => 'spacing',
				'custom_class'                   => '',
				'custom_id'                      => '',
				'show_on'                        => 'desktop tablet phone',
				'valign'                         => '',
				'halign'                         => '',
				'css_module_section_width_unit'  => 'px',
				'css_module_section_width'       => '',
				'margin_top_unit'                => 'px',
				'margin_top'                     => '0',
				'margin_bottom_unit'             => 'px',
				'margin_bottom'                  => '0',
				'margin_left_unit'               => '%',
				'margin_left'                    => '0',
				'margin_right_unit'              => '%',
				'margin_right'                   => '0',
				'padding_top_unit'               => 'px',
				'padding_top'                    => '80',
				'padding_bottom_unit'            => 'px',
				'padding_bottom'                 => '80',
				'padding_left_unit'              => '%',
				'padding_left'                   => '0',
				'padding_right_unit'             => '%',
				'padding_right'                  => '0',
				'bg_color'                       => '',
				'bg_image_thumb'                 => 'disabled',
				'bg_image'                       => '',
				'bg_image_repeat'                => 'repeat',
				'bg_image_position'              => 'left top',
				'bg_image_attachment'            => 'scroll',
				'bg_image_size'                  => 'auto',
				'bg_video'                       => '',
				'bg_video_overlay_color'         => '#000000',
				'bg_video_overlay_opacity'       => '0',
				'sticky_row'                     => '',
				'sticky_row_padding_top_unit'    => 'px',
				'sticky_row_padding_top'         => '20',
				'sticky_row_padding_bottom_unit' => 'px',
				'sticky_row_padding_bottom'      => '20',
				'sticky_row_padding_left_unit'   => 'px',
				'sticky_row_padding_left'        => '0',
				'sticky_row_padding_right_unit'  => 'px',
				'sticky_row_padding_right'       => '0',
				'border_color'                   => '',
				'border_width'                   => '0',
				'border_style'                   => 'solid',
				'border'                         => 'top right bottom left',
				'section_divider_top_style'      => '',
				'section_divider_top_color'      => '',
				'section_divider_top_flip'       => '',
				'section_divider_top_height'     => '100',
				'section_divider_bottom_style'   => '',
				'section_divider_bottom_color'   => '',
				'section_divider_bottom_flip'    => '',
				'section_divider_bottom_height'  => '100',
				'content'                        => array(
					array(
						'element_type'             => 'module_area',
						'modules_area_instance_id' => $this->get_instance_id(),
						'size'                     => '12',
						'custom_class'             => '',
						'custom_id'                => '',
						'columns_spacing'          => 'spacing',
						'show_on'                  => 'desktop tablet phone',
						'valign'                   => '',
						'halign'                   => '',
						'first'                    => 'no',
						'last'                     => 'yes',
						'css_custom'               => 'enabled',
						'margin_unit'              => 'px',
						'margin_top'               => '',
						'margin_bottom'            => '0',
						'margin_left'              => '',
						'margin_right'             => '',
						'padding_unit'             => 'px',
						'padding_top'              => '',
						'padding_bottom'           => '',
						'padding_left'             => '',
						'padding_right'            => '',
						'bg_color'                 => '',
						'bg_image'                 => '',
						'bg_image_repeat'          => 'repeat',
						'bg_image_position'        => 'left top',
						'bg_image_attachment'      => 'scroll',
						'bg_image_size'            => 'auto',
						'border_color'             => '',
						'border_width'             => '0',
						'border_style'             => 'solid',
						'border'                   => 'top right bottom left',
						'css_res_t'                => 'disabled',
						'css_res_t_margin_unit'    => 'px',
						'css_res_t_margin_top'     => '',
						'css_res_t_margin_bottom'  => '',
						'css_res_t_margin_left'    => '',
						'css_res_t_margin_right'   => '',
						'css_res_t_padding_unit'   => 'px',
						'css_res_t_padding_top'    => '',
						'css_res_t_padding_bottom' => '',
						'css_res_t_padding_left'   => '',
						'css_res_t_padding_right'  => '',
						'css_res_p'                => 'disabled',
						'css_res_p_margin_unit'    => 'px',
						'css_res_p_margin_top'     => '',
						'css_res_p_margin_bottom'  => '',
						'css_res_p_margin_left'    => '',
						'css_res_p_margin_right'   => '',
						'css_res_p_padding_unit'   => 'px',
						'css_res_p_padding_top'    => '',
						'css_res_p_padding_bottom' => '',
						'css_res_p_padding_left'   => '',
						'css_res_p_padding_right'  => '',
						'content'                  => array(
							array(
								'element_type'           => 'module',
								'module_id'              => 'ThreadiNowPages_DSLC_Digest',
								'module_instance_id'     => $this->get_instance_id(),
								'dslc_m_size'            => '12',
								'last'                   => 'yes',
								'css_show_on'            => 'desktop tablet phone',
								'period'                 => get_option( 'threadi_now_pages_frontend_period', 'live' ),
								'format'                 => get_option( 'threadi_now_pages_frontend_format', 'verbatim' ),
								// left empty on purpose: without the override
								// the module follows the settings of the website.
								'override_display'       => '',
								'display'                => '',
								'css_custom'             => 'enabled',
								'css_main_margin_bottom' => '0',
								'css_main_padding'       => '0',
								'css_load_preset'        => 'none',
							),
						),
					),
				),
			),
		);

		// Live Composer stores its page code as JSON in this single meta key.
		// The 'post_id' every element carries after an editor save is left
		// out here - it is not known before wp_insert_post() returns, and
		// Live Composer writes it itself on the first save.
		return array(
			'dslc_code' => wp_json_encode( $code ),
		);
	}

	/**
	 * Return an id for one element of the page code.
	 *
	 * Live Composer uses eleven hexadecimal characters and only needs them to
	 * be unique within the page.
	 *
	 * @return string
	 */
	private function get_instance_id(): string {
		return substr( md5( (string) wp_rand() . microtime() ), 0, 11 );
	}

	/**
	 * Embed styles for Live Composer.
	 *
	 * @return void
	 */
	public function add_styles(): void {
		// enqueue our own styles.
		wp_enqueue_style(
			'threadi-now-pages-live-composer',
			Plugin::get_plugin_url() . 'css/style.css',
			array(),
			Plugin::get_file_version( Plugin::get_plugin_path() . '/css/style.css' ),
		);
	}

	/**
	 * Check if Live Composer is active.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return Plugin::is_plugin_active( 'live-composer-page-builder/ds-live-composer.php' );
	}
}
