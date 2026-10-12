<?php
/**
 * File for the object for the element to display the now page entries in Bricks page builder.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder\Bricks;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use Bricks\Element;
use ThreadiNowPages\Digests\Digest_Builder;
use ThreadiNowPages\Digests\Digests;
use ThreadiNowPages\Digests\Display_Settings;

/**
 * Object for the element to display the description part in Bricks page builder.
 */
class Digest extends Element {
	/**
	 * The category of the element.
	 *
	 * @var string
	 */
	public $category = 'threadi-now-pages';

	/**
	 * The name for the element.
	 *
	 * @var string
	 */
	public $name = 'threadi-now-pages-digest';

	/**
	 * Set the icon class.
	 *
	 * @var string
	 */
	public $icon = '';

	/**
	 * Default CSS selector.
	 *
	 * @var string
	 */
	public $css_selector = '.threadi-now-pages-digest-wrapper';

	/**
	 * Return the label for the element.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return esc_html__( 'Digest', 'threadi-now-pages' );
	}

	/**
	 * Set controls.
	 *
	 * @return void
	 */
	public function set_controls(): void {
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

		$this->controls['period']            = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Period', 'threadi-now-pages' ),
			'type'    => 'select',
			'options' => $periods,
			'default' => Digests::get_instance()->get_default_period(),
		);
		$this->controls['format']            = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Format', 'threadi-now-pages' ),
			'type'    => 'select',
			'options' => $formats,
			'default' => Digests::get_instance()->get_default_format(),
		);
		$this->controls['override_display']  = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Use own display settings', 'threadi-now-pages' ),
			'type'    => 'select',
			'options' => array(
				'yes' => esc_attr__( 'Yes', 'threadi-now-pages' ),
				'no'  => esc_attr__( 'No', 'threadi-now-pages' ),
			),
			'default' => 'no',
		);
		$this->controls['show_time']         = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Show times', 'threadi-now-pages' ),
			'type'    => 'select',
			'options' => array(
				'yes' => esc_attr__( 'Yes', 'threadi-now-pages' ),
				'no'  => esc_attr__( 'No', 'threadi-now-pages' ),
			),
			'default' => 'no',
		);
		$this->controls['show_source']       = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Show data source', 'threadi-now-pages' ),
			'type'    => 'select',
			'options' => array(
				'yes' => esc_attr__( 'Yes', 'threadi-now-pages' ),
				'no'  => esc_attr__( 'No', 'threadi-now-pages' ),
			),
			'default' => 'no',
		);
		$this->controls['link_source']       = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Link the data source', 'threadi-now-pages' ),
			'type'    => 'select',
			'options' => array(
				'yes' => esc_attr__( 'Yes', 'threadi-now-pages' ),
				'no'  => esc_attr__( 'No', 'threadi-now-pages' ),
			),
			'default' => 'no',
		);
		$this->controls['show_period']       = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Show period of entries', 'threadi-now-pages' ),
			'type'    => 'select',
			'options' => array(
				'yes' => esc_attr__( 'Yes', 'threadi-now-pages' ),
				'no'  => esc_attr__( 'No', 'threadi-now-pages' ),
			),
			'default' => 'yes',
		);
		$this->controls['group_by_category'] = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Group by category', 'threadi-now-pages' ),
			'type'    => 'select',
			'options' => array(
				'yes' => esc_attr__( 'Yes', 'threadi-now-pages' ),
				'no'  => esc_attr__( 'No', 'threadi-now-pages' ),
			),
			'default' => 'yes',
		);
	}

	/**
	 * Return the value of a setting.
	 *
	 * @param string $name The requested setting name.
	 *
	 * @return mixed
	 */
	private function get_value( string $name ): mixed {
		if ( isset( $this->settings[ $name ] ) ) {
			return $this->settings[ $name ];
		}
		return $this->controls[ $name ]['default'];
	}

	/**
	 * Render the element.
	 *
	 * @return void
	 */
	public function render(): void {
		$args = array(
			'period' => $this->get_value( 'period' ),
			'format' => $this->get_value( 'format' ),
		);
		if ( 'yes' === $this->get_value( 'override_display' ) ) {
			$args['show_time']         = $this->get_value( 'show_time' );
			$args['show_source']       = $this->get_value( 'show_source' );
			$args['link_source']       = $this->get_value( 'link_source' );
			$args['show_period']       = $this->get_value( 'show_period' );
			$args['group_by_category'] = $this->get_value( 'group_by_category' );
		}

		echo '<div>' . wp_kses( ( new Digest_Builder() )->build( $args ), Digests::get_instance()->get_allowed_html() ) . '</div>';
	}
}
