<?php
/**
 * Show a single entry in verbatim format.
 *
 * @param WP_Post $entry The entry object.
 * @param string $line The text.
 * @param array $settings The resolved display settings.
 *
 * @version: 1.0.0
 * @package threadi-now-pages
 */

declare(strict_types=1);

// prevent direct access.
defined( 'ABSPATH' ) || exit;

// the source is only revealed, if it should be shown.
$threadi_now_pages_source_attr = '';
if ( ! empty( $settings['show_source'] ) && $entry->post_parent ) {
	$threadi_now_pages_source_attr = ' data-source="' . absint( $entry->post_parent ) . '"';
}

echo '<li class="threadi-now-pages-digest-entry" data-entry="' . absint( $entry->ID ) . '"' . $threadi_now_pages_source_attr . '>' . wp_kses_post( $line ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute is built from absint().
