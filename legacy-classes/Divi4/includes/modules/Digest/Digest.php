<?php
/**
 * File for digest widget.
 *
 * @package now-pages-threadi
 */

// prevent direct access.
use NowPagesThreadi\Digests\Digests;
use NowPagesThreadi\Digests\Display_Settings;
use NowPagesThreadi\Helper\Plugin;
use NowPagesThreadi\PageBuilder\Divi4\Render;
use NowPagesThreadi\PageBuilder\Shortcode;

defined( 'ABSPATH' ) || exit;

/**
 * Initialize the filter linklist-widget for Divi.
 */
class NowPagesThreadiDivi4Digest extends ET_Builder_Module {

	/**
	 * Set widget-slug.
	 *
	 * @var string
	 */
	public $slug = 'now_pages_threadi_digest';

	/**
	 * Enable visual editor support.
	 *
	 * @var string
	 */
	public $vb_support = 'on';

	/**
	 * Define credentials.
	 *
	 * @var string[]
	 */
	protected $module_credits = array(
		'module_uri' => '',
		'author'     => 'Thomas Zwirner',
		'author_uri' => 'https://plugins.thomaszwirner.de',
	);

	/**
	 * Initialize the widget.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->module_credits['author_uri'] = Plugin::get_plugin_support_url();
		$this->name                         = esc_html__( 'Digest', 'now-pages-threadi' );
	}

	/**
	 * Define fields to configure the widget.
	 *
	 * @return array[]
	 */
	public function get_fields(): array {
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
			'period' => array(
				'label'           => esc_html__( 'Period', 'now-pages-threadi' ),
				'type'            => 'select',
				'option_category' => 'layout',
				'options'         => $periods,
				'default'         => Digests::get_instance()->get_default_period(),
				'toggle_slug'     => 'now_pages_threadi',
				'sub_toggle'      => 'settings',
			),
			'format'     => array(
				'label'           => esc_html__( 'Format', 'now-pages-threadi' ),
				'type'            => 'select',
				'option_category' => 'layout',
				'options'         => $formats,
				'default'         => Digests::get_instance()->get_default_format(),
				'toggle_slug'     => 'now_pages_threadi',
				'sub_toggle'      => 'settings',
			),
			'override_display'               => array(
				'label'           => esc_html__( 'Use own display settings', 'now-pages-threadi' ),
				'type'            => 'yes_no_button',
				'option_category' => 'layout',
				'options'         => array(
					'on'  => esc_html__( 'Yes', 'now-pages-threadi' ),
					'off' => esc_html__( 'No', 'now-pages-threadi' ),
				),
				'default'         => 'off',
				'toggle_slug'     => 'now_pages_threadi',
				'sub_toggle'      => 'settings',
			),

			'show_time'               => array(
				'label'           => esc_html__( 'Show times', 'now-pages-threadi' ),
				'type'            => 'yes_no_button',
				'option_category' => 'layout',
				'options'         => array(
					'on'  => esc_html__( 'Yes', 'now-pages-threadi' ),
					'off' => esc_html__( 'No', 'now-pages-threadi' ),
				),
				'default'         => 'off',
				'toggle_slug'     => 'now_pages_threadi',
				'sub_toggle'      => 'settings',
			),
			'show_source'               => array(
				'label'           => esc_html__( 'Show data source', 'now-pages-threadi' ),
				'type'            => 'yes_no_button',
				'option_category' => 'layout',
				'options'         => array(
					'on'  => esc_html__( 'Yes', 'now-pages-threadi' ),
					'off' => esc_html__( 'No', 'now-pages-threadi' ),
				),
				'default'         => 'off',
				'toggle_slug'     => 'now_pages_threadi',
				'sub_toggle'      => 'settings',
			),
			'link_source'               => array(
				'label'           => esc_html__( 'Link the data source', 'now-pages-threadi' ),
				'type'            => 'yes_no_button',
				'option_category' => 'layout',
				'options'         => array(
					'on'  => esc_html__( 'Yes', 'now-pages-threadi' ),
					'off' => esc_html__( 'No', 'now-pages-threadi' ),
				),
				'default'         => 'off',
				'toggle_slug'     => 'now_pages_threadi',
				'sub_toggle'      => 'settings',
			),
			'show_period'               => array(
				'label'           => esc_html__( 'Show period of entries', 'now-pages-threadi' ),
				'type'            => 'yes_no_button',
				'option_category' => 'layout',
				'options'         => array(
					'on'  => esc_html__( 'Yes', 'now-pages-threadi' ),
					'off' => esc_html__( 'No', 'now-pages-threadi' ),
				),
				'default'         => 'on',
				'toggle_slug'     => 'now_pages_threadi',
				'sub_toggle'      => 'settings',
			),
			'group_by_category'               => array(
				'label'           => esc_html__( 'Group by category', 'now-pages-threadi' ),
				'type'            => 'yes_no_button',
				'option_category' => 'layout',
				'options'         => array(
					'on'  => esc_html__( 'Yes', 'now-pages-threadi' ),
					'off' => esc_html__( 'No', 'now-pages-threadi' ),
				),
				'default'         => 'on',
				'toggle_slug'     => 'now_pages_threadi',
				'sub_toggle'      => 'settings',
			),
		);
	}

	/**
	 * Add our custom toggle for our settings.
	 *
	 * @return array[]
	 */
	public function get_settings_modal_toggles(): array {
		return array(
			'advanced' => array(
				'toggles' => array(
					'now_pages_threadi' => array(
						'priority'          => 24,
						'tabbed_subtoggles' => false,
						'title'             => __( 'Digest', 'now-pages-threadi' ),
					),
				),
			),
		);
	}

	/**
	 * Render the widget.
	 *
	 * @param array  $unprocessed_props List of properties.
	 * @param string $content The Content.
	 * @param string $render_slug The used Slug.
	 *
	 * @return string
	 * @noinspection PhpParameterNameChangedDuringInheritanceInspection
	 */
	public function render( $unprocessed_props, $content = null, $render_slug = '' ): string {
		if ( $this->slug !== $render_slug ) {
			return '';
		}

		// this module uses the same tag as our core shortcode and replaces it while
		// Divi is active. a shortcode written by hand (not saved by the builder, so
		// without "_builder_version") is handed to the core shortcode, so all its
		// attributes (user, category, language, …) keep working.
		if ( ! is_array( $unprocessed_props ) || ! isset( $unprocessed_props['_builder_version'] ) ) {
			return Shortcode::render( is_array( $unprocessed_props ) ? $unprocessed_props : array() );
		}

		// get rendered output.
		return Render::get_instance()->get_entries( $this->props );
	}

	/**
	 * Set import for some CSS.
	 *
	 * @return array
	 */
	public function get_advanced_fields_config(): array {
		return array(
			'background'     => array(
				'css' => array(
					'important' => true,
				),
			),
			'margin_padding' => array(
				'css' => array(
					'important' => 'all',
				),
			),
		);
	}
}

/**
 * Initialize the widget.
 */
new NowPagesThreadiDivi4Digest();
