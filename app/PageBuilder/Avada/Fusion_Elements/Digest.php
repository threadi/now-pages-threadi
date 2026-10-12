<?php
/**
 * File to handle a widget for Avada.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder\Avada\Fusion_Elements;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use Fusion_Builder_Border_Radius_Helper;
use Fusion_Builder_Margin_Helper;
use Fusion_Builder_Padding_Helper;
use Fusion_Element;
use FusionBuilder;
use ThreadiNowPages\Digests\Digest_Builder;
use ThreadiNowPages\Digests\Digests;

/**
 * Object for a widget in Avada.
 */
class Digest extends Fusion_Element {

	/**
	 * Set class for styling single entry in this instance.
	 *
	 * @var string
	 */
	protected string $class = 'threadi-now-pages-entry';

	/**
	 * Define flow-text-elements.
	 *
	 * @var array
	 */
	protected array $flow_elements = array(
		'text' => array(),
	);

	/**
	 * Constructor.
	 *
	 * @access public
	 * @since 1.0.0
	 */
	public function __construct() {
		parent::__construct();

		// add random number to class for possible multiple outputs of this widget in one page.
		$this->class .= '-' . wp_rand( 0, 50 );

		// add the shortcode which needs to be interpreted in frontend.
		add_shortcode( 'avada_threadi_now_pages_digest', array( $this, 'render' ) );
	}

	/**
	 * Render the shortcode
	 *
	 * @access public
	 * @since 1.0.0
	 * @param  array<string,mixed>|string $attributes Shortcode parameters (WordPress passes an empty string if none are set).
	 * @return string          HTML output.
	 */
	public function render( array|string $attributes = array() ): string {
		// WordPress passes an empty string for shortcodes without attributes.
		if ( ! is_array( $attributes ) ) {
			$attributes = array();
		}

		$this->defaults = array_merge( self::get_element_defaults(), $this->get_custom_element_defaults() );
		$this->args     = FusionBuilder::set_shortcode_defaults( $this->defaults, $attributes, 'avada_threadi_now_pages_digest' );

		$args = array(
			'period' => ! empty( $this->args['period'] ) ? $this->args['period'] : Digests::get_instance()->get_default_period(),
			'format' => ! empty( $this->args['format'] ) ? $this->args['format'] : Digests::get_instance()->get_default_format(),
		);

		// only pass the display settings when the block was told to override
		// them. otherwise every block would beat the settings of the website.
		if ( isset( $this->args['override_display'] ) && 'yes' === $this->args['override_display'] ) {
			$names = array( 'show_time', 'show_source', 'link_source', 'show_period', 'group_by_category' );

			foreach ( $names as $name ) {
				if ( ! isset( $this->args[ $name ] ) ) {
					continue;
				}

				$args[ $name ] = 'yes' === $this->args[ $name ];
			}
		}

		// outside the route the block behaves like the shortcode.
		return '<div class="' . esc_attr( $this->get_css_class() ) . '">'
				. ( new Digest_Builder() )->build( $args )
				. '</div>';
	}

	/**
	 * Gets the default values.
	 *
	 * These are the content attributes of this element. Every key that is
	 * missing here is dropped by set_shortcode_defaults(), no matter what
	 * the builder saved.
	 *
	 * @static
	 * @access public
	 * @since 1.0.0
	 * @return array<string,string>
	 */
	public static function get_element_defaults(): array {
		return array(
			'period'            => get_option( 'threadi_now_pages_frontend_period', 'live' ),
			'format'            => get_option( 'threadi_now_pages_frontend_format', 'verbatim' ),
			'override_display'  => 'no',
			'show_time'         => 'no',
			'show_source'       => 'no',
			'link_source'       => 'no',
			'show_period'       => 'no',
			'group_by_category' => 'yes',
		);
	}

	/**
	 * Gets the default values for possible format fields.
	 *
	 * @static
	 * @access public
	 * @since 1.0.0
	 * @return array
	 */
	public function get_custom_element_defaults(): array {
		$list = array();
		foreach ( $this->get_flow_elements() as $part => $element ) {
			$list[ 'fusion_font_family_' . $part . '_font_family' ]        = '';
			$list[ 'fusion_font_variant_' . $part . '_font_family' ]       = '';
			$list[ $part . '_font_size' ]                                  = '';
			$list[ $part . '_text_font' ]                                  = '';
			$list[ $part . '_line_height' ]                                = '';
			$list[ $part . '_letter_spacing' ]                             = '';
			$list[ $part . '_text_color' ]                                 = '';
			$list[ $part . '_transform' ]                                  = '';
			$list[ 'fusion_font_family_' . $part . '_font_family_hover' ]  = '';
			$list[ 'fusion_font_variant_' . $part . '_font_family_hover' ] = '';
			$list[ $part . '_font_size_hover' ]                            = '';
			$list[ $part . '_text_font_hover' ]                            = '';
			$list[ $part . '_line_height_hover' ]                          = '';
			$list[ $part . '_letter_spacing_hover' ]                       = '';
			$list[ $part . '_text_color_hover' ]                           = '';
			$list[ $part . '_transform_hover' ]                            = '';
		}
		$list['background_color']                = '';
		$list['border_color']                    = '';
		$list['content_alignment']               = '';
		$list['filter_alignment']                = '';
		$list['margin_top']                      = '';
		$list['margin_right']                    = '';
		$list['margin_bottom']                   = '';
		$list['margin_left']                     = '';
		$list['item_margin_top']                 = '';
		$list['item_margin_right']               = '';
		$list['item_margin_bottom']              = '';
		$list['item_margin_left']                = '';
		$list['item_margin_top_2']               = '';
		$list['item_margin_right_2']             = '';
		$list['item_margin_bottom_2']            = '';
		$list['item_margin_left_2']              = '';
		$list['padding_top']                     = '';
		$list['padding_right']                   = '';
		$list['padding_bottom']                  = '';
		$list['padding_left']                    = '';
		$list['border_radius_top_left']          = '';
		$list['border_radius_top_right']         = '';
		$list['border_radius_bottom_left']       = '';
		$list['border_radius_bottom_right']      = '';
		$list['position_background_color']       = '';
		$list['position_background_color_hover'] = '';

		// return resulting list of possible element formate fields.
		return $list;
	}

