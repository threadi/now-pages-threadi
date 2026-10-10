<?php
/**
 * This file contains the support for Fusion Builder.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Digests\Digest_Builder;
use NowPagesThreadi\Digests\Digests;
use NowPagesThreadi\Helper\Plugin;
use NowPagesThreadi\Helper\Singleton_Trait;

/**
 * Registers and handles the support for Avada.
 */
class FusionBuilder extends PageBuilder_Base {
	use Singleton_Trait;

	/**
	 * The shortcode for our widget.
	 *
	 * @var string
	 */
	private string $shortcode = 'fusion_builder_now_pages_threadi_digest';

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'fusion_builder';
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// bail if fusion page builder is not enabled.
		if ( ! $this->is_active() ) {
			return;
		}

		// use hooks.
		add_action( 'init', array( $this, 'add_widget' ), 12 );
		add_shortcode( $this->shortcode, array( $this, 'render' ) ); // @phpstan-ignore argument.type
	}

	/**
	 * Add our own widget.
	 *
	 * @return void
	 */
	public function add_widget(): void {
		// bail if the function of Fusion Builder is not available.
		if ( ! function_exists( 'fsn_map' ) ) {
			return;
		}

		// collect the formats.
		$formats = array();
		foreach ( Digests::get_instance()->get_formats() as $format ) {
			$formats[ $format->get_slug() ] = $format->get_label();
		}

		// collect the periods.
		$periods = array();
		foreach ( Digests::get_instance()->get_periods() as $slug => $label ) {
			$periods[ $slug ] = $label;
		}

		fsn_map(
			array(
				'name'          => __( 'Digest', 'now-pages-threadi' ),
				'shortcode_tag' => $this->shortcode,
				'icon'          => 'icon-notes',
				'params'        => array(
					array(
						'type'       => 'select',
						'param_name' => 'period',
						'label'      => __( 'Period', 'now-pages-threadi' ),
						'options'    => $periods,
					),
					array(
						'type'       => 'select',
						'param_name' => 'format',
						'label'      => __( 'Format', 'now-pages-threadi' ),
						'options'    => $formats,
					),
					array(
						'type'       => 'checkbox',
						'param_name' => 'override_display',
						'label'      => __( 'Use own display settings', 'now-pages-threadi' ),
					),
					array(
						'type'       => 'checkbox',
						'param_name' => 'show_time',
						'label'      => __( 'Show times', 'now-pages-threadi' ),
					),
					array(
						'type'       => 'checkbox',
						'param_name' => 'show_source',
						'label'      => __( 'Show data source', 'now-pages-threadi' ),
					),
					array(
						'type'       => 'checkbox',
						'param_name' => 'link_source',
						'label'      => __( 'Link source', 'now-pages-threadi' ),
					),
					array(
						'type'       => 'checkbox',
						'param_name' => 'show_period',
						'label'      => __( 'Show period of entries', 'now-pages-threadi' ),
					),
					array(
						'type'       => 'checkbox',
						'param_name' => 'group_by_category',
						'label'      => __( 'Group by category', 'now-pages-threadi' ),
					),
				),
			)
		);
	}

	/**
	 * Add the render function.
	 *
	 * @param array<string,mixed>|string $atts The attributes (WordPress passes an empty string if none are set).
	 *
	 * @return string
	 */
	public function render( array|string $atts ): string {
		// WordPress passes an empty string for shortcodes without attributes.
		if ( ! is_array( $atts ) ) {
			$atts = array();
		}

		$args = array(
			'period' => ! empty( $atts['period'] ) ? $atts['period'] : Digests::get_instance()->get_default_period(),
			'format' => ! empty( $atts['format'] ) ? $atts['format'] : Digests::get_instance()->get_default_format(),
		);

		// only pass the display settings when the block was told to override
		// them. otherwise every block would beat the settings of the website.
		if ( isset( $atts['override_display'] ) && 'on' === $atts['override_display'] ) {
			$names = array( 'show_time', 'show_source', 'link_source', 'show_period', 'group_by_category' );

			foreach ( $names as $name ) {
				if ( ! isset( $atts[ $name ] ) ) {
					continue;
				}

				$args[ $name ] = 'on' === $atts[ $name ];
			}
		}

		// return the rendered content.
		return ( new Digest_Builder() )->build( $args );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Fusion Page Builder', 'now-pages-threadi' );
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
		return '[fsn_row][fsn_column width="12"][fusion_builder_now_pages_threadi_digest period="' . Digests::get_instance()->get_default_period() . '" format="' . Digests::get_instance()->get_default_format() . '"][/fusion_builder_now_pages_threadi_digest][/fsn_column][/fsn_row]';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		return Plugin::is_plugin_active( 'fusion/fusion-core.php' );
	}
}
