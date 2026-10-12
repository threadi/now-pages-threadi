<?php
/**
 * File for an Element in Breakdance.
 *
 * @package threadi-now-pages
 */

namespace NowPageBreakdanceElements;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Digests\Digests;
use ThreadiNowPages\Digests\Display_Settings;
use ThreadiNowPages\Helper\Plugin;
use function Breakdance\Elements\c;

/**
 * Register the element for editing.
 */
\Breakdance\ElementStudio\registerElementForEditing(
	'NowPageBreakdanceElements\\Digest',
	\Breakdance\Util\getdirectoryPathRelativeToPluginFolder( __DIR__ )
);

/**
 * Object, which represents the element.
 */
class Digest extends \Breakdance\Elements\Element {

	/**
	 * Return the title for the element.
	 *
	 * @return string
	 */
	static function name(): string {
		return __( 'Digest', 'threadi-now-pages' );
	}

	/**
	 * Return the class name for the element.
	 *
	 * @return string
	 */
	static function className(): string {
		return 'threadi-now-pages-digest';
	}

	/**
	 * Define the controls for the element.
	 *
	 * @return array<string,mixed>
	 */
	static function contentControls(): array {
		// collect the formats.
		$formats = array();
		foreach ( Digests::get_instance()->get_formats() as $format ) {
			$formats[] = array(
				'value' => $format->get_slug(),
				'text' => $format->get_label()
			);
		}

		// collect the periods.
		$periods = array();
		foreach ( Digests::get_instance()->get_periods() as $slug => $label ) {
			$periods[] = array(
				'value' => $slug,
				'text' => $label
			);
		}

		// hand the current defaults over, so switching the override on does
		// not change the output until the user actually changes something.
		$defaults = Display_Settings::resolve( array() );

		unset( $defaults['_origin'] );

		// return the controls.
		return array(
			c(
				'digest',
				'Digest',
				array(
					c(
						'period',
						'Period',
						array(),
						array(
							'type'   => 'dropdown',
							'layout' => 'vertical',
							'items'  => $periods,
						),
						false,
						false,
					),
					c(
						'format',
						'Format',
						array(),
						array(
							'type'   => 'dropdown',
							'layout' => 'vertical',
							'items'  => $formats,
						),
						false,
						false,
					),
					c(
						'override_display',
						'Use own display settings',
						array(),
						array(
							'type'   => 'toggle',
							'layout' => 'vertical',
						),
						false,
						false,
					),
					c(
						'show_time',
						'Show times',
						array(),
						array(
							'type'   => 'toggle',
							'layout' => 'vertical',
						),
						false,
						false,
					),
					c(
						'show_source',
						'Show data source',
						array(),
						array(
							'type'   => 'toggle',
							'layout' => 'vertical',
						),
						false,
						false,
					),
					c(
						'link_source',
						'Link the data source',
						array(),
						array(
							'type'   => 'toggle',
							'layout' => 'vertical',
						),
						false,
						false,
					),
					c(
						'show_period',
						'Show period of entries',
						array(),
						array(
							'type'   => 'toggle',
							'layout' => 'vertical',
						),
						false,
						false,
					),
					c(
						'group_by_category',
						'Group by category',
						array(),
						array(
							'type'   => 'toggle',
							'layout' => 'vertical',
						),
						false,
						false,
					),
				),
				array(
					'type'   => 'section',
					'layout' => 'vertical',
				),
				false,
				false,
			),
		);
	}

	/**
	 * Return the default properties.
	 *
	 * @return bool|array<string,mixed>
	 */
	static function defaultProperties(): bool|array {
		return array(
			'content' => array(
				'digest' => array(
					'period' => 'live',
					'format'    => 'verbatim',
					'override_display' => false,
					'show_time' => false,
					'show_source' => false,
					'link_source' => false,
					'show_period' => true,
					'group_by_category' => true,
				),
			),
		);
	}

	/**
	 * Return the icon for the element.
	 *
	 * @return string
	 */
	static function uiIcon(): string {
		return '';
	}

