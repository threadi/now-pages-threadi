<?php
/**
 * Show the overrides for entries from categories or sources.
 *
 * @param int $group_entries_count The count of group entries.
 * @param string $override The override content.
 *
 * @version: 1.0.0
 * @package threadi-now-pages
 */

declare(strict_types=1);

// prevent direct access.
defined( 'ABSPATH' ) || exit;

echo '<div class="threadi-now-pages-digest-override" data-entries="' . absint( $group_entries_count ) . '">' . wp_kses_post( $override ) . '</div>';
