<?php
/**
 * This file contains the WordPress provider.
 *
 * Reads the activity on this website itself: published posts, pages and
 * approved comments. The only provider that needs no network access, no
 * account and no configuration beyond a post type - it works right after
 * installing the plugin.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Providers;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use ThreadiNowPages\Helper\Languages;
use ThreadiNowPages\NowPages\Entries;
use ThreadiNowPages\NowPages\Entry;
use ThreadiNowPages\Plugin\Log;
use ThreadiNowPages\Plugin\Post_Types;
use ThreadiNowPages\Sources\Sources;
use WP_Comment;
use WP_Post;
use WP_Query;
use WP_User;
use WP_User_Query;

/**
 * Object for the WordPress provider.
 */
class WordPress_Provider extends Provider_Base {
	/**
	 * The state key holding the time of the newest item of the last run.
	 *
	 * @var string
	 */
	private const STATE_LAST_ITEM = 'wordpress_last_item';

	/**
	 * The user meta key with which a user disables the own now page.
	 *
	 * @var string
	 */
	private const USER_META_DISABLED = 'threadi_now_pages_disable_now_page';

	/**
	 * Initialize this provider.
	 *
	 * @return void
	 */
	public function init(): void {
		parent::init();

		// remove entries of content which is not public anymore.
		add_action( 'transition_post_status', array( $this, 'remove_entries_of_unpublished_post' ), 10, 3 );
		add_action( 'post_updated', array( $this, 'remove_entries_of_protected_post' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'remove_entries_of_deleted_post' ), 10, 2 );
		add_action( 'transition_comment_status', array( $this, 'remove_entries_of_unapproved_comment' ), 10, 3 );
		add_action( 'delete_comment', array( $this, 'remove_entries_of_deleted_comment' ) );
	}

	/**
	 * Return the provider slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'wordpress_site';
	}

	/**
	 * Return the provider label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'This website', 'threadi-now-pages' );
	}

	/**
	 * Return a short description shown when choosing this provider.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Reads what happens on this website: published posts and approved comments. Needs no account and no external service.', 'threadi-now-pages' );
	}

	/**
	 * Return the configuration fields for this provider.
	 *
	 * @return array<string,mixed>
	 */
	public function get_config_fields(): array {
		return array(
			'wp_post_types' => array(
				'label'            => __( 'Post types', 'threadi-now-pages' ),
				'type'             => 'multiselect',
				'description'      => __( 'Which content should be watched. Nothing selected means posts only.', 'threadi-now-pages' ),
				'options_callback' => array( $this, 'get_post_type_options' ),
			),
			'wp_author'     => array(
				'label'            => __( 'Only this author', 'threadi-now-pages' ),
				'type'             => 'select',
				'description'      => __( 'Read only the activity of this user.', 'threadi-now-pages' ),
				'options_callback' => array( $this, 'get_author_options' ),
				'default_callback' => array( $this, 'get_default_author' ),
				'personal_options' => true,
			),
			'wp_comments'   => array(
				'label'       => __( 'Include comments', 'threadi-now-pages' ),
				'type'        => 'checkbox',
				'default'     => 0,
				'description' => __( 'Also read approved comments. Only comments written by a registered user are used.', 'threadi-now-pages' ),
			),
			'wp_max_items'  => array(
				'label'   => __( 'Max. items per run', 'threadi-now-pages' ),
				'type'    => 'number',
				'default' => 10,
				'min'     => 1,
				'max'     => 50,
				'step'    => 1,
			),
		);
	}

