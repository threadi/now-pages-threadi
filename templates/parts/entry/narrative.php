<?php
/**
 * Show entries in narrative format.
 *
 * @param string $text The entry text.
 *
 * @version: 1.0.0
 * @package threadi-now-pages
 */

declare(strict_types=1);

// prevent direct access.
defined( 'ABSPATH' ) || exit;

echo '<p class="threadi-now-pages-digest-narrative">' . wp_kses_post( $text ) . '</p>';
