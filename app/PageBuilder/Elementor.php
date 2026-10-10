<?php
/**
 * This file contains the support for Elementor.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use Elementor\Elements_Manager;
use Elementor\Widgets_Manager;
use NowPagesThreadi\Helper\Singleton_Trait;
use NowPagesThreadi\PageBuilder\Elementor\Digest;

/**
 * Registers and handles the support for Elementor.
 */
class Elementor extends PageBuilder_Base {
	use Singleton_Trait;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'elementor';
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// bail if Elementor is not enabled.
		if ( ! $this->is_active() ) {
			return;
		}

		// use hooks.
		add_action( 'elementor/elements/categories_registered', array( $this, 'add_widget_categories' ) );
		add_filter( 'elementor/widgets/black_list', array( $this, 'hide_classic_widget' ) );
		add_action( 'init', array( $this, 'elementor_init' ) );
	}

	/**
	 * Add our own widget-category.
	 *
	 * @param Elements_Manager $elements_manager The Elements-manager as object.
	 * @return void
	 */
	public function add_widget_categories( Elements_Manager $elements_manager ): void {
		$elements_manager->add_category(
			'now-pages-threadi',
			array(
				'title' => esc_html_x( 'Now Pages', 'Elementor widget category', 'now-pages-threadi' ),
				'icon'  => 'fa fa-plug',
			)
		);
	}

	/**
	 * Hide our classic WordPress widget in Elementor: our own widget does the same there.
	 *
	 * @param mixed $black_list List of class names of WordPress widgets Elementor should not offer.
	 *
	 * @return mixed
	 */
	public function hide_classic_widget( mixed $black_list ): mixed {
		// bail if the list is not an array.
		if ( ! is_array( $black_list ) ) {
			return $black_list;
		}

		$black_list[] = 'NowPagesThreadi\PageBuilder\WpWidgets\Digest';

		return $black_list;
	}

	/**
	 * Initialize the Elementor support.
	 *
	 * @return void
	 */
	public function elementor_init(): void {
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
	}

	/**
	 * Register the widgets we support.
	 *
	 * @param Widgets_Manager $widgets_manager The widgets manager of Elementor.
	 *
	 * @return void
	 */
	public function register_widgets( Widgets_Manager $widgets_manager ): void {
		// add the class as Elementor widget.
		$widgets_manager->register( new Digest() );
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
	 * {@inheritDoc}
	 */
	public function get_page_meta(): array {
		return array(
			'_elementor_edit_mode'     => 'builder',
			'_elementor_template_type' => 'wp-page',
			'_elementor_data'          => wp_json_encode(
				array(
					array(
						'elType'   => 'container',
						'elements' => array(
							array(
								'elType'     => 'widget',
								'widgetType' => 'now-pages-threadi-digest',
								'settings'   => array(
									'period' => get_option( 'now_pages_threadi_frontend_period', 'live' ),
								),
							),
						),
					),
				)
			),
		);
	}

	/**
	 * Return whether this component is active (true) or not (false).
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return did_action( 'elementor/loaded' ) > 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Elementor', 'now-pages-threadi' );
	}
}