	/**
	 * Fetch the recent activity of this website.
	 *
	 * @param array<string,mixed> $config  The configuration of the source.
	 * @param Provider_Context    $context The context of this run.
	 *
	 * @return array<int,Entry>
	 */
	public function fetch( array $config, Provider_Context $context ): array {
		$max_items = 10;

		if ( isset( $config['wp_max_items'] ) ) {
			$max_items = absint( $config['wp_max_items'] );
		}

		$author = 0;

		if ( isset( $config['wp_author'] ) ) {
			$author = absint( $config['wp_author'] );
		}

		// only look at what happened since the last run.
		$since = $this->get_since( $context );

		$entries = $this->get_post_entries( $config, $author, $since, $max_items );

		// add the comments, if the source asked for them.
		if ( ! empty( $config['wp_comments'] ) ) {
			$entries = array_merge( $entries, $this->get_comment_entries( $author, $since, $max_items ) );
		}

		// newest first, then cut to the configured maximum.
		usort(
			$entries,
			static function ( Entry $a, Entry $b ): int {
				return $b->date <=> $a->date;
			}
		);

		$entries = array_slice( $entries, 0, $max_items );

		// remember the newest item for the next run, but only once the entries of this run have been saved.
		if ( ! empty( $entries ) ) {
			$context->set_pending_state( self::STATE_LAST_ITEM, $entries[0]->date->format( 'Y-m-d H:i:s' ) );
		}

		return $entries;
	}

	/**
	 * Return the point in time this run should start from.
	 *
	 * @param Provider_Context $context The context of this run.
	 *
	 * @return string A date in Y-m-d H:i:s, GMT.
	 */
	private function get_since( Provider_Context $context ): string {
		$since = (string) $context->get_state( self::STATE_LAST_ITEM, '' );

		// on the very first run, look back one day instead of importing the
		// complete history of the website.
		if ( '' === $since ) {
			return gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		}

		return $since;
	}

	/**
	 * Return the entries for recently published posts.
	 *
	 * @param array<string,mixed> $config    The configuration of the source.
	 * @param int                 $author    The user to limit to, 0 for everyone.
	 * @param string              $since     Only items after this GMT date.
	 * @param int                 $max_items The maximum number of items.
	 *
	 * @return array<int,Entry>
	 */
	private function get_post_entries( array $config, int $author, string $since, int $max_items ): array {
		$entries = array();

		$query_args = array(
			'post_type'           => $this->get_post_types( $config ),
			'post_status'         => 'publish',
			// a password protected post is not public: its title must not show up on the now page.
			'has_password'        => false,
			'posts_per_page'      => $max_items,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'date_query'          => array(
				array(
					'after'     => $since,
					'inclusive' => false,
					'column'    => 'post_date_gmt',
				),
			),
		);

		if ( $author > 0 ) {
			$query_args['author'] = $author;
		}

		$query = new WP_Query( $query_args );

		foreach ( $query->get_posts() as $post ) {
			// bail on anything that is not a post object.
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			// skip posts of users who disabled their now page, also if this author is chosen.
			if ( $this->is_user_excluded( absint( $post->post_author ) ) ) {
				continue;
			}

			$entry = new Entry();

			$entry->text = sprintf(
			/* translators: %1$s will be replaced by the title of a post. */
				__( 'Published "%1$s"', 'threadi-now-pages' ),
				wp_strip_all_tags( get_the_title( $post ) )
			);
			$entry->date        = $this->get_date( (string) $post->post_date_gmt );
			$entry->source_slug = $this->get_slug();
			$entry->source_url  = (string) get_permalink( $post );
			$entry->external_id = 'wp-post-' . $post->ID;
			$entry->language    = Languages::get_current_language();

			$entries[] = $entry;
		}

		return $entries;
	}

