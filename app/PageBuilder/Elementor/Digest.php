<?php
/**
 * File to handle output of the digest as Elementor widget.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder\Elementor;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use ThreadiNowPages\Digests\Digest_Builder;
use ThreadiNowPages\Digests\Digests;
use ThreadiNowPages\Digests\Display_Settings;

/**
 * Object to show the digest as Elementor widget.
 */
class Digest extends Widget_Base {
	/**
	 * Widget name.
	 *
	 * @var string
	 */
	protected string $name = 'threadi-now-pages-digest';

	/**
	 * Return the plugin name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Retrieve the widget title.
	 *
	 * @return string Widget title.
	 */
	public function get_title(): string {
		return __( 'Digest', 'threadi-now-pages' );
	}

	/**
	 * Set keywords for Elementor-internal search for widgets.
	 *
	 * @return string[]
	 */
	public function get_keywords(): array {
		return array( 'Now Page', 'Digest' );
	}

	/**
	 * Retrieve the list of categories the widget belongs to.
	 *
	 * Used to determine where to display the widget in the editor.
	 *
	 * Note that currently Elementor supports only one category.
	 * When multiple categories passed, Elementor uses the first one.
	 *
	 * @return array Widget categories.
	 */
	public function get_categories(): array {
		return array( 'threadi-now-pages' );
	}

	/**
	 * Register the widget controls.
	 *
	 * @access protected
	 */
	protected function register_controls(): void {
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

		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Settings', 'threadi-now-pages' ),
			)
		);

		$this->add_control(
			'period',
			array(
				'type'        => Controls_Manager::SELECT2,
				'label'       => esc_html__( 'Period', 'threadi-now-pages' ),
				'multiple'    => false,
				'label_block' => true,
				'options'     => $periods,
				'default'     => Digests::get_instance()->get_default_period(),
			)
		);

		$this->add_control(
			'format',
			array(
				'type'        => Controls_Manager::SELECT2,
				'label'       => esc_html__( 'Format', 'threadi-now-pages' ),
				'multiple'    => false,
				'label_block' => true,
				'options'     => $formats,
				'default'     => Digests::get_instance()->get_default_format(),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_display',
			array(
				'label' => __( 'Display', 'threadi-now-pages' ),
			)
		);

		$this->add_responsive_control(
			'override_display',
			array(
				'label'        => esc_html__( 'Use own display settings', 'threadi-now-pages' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'threadi-now-pages' ),
				'label_off'    => esc_html__( 'Hide', 'threadi-now-pages' ),
				'return_value' => 'yes',
				'default'      => 'no',
			)
		);

		$this->add_responsive_control(
			'show_time',
			array(
				'label'        => esc_html__( 'Show times', 'threadi-now-pages' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'threadi-now-pages' ),
				'label_off'    => esc_html__( 'Hide', 'threadi-now-pages' ),
				'return_value' => 'yes',
				'default'      => 1 !== absint( get_option( 'threadi_now_pages_show_time' ) ) ? 'no' : 'yes',
				'condition'    => array(
					'override_display' => 'yes',
				),
			)
		);

		$this->add_responsive_control(
			'show_source',
			array(
				'label'        => esc_html__( 'Show data source', 'threadi-now-pages' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'threadi-now-pages' ),
				'label_off'    => esc_html__( 'Hide', 'threadi-now-pages' ),
				'return_value' => 'yes',
				'default'      => 1 !== absint( get_option( 'threadi_now_pages_show_source' ) ) ? 'no' : 'yes',
				'condition'    => array(
					'override_display' => 'yes',
				),
			)
		);

		$this->add_responsive_control(
			'link_source',
			array(
				'label'        => esc_html__( 'Link the data source', 'threadi-now-pages' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'threadi-now-pages' ),
				'label_off'    => esc_html__( 'Hide', 'threadi-now-pages' ),
				'return_value' => 'yes',
				'default'      => 1 !== absint( get_option( 'threadi_now_pages_link_source' ) ) ? 'no' : 'yes',
				'condition'    => array(
					'override_display' => 'yes',
				),
			)
		);

		$this->add_responsive_control(
			'show_period',
			array(
				'label'        => esc_html__( 'Show period of entries', 'threadi-now-pages' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'threadi-now-pages' ),
				'label_off'    => esc_html__( 'Hide', 'threadi-now-pages' ),
				'return_value' => 'yes',
				'default'      => 1 !== absint( get_option( 'threadi_now_pages_show_period' ) ) ? 'no' : 'yes',
				'condition'    => array(
					'override_display' => 'yes',
				),
			)
		);

		$this->add_responsive_control(
			'group_by_category',
			array(
				'label'        => esc_html__( 'Group by category', 'threadi-now-pages' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'threadi-now-pages' ),
				'label_off'    => esc_html__( 'Hide', 'threadi-now-pages' ),
				'return_value' => 'yes',
				'default'      => 1 !== absint( get_option( 'threadi_now_pages_group_by_category' ) ) ? 'no' : 'yes',
				'condition'    => array(
					'override_display' => 'yes',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render the widget output in Elementor and frontend.
	 */
	protected function render(): void {
		// get widget-settings.
		$attributes = $this->get_settings_for_display();

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

				// switchers return "yes" if enabled; the default of disabled ones is "no".
				$args[ $name ] = 'yes' === $attributes[ $name ];
			}
		}

		// outside the route the block behaves like the shortcode.
		echo wp_kses( ( new Digest_Builder() )->build( $args ), Digests::get_instance()->get_allowed_html() );
	}
}
