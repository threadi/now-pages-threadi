<?php
/**
 * File to handle basic cpt-function.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Helper\Plugin;
use ThreadiNowPages\Helper\Singleton_Interface;
use ThreadiNowPages\Helper\Singleton_Trait;
use WP_Query;
use WP_REST_Posts_Controller;
use WP_Screen;

/**
 * Base Object for each post-type.
 */
abstract class Post_Type_Base implements Singleton_Interface {
	use Singleton_Trait;

	/**
	 * The slug.
	 *
	 * @var string
	 */
	protected string $slug = '';

	/**
	 * Constructor, not used as this a Singleton object.
	 *
	 * Declared once here (not per subclass): a private constructor is
	 * still callable via `new static()` from code inside the class that
	 * declares it - which is exactly what Singleton_Trait::get_instance()
	 * does, even when static resolves to a subclass like Entries.
	 */
	private function __construct() {}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_filter( 'post_updated_messages', array( $this, 'post_updated_messages' ) );
		add_filter( 'bulk_post_updated_messages', array( $this, 'bulk_post_updated_messages' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ) );
		add_filter( 'wp_count_posts', array( $this, 'wp_count_posts' ), 10, 3 );
		add_filter( 'admin_footer_text', array( $this, 'show_plugin_hint_in_footer' ), 0 );
		add_filter( 'threadi_now_pages_show_help', array( $this, 'show_help' ), 10, 2 );
	}

	/**
	 * Return the cpt slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return $this->slug;
	}

	/**
	 * Return the labels.
	 *
	 * @return array<string,string>
	 */
	protected function get_labels(): array {
		return array(
			'name' => '',
		);
	}

	/**
	 * Register this custom post-type.
	 *
	 * @return void
	 */
	public function register(): void {
		$slug = $this->get_slug();

		// bail if no slug is set, register_post_type() requires a non-empty, lowercase slug.
		if ( '' === $slug ) {
			return;
		}

		// get the labels.
		$labels = $this->get_labels();

		// set arguments for our own cpt.
		$args = array(
			'label'                 => $labels['name'],
			'description'           => '',
			'labels'                => $labels,
			'supports'              => $this->get_supports(),

			// define visibility in frontend.
			'public'                => $this->is_public(),
			'publicly_queryable'    => $this->is_public_queryable(),
			'exclude_from_search'   => $this->is_excluded_from_search(),
			'has_archive'           => $this->has_archive(),
			'rewrite'               => $this->get_rewrite(),
			'query_var'             => $this->get_query_var(),

			// define visibility in backend.
			'show_ui'               => true,
			'show_in_menu'          => true,
			'show_in_nav_menus'     => false,
			'show_in_admin_bar'     => true,

			// set REST API visibility.
			'show_in_rest'          => $this->is_shown_in_rest(),
			'rest_controller_class' => $this->get_rest_controller_class(),

			// set capabilities.
			'map_meta_cap'          => true,
			'capability_type'       => $this->get_slug(),

			// other settings.
			'can_export'            => false,
			'hierarchical'          => false,
			'taxonomies'            => array(),
			'menu_icon'             => $this->get_menu_icon(),
		);
		register_post_type( strtolower( $slug ), $args );
	}

	/**
	 * Return list of WP-functions this post type supports.
	 *
	 * @return array<int,string>
	 */
	protected function get_supports(): array {
		return array( 'title' );
	}

	/**
	 * Return whether this post type is visible in REST API.
	 *
	 * Hint: using false will disable the block editor.
	 *
	 * @return bool
	 */
	protected function is_shown_in_rest(): bool {
		return false;
	}

	/**
	 * Return the menu icon for this cpt.
	 *
	 * @return string
	 */
	protected function get_menu_icon(): string {
		return 'dashicons-list-view';
	}

	/**
	 * Whether this cpt is public available (true) or not (false).
	 *
	 * @return bool
	 */
	protected function is_public(): bool {
		return false;
	}

	/**
	 * Return whether this cpt is public queryable.
	 *
	 * @return bool
	 */
	protected function is_public_queryable(): bool {
		return false;
	}

	/**
	 * Return whether this cpt is excluded from search.
	 *
	 * @return bool
	 */
	protected function is_excluded_from_search(): bool {
		return true;
	}

	/**
	 * Return whether this cpt has archives.
	 *
	 * @return bool
	 */
	protected function has_archive(): bool {
		return false;
	}

	/**
	 * Return the rewrite-rule. "false" to disable it.
	 *
	 * @return bool|array<string,mixed>
	 */
	protected function get_rewrite(): bool|array {
		return false;
	}

	/**
	 * Return the query var. "false" to disable it.
	 *
	 * @return bool|string
	 */
	protected function get_query_var(): bool|string {
		return false;
	}

	/**
	 * Update the post messages for this cpt.
	 *
	 * @param array<string,array<int,string>> $messages The messages.
	 *
	 * @return array<string,array<int,string>>
	 */
	public function post_updated_messages( array $messages ): array {
		return $messages;
	}

	/**
	 * Update the bulk post messages for this cpt.
	 *
	 * @param array<string,mixed> $bulk_messages The messages.
	 * @param array<string,int>   $bulk_counts The count.
	 *
	 * @return array<string,mixed>
	 */
	public function bulk_post_updated_messages( array $bulk_messages, array $bulk_counts ): array {
		return $bulk_messages;
	}

	/**
	 * Hide entries which are not belong to this user in our own cpt.
	 *
	 * @param WP_Query $query The WP Query object.
	 *
	 * @return void
	 */
	public function pre_get_posts( WP_Query $query ): void {
		// bail if we are not in backend or this is not the main query.
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		// bail if this is not our post type.
		if ( $query->get( 'post_type' ) !== $this->get_slug() ) {
			return;
		}

		// bail if this is an administrator, which will see everything.
		if ( current_user_can( 'manage_options' ) || current_user_can( 'edit_others_' . $this->get_slug() . 's' ) ) {
			return;
		}

		// restrict the query to the actual user as author.
		$query->set( 'author', get_current_user_id() );
	}

	/**
	 * Update the post counts on top of the list of our post types.
	 *
	 * @param mixed  $counts List of counts.
	 * @param string $type The type.
	 * @param string $perm The permissions.
	 *
	 * @return mixed
	 */
	public function wp_count_posts( mixed $counts, string $type, string $perm ): mixed {
		// bail in frontend.
		if ( ! is_admin() ) {
			return $counts;
		}

		// bail if this is not our slug.
		if ( $type !== $this->get_slug() ) {
			return $counts;
		}

		// bail if this is an administrator, which will see everything.
		if ( current_user_can( 'manage_options' ) || current_user_can( 'edit_others_' . $this->get_slug() . 's' ) ) {
			return $counts;
		}

		/**
		 * Re-count the values.
		 */
		global $wpdb;
		$user_id = get_current_user_id();

		// query for the counts of the actual user.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Get posts for single user.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_status, COUNT( * ) AS num_posts
             FROM {$wpdb->posts}
             WHERE post_type = %s
               AND post_author = %d
             GROUP BY post_status",
				$type,
				$user_id
			),
			ARRAY_A
		);
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery

		// reset all states.
		foreach ( get_object_vars( $counts ) as $status => $count ) {
			$counts->$status = 0;
		}

		// fill the states we use.
		foreach ( $results as $row ) {
			$status = $row['post_status'];
			if ( isset( $counts->$status ) ) {
				$counts->$status = (int) $row['num_posts'];
			}
		}

		// return the resulting list of counts.
		return $counts;
	}

	/**
	 * Show hint in footer.
	 *
	 * @param string $content The actual footer content.
	 *
	 * @return string
	 */
	public function show_plugin_hint_in_footer( string $content ): string {
		// get requested post-type.
		$post_type = (string) filter_input( INPUT_GET, 'post_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		// get requested post.
		$post = absint( filter_input( INPUT_GET, 'post', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );

		// bail if this is not the listing or the single view of a position in backend.
		if ( $post_type !== $this->get_slug() && get_post_type( $post ) !== $this->get_slug() ) {
			return $content;
		}

		// show hint for our plugin.
		/* translators: %1$s will be replaced by the plugin name. */
		return $content . ' ' . sprintf( __( 'This page is provided by the plugin %1$s.', 'threadi-now-pages' ), '<em>' . Plugin::get_plugin_name() . '</em>' );
	}

	/**
	 * Return the backend URL of this post type.
	 *
	 * @return string
	 */
	public function get_backend_list_url(): string {
		return admin_url( 'edit.php?post_type=' . $this->get_slug() );
	}

	/**
	 * Return to show the help if this post type is called.
	 *
	 * @param bool      $allowed The decision.
	 * @param WP_Screen $screen The called screen.
	 *
	 * @return bool
	 */
	public function show_help( bool $allowed, WP_Screen $screen ): bool {
		// bail if it is already allowed.
		if ( $allowed ) {
			return true;
		}

		// return to show this screen if the post type is called.
		return ! empty( $screen->post_type ) && $screen->post_type === $this->get_slug();
	}

	/**
	 * Return the rest controller class.
	 *
	 * @return string
	 */
	protected function get_rest_controller_class(): string {
		return WP_REST_Posts_Controller::class;
	}
}
