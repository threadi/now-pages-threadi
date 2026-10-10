<?php
/**
 * File for the object to handle the now pages entries module in Divi 5.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\PageBuilder\Divi5\Digest;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ET\Builder\Framework\DependencyManagement\Interfaces\DependencyInterface;
use ET\Builder\FrontEnd\Module\Style;
use ET\Builder\Packages\Module\Layout\Components\ModuleElements\ModuleElements;
use ET\Builder\Packages\Module\Options\Css\CssStyle;
use ET\Builder\Packages\Module\Options\Element\ElementClassnames;
use ET\Builder\Packages\Module\Options\Text\TextClassnames;
use ET\Builder\Packages\Module\Options\Text\TextStyle;
use ET\Builder\Packages\ModuleLibrary\ModuleRegistration;
use NowPagesThreadi\Digests\Digest_Builder;
use NowPagesThreadi\Digests\Digests;
use NowPagesThreadi\Helper\Plugin;
use NowPagesThreadi\Plugin\Log;
use WP_Block;

/**
 * Object to handle the Description_Part module in Divi 5.
 */
class Digest implements DependencyInterface {

	/**
	 * The module name.
	 *
	 * @var string
	 */
	private string $name = 'digest';

	/**
	 * Define the render callback for this module.
	 *
	 * @param array<string,mixed> $attrs List of arguments.
	 * @param string              $content The content.
	 * @param WP_Block            $block The block object.
	 * @param ModuleElements      $elements The ModuleElements object.
	 * @return string
	 */
	public static function render_callback( $attrs, $content, $block, $elements ): string {
		// return the rendering of this module.
		return self::render_content( $attrs, $content );
	}

	/**
	 * Define the module class.
	 *
	 * @param array<string,mixed> $args The arguments.
	 * @return void
	 */
	public static function module_classnames( array $args ): void {
		$classnames_instance = $args['classnamesInstance'];
		$attrs               = $args['attrs'];

		// Text Options.
		$classnames_instance->add(
			TextClassnames::text_options_classnames(
				$attrs['module']['advanced']['text'] ?? array(),
				array(
					'orientation' => false,
				)
			),
			true
		);

		// Module.
		$classnames_instance->add(
			ElementClassnames::classnames(
				array(
					'attrs' => array_merge(
						$attrs['module']['decoration'] ?? array(),
						array(
							'link' => $attrs['module']['advanced']['link'] ?? array(),
						)
					),
				)
			)
		);
	}

	/**
	 * Define the module styles.
	 *
	 * @param array<string,mixed> $args List of arguments.
	 * @return void
	 */
	public static function module_styles( array $args ): void {
		$attrs    = $args['attrs'] ?? array();
		$elements = $args['elements'];
		$settings = $args['settings'] ?? array();

		Style::add(
			array(
				'id'            => $args['id'],
				'name'          => $args['name'],
				'orderIndex'    => $args['orderIndex'],
				'storeInstance' => $args['storeInstance'],
				'styles'        => array(
					// Element: Module.
					$elements->style(
						array(
							'attrName'   => 'module',
							'styleProps' => array(
								'disabledOn' => array(
									'disabledModuleVisibility' => $settings['disabledModuleVisibility'] ?? null,
								),
							),
						)
					),
					CssStyle::style(
						array(
							'selector' => $args['orderClass'],
							'attr'     => $attrs['css'] ?? array(),
						)
					),
					TextStyle::style(
						array(
							'selector'          => "{$args['orderClass']} .now-pages-threadi-content",
							'attr'              => $attrs['module']['advanced']['text'] ?? array(),
							'propertySelectors' => array(
								'textShadow' => array(
									'desktop' => array(
										'value' => array(
											'text-shadow' => "{$args['orderClass']} .now-pages-threadi-content",
										),
									),
								),
							),
						)
					),
				),
			)
		);
	}

	/**
	 * Define the script data.
	 *
	 * @param array<string,mixed> $args The arguments.
	 * @return void
	 */
	public static function module_script_data( array $args ): void {
		$args['elements']->script_data( array( 'attrName' => 'module' ) );
	}

	/**
	 * Return the REST controller.
	 *
	 * @return string
	 */
	public function get_rest_object(): string {
		return Rest::class;
	}

	/**
	 * Return the content of the module to render for Divi 5 editor.
	 *
	 * @param array  $attrs The attributes.
	 * @param string $content The content.
	 *
	 * @return string
	 */
	public static function render_content( array $attrs, string $content ): string {
		$args = array(
			'period' => self::get_attr_value( $attrs, 'period', Digests::get_instance()->get_default_period() ),
			'format' => self::get_attr_value( $attrs, 'format', Digests::get_instance()->get_default_format() ),
		);

		if ( 'on' === self::get_attr_value( $attrs, 'overrideDisplay', 'off' ) ) {
			$names = array(
				'showTime'        => 'show_time',
				'showSource'      => 'show_source',
				'linkSource'      => 'link_source',
				'showPeriod'      => 'show_period',
				'groupByCategory' => 'group_by_category',
			);

			foreach ( $names as $attr => $name ) {
				$args[ $name ] = 'on' === self::get_attr_value( $attrs, $attr, 'off' );
			}
		}

		return ( new Digest_Builder() )->build( $args );
	}

	/**
	 * Return the desktop value of a Divi 5 attribute.
	 *
	 * @param array<string,mixed> $attrs         All attributes of the module.
	 * @param string              $name          The name of the attribute.
	 * @param string              $default_value The value to use if nothing is set.
	 *
	 * @return string
	 */
	private static function get_attr_value( array $attrs, string $name, string $default_value ): string {
		if ( empty( $attrs['digest']['advanced'][ $name ]['desktop']['value'] ) ) {
			return $default_value;
		}

		return (string) $attrs['digest']['advanced'][ $name ]['desktop']['value'];
	}

	/**
	 * Load the module.
	 *
	 * @return void
	 */
	public function load() {
		$this->register_module();
	}

	/**
	 * Register the module.
	 *
	 * @return void
	 */
	private function register_module(): void {
		// get WP_Filesystem.
		$wp_filesystem = Plugin::get_wp_filesystem();

		// set the JSON folder path.
		$module_json_folder_path = Plugin::get_plugin_path() . 'divi5/src/modules/' . $this->name;

		// bail if the path is missing.
		if ( ! $wp_filesystem->exists( $module_json_folder_path ) ) {
			return;
		}

		// register this module.
		try {
			ModuleRegistration::register_module(
				$module_json_folder_path,
				array(
					'render_callback' => array( $this, 'render_callback' ),
				)
			);
		} catch ( \Exception $e ) {
			// log this event.
			Log::get_instance()->add( __( 'Error while loading Divi 5 module:', 'now-pages-threadi' ) . ' <code>' . $e->getMessage() . '</code>', 'error', 'pagebuilder' );
		}
	}
}
