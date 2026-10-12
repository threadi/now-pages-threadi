<?php
/**
 * File for digest widget in WpBakery.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder\WpBakery;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use Exception;
use ThreadiNowPages\Digests\Digest_Builder;
use ThreadiNowPages\Digests\Digests;
use ThreadiNowPages\Digests\Display_Settings;
use ThreadiNowPages\PageBuilder\Shortcode;
use WPBakeryShortCode;

/**
 * Object to handle the digest widget in WpBakery.
 */
class Digest extends WPBakeryShortCode {
	/**
	 * Shortcode used by this widget.
	 *
	 * @var string
	 */
	protected string $threadi_now_pages_shortcode = 'threadi_now_pages_digest';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register' ), 999 );
		parent::__construct( array( 'base' => $this->get_threadi_now_pages_shortcode() ) );
	}

	/**
	 * Define the settings for this widget.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_settings(): array {
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

		return array(
			'name'        => __( 'Digest', 'threadi-now-pages' ),
			'description' => __( 'Show entries of your now page.', 'threadi-now-pages' ),
			'params'      => array(
				array(
					'type'        => 'dropdown',
					'heading'     => __( 'Period', 'threadi-now-pages' ),
					'param_name'  => 'period',
					'admin_label' => false,
					'group'       => __( 'Now Pages', 'threadi-now-pages' ),
					'value'       => $periods,
					'std'         => Digests::get_instance()->get_default_period(),
				),
				array(
					'type'        => 'dropdown',
					'heading'     => __( 'Format', 'threadi-now-pages' ),
					'param_name'  => 'format',
					'admin_label' => false,
					'group'       => __( 'Now Pages', 'threadi-now-pages' ),
					'value'       => $formats,
					'std'         => Digests::get_instance()->get_default_format(),
				),
				array(
					'type'        => 'checkbox',
					'heading'     => __( 'Use own display settings', 'threadi-now-pages' ),
					'param_name'  => 'override_display',
					'admin_label' => false,
					'group'       => __( 'Now Pages', 'threadi-now-pages' ),
					'value'       => array( __( 'Yes', 'threadi-now-pages' ) => 'yes' ),
					'std'         => 'no',
				),

				array(
					'type'        => 'checkbox',
					'heading'     => __( 'Show times', 'threadi-now-pages' ),
					'param_name'  => 'show_time',
					'admin_label' => false,
					'group'       => __( 'Now Pages', 'threadi-now-pages' ),
					'value'       => array( __( 'Yes', 'threadi-now-pages' ) => 'yes' ),
					'std'         => 'no',
				),

				array(
					'type'        => 'checkbox',
					'heading'     => __( 'Show data source', 'threadi-now-pages' ),
					'param_name'  => 'show_source',
					'admin_label' => false,
					'group'       => __( 'Now Pages', 'threadi-now-pages' ),
					'value'       => array( __( 'Yes', 'threadi-now-pages' ) => 'yes' ),
					'std'         => 'no',
				),

				array(
					'type'        => 'checkbox',
					'heading'     => __( 'Link the data source', 'threadi-now-pages' ),
					'param_name'  => 'link_source',
					'admin_label' => false,
					'group'       => __( 'Now Pages', 'threadi-now-pages' ),
					'value'       => array( __( 'Yes', 'threadi-now-pages' ) => 'yes' ),
					'std'         => 'no',
				),

				array(
					'type'        => 'checkbox',
					'heading'     => __( 'Show period of entries', 'threadi-now-pages' ),
					'param_name'  => 'show_period',
					'admin_label' => false,
					'group'       => __( 'Now Pages', 'threadi-now-pages' ),
					'value'       => array( __( 'Yes', 'threadi-now-pages' ) => 'yes' ),
					'std'         => 'yes',
				),

				array(
					'type'        => 'checkbox',
					'heading'     => __( 'Group by category', 'threadi-now-pages' ),
					'param_name'  => 'group_by_category',
					'admin_label' => false,
					'group'       => __( 'Now Pages', 'threadi-now-pages' ),
					'value'       => array( __( 'Yes', 'threadi-now-pages' ) => 'yes' ),
					'std'         => 'yes',
				),
			),
		);
	}

	/**
	 * Output in frontend.
	 *
	 * @param array<string,mixed>|string $attributes List of attributes.
	 *
	 * @return string
	 */
	public function render( array|string $attributes ): string {
		if ( ! is_array( $attributes ) ) {
			$attributes = array();
		}

		// this widget uses the same tag as our core shortcode and replaces it while
		// WPBakery is active. a shortcode with attributes this widget does not know
		// (like user, category or delay_hours) has been written by hand: it is handed
		// to the core shortcode, so all its attributes and privacy checks apply.
		$own_attributes = array( 'period', 'format', 'override_display', 'show_time', 'show_source', 'link_source', 'show_period', 'group_by_category', 'css' );
		if ( ! empty( array_diff( array_keys( $attributes ), $own_attributes ) ) ) {
			return Shortcode::render( $attributes );
		}

		$args = array(
			'period' => ! empty( $attributes['period'] ) ? $attributes['period'] : Digests::get_instance()->get_default_period(),
			'format' => ! empty( $attributes['format'] ) ? $attributes['format'] : Digests::get_instance()->get_default_format(),
		);

		// only pass the display settings when the block was told to override
		// them. otherwise every block would beat the settings of the website.
		if ( isset( $attributes['override_display'] ) && 'yes' === $attributes['override_display'] ) {
			$names = array( 'show_time', 'show_source', 'link_source', 'show_period', 'group_by_category' );

			foreach ( $names as $name ) {
				if ( ! isset( $attributes[ $name ] ) ) {
					continue;
				}

				$args[ $name ] = 'yes' === $attributes[ $name ];
			}
		}

		// return the rendered output.
		return ( new Digest_Builder() )->build( $args );
	}

	/**
	 * Return the shortcode this widget is using.
	 *
	 * @return string
	 */
	protected function get_threadi_now_pages_shortcode(): string {
		return $this->threadi_now_pages_shortcode;
	}

	/**
	 * Register this widget.
	 *
	 * @return void
	 * @throws Exception Could throw exception.
	 */
	public function register(): void {
		// register the shortcode.
		add_shortcode( $this->get_threadi_now_pages_shortcode(), array( $this, 'render' ) );

		// get settings and add some global identical settings for all widgets.
		$settings                      = $this->get_settings();
		$settings['base']              = $this->get_threadi_now_pages_shortcode();
		$settings['class']             = '';
		$settings['icon']              = '';
		$settings['category']          = __( 'Now Pages', 'threadi-now-pages' );
		$settings['admin_enqueue_css'] = '';
		$settings['front_enqueue_css'] = '';
		$settings['params']            = array_merge(
			$settings['params'],
			array(
				array(
					'type'       => 'css_editor',
					'heading'    => esc_html__( 'CSS box', 'threadi-now-pages' ),
					'param_name' => 'css',
					'group'      => esc_html__( 'Design Options', 'threadi-now-pages' ),
				),
			)
		);

		// register them.
		vc_map( $settings );
	}
}
