<?php
/**
 * This file contains the template for our module.
 *
 * @param array<string,mixed> $args The arguments from the module settings.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

// prevent direct access.
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- this template is
// included from inside a method, so its variables are function-scoped despite
// how it looks. The themify_builder_* hooks belong to Themify: every one of its
// modules fires them so extensions can hook into the rendering.

use ThreadiNowPages\Digests\Digest_Builder;
use ThreadiNowPages\Digests\Digests;

$threadi_np_fields_default = array(
	'period'            => Digests::get_instance()->get_default_period(),
	'format'            => Digests::get_instance()->get_default_format(),
	'override_display'  => '',
	'show_time'         => '',
	'show_source'       => '',
	'link_source'       => '',
	'show_period'       => '',
	'group_by_category' => '',
);

$threadi_np_fields_args = wp_parse_args( $args['mod_settings'], $threadi_np_fields_default );
unset( $args['mod_settings'] );

// build the args for the central digest builder, the same way the
// Elementor widget (app/PageBuilder/Elementor/Digest.php) does it, so both
// widgets stay behaviourally identical.
$threadi_np_digest_args = array(
	'period' => ! empty( $threadi_np_fields_args['period'] ) ? $threadi_np_fields_args['period'] : Digests::get_instance()->get_default_period(),
	'format' => ! empty( $threadi_np_fields_args['format'] ) ? $threadi_np_fields_args['format'] : Digests::get_instance()->get_default_format(),
);

// only pass the display settings when the module was told to override them,
// otherwise this one module would beat the site-wide settings for everyone.
if ( TB_Digest_Module::is_enabled( $threadi_np_fields_args['override_display'] ) ) {
	$threadi_np_names = array( 'show_time', 'show_source', 'link_source', 'show_period', 'group_by_category' );

	foreach ( $threadi_np_names as $threadi_np_name ) {
		$threadi_np_digest_args[ $threadi_np_name ] = TB_Digest_Module::is_enabled( $threadi_np_fields_args[ $threadi_np_name ] );
	}
}

$threadi_np_content = ( new Digest_Builder() )->build( $threadi_np_digest_args );

// nothing to show, e.g. no entries in the selected period.
if ( '' === $threadi_np_content ) {
	return;
}

$threadi_np_container_class = apply_filters( 'themify_builder_module_classes', array(
		'module', 'module-' . $args['mod_name'], $args['module_ID'],
	), $args['mod_name'], $args['module_ID'], $threadi_np_fields_args );
if ( ! empty( $args['element_id'] ) ) {
	$threadi_np_container_class[] = 'tb_' . $args['element_id'];
}
$threadi_np_container_props = apply_filters( 'themify_builder_module_container_props', array(
	'id'    => $args['module_ID'],
	'class' => implode( ' ', $threadi_np_container_class ),
), $threadi_np_fields_args, $args['mod_name'], $args['module_ID'] );
?>
<div <?php echo themify_get_element_attributes( $threadi_np_container_props ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- themify escapes every value with esc_attr(), the return value is a ready-made attribute string. ?>>
	<?php do_action( 'themify_builder_before_template_content_render' ); ?>
	<?php echo wp_kses( $threadi_np_content, Digests::get_instance()->get_allowed_html() ); ?>
	<?php do_action( 'themify_builder_after_template_content_render' ); ?>
</div><!-- .module-<?php echo esc_attr( $args['mod_name'] ); ?> -->