	/**
	 * Return the template for the element.
	 *
	 * Hint: we use 1 template for all elements as this contains only 1 string.
	 *
	 * @return string
	 */
	static function template(): string {
		// get the WP_Filesystem object.
		$wp_filesystem = Plugin::get_wp_filesystem();

		// get the template content.
		$template_content = $wp_filesystem->get_contents( Plugin::get_plugin_path() . 'legacy-classes/Breakdance/html.twig' );

		// bail if template is not available.
		if( ! is_string( $template_content ) ) {
			return '';
		}

		// return the template content.
		return $template_content;
	}

	/**
	 * Return the tag for the element.
	 *
	 * @return string
	 */
	static function tag(): string {
		return 'div';
	}

	/**
	 * Return the tag options.
	 *
	 * @return array<string, string>
	 */
	static function tagOptions(): array {
		return array();
	}

	/**
	 * Return the path to the tag control.
	 *
	 * @return bool
	 */
	static function tagControlPath(): bool {
		return false;
	}

	/**
	 * Return the category of the element.
	 *
	 * @return string
	 */
	static function category(): string {
		return 'threadi-now-pages';
	}

	/**
	 * Return the badge.
	 *
	 * @return bool
	 */
	static function badge(): bool {
		return false;
	}

	/**
	 * Return the slug of the element.
	 *
	 * @return string
	 */
	static function slug(): string {
		return __CLASS__;
	}

	/**
	 * Return the default children.
	 *
	 * @return false
	 */
	static function defaultChildren(): bool {
		return false;
	}

	/**
	 * Return the controls.
	 *
	 * @return array<string,mixed>
	 */
	static function designControls(): array {
		return array();
	}

	/**
	 * Return the controls.
	 *
	 * @return array<string,mixed>
	 */
	static function settingsControls(): array {
		return array();
	}

	/**
	 * Return the dependencies.
	 *
	 * @return false
	 */
	static function dependencies(): bool {
		return false;
	}

	/**
	 * Return the settings.
	 *
	 * @return bool|array
	 */
	static function settings(): bool|array {
		return false;
	}

	/**
	 * Return the panel rules.
	 *
	 * @return false
	 */
	static function addPanelRules(): bool {
		return false;
	}

	/**
	 * Return the actions.
	 *
	 * @return false
	 */
	public static function actions(): bool {
		return false;
	}

	/**
	 * Return the nesting rule.
	 *
	 * @return array<string,mixed>
	 */
	static function nestingRule(): array {
		return array( 'type' => 'final' );
	}

	/**
	 * Return the spacing bars.
	 *
	 * @return bool
	 */
	static function spacingBars(): bool {
		return false;
	}

	/**
	 * Return the attributes.
	 *
	 * @return bool
	 */
	static function attributes(): bool {
		return false;
	}

	/**
	 * Return the experimental flag.
	 *
	 * @return false
	 */
	static function experimental(): bool {
		return false;
	}

	/**
	 * Return the builders this element is available in.
	 *
	 * Oxygen 6 runs the same codebase in another mode. Without naming it
	 * here the element is filtered out before it reaches the panel.
	 *
	 * @return array<int,string>
	 */
	static function availableIn(): array {
		return array( 'breakdance', 'oxygen' );
	}

	/**
	 * Return the order.
	 *
	 * @return int
	 */
	static function order(): int {
		return 0;
	}

	/**
	 * Return the dynamic property paths.
	 *
	 * @return array<string,mixed>
	 */
	static function dynamicPropertyPaths(): array {
		return array();
	}

	/**
	 * Return the additional classes.
	 *
	 * @return bool
	 */
	static function additionalClasses(): bool {
		return false;
	}

	/**
	 * Return the project management.
	 *
	 * @return bool
	 */
	static function projectManagement(): bool {
		return false;
	}

	/**
	 * Return the property paths to whitelist in flat props.
	 *
	 * @return bool|string
	 */
	static function propertyPathsToWhitelistInFlatProps(): bool|string {
		return false;
	}

	/**
	 * Return the property paths to the SSR element when the value changes.
	 *
	 * @return bool|string
	 */
	static function propertyPathsToSsrElementWhenValueChanges(): bool|string {
		return false;
	}
}
