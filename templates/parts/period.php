<?php
/**
 * Show the period your entries appears.
 *
 * @param string $text The period text.
 *
 * @version: 1.0.0
 * @package threadi-now-pages
 */

declare(strict_types=1);

// prevent direct access.
defined( 'ABSPATH' ) || exit;

echo '<p class="threadi-now-pages-digest-period">' . esc_html( $text ) . '</p>';
