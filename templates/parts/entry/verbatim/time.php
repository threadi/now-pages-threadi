<?php
/**
 * Show the time of a single entry in verbatim format.
 *
 * @param string $datetime The datetime for the attribute.
 * @param string $time_to_show The time to show for user.
 *
 * @version: 1.0.0
 * @package now-pages-threadi
 */

declare(strict_types=1);

// prevent direct access.
defined( 'ABSPATH' ) || exit;

echo '<time class="now-pages-threadi-digest-time" datetime="' . esc_attr( $datetime ) . '">' . esc_html( $time_to_show ) . '</time> ';
