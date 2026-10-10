<?php
/**
 * File to render the output of this Breakdance Element.
 *
 * @package now-pages-threadi
 */

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Digests\Digest_Builder;

// bail if $propertiesData is missing.
if ( ! isset( $propertiesData ) ) {
	return;
}

// get the attributes.
$now_pages_threadi_attributes = ! empty( $propertiesData['content']['digest'] ) && is_array( $propertiesData['content']['digest'] ) ? $propertiesData['content']['digest'] : array();

// only pass known attributes: the element data may contain anything.
$now_pages_threadi_args = array();
foreach ( array( 'period', 'format' ) as $now_pages_threadi_name ) {
	if ( ! empty( $now_pages_threadi_attributes[ $now_pages_threadi_name ] ) && is_string( $now_pages_threadi_attributes[ $now_pages_threadi_name ] ) ) {
		$now_pages_threadi_args[ $now_pages_threadi_name ] = $now_pages_threadi_attributes[ $now_pages_threadi_name ];
	}
}

// only pass the display settings when the element was told to override them.
if ( ! empty( $now_pages_threadi_attributes['override_display'] ) ) {
	foreach ( array( 'show_time', 'show_source', 'link_source', 'show_period', 'group_by_category' ) as $now_pages_threadi_name ) {
		if ( isset( $now_pages_threadi_attributes[ $now_pages_threadi_name ] ) ) {
			$now_pages_threadi_args[ $now_pages_threadi_name ] = (bool) $now_pages_threadi_attributes[ $now_pages_threadi_name ];
		}
	}
}

echo wp_kses_post( ( new Digest_Builder() )->build( $now_pages_threadi_args ) );
