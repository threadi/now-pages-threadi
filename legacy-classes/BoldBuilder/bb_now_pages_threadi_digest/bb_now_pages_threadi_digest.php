<?php
/**
 * This file contains an object to handle a widget.
 *
 * Must be used in legacy-classes as the class name is used as shortcode for the builder,
 * and it is not compatible wie PSR-4.
 *
 * @package now-pages-threadi
 */

declare( strict_types=1 );

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Digests\Digest_Builder;
use NowPagesThreadi\Digests\Digests;

/**
 * Object to handle a widget.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Bold Builder derives the element from this exact class name, a prefix of ours would break the registration.
class bb_now_pages_threadi_digest extends BT_BB_Element {

	/**
	 * Render the content used by this shortcode.
	 *
	 * @param array<string,mixed>|string $atts    The attributes (WordPress passes an empty string if none are set).
	 * @param string|null                $content The content (not used, but as parameter required).
	 *
	 * @return string
	 * @noinspection PhpUnusedParameterInspection
	 */
	public function handle_shortcode( $atts, $content = null ): string {
		// WordPress passes an empty string for shortcodes without attributes.
		if ( ! is_array( $atts ) ) {
			$atts = array();
		}

		$args = array(
			'period' => ! empty( $atts['period'] ) ? $atts['period'] : 'live',
			'format' => ! empty( $atts['format'] ) ? $atts['format'] : 'verbatim',
		);

		// only pass the display settings when the block was told to override
		// them. otherwise every block would beat the settings of the website.
		if ( isset( $atts['override_display'] ) && 'yes' === $atts['override_display'] ) {
			$names = array( 'show_time', 'show_source', 'link_source', 'show_period', 'group_by_category' );

			foreach ( $names as $name ) {
				if ( ! isset( $atts[ $name ] ) ) {
					continue;
				}

				$args[ $name ] = 'yes' === $atts[ $name ];
			}
		}

		// return the rendered content.
		return ( new Digest_Builder() )->build( $args );
	}

	/**
	 * Map the shortcode.
	 *
	 * @return void
	 */
	public function map_shortcode(): void {
		// collect the formats.
		$formats = array();
		foreach ( Digests::get_instance()->get_formats() as $format ) {
			$formats[ $format->get_label() ] = $format->get_slug();
		}

		// collect the periods.
		$periods = array();
		foreach ( Digests::get_instance()->get_periods() as $slug => $label ) {
			$periods[ $label ] = $slug;
		}

		// configure the mapping.
		bt_bb_map( $this->shortcode,
			array(
				'name'                    => esc_html__( 'Digest', 'now-pages-threadi' ),
				'description'             => esc_html__( 'Show your Now Page entries.', 'now-pages-threadi' ),
				'icon'                    => $this->prefix_backend . 'icon' . '_' . $this->shortcode,
				'container'               => 'vertical',
				'accept'                  => array( 'bt_bb_row' => true ),
				'toggle'                  => true,
				'show_settings_on_create' => false,
				'params'                  => array(
					array(
						'param_name' => 'period',
				       'type' => 'dropdown',
				       'heading' => esc_html__( 'Period', 'now-pages-threadi' ),
				       'preview' => true,
				       'value' => $periods,
					),
					array(
						'param_name' => 'format',
						'type' => 'dropdown',
						'heading' => esc_html__( 'Format', 'now-pages-threadi' ),
						'preview' => true,
						'value' => $formats,
					),
					array(
						'param_name' => 'override_display',
						'type' => 'checkbox',
						'heading' => esc_html__( 'Use own display settings', 'now-pages-threadi' ),
						'value' => array( esc_html__( 'Yes', 'now-pages-threadi' ) => 'yes' ),
						'default' => '',
					),

					array(
						'param_name' => 'show_time',
						'type' => 'checkbox',
						'heading' => esc_html__( 'Show times', 'now-pages-threadi' ),
						'value' => array( esc_html__( 'Yes', 'now-pages-threadi' ) => 'yes' ),
						'default' => '',
					),
					array(
						'param_name' => 'show_source',
						'type' => 'checkbox',
						'heading' => esc_html__( 'Show data source', 'now-pages-threadi' ),
						'value' => array( esc_html__( 'Yes', 'now-pages-threadi' ) => 'yes' ),
						'default' => '',
					),
					array(
						'param_name' => 'link_source',
						'type' => 'checkbox',
						'heading' => esc_html__( 'Link the data source', 'now-pages-threadi' ),
						'value' => array( esc_html__( 'Yes', 'now-pages-threadi' ) => 'yes' ),
						'default' => '',
					),
					array(
						'param_name' => 'show_period',
						'type' => 'checkbox',
						'heading' => esc_html__( 'Show period of entries', 'now-pages-threadi' ),
						'value' => array( esc_html__( 'Yes', 'now-pages-threadi' ) => 'yes' ),
						'default' => 'yes',
					),
					array(
						'param_name' => 'group_by_category',
						'type' => 'checkbox',
						'heading' => esc_html__( 'Group by category', 'now-pages-threadi' ),
						'value' => array( esc_html__( 'Yes', 'now-pages-threadi' ) => 'yes' ),
						'default' => 'yes',
					),
				),
			)
		);
	}

}
