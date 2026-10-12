<?php
/**
 * This file contains the support for Gutenberg aka Block Editor.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Digests\Digest_Builder;
use ThreadiNowPages\Digests\Digests;
use ThreadiNowPages\Digests\Display_Settings;
use ThreadiNowPages\Helper\Plugin;
use ThreadiNowPages\Helper\Singleton_Trait;
use ThreadiNowPages\NowPages\Frontend;
use WP_Block_Type;
use WP_Block_Type_Registry;
use WP_User;

/**
 * Registers and handles the support for Gutenberg aka Block Editor.
 */
class Gutenberg extends PageBuilder_Base {
	use Singleton_Trait;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'gutenberg';
	}

	/**
	 * Slug used for the block template.
	 *
	 * @var string
	 */
	public const TEMPLATE_SLUG = 'threadi-now-pages';

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// use hooks.
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'init', array( $this, 'register_block_template' ) );
		add_filter( 'block_categories_all', array( $this, 'add_block_category' ) );
	}

	/**
	 * Register the block.
	 *
	 * @return void
	 */
	public function register_block(): void {
		// get the block type registry.
		$block_type_registry = WP_Block_Type_Registry::get_instance();

		// bail if the block type registry could not be loaded.
		if ( ! $block_type_registry instanceof WP_Block_Type_Registry ) { // @phpstan-ignore instanceof.alwaysTrue
			return;
		}

		// bail if the block is already registered.
		if ( $block_type_registry->is_registered( 'threadi-now-pages/digest' ) ) {
			return;
		}

		// register the block.
		$block_type = register_block_type(
			Plugin::get_plugin_path() . 'blocks/digest/',
			array(
				'render_callback' => array( $this, 'render_digest' ),
			)
		);

		// bail if the block could not be registered.
		if ( ! $block_type instanceof WP_Block_Type ) {
			return;
		}

		// bail if the block has no editor script to attach the lists to.
		if ( empty( $block_type->editor_script_handles ) ) {
			return;
		}

		// collect the formats.
		$formats = array();

		foreach ( Digests::get_instance()->get_formats() as $format ) {
			$formats[] = array(
				'label' => $format->get_label(),
				'value' => $format->get_slug(),
			);
		}

		// collect the periods.
		$periods = array();

		foreach ( Digests::get_instance()->get_periods() as $slug => $label ) {
			$periods[] = array(
				'label' => $label,
				'value' => $slug,
			);
		}

		// hand the current defaults over, so switching the override on does
		// not change the output until the user actually changes something.
		$defaults = Display_Settings::resolve( array() );

		unset( $defaults['_origin'] );

		wp_localize_script(
			$block_type->editor_script_handles[0],
			'threadiNowPagesDigestBlock',
			array(
				'periods'          => $periods,
				'formats'          => $formats,
				'display_defaults' => $defaults,
			)
		);
	}

	/**
	 * Register our block template so it shows up in the site editor.
	 *
	 * @return void
	 */
	public function register_block_template(): void {
		// get the template name.
		$template_name = 'threadi-now-pages//' . self::TEMPLATE_SLUG;

		// bail if it is already registered.
		if ( \WP_Block_Templates_Registry::get_instance()->is_registered( $template_name ) ) {
			return;
		}

		// get WP_Filesystem.
		$wp_filesystem = Plugin::get_wp_filesystem( true );

		// set path to our base template.
		$file = Plugin::get_plugin_path() . 'templates/' . self::TEMPLATE_SLUG . '.html';

		// bail if file is not readable.
		if ( ! $wp_filesystem->is_readable( $file ) ) {
			return;
		}

		// get the file content.
		$content = (string) $wp_filesystem->get_contents( $file );

		// register this template.
		register_block_template(
			$template_name,
			array(
				'title'       => __( 'Now', 'threadi-now-pages' ),
				'description' => __( 'Lists the current activities on /now and /author/…/now.', 'threadi-now-pages' ),
				'post_types'  => array(),
				'content'     => $content,
			)
		);
	}

	/**
	 * Render the digest for block themes.
	 *
	 * @param array<string,mixed> $attributes List of attributes.
	 *
	 * @return string
	 */
	public static function render_digest( array $attributes ): string {
		$args = array(
			'period' => Digests::get_instance()->get_default_period(),
			'format' => Digests::get_instance()->get_default_format(),
		);

		// inside a now route, use the args of this route - including its author.
		$frontend_obj  = Frontend::get_instance();
		$route_args    = array();
		$route_content = '';
		if ( $frontend_obj->is_now_request() && ! empty( $frontend_obj->get_args() ) ) {
			$args = $frontend_obj->get_args();

			// make sure the author of the route is used.
			$user = $frontend_obj->get_user();
			if ( $user instanceof WP_User ) {
				$args['user_id'] = $user->ID;
			}

			// remember the content the route has prepared, with the hints for administrators and the diagnostics.
			$route_args    = $args;
			$route_content = $frontend_obj->get_content();
		}

		// use the period and format of the block, if set.
		if ( ! empty( $attributes['period'] ) && is_string( $attributes['period'] ) ) {
			$args['period'] = $attributes['period'];
		}
		if ( ! empty( $attributes['format'] ) && is_string( $attributes['format'] ) ) {
			$args['format'] = $attributes['format'];
		}

		// only pass the display settings when the block was told to override
		// them. otherwise every block would beat the settings of the website.
		if ( ! empty( $attributes['override_display'] ) ) {
			$names = array( 'show_time', 'show_source', 'link_source', 'show_period', 'group_by_category' );

			// on the page of a user, the block may not show more than the user allows.
			$user_settings = ! empty( $args['user_id'] ) ? Display_Settings::resolve( array( 'user_id' => absint( $args['user_id'] ) ) ) : array();

			foreach ( $names as $name ) {
				if ( ! isset( $attributes[ $name ] ) ) {
					continue;
				}

				$value = (bool) $attributes[ $name ];

				// bail if this would show something the user has disabled.
				if ( $value && in_array( $name, array( 'show_time', 'show_source', 'link_source' ), true ) && isset( $user_settings[ $name ] ) && ! $user_settings[ $name ] ) {
					continue;
				}

				$args[ $name ] = $value;
			}
		}

		// use the content of the route on block themes, if the block does not change its args.
		if ( '' !== $route_content && $route_args === $args ) {
			$content = $route_content;
		} else {
			// render the digest.
			$content = ( new Digest_Builder() )->build( $args );
		}

		// bail if there is nothing to show.
		if ( '' === $content ) {
			return '';
		}

		// add the wrapper with the classes and styles of the block supports, e.g. the alignment.
		return '<div ' . get_block_wrapper_attributes() . '>' . $content . '</div>';
	}

	/**
	 * Add our custom block category for all of our own widgets.
	 *
	 * @param array<int,array<string,mixed>> $block_categories List of block categories.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function add_block_category( array $block_categories ): array {
		// add our custom block category.
		$block_categories[] = array(
			'slug'  => 'threadi-now-pages',
			'title' => __( 'Now Pages', 'threadi-now-pages' ),
		);

		// return resulting list.
		return $block_categories;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Block editor', 'threadi-now-pages' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		// only claim a block theme - with a classic theme the shortcode is
		// the friendlier default.
		return wp_is_block_theme();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 10;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_page_content(): string {
		return '<!-- wp:threadi-now-pages/digest {"period":"' . get_option( 'threadi_now_pages_frontend_period', 'live' ) . '"} /-->';
	}
}
