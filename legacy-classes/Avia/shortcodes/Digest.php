<?php
/**
 * File to handle the Avia shortcode for the now pages Digest widget.
 *
 * @package now-pages-threadi
 */

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Digests\Digest_Builder;
use NowPagesThreadi\Digests\Digests;
use NowPagesThreadi\Digests\Display_Settings;

/**
 * Object to handle the shortcode.
 */
class NowPagesThreadiAviaDigest extends aviaShortcodeTemplate {
	/**
	 * Create the config array for the shortcode button
	 */
	function shortcode_insert_button(): void {
		$this->config['version']      = '1.0';
		$this->config['self_closing'] = 'no';
		$this->config['base_element'] = 'yes';

		$this->config['name']      = __( 'Digest', 'now-pages-threadi' );
		$this->config['tab']       = __( 'Now Pages', 'now-pages-threadi' );
		$this->config['icon']      = '';
		$this->config['order']     = 10;
		$this->config['target']    = 'avia-target-insert';
		$this->config['shortcode'] = 'avia_now_pages_threadi_digest';
		$this->config['tinyMCE']   = array( 'disable' => true );
		$this->config['tooltip']   = __( 'Provides an Avia Widget to show your now page entries.', 'now-pages-threadi' );
		$this->config['preview']   = false;
		$this->config['id_name']   = 'id';
		$this->config['id_show']   = 'yes';
	}

	/**
	 * Popup Elements: If this function is defined in a child class the element automatically gets an edit button, that,
	 * when pressed opens a modal window that allows to edit the element properties.
	 *
	 * @return void
	 */
	function popup_elements(): void {
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

		// hand the current defaults over, so switching the override on does
		// not change the output until the user actually changes something.
		$defaults = Display_Settings::resolve( array() );

		unset( $defaults['_origin'] );

		$this->elements = array(
			array(
				'type'          => 'tab_container',
				'nodescription' => true,
			),

			array(
				'type'          => 'tab',
				'name'          => __( 'Settings', 'now-pages-threadi' ),
				'nodescription' => true,
			),

			array(
				'name'     => __( 'Period', 'now-pages-threadi' ),
				'id'       => 'period',
				'type'     => 'select',
				'subtype'  => $periods,
				'std'      => Digests::get_instance()->get_default_period(),
				'lockable' => true,
			),

			array(
				'name'     => __( 'Format', 'now-pages-threadi' ),
				'id'       => 'format',
				'type'     => 'select',
				'subtype'  => $formats,
				'std'      => Digests::get_instance()->get_default_format(),
				'lockable' => true,
			),

			array(
				'name'     => __( 'Use own display settings', 'now-pages-threadi' ),
				'id'       => 'override_display',
				'type'     => 'select',
				'subtype'  => array(
					esc_html__( 'Yes', 'now-pages-threadi' ) => 'yes',
					esc_html__( 'No', 'now-pages-threadi' ) => 'no',
				),
				'std'      => 'no',
				'lockable' => true,
			),

			array(
				'name'     => __( 'Show times', 'now-pages-threadi' ),
				'id'       => 'show_time',
				'type'     => 'select',
				'subtype'  => array(
					esc_html__( 'Yes', 'now-pages-threadi' ) => 'yes',
					esc_html__( 'No', 'now-pages-threadi' ) => 'no',
				),
				'std'      => 'no',
				'lockable' => true,
			),

			array(
				'name'     => __( 'Show data source', 'now-pages-threadi' ),
				'id'       => 'show_source',
				'type'     => 'select',
				'subtype'  => array(
					esc_html__( 'Yes', 'now-pages-threadi' ) => 'yes',
					esc_html__( 'No', 'now-pages-threadi' ) => 'no',
				),
				'std'      => 'no',
				'lockable' => true,
			),

			array(
				'name'     => __( 'Link the data source', 'now-pages-threadi' ),
				'id'       => 'link_source',
				'type'     => 'select',
				'subtype'  => array(
					esc_html__( 'Yes', 'now-pages-threadi' ) => 'yes',
					esc_html__( 'No', 'now-pages-threadi' ) => 'no',
				),
				'std'      => 'no',
				'lockable' => true,
			),

			array(
				'name'     => __( 'Show period of entries', 'now-pages-threadi' ),
				'id'       => 'show_period',
				'type'     => 'select',
				'subtype'  => array(
					esc_html__( 'Yes', 'now-pages-threadi' ) => 'yes',
					esc_html__( 'No', 'now-pages-threadi' ) => 'no',
				),
				'std'      => 'yes',
				'lockable' => true,
			),

			array(
				'name'     => __( 'Group by category', 'now-pages-threadi' ),
				'id'       => 'group_by_category',
				'type'     => 'select',
				'subtype'  => array(
					esc_html__( 'Yes', 'now-pages-threadi' ) => 'yes',
					esc_html__( 'No', 'now-pages-threadi' ) => 'no',
				),
				'std'      => 'yes',
				'lockable' => true,
			),
		);
	}

	/**
	 * Editor Element - this function defines the visual appearance of an element on the AviaBuilder Canvas
	 * Most common usage is to define some markup in the $params['innerHtml'] which is then inserted into the drag and drop container
	 * Less often used: $params['data'] to add data attributes, $params['class'] to modify the className
	 *
	 * @param array $params this array holds the default values for $content and $args.
	 *
	 * @return array $params the return array usually holds an innerHtml key that holds item specific markup.
	 */
	function editor_element( $params ): array {
		$params['class']     = '';
		$params['innerHtml'] = '<div class="avia_now_pages_hint">' . esc_html__( 'Your now page entries will be displayed here.', 'now-pages-threadi' ) . '</div>';
		return $params;
	}

	/**
	 * Frontend Shortcode Handler
	 *
	 * @param array  $atts array of attributes
	 * @param string $content text within enclosing form of shortcode element
	 * @param string $shortcodename the shortcode found, when == callback name
	 * @return string $output returns the modified html string
	 */
	function shortcode_handler( $atts, $content = '', $shortcodename = '', $meta = '' ): string {
		// WordPress passes an empty string for shortcodes without attributes.
		if ( ! is_array( $atts ) ) {
			$atts = array();
		}

		// let Avia prepare the element styles (the result is not needed for our output).
		$this->get_element_styles( compact( array( 'atts', 'content', 'shortcodename', 'meta' ) ) );

		$attributes = array(
			'period' => ! empty( $atts['period'] ) ? $atts['period'] : Digests::get_instance()->get_default_period(),
			'format' => ! empty( $atts['format'] ) ? $atts['format'] : Digests::get_instance()->get_default_format(),
		);

		if ( isset( $atts['override_display'] ) && 'yes' === $atts['override_display'] ) {
			$names = array( 'show_time', 'show_source', 'link_source', 'show_period', 'group_by_category' );

			foreach ( $names as $name ) {
				if ( ! isset( $atts[ $name ] ) ) {
					continue;
				}

				$attributes[ $name ] = 'yes' === $atts[ $name ];
			}
		}

		return wp_kses( ( new Digest_Builder() )->build( $attributes ), Digests::get_instance()->get_allowed_html() );
	}
}
