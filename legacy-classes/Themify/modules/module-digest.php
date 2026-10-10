<?php
/**
 * This file contains a Themify widget.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Helper\Plugin;

/**
 * Object to handle a Themify widget.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Themify derives the module from this exact class name, a prefix of ours would break the registration.
class TB_Digest_Module extends Themify_Builder_Component_Module {

	/**
	 * Return the module name.
	 *
	 * @return string
	 */
	public static function get_module_name(): string {
		add_filter( 'themify_builder_active_vars', array( __CLASS__, 'builder_active_enqueue' ) );

		return __( 'Digest', 'now-pages-threadi' );
	}

	/**
	 * Return the icon shown for this module in the Builder panel.
	 *
	 * @return string
	 */
	public static function get_module_icon(): string {
		return 'calendar';
	}

	/**
	 * Enqueue the module JS. This is a plain static file - Builder's own
	 * addon-loader appends ".js?ver=..." to the given URL, so it MUST
	 * literally end in ".js" with no query string.
	 *
	 * The lists the fields are built from do not travel with the file: they
	 * are printed as a global right before, see print_builder_data().
	 *
	 * @param array<string,mixed> $vars The vars of the builder.
	 *
	 * @return array<string,mixed>
	 */
	public static function builder_active_enqueue( array $vars ): array {
		$url = Plugin::get_plugin_url() . 'legacy-classes/Themify/assets/digest.js';

		$vars['addons'][ $url ] = Plugin::get_file_version( Plugin::get_plugin_path() . 'legacy-classes/Themify/assets/digest.js' );

		return $vars;
	}

	/**
	 * Whether a Builder checkbox field is "checked", regardless of the exact
	 * shape the Builder happens to store it in (array of selected values, or
	 * a plain scalar).
	 *
	 * @param mixed $value The raw stored value.
	 *
	 * @return bool
	 */
	public static function is_enabled( mixed $value ): bool {
		if ( is_array( $value ) ) {
			return in_array( 'yes', $value, true );
		}

		return in_array( $value, array( 'yes', '1', 1, true ), true );
	}
}
