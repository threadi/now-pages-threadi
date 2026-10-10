<?php
/**
 * File to provide a Beaver Builder widget.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\PageBuilder\Beaver;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use FLBuilder;
use FLBuilderModule;
use NowPagesThreadi\Digests\Digests;

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
				'name'        => __( 'Digest', 'now-pages-threadi' ),
				'description' => __( 'Provides a Beaver Block to show the digest for now pages.', 'now-pages-threadi' ),
				'group'       => __( 'Now Pages', 'now-pages-threadi' ),
				'category'    => __( 'Now Pages', 'now-pages-threadi' ),
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
$now_pages_threadi_formats = array();
foreach ( Digests::get_instance()->get_formats() as $now_pages_threadi_format ) {
	$now_pages_threadi_formats[ $now_pages_threadi_format->get_slug() ] = $now_pages_threadi_format->get_label();
}

// collect the periods.
$now_pages_threadi_periods = array();
foreach ( Digests::get_instance()->get_periods() as $now_pages_threadi_period_slug => $now_pages_threadi_period_label ) {
	$now_pages_threadi_periods[ $now_pages_threadi_period_slug ] = $now_pages_threadi_period_label;
}

/**
 * Register the module and its form settings.
 */
FLBuilder::register_module(
	'NowPagesThreadi\PageBuilder\Beaver\Digest',
	array(
		'now-pages-threadi-tab' => array(
			'title'    => __( 'Settings', 'now-pages-threadi' ),
			'sections' => array(
				'settings' => array(
					'title'  => __( 'Settings', 'now-pages-threadi' ),
					'fields' => array(
						'period'            => array(
							'type'    => 'select',
							'label'   => __( 'Period', 'now-pages-threadi' ),
							'default' => Digests::get_instance()->get_default_period(),
							'options' => $now_pages_threadi_periods,
						),
						'format'            => array(
							'type'    => 'select',
							'label'   => __( 'Format', 'now-pages-threadi' ),
							'default' => Digests::get_instance()->get_default_format(),
							'options' => $now_pages_threadi_formats,
						),
						'override_display'  => array(
							'type'    => 'select',
							'label'   => __( 'Use own display settings', 'now-pages-threadi' ),
							'options' => array(
								'yes' => esc_html__( 'Show', 'now-pages-threadi' ),
								'no'  => esc_html__( 'Hide', 'now-pages-threadi' ),
							),
							'default' => 'no',
						),
						'show_time'         => array(
							'type'    => 'select',
							'label'   => __( 'Show times', 'now-pages-threadi' ),
							'options' => array(
								'yes' => esc_html__( 'Show', 'now-pages-threadi' ),
								'no'  => esc_html__( 'Hide', 'now-pages-threadi' ),
							),
							'default' => 'no',
						),
						'show_source'       => array(
							'type'    => 'select',
							'label'   => __( 'Show data source', 'now-pages-threadi' ),
							'options' => array(
								'yes' => esc_html__( 'Show', 'now-pages-threadi' ),
								'no'  => esc_html__( 'Hide', 'now-pages-threadi' ),
							),
							'default' => 'no',
						),
						'link_source'       => array(
							'type'    => 'select',
							'label'   => __( 'Link the data source', 'now-pages-threadi' ),
							'options' => array(
								'yes' => esc_html__( 'Show', 'now-pages-threadi' ),
								'no'  => esc_html__( 'Hide', 'now-pages-threadi' ),
							),
							'default' => 'no',
						),
						'show_period'       => array(
							'type'    => 'select',
							'label'   => __( 'Show period of entries', 'now-pages-threadi' ),
							'options' => array(
								'yes' => esc_html__( 'Show', 'now-pages-threadi' ),
								'no'  => esc_html__( 'Hide', 'now-pages-threadi' ),
							),
							'default' => 'yes',
						),
						'group_by_category' => array(
							'type'    => 'select',
							'label'   => __( 'Group by category', 'now-pages-threadi' ),
							'options' => array(
								'yes' => esc_html__( 'Show', 'now-pages-threadi' ),
								'no'  => esc_html__( 'Hide', 'now-pages-threadi' ),
							),
							'default' => 'yes',
						),
					),
				),
			),
		),
	)
);
