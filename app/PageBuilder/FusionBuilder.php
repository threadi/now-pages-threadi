<?php
/**
 * This file contains the support for Fusion Builder.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Digests\Digest_Builder;
use ThreadiNowPages\Digests\Digests;
use ThreadiNowPages\Helper\Plugin;
use ThreadiNowPages\Helper\Singleton_Trait;

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
	private string $shortcode = 'fusion_builder_threadi_now_pages_digest';

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
				'name'          => __( 'Digest', 'threadi-now-pages' ),
				'shortcode_tag' => $this->shortcode,
				'icon'          => 'icon-notes',
				'params'        => array(
					array(
						'type'       => 'select',
						'param_name' => 'period',
						'label'      => __( 'Period', 'threadi-now-pages' ),
						'options'    => $periods,
					),
					array(
						'type'       => 'select',
						'param_name' => 'format',
						'label'      => __( 'Format', 'threadi-now-pages' ),
						'options'    => $formats,
					),
					array(
						'type'       => 'checkbox',
						'param_name' => 'override_display',
						'label'      => __( 'Use own display settings', 'threadi-now-pages' ),
					),
					array(
						'type'       => 'checkbox',
						'param_name' => 'show_time',
						'label'      => __( 'Show times', 'threadi-now-pages' ),
					),
					array(
						'type'       => 'checkbox',
						'param_name' => 'show_source',
						'label'      => __( 'Show data source', 'threadi-now-pages' ),
					),
					array(
						'type'       => 'checkbox',
						'param_name' => 'link_source',
						'label'      => __( 'Link source', 'threadi-now-pages' ),
					),
					array(
						'type'       => 'checkbox',
						'param_name' => 'show_period',
						'label'      => __( 'Show period of entries', 'threadi-now-pages' ),
					),
					array(
						'type'       => 'checkbox',
						'param_name' => 'group_by_category',
						'label'      => __( 'Group by category', 'threadi-now-pages' ),
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
		return __( 'Fusion Page Builder', 'threadi-now-pages' );
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
		return '[fsn_row][fsn_column width="12"][fusion_builder_threadi_now_pages_digest period="' . Digests::get_instance()->get_default_period() . '" format="' . Digests::get_instance()->get_default_format() . '"][/fusion_builder_threadi_now_pages_digest][/fsn_column][/fsn_row]';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		return Plugin::is_plugin_active( 'fusion/fusion-core.php' );
	}
}
