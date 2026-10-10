<?php
/**
 * This file contains the handling of a real page behind the now route.
 *
 * The route is virtual by default, which is fine for a classic theme but
 * invisible to every page builder: their layouts and templates decide by
 * conditions that need a post object. Backing the route with a real page
 * makes /now a normal page for WordPress - and therefore for any pagebuilder.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\NowPages;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Dependencies\easyTransientsForWordPress\Transients;
use NowPagesThreadi\Helper\Singleton_Interface;
use NowPagesThreadi\Helper\Singleton_Trait;
use NowPagesThreadi\PageBuilder\PageBuilder_Base;
use NowPagesThreadi\PageBuilder\Page_Builders;
use NowPagesThreadi\Plugin\Init;
use NowPagesThreadi\Plugin\Log;
use WP_Post;
use WP_Post_Type;
use WP_Query;

/**
 * Object which handles the page behind the now route.
 */
class Now_Page implements Singleton_Interface {
	use Singleton_Trait;

	/**
	 * The option holding the ID of the page.
	 *
	 * @var string
	 */
	public const OPTION = 'now_pages_threadi_frontend_page';

	/**
	 * Constructor, not used as this a Singleton object.
	 */
	private function __construct() {}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// use hooks.
		add_action( 'admin_notices', array( $this, 'show_conflict_hint' ) );
	}

	/**
	 * Return the page configured for the now route.
	 *
	 * @return WP_Post|null Null if no page is configured or it is gone.
	 */
	public function get_page(): ?WP_Post {
		$page_id = absint( get_option( self::OPTION, 0 ) );

		// bail if no page is configured.
		if ( 0 === $page_id ) {
			return null;
		}

		$post = get_post( $page_id );

		// bail if the page has been deleted in the meantime.
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		// bail if it is not published.
		if ( 'publish' !== $post->post_status ) {
			return null;
		}

		return $post;
	}

	/**
	 * Return the post that already occupies the now slug, if any.
	 *
	 * WordPress resolves a slug against every public post type, so the
	 * conflict is not limited to pages.
	 *
	 * @param string $slug The slug to check.
	 *
	 * @return WP_Post|null Null if the slug is free.
	 */
	public function get_conflicting_post( string $slug ): ?WP_Post {
		// bail if no slug is given.
		if ( '' === $slug ) {
			return null;
		}

		$post_id = $this->get_conflicting_post_id( $slug );

		// bail if the slug is free.
		if ( 0 === $post_id ) {
			return null;
		}

		$post = get_post( $post_id );

		// bail if the post is gone in the meantime.
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		// our own page is not a conflict, it is the point of the exercise.
		if ( absint( get_option( self::OPTION, 0 ) ) === $post->ID ) {
			return null;
		}

		return $post;
	}

	/**
	 * Return whether something already claims the given slug.
	 *
	 * Cached: the hint using this runs on every backend page, and checking
	 * every rewrite rule with a regular expression is not free.
	 *
	 * @param string $slug The slug to check.
	 *
	 * @return bool
	 */
	public function has_conflict( string $slug ): bool {
		return $this->get_conflicting_post( $slug ) instanceof WP_Post;
	}

	/**
	 * Return the ID of the post occupying the given slug.
	 *
	 * Cached, because the hint showing this runs on every backend page, and
	 * a slug rarely changes. Only the ID is stored - a whole WP_Post in a
	 * transient would be both wasteful and prone to going stale.
	 *
	 * @param string $slug The slug to check.
	 *
	 * @return int Zero if the slug is free.
	 */
	private function get_conflicting_post_id( string $slug ): int {
		$cache_key = 'now_pages_threadi_slug_conflict_' . md5( $slug );
		$cached    = get_transient( $cache_key );

		// a stored value is either an ID or a zero for "nothing found".
		if ( is_numeric( $cached ) ) {
			return absint( $cached );
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'name'           => $slug,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$post_id = 0;

		if ( $query->have_posts() ) {
			$post_id = absint( $query->posts[0] ?? 0 );
		}

		set_transient( $cache_key, $post_id, WEEK_IN_SECONDS );

		return $post_id;
	}

	/**
	 * Show a hint in backend if something else occupies our slug.
	 *
	 * @return void
	 */
	public function show_conflict_hint(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// a real page has its own permalink, a taken slug does not affect it.
		if ( $this->get_page() instanceof WP_Post ) {
			return;
		}

		// get the configured slug.
		$slug = (string) get_option( 'now_pages_threadi_slug', 'now' );

		// bail if no conflict is detected.
		if ( ! $this->has_conflict( $slug ) ) {
			return;
		}

		// bail if the hint is already set, so it is not written again on every page view.
		// it is removed when the slug or the page for the now route changes.
		if ( Transients::get_instance()->get_transient_by_name( 'now_pages_threadi_slug_warning' )->is_set() ) {
			return;
		}

		// get the conflicting post.
		$post = $this->get_conflicting_post( $slug );

		// bail if there is no conflict.
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$post_type = get_post_type_object( $post->post_type );
		$type_name = $post->post_type;

		if ( $post_type instanceof WP_Post_Type ) {
			$type_name = $post_type->labels->singular_name;
		}

		$transient_obj = Transients::get_instance()->add();
		$transient_obj->set_name( 'now_pages_threadi_slug_warning' );
		$transient_obj->set_message(
			sprintf(
			/* translators: %1$s will be replaced by a URL, %2$s by a post type name, %3$s by a link to the conflicting entry, %4$s by a link to the settings. */
				esc_html__( 'The address %1$s is already used by the %2$s %3$s. Your now page is not reachable there. Either give that entry another address, or change the address of your now page in the %4$s.', 'now-pages-threadi' ),
				'<code>/' . esc_html( $slug ) . '/</code>',
				esc_html( $type_name ),
				'<a href="' . esc_url( (string) get_edit_post_link( $post->ID, '' ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a>',
				'<a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">' . esc_html__( 'permalink settings', 'now-pages-threadi' ) . '</a>'
			)
		);
		$transient_obj->set_type( 'success' );
		$transient_obj->set_dismissible_days( 7 );
		$transient_obj->save();
	}

	/**
	 * Create a page for the now route and remember it.
	 *
	 * @param string $title The title of the new page.
	 *
	 * @return int The ID of the created page, zero on error.
	 */
	public function create_page( string $title = '' ): int {
		// bail if a usable page already exists.
		$existing = $this->get_page();

		if ( $existing instanceof WP_Post ) {
			return $existing->ID;
		}

		if ( '' === $title ) {
			$title = __( 'Now', 'now-pages-threadi' );
		}

		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $this->get_initial_content(),
				'post_author'  => get_current_user_id(),
				'meta_input'   => $this->get_initial_meta(),
			),
			true
		);

		// bail if the page could not be created.
		if ( is_wp_error( $page_id ) ) {
			Log::get_instance()->add( __( 'The page for the now route could not be created.', 'now-pages-threadi' ), 'error', 'system' );

			return 0;
		}

		// the select field validates against the list of pages built during
		// init - our brand-new page is not in it yet, so the value would be
		// rejected. write it past the validation.
		remove_all_filters( 'sanitize_option_' . self::OPTION );

		// save the ID of the added page as the now page.
		update_option( self::OPTION, absint( $page_id ) );

		// save the slug this page is using.
		$post = get_post( $page_id );
		if ( $post instanceof WP_Post ) {
			update_option( 'now_pages_threadi_slug', $post->post_name );
		}

		// the route now points at a page, so the rules have to be rebuilt.
		Frontend::trigger_slug_update();

		// clear our cache.
		Init::get_instance()->delete_option_caches();

		return absint( $page_id );
	}

	/**
	 * Fill an existing, empty page with the content of the active builder.
	 *
	 * Never touches a page that already has content - what somebody wrote
	 * there is theirs.
	 *
	 * @param int $page_id The page to fill.
	 *
	 * @return bool True if the page has been filled.
	 */
	public function fill_page( int $page_id ): bool {
		$post = get_post( $page_id );

		// bail if the page does not exist.
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		// bail if the page already holds something.
		if ( '' !== trim( wp_strip_all_tags( $post->post_content ) ) ) {
			return false;
		}

		wp_update_post(
			array(
				'ID'           => $page_id,
				'post_content' => $this->get_initial_content(),
			)
		);

		foreach ( $this->get_initial_meta() as $key => $value ) {
			update_post_meta( $page_id, $key, $value );
		}

		return true;
	}

	/**
	 * Return the page builder a new page should be built with.
	 *
	 * @return PageBuilder_Base|null Null if none is active.
	 */
	public function get_active_page_builder(): ?PageBuilder_Base {
		$active = null;

		foreach ( Page_Builders::get_instance()->get_page_builders_as_objects() as $page_builder_obj ) {
			// skip the ones that are not in use here.
			if ( ! $page_builder_obj->is_active() ) {
				continue;
			}

			// keep the one with the highest priority.
			if ( $active instanceof PageBuilder_Base && $active->get_priority() >= $page_builder_obj->get_priority() ) {
				continue;
			}

			$active = $page_builder_obj;
		}

		/**
		 * Filter the page builder used to prefill a new now page.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param PageBuilder_Base|null $active The chosen page builder.
		 */
		return apply_filters( 'now_pages_threadi_page_builder_for_page', $active );
	}

	/**
	 * Return the content a new page starts with.
	 *
	 * @return string
	 */
	private function get_initial_content(): string {
		$page_builder_obj = $this->get_active_page_builder();

		if ( $page_builder_obj instanceof PageBuilder_Base && '' !== $page_builder_obj->get_page_content() ) {
			return $page_builder_obj->get_page_content();
		}

		// the shortcode works everywhere, so it is the fallback.
		return '[now_pages_threadi_digest]';
	}

	/**
	 * Return the meta a new page starts with.
	 *
	 * The values are slashed: wp_insert_post() and update_post_meta() strip
	 * one level of slashes, which would break the escapes inside the JSON
	 * several page builders store there.
	 *
	 * @return array<string,mixed>
	 */
	private function get_initial_meta(): array {
		$page_builder_obj = $this->get_active_page_builder();

		if ( ! $page_builder_obj instanceof PageBuilder_Base ) {
			return array();
		}

		return wp_slash( $page_builder_obj->get_page_meta() );
	}
}
