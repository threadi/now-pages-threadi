<?php
/**
 * This file contains the template for our module.
 *
 * @param array<string,mixed> $args The arguments from the module settings.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

// prevent direct access.
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- this template is
// included from inside a method, so its variables are function-scoped despite
// how it looks. The themify_builder_* hooks belong to Themify: every one of its
// modules fires them so extensions can hook into the rendering.

use NowPagesThreadi\Digests\Digest_Builder;
use NowPagesThreadi\Digests\Digests;

$np_threadi_fields_default = array(
	'period'            => Digests::get_instance()->get_default_period(),
	'format'            => Digests::get_instance()->get_default_format(),
	'override_display'  => '',
	'show_time'         => '',
	'show_source'       => '',
	'link_source'       => '',
	'show_period'       => '',
	'group_by_category' => '',
);

$np_threadi_fields_args = wp_parse_args( $args['mod_settings'], $np_threadi_fields_default );
unset( $args['mod_settings'] );

// build the args for the central digest builder, the same way the
// Elementor widget (app/PageBuilder/Elementor/Digest.php) does it, so both
// widgets stay behaviourally identical.
$np_threadi_digest_args = array(
	'period' => ! empty( $np_threadi_fields_args['period'] ) ? $np_threadi_fields_args['period'] : Digests::get_instance()->get_default_period(),
	'format' => ! empty( $np_threadi_fields_args['format'] ) ? $np_threadi_fields_args['format'] : Digests::get_instance()->get_default_format(),
);

// only pass the display settings when the module was told to override them,
// otherwise this one module would beat the site-wide settings for everyone.
if ( TB_Digest_Module::is_enabled( $np_threadi_fields_args['override_display'] ) ) {
	$np_threadi_names = array( 'show_time', 'show_source', 'link_source', 'show_period', 'group_by_category' );

	foreach ( $np_threadi_names as $np_threadi_name ) {
		$np_threadi_digest_args[ $np_threadi_name ] = TB_Digest_Module::is_enabled( $np_threadi_fields_args[ $np_threadi_name ] );
	}
}

$np_threadi_content = ( new Digest_Builder() )->build( $np_threadi_digest_args );

// nothing to show, e.g. no entries in the selected period.
if ( '' === $np_threadi_content ) {
	return;
}

$np_threadi_container_class = apply_filters( 'themify_builder_module_classes', array(
		'module', 'module-' . $args['mod_name'], $args['module_ID'],
	), $args['mod_name'], $args['module_ID'], $np_threadi_fields_args );
if ( ! empty( $args['element_id'] ) ) {
	$np_threadi_container_class[] = 'tb_' . $args['element_id'];
}
$np_threadi_container_props = apply_filters( 'themify_builder_module_container_props', array(
	'id'    => $args['module_ID'],
	'class' => implode( ' ', $np_threadi_container_class ),
), $np_threadi_fields_args, $args['mod_name'], $args['module_ID'] );
?>
<div <?php echo themify_get_element_attributes( $np_threadi_container_props ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- themify escapes every value with esc_attr(), the return value is a ready-made attribute string. ?>>
	<?php do_action( 'themify_builder_before_template_content_render' ); ?>
	<?php echo wp_kses( $np_threadi_content, Digests::get_instance()->get_allowed_html() ); ?>
	<?php do_action( 'themify_builder_after_template_content_render' ); ?>
</div><!-- .module-<?php echo esc_attr( $args['mod_name'] ); ?> -->
