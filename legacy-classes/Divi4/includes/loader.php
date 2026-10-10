<?php
/**
 * File to load the Divi modules.
 *
 * @package now-pages-threadi
 */

// prevent direct access.
defined( 'ABSPATH' ) || exit;

// do not run without Divi.
if ( ! class_exists( 'ET_Builder_Element' ) ) {
	return;
}

$now_pages_threadi_module_files = array();
/**
 * Filter the available Divi widgets.
 *
 * @since 1.0.0 Available since 1.0.0.
 * @param array<int,string> $module_files List of modules.
 */
$now_pages_threadi_module_files = apply_filters( 'now_pages_threadi_divi4_widgets', $now_pages_threadi_module_files );

// Load custom Divi Builder modules.
foreach ( (array) $now_pages_threadi_module_files as $now_pages_threadi_module_file ) {
	if ( $now_pages_threadi_module_file && preg_match( "/\/modules\/\b([^\/]+)\/\\1\.php$/", $now_pages_threadi_module_file ) ) {
		require_once $now_pages_threadi_module_file;
	}
}
