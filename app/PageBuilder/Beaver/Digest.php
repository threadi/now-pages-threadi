<?php
/**
 * File to provide a Beaver Builder widget.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder\Beaver;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use FLBuilder;
use FLBuilderModule;
use ThreadiNowPages\Digests\Digests;

/**
 * Provides a modul for Beaver Builder widget.
 */
class Digest extends FLBuilderModule {
	/**
	 * Initialize the modul.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'name'        => __( 'Digest', 'threadi-now-pages' ),
				'description' => __( 'Provides a Beaver Block to show the digest for now pages.', 'threadi-now-pages' ),
				'group'       => __( 'Now Pages', 'threadi-now-pages' ),
				'category'    => __( 'Now Pages', 'threadi-now-pages' ),
				'dir'         => __DIR__ . '/digest/',
				'url'         => __DIR__ . '/digest/',
			)
		);
	}

	/**
	 * Disable icon for this module.
	 *
	 * @param string $icon The icon.
	 * @return bool
	 */
	public function get_icon( $icon = '' ): bool {
		return false;
	}
}

// collect the formats.
$threadi_now_pages_formats = array();
foreach ( Digests::get_instance()->get_formats() as $threadi_now_pages_format ) {
	$threadi_now_pages_formats[ $threadi_now_pages_format->get_slug() ] = $threadi_now_pages_format->get_label();
}

// collect the periods.
$threadi_now_pages_periods = array();
foreach ( Digests::get_instance()->get_periods() as $threadi_now_pages_period_slug => $threadi_now_pages_period_label ) {
	$threadi_now_pages_periods[ $threadi_now_pages_period_slug ] = $threadi_now_pages_period_label;
}

/**
 * Register the module and its form settings.
 */
FLBuilder::register_module(
	'ThreadiNowPages\PageBuilder\Beaver\Digest',
	array(
		'threadi-now-pages-tab' => array(
			'title'    => __( 'Settings', 'threadi-now-pages' ),
			'sections' => array(
				'settings' => array(
					'title'  => __( 'Settings', 'threadi-now-pages' ),
					'fields' => array(
						'period'            => array(
							'type'    => 'select',
							'label'   => __( 'Period', 'threadi-now-pages' ),
							'default' => Digests::get_instance()->get_default_period(),
							'options' => $threadi_now_pages_periods,
						),
						'format'            => array(
							'type'    => 'select',
							'label'   => __( 'Format', 'threadi-now-pages' ),
							'default' => Digests::get_instance()->get_default_format(),
							'options' => $threadi_now_pages_formats,
						),
						'override_display'  => array(
							'type'    => 'select',
							'label'   => __( 'Use own display settings', 'threadi-now-pages' ),
							'options' => array(
								'yes' => esc_html__( 'Show', 'threadi-now-pages' ),
								'no'  => esc_html__( 'Hide', 'threadi-now-pages' ),
							),
							'default' => 'no',
						),
						'show_time'         => array(
							'type'    => 'select',
							'label'   => __( 'Show times', 'threadi-now-pages' ),
							'options' => array(
								'yes' => esc_html__( 'Show', 'threadi-now-pages' ),
								'no'  => esc_html__( 'Hide', 'threadi-now-pages' ),
							),
							'default' => 'no',
						),
						'show_source'       => array(
							'type'    => 'select',
							'label'   => __( 'Show data source', 'threadi-now-pages' ),
							'options' => array(
								'yes' => esc_html__( 'Show', 'threadi-now-pages' ),
								'no'  => esc_html__( 'Hide', 'threadi-now-pages' ),
							),
							'default' => 'no',
						),
						'link_source'       => array(
							'type'    => 'select',
							'label'   => __( 'Link the data source', 'threadi-now-pages' ),
							'options' => array(
								'yes' => esc_html__( 'Show', 'threadi-now-pages' ),
								'no'  => esc_html__( 'Hide', 'threadi-now-pages' ),
							),
							'default' => 'no',
						),
						'show_period'       => array(
							'type'    => 'select',
							'label'   => __( 'Show period of entries', 'threadi-now-pages' ),
							'options' => array(
								'yes' => esc_html__( 'Show', 'threadi-now-pages' ),
								'no'  => esc_html__( 'Hide', 'threadi-now-pages' ),
							),
							'default' => 'yes',
						),
						'group_by_category' => array(
							'type'    => 'select',
							'label'   => __( 'Group by category', 'threadi-now-pages' ),
							'options' => array(
								'yes' => esc_html__( 'Show', 'threadi-now-pages' ),
								'no'  => esc_html__( 'Hide', 'threadi-now-pages' ),
							),
							'default' => 'yes',
						),
					),
				),
			),
		),
	)
);
