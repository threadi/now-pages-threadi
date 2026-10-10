<?php
/**
 * Define output for the widget.
 *
 * @param stdClass $settings The settings object from Beaver.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Digests\Digest_Builder;
use NowPagesThreadi\Digests\Digests;

$now_pages_threadi_args = array(
	'period' => ! empty( $settings->period ) ? $settings->period : Digests::get_instance()->get_default_period(),
	'format' => ! empty( $settings->format ) ? $settings->format : Digests::get_instance()->get_default_format(),
);
if ( isset( $settings->override_display ) && 'yes' === $settings->override_display ) {
	$now_pages_threadi_args['show_time']         = $settings->show_time;
	$now_pages_threadi_args['show_source']       = $settings->show_source;
	$now_pages_threadi_args['link_source']       = $settings->link_source;
	$now_pages_threadi_args['show_period']       = $settings->show_period;
	$now_pages_threadi_args['group_by_category'] = $settings->group_by_category;
}

echo wp_kses( ( new Digest_Builder() )->build( $now_pages_threadi_args ), Digests::get_instance()->get_allowed_html() );
