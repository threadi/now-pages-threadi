<?php
/**
 * File to load the Divi modules.
 *
 * @package threadi-now-pages
 */

// prevent direct access.
defined( 'ABSPATH' ) || exit;

// do not run without Divi.
if ( ! class_exists( 'ET_Builder_Element' ) ) {
	return;
}

$threadi_now_pages_module_files = array();
/**
 * Filter the available Divi widgets.
 *
 * @since 1.0.0 Available since 1.0.0.
 * @param array<int,string> $module_files List of modules.
 */
$threadi_now_pages_module_files = apply_filters( 'threadi_now_pages_divi4_widgets', $threadi_now_pages_module_files );

// Load custom Divi Builder modules.
foreach ( (array) $threadi_now_pages_module_files as $threadi_now_pages_module_file ) {
	if ( $threadi_now_pages_module_file && preg_match( "/\/modules\/\b([^\/]+)\/\\1\.php$/", $threadi_now_pages_module_file ) ) {
		require_once $threadi_now_pages_module_file;
	}
}
