<?php
/**
 * File to handle a widget for Avada.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder\Avada\Widgets;

// prevent direct access.
use ThreadiNowPages\Digests\Digests;
use ThreadiNowPages\Digests\Display_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Object to handle a widget for Avada.
 */
class Digest {

	/**
	 * The title of this widget.
	 *
	 * @var string
	 */
	protected string $title = 'Digest';

	/**
	 * The shortcode for this widget.
	 *
	 * @var string
	 */
	protected string $shortcode = 'avada_threadi_now_pages_digest';

	/**
	 * Instance of this object.
	 *
	 * @var ?Digest
	 */
	private static ?Digest $instance = null;

	/**
	 * Prevent cloning of this object.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Return the instance of this Singleton object.
	 */
	public static function get_instance(): Digest {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Define the widget.
	 *
	 * @return void
	 */
	public function init(): void {
		// load the Fusion_Element which is assigned to this widget.
		$this->load_fusion_element();

		// load the widget.
		$this->add_widget();
	}

	/**
	 * Load the Fusion_Element assigned to this widget.
	 *
	 * @return void
	 */
	public function load_fusion_element(): void {
		new \ThreadiNowPages\PageBuilder\Avada\Fusion_Elements\Digest();
	}

	/**
	 * Return the title of this widget.
	 *
	 * @return string
	 */
	public function get_shortcode(): string {
		return $this->shortcode;
	}

	/**
	 * Add the widgets.
	 *
	 * @return void
	 */
	public function add_widget(): void {
		// add the element to the builder with its settings.
		fusion_builder_map(
			fusion_builder_frontend_data(
				'\ThreadiNowPages\PageBuilder\Avada\Fusion_Elements\Digest',
				array(
					'name'      => __( 'Now entries', 'threadi-now-pages' ),
					'shortcode' => $this->get_shortcode(),
					'params'    => $this->get_params(),
				)
			)
		);
	}

	/**
	 * Return the parameter this widget is using in Avada builder.
	 *
	 * @return array<int,mixed>
	 */
	public function get_params(): array {
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

		// hand the current defaults over, so switching the override on does
		// not change the output until the user actually changes something.
		$defaults = Display_Settings::resolve( array() );

		unset( $defaults['_origin'] );

		return array(
			array(
				'type'       => 'select',
				'heading'    => esc_attr__( 'Period', 'threadi-now-pages' ),
				'param_name' => 'period',
				'value'      => $periods,
				'default'    => Digests::get_instance()->get_default_period(),
			),
			array(
				'type'       => 'select',
				'heading'    => esc_attr__( 'Format', 'threadi-now-pages' ),
				'param_name' => 'format',
				'value'      => $formats,
				'default'    => Digests::get_instance()->get_default_format(),
			),
			array(
				'type'        => 'radio_button_set',
				'heading'     => esc_attr__( 'Use own display settings', 'threadi-now-pages' ),
				'description' => esc_attr__( 'Without this the widget follows the settings of your website, and follows them when you change them later.', 'threadi-now-pages' ),
				'param_name'  => 'override_display',
				'value'       => array(
					'no'  => esc_attr__( 'No', 'threadi-now-pages' ),
					'yes' => esc_attr__( 'Yes', 'threadi-now-pages' ),
				),
				'default'     => 'no',
			),
			array(
				'type'       => 'radio_button_set',
				'heading'    => esc_attr__( 'Show times', 'threadi-now-pages' ),
				'param_name' => 'show_time',
				'value'      => array(
					'no'  => esc_attr__( 'No', 'threadi-now-pages' ),
					'yes' => esc_attr__( 'Yes', 'threadi-now-pages' ),
				),
				'default'    => $defaults['show_time'] ? 'yes' : 'no',
				'dependency' => array(
					array(
						'element'  => 'override_display',
						'value'    => 'yes',
						'operator' => '==',
					),
				),
			),
			array(
				'type'       => 'radio_button_set',
				'heading'    => esc_attr__( 'Show data source', 'threadi-now-pages' ),
				'param_name' => 'show_source',
				'value'      => array(
					'no'  => esc_attr__( 'No', 'threadi-now-pages' ),
					'yes' => esc_attr__( 'Yes', 'threadi-now-pages' ),
				),
				'default'    => $defaults['show_source'] ? 'yes' : 'no',
				'dependency' => array(
					array(
						'element'  => 'override_display',
						'value'    => 'yes',
						'operator' => '==',
					),
				),
			),
			array(
				'type'        => 'radio_button_set',
				'heading'     => esc_attr__( 'Link the data source', 'threadi-now-pages' ),
				'description' => esc_attr__( 'Links each source to the original entry, if the source delivered a URL.', 'threadi-now-pages' ),
				'param_name'  => 'link_source',
				'value'       => array(
					'no'  => esc_attr__( 'No', 'threadi-now-pages' ),
					'yes' => esc_attr__( 'Yes', 'threadi-now-pages' ),
				),
				'default'     => $defaults['link_source'] ? 'yes' : 'no',
				'dependency'  => array(
					array(
						'element'  => 'override_display',
						'value'    => 'yes',
						'operator' => '==',
					),
					array(
						'element'  => 'show_source',
						'value'    => 'yes',
						'operator' => '==',
					),
				),
			),
			array(
				'type'       => 'radio_button_set',
				'heading'    => esc_attr__( 'Show period of entries', 'threadi-now-pages' ),
				'param_name' => 'show_period',
				'value'      => array(
					'no'  => esc_attr__( 'No', 'threadi-now-pages' ),
					'yes' => esc_attr__( 'Yes', 'threadi-now-pages' ),
				),
				'default'    => $defaults['show_period'] ? 'yes' : 'no',
				'dependency' => array(
					array(
						'element'  => 'override_display',
						'value'    => 'yes',
						'operator' => '==',
					),
				),
			),
			array(
				'type'       => 'radio_button_set',
				'heading'    => esc_attr__( 'Group by category', 'threadi-now-pages' ),
				'param_name' => 'group_by_category',
				'value'      => array(
					'no'  => esc_attr__( 'No', 'threadi-now-pages' ),
					'yes' => esc_attr__( 'Yes', 'threadi-now-pages' ),
				),
				'default'    => $defaults['group_by_category'] ? 'yes' : 'no',
				'dependency' => array(
					array(
						'element'  => 'override_display',
						'value'    => 'yes',
						'operator' => '==',
					),
				),
			),
		);
	}
}
