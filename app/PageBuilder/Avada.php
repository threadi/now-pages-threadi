<?php
/**
 * This file contains the support for Avada.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Helper\Plugin;
use NowPagesThreadi\Helper\Singleton_Trait;
use NowPagesThreadi\PageBuilder\Avada\Widgets\Digest;

/**
 * Registers and handles the support for Avada.
 */
class Avada extends PageBuilder_Base {
	use Singleton_Trait;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'avada';
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// bail if Avada is not enabled.
		if ( ! $this->is_active() ) {
			return;
		}

		// use hooks.
		add_action(
			'init',
			function () {
				$this->load_widgets();
			},
			100
		);
	}

	/**
	 * Load the widgets for Avada.
	 *
	 * @return void
	 */
	public function load_widgets(): void {
		// bail if the class and functions of Avada are not available.
		if ( ! class_exists( 'Fusion_Element' ) || ! function_exists( 'fusion_builder_map' ) || ! function_exists( 'fusion_builder_frontend_data' ) ) {
			return;
		}

		$widget = new Digest();
		$widget->init();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 20;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_page_content(): string {
		return '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"][avada_now_pages_threadi_digest period="' . get_option( 'now_pages_threadi_frontend_period', 'live' ) . '"][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_page_meta(): array {
		return array( 'fusion_builder_status' => 'active' );
	}

	/**
	 * Check if Avada and its necessary plugins are active.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		// bail if fusion-builder-plugin is not available.
		if ( ! Plugin::is_plugin_active( 'fusion-builder/fusion-builder.php' ) ) {
			return false;
		}

		// otherwise check for the theme.
		$is_avada = false;
		$theme    = wp_get_theme();
		if ( 'Avada' === $theme->get( 'Name' ) ) {
			$is_avada = true;
		}
		if ( $theme->parent() && 'Avada' === $theme->parent()->get( 'Name' ) ) {
			$is_avada = true;
		}

		// return resulting value.
		return $is_avada;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Avada', 'now-pages-threadi' );
	}
}
