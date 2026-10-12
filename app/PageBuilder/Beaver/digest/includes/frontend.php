<?php
/**
 * Define output for the widget.
 *
 * @param stdClass $settings The settings object from Beaver.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Digests\Digest_Builder;
use ThreadiNowPages\Digests\Digests;

$threadi_now_pages_args = array(
	'period' => ! empty( $settings->period ) ? $settings->period : Digests::get_instance()->get_default_period(),
	'format' => ! empty( $settings->format ) ? $settings->format : Digests::get_instance()->get_default_format(),
);
if ( isset( $settings->override_display ) && 'yes' === $settings->override_display ) {
	$threadi_now_pages_args['show_time']         = $settings->show_time;
	$threadi_now_pages_args['show_source']       = $settings->show_source;
	$threadi_now_pages_args['link_source']       = $settings->link_source;
	$threadi_now_pages_args['show_period']       = $settings->show_period;
	$threadi_now_pages_args['group_by_category'] = $settings->group_by_category;
}

echo wp_kses( ( new Digest_Builder() )->build( $threadi_now_pages_args ), Digests::get_instance()->get_allowed_html() );
