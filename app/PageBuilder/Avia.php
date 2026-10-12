<?php
/**
 * This file contains the support for Avia (Enfold).
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Helper\Plugin;
use ThreadiNowPages\Helper\Singleton_Trait;
use ThreadiNowPages\Plugin\Post_Types;
use WP_Post;

/**
 * Registers and handles the support for Avada.
 */
class Avia extends PageBuilder_Base {
	use Singleton_Trait;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'avia';
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// add our shortcodes to Avia.
		add_filter( 'avia_load_shortcodes', array( $this, 'add_shortcode_directory' ) );
		add_filter( 'avf_duplicate_post_capability', array( $this, 'prevent_duplicate_post' ), 10, 2 );
	}

	/**
	 * Add our own shortcode folder to the list of possible folders.
	 *
	 * @param array<int,string> $shortcodes List of directories for shortcodes.
	 *
	 * @return array<int,string>
	 */
	public function add_shortcode_directory( array $shortcodes ): array {
		$shortcodes[] = Plugin::get_plugin_path() . 'legacy-classes/Avia/shortcodes/';
		return $shortcodes;
	}

	/**
	 * Prevent option to duplicate entries.
	 *
	 * @param string  $capability The used capability.
	 * @param WP_Post $post The requested Post as object.
	 *
	 * @return string
	 */
	public function prevent_duplicate_post( string $capability, WP_Post $post ): string {
		// bail if this object is not using our own cpt.
		if ( ! in_array( $post->post_type, Post_Types::get_instance()->get_post_type_slugs(), true ) ) {
			return $capability;
		}

		// return god to prevent any further usage.
		return 'god';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_page_content(): string {
		return "<!-- wp:shortcode -->
[avia_threadi_now_pages_digest period='" . get_option( 'threadi_now_pages_frontend_period', 'live' ) . "' format='verbatim' override_display='yes' show_time='yes' show_source='no' link_source='no' show_period='yes' group_by_category='yes' av_uid='av-jisd9a' sc_version='1.0'][/avia_threadi_now_pages_digest]
<!-- /wp:shortcode -->";
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_page_meta(): array {
		return array(
			// without this Enfold shows the classic editor instead of its builder.
			'_aviaLayoutBuilder_active'   => 'active',
			// the builder keeps its own copy of the content next to post_content.
			'_aviaLayoutBuilderCleanData' => $this->get_page_content(),
			// the parser rebuilds the tree, it only has to be told to look.
			'_avia_sc_parser_state'       => 'check_only',
			'_av_el_mgr_version'          => '1.0',
		);
	}

	/**
	 * Check if Avada and its necessary plugins are active.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		// check for the theme.
		$theme = wp_get_theme();

		if ( 'Enfold' === $theme->get( 'Name' ) ) {
			return true;
		}

		// check for the parent theme.
		if ( $theme->parent() && 'Enfold' === $theme->parent()->get( 'Name' ) ) {
			return true;
		}

		// otherwise return false.
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Enfold', 'threadi-now-pages' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 20;
	}
}
