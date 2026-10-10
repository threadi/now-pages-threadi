<?php
/**
 * Widget Name: Digest
 * Description: Provides a SiteOrigin Widget to show the now page entries.
 * Author: Thomas Zwirner
 * Author URI: https://plugin.thomaszwirner.de
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\PageBuilder\SiteOrigin\Digest;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Digests\Digests;
use NowPagesThreadi\Digests\Display_Settings;
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
			'now-pages-threadi-digest',
			// The name of the widget for display purposes.
			__( 'Digest', 'now-pages-threadi' ),
			// The $widget_options array, which is passed through to WP_Widget.
			// It has a couple of extras like the optional help URL, which should link to your sites help or support page.
			array(
				'description' => __( 'Provides a SiteOrigin Widget to show your now page entries.', 'now-pages-threadi' ),
			),
			// The $control_options array, which is passed through to WP_Widget.
			array(),
			// The $form_options array, which describes the form fields used to configure SiteOrigin widgets. We'll explain these in more detail later.
			array(
				'period'            => array(
					'type'    => 'select',
					'label'   => __( 'Period', 'now-pages-threadi' ),
					'default' => Digests::get_instance()->get_default_period(),
					'options' => $periods,
				),
				'format'            => array(
					'type'    => 'select',
					'label'   => __( 'Format', 'now-pages-threadi' ),
					'default' => Digests::get_instance()->get_default_format(),
					'options' => $formats,
				),
				'override_display'  => array(
					'type'    => 'radio',
					'label'   => __( 'Use own display settings', 'now-pages-threadi' ),
					'default' => 'no',
					'options' => array(
						'yes' => esc_html__( 'Yes', 'now-pages-threadi' ),
						'no'  => esc_html__( 'No', 'now-pages-threadi' ),
					),
				),
				'show_time'         => array(
					'type'    => 'radio',
					'label'   => __( 'Show times', 'now-pages-threadi' ),
					'default' => 'no',
					'options' => array(
						'yes' => esc_html__( 'Show', 'now-pages-threadi' ),
						'no'  => esc_html__( 'Hide', 'now-pages-threadi' ),
					),
				),
				'show_source'       => array(
					'type'    => 'radio',
					'label'   => __( 'Show data source', 'now-pages-threadi' ),
					'default' => 'no',
					'options' => array(
						'yes' => esc_html__( 'Show', 'now-pages-threadi' ),
						'no'  => esc_html__( 'Hide', 'now-pages-threadi' ),
					),
				),
				'link_source'       => array(
					'type'    => 'radio',
					'label'   => __( 'Link the data source', 'now-pages-threadi' ),
					'default' => 'no',
					'options' => array(
						'yes' => esc_html__( 'Show', 'now-pages-threadi' ),
						'no'  => esc_html__( 'Hide', 'now-pages-threadi' ),
					),
				),
				'show_period'       => array(
					'type'    => 'radio',
					'label'   => __( 'Show period of entries', 'now-pages-threadi' ),
					'default' => 'yes',
					'options' => array(
						'yes' => esc_html__( 'Show', 'now-pages-threadi' ),
						'no'  => esc_html__( 'Hide', 'now-pages-threadi' ),
					),
				),
				'group_by_category' => array(
					'type'    => 'radio',
					'label'   => __( 'Group by category', 'now-pages-threadi' ),
					'default' => 'yes',
					'options' => array(
						'yes' => esc_html__( 'Show', 'now-pages-threadi' ),
						'no'  => esc_html__( 'Hide', 'now-pages-threadi' ),
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
siteorigin_widget_register( 'now-pages-threadi-digest', __FILE__, 'NowPagesThreadi\PageBuilder\SiteOrigin\Digest\Digest' );
