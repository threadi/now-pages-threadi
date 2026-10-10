<?php
/**
 * Show entries in verbatim format.
 *
 * @param string $text The entry text.
 *
 * @version: 1.0.0
 * @package now-pages-threadi
 */

declare(strict_types=1);

// prevent direct access.
defined( 'ABSPATH' ) || exit;

echo '<ul class="now-pages-threadi-digest-list">' . wp_kses_post( $text ) . '</ul>';
