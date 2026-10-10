<?php
/**
 * This file contains the post type of the updates shared in the Fediverse.
 *
 * Definition:
 * An update is what followers in the Fediverse receive: either a summary
 * of a period or a single entry. It exists as a post only because the
 * ActivityPub plugin federates posts - it is not a page of this website.
 *
 * An update has no address of its own for visitors: the post type is not
 * public, not queryable and not part of the search. The ActivityPub plugin
 * answers requests of other servers by the ID of the post, which does not
 * depend on any of that. Everybody else is pointed to the now page.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Fediverse;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\NowPages\Entries;
use NowPagesThreadi\Plugin\Post_Type_Base;
use WP_Post;

/**
 * Object to register the post type of the updates.
 */
class Updates extends Post_Type_Base {
	/**
	 * The slug.
	 *
	 * @var string
	 */
	protected string $slug = 'np_threadi_update';

	/**
	 * The column which shows what was shared.
	 */
	private const COLUMN = 'now_pages_threadi_update';

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		parent::init();

		// show what was shared instead of a title, updates do not have one.
		add_filter( 'manage_' . $this->get_slug() . '_posts_columns', array( $this, 'add_columns' ) );
		add_action( 'manage_' . $this->get_slug() . '_posts_custom_column', array( $this, 'show_column' ), 10, 2 );
		add_filter( 'list_table_primary_column', array( $this, 'set_primary_column' ), 10, 2 );
	}

	/**
	 * Replace the title column by the shared text.
	 *
	 * @param array<string,string> $columns The columns.
	 *
	 * @return array<string,string>
	 */
	public function add_columns( array $columns ): array {
		$new = array();

		foreach ( $columns as $name => $label ) {
			if ( 'title' === $name ) {
				$new[ self::COLUMN ] = __( 'Shared post', 'now-pages-threadi' );
				continue;
			}

			$new[ $name ] = $label;
		}

		// add it if there was no title to replace.
		if ( ! isset( $new[ self::COLUMN ] ) ) {
			$new[ self::COLUMN ] = __( 'Shared post', 'now-pages-threadi' );
		}

		return $new;
	}

	/**
	 * Show the shared text of an update.
	 *
	 * @param string $column  The column.
	 * @param int    $post_id The ID of the update.
	 *
	 * @return void
	 */
	public function show_column( string $column, int $post_id ): void {
		// bail if this is not our column.
		if ( self::COLUMN !== $column ) {
			return;
		}

		$post = get_post( $post_id );

		// bail if the update does not exist.
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$text = wp_trim_words( wp_strip_all_tags( str_replace( array( '</p>', '</li>', '<br>' ), ' ', $post->post_content ) ), 30 );
		$kind = absint( get_post_meta( $post_id, Fediverse::META_ENTRY, true ) ) > 0 ? __( 'Single entry', 'now-pages-threadi' ) : __( 'Summary', 'now-pages-threadi' );

		echo '<strong>' . esc_html( $kind ) . '</strong><br>' . esc_html( $text );
	}

	/**
	 * Hang the row actions on our column.
	 *
	 * @param string $column    The primary column.
	 * @param string $screen_id The ID of the screen.
	 *
	 * @return string
	 */
	public function set_primary_column( string $column, string $screen_id ): string {
		// bail if this is not the list of updates.
		if ( 'edit-' . $this->get_slug() !== $screen_id ) {
			return $column;
		}

		return self::COLUMN;
	}

	/**
	 * Return the labels.
	 *
	 * @return array<string,string>
	 */
	protected function get_labels(): array {
		return array(
			'name'          => __( 'Fediverse Updates', 'now-pages-threadi' ),
			'singular_name' => __( 'Fediverse Update', 'now-pages-threadi' ),
			'menu_name'     => __( 'Fediverse Updates', 'now-pages-threadi' ),
			'all_items'     => __( 'Fediverse Updates', 'now-pages-threadi' ),
			'edit_item'     => __( 'Edit Fediverse Update', 'now-pages-threadi' ),
			'search_items'  => __( 'Search Fediverse Updates', 'now-pages-threadi' ),
			'not_found'     => __( 'Nothing has been shared in the Fediverse yet.', 'now-pages-threadi' ),
		);
	}

	/**
	 * Register this post type.
	 *
	 * Not via the parent: an update is listed below the entries instead of
	 * getting a menu of its own, and it uses the capabilities of the
	 * entries, so nobody needs a new permission to see what was shared.
	 *
	 * @return void
	 */
	public function register(): void {
		$slug = strtolower( $this->get_slug() );

		// bail if no slug is set.
		if ( '' === $slug ) {
			return;
		}

		$labels = $this->get_labels();

		register_post_type(
			$slug,
			array(
				'label'               => $labels['name'],
				'labels'              => $labels,
				'supports'            => array( 'editor', 'author', 'comments' ),

				// not a part of the website visitors can reach.
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'show_in_rest'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,

				// only listed in the backend while something can be shared.
				'show_ui'             => Fediverse::get_instance()->is_enabled(),
				'show_in_menu'        => 'edit.php?post_type=' . Entries::get_instance()->get_slug(),

				// who may manage the entries may manage what was shared of them. updates are never written by hand.
				'map_meta_cap'        => true,
				'capability_type'     => Entries::get_instance()->get_slug(),
				'capabilities'        => array(
					'create_posts' => 'do_not_allow',
				),

				'can_export'          => false,
				'hierarchical'        => false,
				'taxonomies'          => array(),
			)
		);
	}
}
