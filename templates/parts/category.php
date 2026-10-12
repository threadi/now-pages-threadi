<?php
/**
 * Show the category of grouped entries.
 *
 * @param string $label The category title.
 *
 * @version: 1.0.0
 * @package threadi-now-pages
 */

declare(strict_types=1);

// prevent direct access.
defined( 'ABSPATH' ) || exit;

echo '<h3 class="threadi-now-pages-digest-category">' . esc_html( $label ) . '</h3>';