	/**
	 * Builds the styles.
	 *
	 * @return string
	 */
	public function get_style_vars(): string {
		// get sanitizer-helper.
		$sanitize = fusion_library()->sanitize;

		// collect the styles.
		$classes_with_styles = array(
			'' => array(),
		);

		// loop through the flow-text-elements.
		foreach ( $this->get_flow_elements() as $part => $elements ) {
			// define which variable should be sanitized and how.
			$css_vars = array(
				'content_alignment',
				'background_color',
				'border_color',
				'item_margin_top'               => array( 'callback' => array( $sanitize, 'get_value_with_unit' ) ),
				'item_margin_right'             => array( 'callback' => array( $sanitize, 'get_value_with_unit' ) ),
				'item_margin_bottom'            => array( 'callback' => array( $sanitize, 'get_value_with_unit' ) ),
				'item_margin_left'              => array( 'callback' => array( $sanitize, 'get_value_with_unit' ) ),
				'item_margin_top_2'             => array( 'callback' => array( $sanitize, 'get_value_with_unit' ) ),
				'item_margin_right_2'           => array( 'callback' => array( $sanitize, 'get_value_with_unit' ) ),
				'item_margin_bottom_2'          => array( 'callback' => array( $sanitize, 'get_value_with_unit' ) ),
				'item_margin_left_2'            => array( 'callback' => array( $sanitize, 'get_value_with_unit' ) ),
				$part . '_font_size'            => array( 'callback' => array( $sanitize, 'get_value_with_unit' ) ),
				$part . '_line_height'          => array( 'callback' => array( $sanitize, 'size' ) ),
				$part . '_letter_spacing'       => array( 'callback' => array( $sanitize, 'get_value_with_unit' ) ),
				$part . '_transform',
				$part . '_text_color'           => array( 'callback' => array( $sanitize, 'color' ) ),
				$part . '_font_size_hover'      => array( 'callback' => array( $sanitize, 'get_value_with_unit' ) ),
				$part . '_line_height_hover'    => array( 'callback' => array( $sanitize, 'size' ) ),
				$part . '_letter_spacing_hover' => array( 'callback' => array( $sanitize, 'get_value_with_unit' ) ),
				$part . '_transform_hover',
				$part . '_text_color_hover'     => array( 'callback' => array( $sanitize, 'color' ) ),
			);

			$font_vars       = $this->get_font_styling_vars( $part . '_font_family' );
			$font_vars_hover = $this->get_font_styling_vars( $part . '_font_family_hover' );
			if ( empty( $elements ) ) {
				// add styles for element.
				$classes_with_styles[''][] = $this->get_css_vars_for_options( $css_vars ) . $font_vars . $font_vars_hover;
			} else {
				foreach ( $elements as $element ) {
					// add styles for element.
					$classes_with_styles[ $element ][] = $this->get_css_vars_for_options( $css_vars ) . $font_vars . $font_vars_hover;
				}
			}
		}

		// get item-styles.
		$margin        = Fusion_Builder_Margin_Helper::get_margin_vars( $this->args );
		$padding       = Fusion_Builder_Padding_Helper::get_padding_vars( $this->args );
		$border_radius = Fusion_Builder_Border_Radius_Helper::get_border_radius_vars( $this->args );
		if ( ! empty( $this->args['filter_alignment'] ) ) {
			$classes_with_styles[''][] = '--awb-justify-content: ' . $this->args['filter_alignment'] . ';';
		}

		// concat all styles for output.
		$styles = '';
		foreach ( $classes_with_styles as $element => $style ) {
			// collect the element styles.
			$element_styles = implode( '', $style ) . $border_radius . $margin . $padding;

			// bail if element styles are empty.
			if ( empty( $element_styles ) ) {
				continue;
			}

			// add this styles to the list of styles.
			$styles .= '.' . $this->get_css_class() . ' ' . $element . ' {' . $element_styles . ' }';
		}

		// return resulting code.
		return $styles;
	}

	/**
	 * Return the CSS-class to use for output in frontend.
	 *
	 * @return string
	 */
	protected function get_css_class(): string {
		$class_name = $this->class;

		$instance = $this;

		/**
		 * Filter the used CSS class for Avada widgets.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param string         $class_name The CSS-classes.
		 * @param Fusion_Element $instance   The widget-object.
		 */
		return apply_filters( 'threadi_now_pages_avada_class', $class_name, $instance );
	}

	/**
	 * Get flow-text-elements.
	 *
	 * @return array
	 */
	protected function get_flow_elements(): array {
		$flow_elements = $this->flow_elements;

		/**
		 * Filter the flow elements used by Avada to format output in frontend.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array $flow_elements List of elements.
		 */
		return apply_filters( 'threadi_now_pages_avada_fusion_flow_elements', $flow_elements );
	}
}
