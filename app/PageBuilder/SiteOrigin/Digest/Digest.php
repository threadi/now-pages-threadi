<?php
/**
 * Widget Name: Digest
 * Description: Provides a SiteOrigin Widget to show the now page entries.
 * Author: Thomas Zwirner
 * Author URI: https://plugin.thomaszwirner.de
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder\SiteOrigin\Digest;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Digests\Digests;
use ThreadiNowPages\Digests\Display_Settings;
use SiteOrigin_Widget;

/**
 * Object to handle this widget.
 */
class Digest extends SiteOrigin_Widget {

	/**
	 * Initialize this object.
	 */
	public function __construct() {
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

		// Call the parent constructor with the required arguments.
		parent::__construct(
			// The unique id for your widget.
			'threadi-now-pages-digest',
			// The name of the widget for display purposes.
			__( 'Digest', 'threadi-now-pages' ),
			// The $widget_options array, which is passed through to WP_Widget.
			// It has a couple of extras like the optional help URL, which should link to your sites help or support page.
			array(
				'description' => __( 'Provides a SiteOrigin Widget to show your now page entries.', 'threadi-now-pages' ),
			),
			// The $control_options array, which is passed through to WP_Widget.
			array(),
			// The $form_options array, which describes the form fields used to configure SiteOrigin widgets. We'll explain these in more detail later.
			array(
				'period'            => array(
					'type'    => 'select',
					'label'   => __( 'Period', 'threadi-now-pages' ),
					'default' => Digests::get_instance()->get_default_period(),
					'options' => $periods,
				),
				'format'            => array(
					'type'    => 'select',
					'label'   => __( 'Format', 'threadi-now-pages' ),
					'default' => Digests::get_instance()->get_default_format(),
					'options' => $formats,
				),
				'override_display'  => array(
					'type'    => 'radio',
					'label'   => __( 'Use own display settings', 'threadi-now-pages' ),
					'default' => 'no',
					'options' => array(
						'yes' => esc_html__( 'Yes', 'threadi-now-pages' ),
						'no'  => esc_html__( 'No', 'threadi-now-pages' ),
					),
				),
				'show_time'         => array(
					'type'    => 'radio',
					'label'   => __( 'Show times', 'threadi-now-pages' ),
					'default' => 'no',
					'options' => array(
						'yes' => esc_html__( 'Show', 'threadi-now-pages' ),
						'no'  => esc_html__( 'Hide', 'threadi-now-pages' ),
					),
				),
				'show_source'       => array(
					'type'    => 'radio',
					'label'   => __( 'Show data source', 'threadi-now-pages' ),
					'default' => 'no',
					'options' => array(
						'yes' => esc_html__( 'Show', 'threadi-now-pages' ),
						'no'  => esc_html__( 'Hide', 'threadi-now-pages' ),
					),
				),
				'link_source'       => array(
					'type'    => 'radio',
					'label'   => __( 'Link the data source', 'threadi-now-pages' ),
					'default' => 'no',
					'options' => array(
						'yes' => esc_html__( 'Show', 'threadi-now-pages' ),
						'no'  => esc_html__( 'Hide', 'threadi-now-pages' ),
					),
				),
				'show_period'       => array(
					'type'    => 'radio',
					'label'   => __( 'Show period of entries', 'threadi-now-pages' ),
					'default' => 'yes',
					'options' => array(
						'yes' => esc_html__( 'Show', 'threadi-now-pages' ),
						'no'  => esc_html__( 'Hide', 'threadi-now-pages' ),
					),
				),
				'group_by_category' => array(
					'type'    => 'radio',
					'label'   => __( 'Group by category', 'threadi-now-pages' ),
					'default' => 'yes',
					'options' => array(
						'yes' => esc_html__( 'Show', 'threadi-now-pages' ),
						'no'  => esc_html__( 'Hide', 'threadi-now-pages' ),
					),
				),
			),
			// The $base_folder path string.
			plugin_dir_path( __FILE__ )
		);
	}

	/**
	 * Set the name for the template.
	 *
	 * @param array $instance The instance of the SiteOrigin widget.
	 *
	 * @return string
	 */
	public function get_template_name( $instance ): string {
		return 'digest';
	}
}
siteorigin_widget_register( 'threadi-now-pages-digest', __FILE__, 'ThreadiNowPages\PageBuilder\SiteOrigin\Digest\Digest' );