	/**
	 * Return the entries for recent comments.
	 *
	 * Only comments of registered users are used: an entry on a now page
	 * speaks in the first person, and an anonymous commenter is not the
	 * author of this website.
	 *
	 * @param int    $author    The user to limit to, 0 for everyone.
	 * @param string $since     Only items after this GMT date.
	 * @param int    $max_items The maximum number of items.
	 *
	 * @return array<int,Entry>
	 */
	private function get_comment_entries( int $author, string $since, int $max_items ): array {
		$entries = array();

		// prepare the query.
		$query_args = array(
			'status'      => 'approve',
			'type'        => 'comment',
			'post_status' => 'publish',
			'number'      => $max_items,
			'orderby'     => 'comment_date_gmt',
			'order'       => 'DESC',
			'date_query'  => array(
				array(
					'after'     => $since,
					'inclusive' => false,
					'column'    => 'comment_date_gmt',
				),
			),
		);

		// filter for a specific user.
		if ( $author > 0 ) {
			$query_args['user_id'] = $author;
		}

		// get the comments.
		$comments = get_comments( $query_args );

		// bail if no list has been returned (get_comments() returns a number for count queries).
		if ( ! is_array( $comments ) ) { // @phpstan-ignore function.alreadyNarrowedType
			return array();
		}

		// get the comments.
		foreach ( $comments as $comment ) {
			// bail on anything that is not a comment object.
			if ( ! $comment instanceof WP_Comment ) { // @phpstan-ignore instanceof.alwaysTrue
				continue;
			}

			// skip anonymous comments, see the method description.
			if ( 0 === absint( $comment->user_id ) ) {
				continue;
			}

			// skip comments of users who disabled their now page, also if this author is chosen.
			if ( $this->is_user_excluded( absint( $comment->user_id ) ) ) {
				continue;
			}

			// skip comments on our own entries, that would be a loop.
			if ( Entries::get_instance()->get_slug() === get_post_type( (int) $comment->comment_post_ID ) ) {
				continue;
			}

			// skip comments on posts which are not public: their title must not show up on the now page.
			$commented_post = get_post( (int) $comment->comment_post_ID );
			if ( ! $commented_post instanceof WP_Post || ! is_post_publicly_viewable( $commented_post ) || '' !== $commented_post->post_password ) {
				continue;
			}

			$entry = new Entry();

			$entry->text = sprintf(
			/* translators: %1$s will be replaced by the title of a post. */
				__( 'Commented on "%1$s"', 'threadi-now-pages' ),
				wp_strip_all_tags( get_the_title( (int) $comment->comment_post_ID ) )
			);
			$entry->date        = $this->get_date( (string) $comment->comment_date_gmt );
			$entry->source_slug = $this->get_slug();
			$entry->source_url  = get_comment_link( $comment );
			$entry->external_id = 'wp-comment-' . $comment->comment_ID;
			$entry->language    = Languages::get_current_language();

			$entries[] = $entry;
		}

		return $entries;
	}

	/**
	 * Return the post types this source watches.
	 *
	 * Our own entries are always excluded - reading them would create a
	 * loop that fills the database on every run.
	 *
	 * @param array<string,mixed> $config The configuration of the source.
	 *
	 * @return array<int,string>
	 */
	private function get_post_types( array $config ): array {
		$configured = array();

		if ( isset( $config['wp_post_types'] ) ) {
			$configured = $config['wp_post_types'];
		}

		// a MultiSelect stores an array, an older configuration a comma
		// separated list.
		if ( is_string( $configured ) ) {
			$configured = explode( ',', $configured );
		}

		// bail on anything else.
		if ( ! is_array( $configured ) ) {
			return array( 'post' );
		}

		$post_types = array();

		foreach ( $configured as $post_type ) {
			// skip anything that is not a usable name.
			if ( ! is_scalar( $post_type ) ) {
				continue;
			}

			$post_type = sanitize_key( trim( (string) $post_type ) );

			// skip empty and unknown post types.
			if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
				continue;
			}

			// never read our own entries or sources.
			if ( $this->is_own_post_type( $post_type ) ) {
				continue;
			}

			$post_types[] = $post_type;
		}

		if ( empty( $post_types ) ) {
			return array( 'post' );
		}

