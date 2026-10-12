<?php
/**
 * File to render the output of this Breakdance Element.
 *
 * @package threadi-now-pages
 */

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Digests\Digest_Builder;

// bail if $propertiesData is missing.
if ( ! isset( $propertiesData ) ) {
	return;
}

// get the attributes.
$threadi_now_pages_attributes = ! empty( $propertiesData['content']['digest'] ) && is_array( $propertiesData['content']['digest'] ) ? $propertiesData['content']['digest'] : array();

// only pass known attributes: the element data may contain anything.
$threadi_now_pages_args = array();
foreach ( array( 'period', 'format' ) as $threadi_now_pages_name ) {
	if ( ! empty( $threadi_now_pages_attributes[ $threadi_now_pages_name ] ) && is_string( $threadi_now_pages_attributes[ $threadi_now_pages_name ] ) ) {
		$threadi_now_pages_args[ $threadi_now_pages_name ] = $threadi_now_pages_attributes[ $threadi_now_pages_name ];
	}
}

// only pass the display settings when the element was told to override them.
if ( ! empty( $threadi_now_pages_attributes['override_display'] ) ) {
	foreach ( array( 'show_time', 'show_source', 'link_source', 'show_period', 'group_by_category' ) as $threadi_now_pages_name ) {
		if ( isset( $threadi_now_pages_attributes[ $threadi_now_pages_name ] ) ) {
			$threadi_now_pages_args[ $threadi_now_pages_name ] = (bool) $threadi_now_pages_attributes[ $threadi_now_pages_name ];
		}
	}
}

echo wp_kses_post( ( new Digest_Builder() )->build( $threadi_now_pages_args ) );
