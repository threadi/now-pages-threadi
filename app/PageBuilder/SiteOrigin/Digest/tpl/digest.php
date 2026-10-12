<?php
/**
 * Template for output of our widget with SiteOrigin.
 *
 * @param array<string,mixed> $instance This contains the parameter from SiteOrigin.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Digests\Digest_Builder;
use ThreadiNowPages\Digests\Digests;

// bail if instance is not set.
if ( empty( $instance ) ) {
	return;
}

$threadi_now_pages_args = array(
	'period' => $instance['period'],
	'format' => $instance['format'],
);

// only pass the display settings when the block was told to override
// them. otherwise every block would beat the settings of the website.
if ( isset( $instance['override_display'] ) && 'yes' === $instance['override_display'] ) {
	$threadi_now_pages_names = array( 'show_time', 'show_source', 'link_source', 'show_period', 'group_by_category' );

	foreach ( $threadi_now_pages_names as $threadi_now_pages_name ) {
		if ( ! isset( $instance[ $threadi_now_pages_name ] ) ) {
			continue;
		}

		$threadi_now_pages_args[ $threadi_now_pages_name ] = 'yes' === $instance[ $threadi_now_pages_name ];
	}
}

// get the output.
echo wp_kses( ( new Digest_Builder() )->build( $threadi_now_pages_args ), Digests::get_instance()->get_allowed_html() );