		return $post_types;
	}

	/**
	 * Turn a GMT date from the database into a date object.
	 *
	 * @param string $value The date in Y-m-d H:i:s.
	 *
	 * @return DateTimeImmutable
	 */
	private function get_date( string $value ): DateTimeImmutable {
		try {
			return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			// log this event.
			Log::get_instance()->add(
				__( 'A timestamp from this website could not be parsed. Error:', 'threadi-now-pages' ) . ' <code>' . esc_html( $e->getMessage() ) . '</code>',
				'error',
				'provider'
			);

			return new DateTimeImmutable();
		}
	}

	/**
	 * Return whether a post type belongs to this plugin (entries, sources, …).
	 *
	 * @param string $post_type The name of the post type.
	 *
	 * @return bool
	 */
	private function is_own_post_type( string $post_type ): bool {
		return in_array( $post_type, Post_Types::get_instance()->get_post_type_slugs(), true );
	}

	/**
	 * Return the post types a source can watch.
	 *
	 * @return array<string,string>
	 */
	public function get_post_type_options(): array {
		$options = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			// never offer our own post types, reading them would be a loop.
			if ( $this->is_own_post_type( $post_type->name ) ) {
				continue;
			}

			$options[ $post_type->name ] = $post_type->labels->name;
		}

		return $options;
	}

	/**
	 * Return the users a WordPress source can be limited to.
	 *
	 * @return array<string,string> Map of user ID => display name.
	 */
	public function get_author_options(): array {
		// prepare the list of options.
		$options = array(
			'0' => __( 'All authors', 'threadi-now-pages' ),
		);

		// get the users.
		$query = array(
			'capability' => array( 'edit_posts' ),
			'orderby'    => 'display_name',
			'order'      => 'ASC',
			'number'     => 100,
		);
		$users = new WP_User_Query( $query );

		// loop through the results.
		foreach ( $users->get_results() as $user ) {
			// bail if object is not given.
			if ( ! $user instanceof WP_User ) {
				continue;
			}

			// add the user to the list.
			$options[ (string) $user->ID ] = $user->display_name;
		}

		// return the resulting options list.
		return $options; // @phpstan-ignore return.type
	}

	/**
	 * Return the author preselected for a new source: its owner.
	 *
	 * @param int $source_id The ID of the source.
	 *
	 * @return string
	 */
	public function get_default_author( int $source_id ): string {
		$author = absint( get_post_field( 'post_author', $source_id ) );

		// use the current user if the source has no owner yet.
		if ( 0 === $author ) {
			$author = get_current_user_id();
		}

		return (string) $author;
	}

	/**
	 * Return whether the content of a user must not be imported, because
	 * the user disabled the own now page.
	 *
	 * @param int $user_id The ID of the user.
	 *
	 * @return bool
	 */
	private function is_user_excluded( int $user_id ): bool {
		// bail without a user.
		if ( 0 === $user_id ) {
			return false;
		}

		return '1' === (string) get_user_meta( $user_id, self::USER_META_DISABLED, true );
	}

	/**
	 * Remove the entries of a post which is not published anymore.
	 *
	 * @param mixed $new_status The new status.
	 * @param mixed $old_status The old status.
	 * @param mixed $post       The post object.
	 *
	 * @return void
	 */
	public function remove_entries_of_unpublished_post( mixed $new_status, mixed $old_status, mixed $post ): void {
		// bail if the post was not published before or still is.
		if ( 'publish' !== $old_status || 'publish' === $new_status ) {
			return;
		}

		// bail on anything that is not a post object.
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$this->remove_entries_of_post( $post, true );
	}

	/**
	 * Remove the entries of a published post which just got a password.
	 *
	 * @param mixed $post_id     The post ID.
	 * @param mixed $post_after  The post object after the update.
	 * @param mixed $post_before The post object before the update.
	 *
	 * @return void
	 */
	public function remove_entries_of_protected_post( mixed $post_id, mixed $post_after, mixed $post_before ): void {
		// bail on anything that is not a post object.
		if ( ! $post_after instanceof WP_Post || ! $post_before instanceof WP_Post ) {
			return;
		}

		// bail if the post is not published or has no new password.
		if ( 'publish' !== $post_after->post_status || '' === $post_after->post_password || '' !== $post_before->post_password ) {
			return;
		}

		$this->remove_entries_of_post( $post_after, true );
	}

	/**
	 * Remove the entries of a deleted post.
	 *
	 * Its comments are deleted separately and handled by
	 * remove_entries_of_deleted_comment().
	 *
	 * @param mixed $post_id The post ID.
	 * @param mixed $post    The post object.
	 *
	 * @return void
	 */
	public function remove_entries_of_deleted_post( mixed $post_id, mixed $post ): void {
		// bail on anything that is not a post object.
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$this->remove_entries_of_post( $post, false );
	}

	/**
	 * Remove the entries of a post and optionally of its comments.
	 *
	 * @param WP_Post $post          The post object.
	 * @param bool    $with_comments Whether the entries of its comments should be removed, too.
	 *
	 * @return void
	 */
	private function remove_entries_of_post( WP_Post $post, bool $with_comments ): void {
		// bail on revisions, our own post types, types without public views and if nothing could have been imported.
		if ( 'revision' === $post->post_type || 'attachment' === $post->post_type || $this->is_own_post_type( $post->post_type ) || ! is_post_type_viewable( $post->post_type ) || ! $this->has_sources() ) {
			return;
		}

		Entries::get_instance()->delete_entries_by_external_id( 'wp-post-' . $post->ID, $this->get_slug() );

		// bail if the comments should stay.
		if ( ! $with_comments ) {
			return;
		}

		// their entries show the title of the post, which is not public anymore.
		$comment_ids = get_comments(
			array(
				'post_id' => $post->ID,
				'type'    => 'comment',
				'fields'  => 'ids',
			)
		);

		// bail if no list has been returned.
		if ( ! is_array( $comment_ids ) ) { // @phpstan-ignore function.alreadyNarrowedType
			return;
		}

		foreach ( $comment_ids as $comment_id ) {
			Entries::get_instance()->delete_entries_by_external_id( 'wp-comment-' . absint( $comment_id ), $this->get_slug() );
		}
	}

	/**
	 * Remove the entries of a comment which is not approved anymore.
	 *
	 * @param mixed $new_status The new status.
	 * @param mixed $old_status The old status.
	 * @param mixed $comment    The comment object.
	 *
	 * @return void
	 */
	public function remove_entries_of_unapproved_comment( mixed $new_status, mixed $old_status, mixed $comment ): void {
		// bail if the comment was not approved before or still is.
		if ( 'approved' !== $old_status || 'approved' === $new_status ) {
			return;
		}

		// bail on anything that is not a comment object or if nothing could have been imported.
		if ( ! $comment instanceof WP_Comment || ! $this->has_sources() ) {
			return;
		}

		Entries::get_instance()->delete_entries_by_external_id( 'wp-comment-' . $comment->comment_ID, $this->get_slug() );
	}

	/**
	 * Return whether any source uses this provider.
	 *
	 * The cleanup hooks fire for every post and comment of the website; without
	 * a source of this provider there is nothing to clean up, so they bail early.
	 *
	 * @return bool
	 */
	private function has_sources(): bool {
		static $has_sources = null;

		if ( null === $has_sources ) {
			$sources     = get_posts(
				array(
					'post_type'      => Sources::get_instance()->get_slug(),
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => 'provider', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small query, cached per request.
					'meta_value'     => $this->get_slug(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- small query, cached per request.
				)
			);
			$has_sources = ! empty( $sources );
		}

		return $has_sources;
	}

	/**
	 * Remove the entries of a deleted comment.
	 *
	 * @param mixed $comment_id The comment ID.
	 *
	 * @return void
	 */
	public function remove_entries_of_deleted_comment( mixed $comment_id ): void {
		// bail if nothing could have been imported.
		if ( ! $this->has_sources() ) {
			return;
		}

		Entries::get_instance()->delete_entries_by_external_id( 'wp-comment-' . absint( $comment_id ), $this->get_slug() );
	}
}
