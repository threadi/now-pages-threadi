<?php
/**
 * This file contains the digest module for Live Composer.
 *
 * Live Composer instantiates a module with `new $module_id()`, so the class
 * has to live in the global namespace and its name has to match exactly the
 * id it is registered with. The id is also written into the page code, so it
 * must never change once a user has placed the module on a page.
 *
 * The file is required from NowPagesThreadi\PageBuilder\LiveComposer::register_module(),
 * because DSLC_Module only exists once Live Composer itself has loaded.
 *
 * @package now-pages-threadi
 */

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Digests\Digest_Builder;
use NowPagesThreadi\Digests\Digests;
use NowPagesThreadi\Digests\Display_Settings;

/**
 * Object for the digest module in Live Composer.
 */
class NowPagesThreadi_DSLC_Digest extends DSLC_Module {

	/**
	 * Unique module id. Must match the class name.
	 *
	 * @var string
	 */
	public $module_id;

	/**
	 * Module label shown in the page builder.
	 *
	 * @var string
	 */
	public $module_title;

	/**
	 * Module icon, a FontAwesome name without its prefix.
	 *
	 * @var string
	 */
	public $module_icon;

	/**
	 * Section of the module panel this module appears in.
	 *
	 * @var string
	 */
	public $module_category;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->module_id = 'NowPagesThreadi_DSLC_Digest';
		$this->module_title = __( 'Now Pages Digest', 'now-pages-threadi' );
		$this->module_icon = 'list';
		// Live Composer asks third party modules to use this category,
		// see the doc block of its own modules.
		$this->module_category = 'Extensions';
	}

	/**
	 * Return the options of this module.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function options() {
		// collect the periods.
		$periods = array();

		foreach ( Digests::get_instance()->get_periods() as $slug => $label ) {
			$periods[] = array(
				'label' => $label,
				'value' => $slug,
			);
		}

		// collect the formats.
		$formats = array();

		foreach ( Digests::get_instance()->get_formats() as $format ) {
			$formats[] = array(
				'label' => $format->get_label(),
				'value' => $format->get_slug(),
			);
		}

		// hand the current defaults over, so switching the override on does
		// not change the output until the user actually changes something.
		$defaults = Display_Settings::resolve( array() );
		$switches = array(
			'show_time'         => __( 'Show times', 'now-pages-threadi' ),
			'show_source'       => __( 'Show data source', 'now-pages-threadi' ),
			'link_source'       => __( 'Link the data source', 'now-pages-threadi' ),
			'show_period'       => __( 'Show period of entries', 'now-pages-threadi' ),
			'group_by_category' => __( 'Group by category', 'now-pages-threadi' ),
		);

		$display_choices = array();
		$display_default = array();

		foreach ( $switches as $name => $label ) {
			$display_choices[] = array(
				'label' => $label,
				'value' => $name,
			);

			if ( ! empty( $defaults[ $name ] ) ) {
				$display_default[] = $name;
			}
		}

		$dslc_options = array(

			// Live Composer handles the responsive visibility itself, but
			// only if the module offers this option.
			array(
				'label'   => __( 'Show On', 'now-pages-threadi' ),
				'id'      => 'css_show_on',
				'std'     => 'desktop tablet phone',
				'type'    => 'checkbox',
				'choices' => array(
					array(
						'label' => __( 'Desktop', 'now-pages-threadi' ),
						'value' => 'desktop',
					),
					array(
						'label' => __( 'Tablet', 'now-pages-threadi' ),
						'value' => 'tablet',
					),
					array(
						'label' => __( 'Phone', 'now-pages-threadi' ),
						'value' => 'phone',
					),
				),
			),

			array(
				'label'   => __( 'Period', 'now-pages-threadi' ),
				'id'      => 'period',
				'std'     => Digests::get_instance()->get_default_period(),
				'type'    => 'select',
				'choices' => $periods,
				'section' => 'functionality',
				'help'    => __( 'Which period the shown entries are from.', 'now-pages-threadi' ),
			),

			array(
				'label'   => __( 'Format', 'now-pages-threadi' ),
				'id'      => 'format',
				'std'     => Digests::get_instance()->get_default_format(),
				'type'    => 'select',
				'choices' => $formats,
				'section' => 'functionality',
				'help'    => __( 'How the entries are rendered.', 'now-pages-threadi' ),
			),

			array(
				'label'   => __( 'Display Settings', 'now-pages-threadi' ),
				'id'      => 'override_display',
				'std'     => '',
				'type'    => 'checkbox',
				'choices' => array(
					array(
						'label' => __( 'Use own display settings', 'now-pages-threadi' ),
						'value' => 'active',
					),
				),
				'section' => 'functionality',
				'help'    => __( 'Without this the module follows the settings of your website.', 'now-pages-threadi' ),
			),

			// Live Composer knows no conditional options, so this one is
			// always visible and simply ignored unless the override above
			// is enabled. The default mirrors the settings of the website.
			array(
				'label'   => __( 'Own Display Settings', 'now-pages-threadi' ),
				'id'      => 'display',
				'std'     => implode( ' ', $display_default ),
				'type'    => 'checkbox',
				'choices' => $display_choices,
				'section' => 'functionality',
				'help'    => __( 'Only used if "Use own display settings" is enabled above.', 'now-pages-threadi' ),
			),

			/**
			 * Styling Options
			 */

			array(
				'label'                 => __( 'BG Color', 'now-pages-threadi' ),
				'id'                    => 'css_main_bg_color',
				'std'                   => '',
				'type'                  => 'color',
				'refresh_on_change'     => false,
				'affect_on_change_el'   => '.dslc-module-now-pages-threadi-digest',
				'affect_on_change_rule' => 'background-color',
				'section'               => 'styling',
			),

			array(
				'label'                 => __( 'Text Color', 'now-pages-threadi' ),
				'id'                    => 'css_main_color',
				'std'                   => '',
				'type'                  => 'color',
				'refresh_on_change'     => false,
				'affect_on_change_el'   => '.dslc-module-now-pages-threadi-digest',
				'affect_on_change_rule' => 'color',
				'section'               => 'styling',
			),

			array(
				'label'                 => __( 'Margin Bottom', 'now-pages-threadi' ),
				'id'                    => 'css_main_margin_bottom',
				'onlypositive'          => true,
				'min'                   => 0,
				'max'                   => 500,
				'std'                   => '0',
				'type'                  => 'slider',
				'refresh_on_change'     => false,
				'affect_on_change_el'   => '.dslc-module-now-pages-threadi-digest',
				'affect_on_change_rule' => 'margin-bottom',
				'section'               => 'styling',
				'ext'                   => 'px',
			),

			array(
				'label'                 => __( 'Padding', 'now-pages-threadi' ),
				'id'                    => 'css_main_padding',
				'onlypositive'          => true,
				'min'                   => 0,
				'max'                   => 500,
				'std'                   => '0',
				'type'                  => 'slider',
				'refresh_on_change'     => false,
				'affect_on_change_el'   => '.dslc-module-now-pages-threadi-digest',
				'affect_on_change_rule' => 'padding',
				'section'               => 'styling',
				'ext'                   => 'px',
			),
		);

		$dslc_options = array_merge( $dslc_options, $this->presets_options() );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook of Live Composer: every module has to pass its options through it, a prefix of ours would cut them off from it.
		return apply_filters( 'dslc_module_options', $dslc_options, $this->module_id );
	}

	/**
	 * Output of this module.
	 *
	 * @param array<string,mixed> $options The values of the options above.
	 *
	 * @return void
	 */
	public function output( $options ) {
		// open the wrapper of Live Composer. it carries the module id, the
		// classes the styling options target and the controls of the editor.
		$this->module_start( $options );

		$args = array(
			'period' => isset( $options['period'] ) ? (string) $options['period'] : 'live',
			'format' => isset( $options['format'] ) ? (string) $options['format'] : 'verbatim',
		);

		// only pass the display settings when the module was told to
		// override them. otherwise every module would beat the settings of
		// the website.
		if ( ! empty( $options['override_display'] ) ) {
			// checkbox values arrive as a space separated string.
			$active = isset( $options['display'] ) ? explode( ' ', (string) $options['display'] ) : array();
			$names = array( 'show_time', 'show_source', 'link_source', 'show_period', 'group_by_category' );

			foreach ( $names as $name ) {
				$args[ $name ] = in_array( $name, $active, true );
			}
		}

		?>
		<div class="dslc-module-now-pages-threadi-digest">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled locally, all dynamic parts are escaped in the formats.
			echo ( new Digest_Builder() )->build( $args );
			?>
		</div>
		<?php

		// close the wrapper of Live Composer.
		$this->module_end( $options );
	}
}
