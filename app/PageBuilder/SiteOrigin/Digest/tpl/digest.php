<?php
/**
 * Template for output of our widget with SiteOrigin.
 *
 * @param array<string,mixed> $instance This contains the parameter from SiteOrigin.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Digests\Digest_Builder;
use NowPagesThreadi\Digests\Digests;

// bail if instance is not set.
if ( empty( $instance ) ) {
	return;
}

$now_pages_threadi_args = array(
	'period' => $instance['period'],
	'format' => $instance['format'],
);

// only pass the display settings when the block was told to override
// them. otherwise every block would beat the settings of the website.
if ( isset( $instance['override_display'] ) && 'yes' === $instance['override_display'] ) {
	$now_pages_threadi_names = array( 'show_time', 'show_source', 'link_source', 'show_period', 'group_by_category' );

	foreach ( $now_pages_threadi_names as $now_pages_threadi_name ) {
		if ( ! isset( $instance[ $now_pages_threadi_name ] ) ) {
			continue;
		}

		$now_pages_threadi_args[ $now_pages_threadi_name ] = 'yes' === $instance[ $now_pages_threadi_name ];
	}
}

// get the output.
echo wp_kses( ( new Digest_Builder() )->build( $now_pages_threadi_args ), Digests::get_instance()->get_allowed_html() );
