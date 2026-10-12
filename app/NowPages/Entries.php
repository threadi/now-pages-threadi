<?php
/**
 * This file contains the object to handle entries for now pages.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\NowPages;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;
use ThreadiNowPages\Helper\Format;
use ThreadiNowPages\Helper\Languages;
use ThreadiNowPages\Plugin\Log;
use ThreadiNowPages\Plugin\Post_Type_Base;
use ThreadiNowPages\Sources\Sources;
use WP_Post;
use WP_Query;

/**
 * Initialize the plugin, connect all together.
 */
class Entries extends Post_Type_Base {
	/**
	 * Option to save deleted external IDs.
	 */
	public const OPTION_DELETED = 'threadi_now_pages_deleted_entries';

	/**
	 * Option to save the time (GMT timestamp) before which the cleanup has deleted all entries.
	 */
	public const OPTION_CLEANED_BEFORE = 'threadi_now_pages_cleaned_before';

	/**
	 * Maximum number of remembered deletions, older ones are dropped first.
	 */
	private const MAX_DELETED = 5000;

	/**
	 * How many words of its text an entry shows as title.
	 */
	private const TITLE_WORDS = 12;

	/**
	 * The slug.
	 *
	 * @var string
	 */
	protected string $slug = 'threadi_np_entry';

	/**
	 * The outcome of the last call of add_entry() in this request.
	 *
	 * @var array{added:bool,post_id:int,reason:string,message:string}|null
	 */
	private ?array $last_add_result = null;

	/**
	 * Whether deletions should be remembered at the moment.
	 *
	 * @var bool
	 */
	private bool $remember_deletions = true;

	/**
	 * Deletions of this request which have not been saved yet.
	 *
	 * @var array<int,string>
	 */
	private array $pending_deletions = array();

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// use our own hooks.
		add_action( 'threadi_now_pages_entry_received', array( $this, 'add_entry' ), 10, 2 );

		// use hooks.
		add_action( 'restrict_manage_posts', array( $this, 'add_author_filter' ) );
		add_action( 'restrict_manage_posts', array( $this, 'add_source_filter' ) );
		add_action( 'before_delete_post', array( $this, 'remember_deletion' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'forget_deletions_of_source' ), 10, 2 );
		add_action( 'delete_option_' . self::OPTION_DELETED, array( $this, 'forget_pending_deletions' ) );
		add_action( 'save_post_' . $this->get_slug(), array( $this, 'ensure_language' ), 10, 2 );

		// add a "Source" column to the entries list-table, linked to the parent threadi_np_source post.
		add_filter( 'manage_' . $this->get_slug() . '_posts_columns', array( $this, 'add_source_column' ) );
		add_action( 'manage_' . $this->get_slug() . '_posts_custom_column', array( $this, 'render_source_column' ), 10, 2 );
		add_filter( 'manage_edit-' . $this->get_slug() . '_sortable_columns', array( $this, 'add_sortable_source_column' ) );

		// an entry has no title, its text is all there is: show that wherever WordPress asks for a title.
		add_filter( 'the_title', array( $this, 'get_title' ), 10, 2 );

		// run parent function.
		parent::init();
	}

	/**
	 * Return the labels.
	 *
	 * @return array<string,string>
	 */
	protected function get_labels(): array {
		return array(
			'name'                  => __( 'Now Page Entries', 'threadi-now-pages' ),
			'singular_name'         => __( 'Now Page Entry', 'threadi-now-pages' ),
			'menu_name'             => __( 'Now Page Entries', 'threadi-now-pages' ),
			'parent_item_colon'     => __( 'Parent Now Page Entry', 'threadi-now-pages' ),
			'all_items'             => __( 'All Now Page Entries', 'threadi-now-pages' ),
			'view_item'             => __( 'View Now Page Entry in frontend', 'threadi-now-pages' ),
			'view_items'            => __( 'View Now Page Entries', 'threadi-now-pages' ),
			'edit_item'             => __( 'View Now Page Entry', 'threadi-now-pages' ),
			'search_items'          => __( 'Search Now Page Entry', 'threadi-now-pages' ),
			'not_found'             => __( 'No entries found', 'threadi-now-pages' ),
			'item_link'             => _x( 'Now Page Entry Link', 'navigation link block title', 'threadi-now-pages' ),
			'item_link_description' => _x( 'A link to a Now Page entry.', 'navigation link block description', 'threadi-now-pages' ),
			'add_new_item'          => __( 'Add New Now Page Entry', 'threadi-now-pages' ),
		);
	}

	/**
	 * Add a new entry.
	 *
	 * This is the main function to add new entries for new Now Pages.
	 *
	 * It must be requested via hook:
	 * `do_action( 'threadi_now_pages_entry_received', $entry, $source_id );`
	 *
	 * @param Entry $entry The entry object.
	 * @param int   $sources_id The ID of the used source.
	 *
	 * @return void
	 */
	public function add_entry( Entry $entry, int $sources_id ): void {
		// skip if this exact entry (same source, same external_id) already exists.
		if ( '' !== $entry->external_id && $this->entry_exists( $entry->external_id, $sources_id ) ) {
			$this->set_last_add_result( 0, 'duplicate', __( 'An entry with this external ID already exists for this source.', 'threadi-now-pages' ) );
			return;
		}

		// skip if this entry was deleted by hand before.
		if ( '' !== $entry->external_id && in_array( $sources_id . '|' . $entry->external_id, $this->get_deleted_entries(), true ) ) {
			$this->set_last_add_result( 0, 'deleted_before', __( 'An entry with this external ID was deleted before and will not be added again.', 'threadi-now-pages' ) );
			return;
		}

		// skip if this imported entry is older than what the cleanup has deleted: a source
		// like a feed delivers its newest items regardless of their age, so they would return.
		$cleaned_before = absint( get_option( self::OPTION_CLEANED_BEFORE, 0 ) );
		if ( '' !== $entry->external_id && $cleaned_before > 0 && $entry->date->getTimestamp() < $cleaned_before ) {
			$this->set_last_add_result( 0, 'cleaned_up', __( 'The entry is older than the entries deleted by the cleanup and will not be added again.', 'threadi-now-pages' ) );
			return;
		}

		// skip if the entry does not pass the configured content filter rules.
		$filter_result = Content_Filters::get_instance()->check( $entry, $sources_id );

		if ( true !== $filter_result ) {
			// log this event.
			Log::get_instance()->add(
				sprintf(
				/* translators: %1$s will be replaced by a rule description. */
					__( 'Entry skipped, it did not pass the content filter: %1$s', 'threadi-now-pages' ),
					esc_html( $filter_result )
				) . ' <code>' . esc_html( wp_trim_words( $entry->text, 12 ) ) . '</code>',
				'info',
				'content_filter'
			);

			$this->set_last_add_result( 0, 'content_filter', $filter_result );

			// do nothing more.
			return;
		}

		// a date in the future would turn the entry into a scheduled post, which
		// stays invisible until then: use the current time instead.
		$date = $entry->date;
		if ( $date->getTimestamp() > time() ) {
			$date = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		}

		// create a new entry.
		$query = array(
			'post_type'     => $this->get_slug(),
			// an entry is published, unless its provider lets it wait for the user.
			'post_status'   => Confirmation::STATUS === $entry->status ? Confirmation::STATUS : 'publish',
			'post_title'    => '',
			// filter the markup ourselves: kses is skipped if the import runs for a
			// user with "unfiltered_html" (manual import, CLI with --user).
			'post_content'  => wp_kses_post( $entry->text ),
			'post_date'     => $date->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' ),
			'post_date_gmt' => $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			'post_parent'   => $sources_id,
			'post_author'   => $this->resolve_author( $sources_id ),
			'meta_input'    => array(
				'language'    => $this->resolve_language( $entry, $sources_id ),
				'external_id' => $entry->external_id,
				'source_url'  => ! empty( $entry->source_url ) ? $entry->source_url : '',
			),
		);

		// wp_insert_post() expects slashed data, otherwise backslashes get lost.
		$post_id = wp_insert_post( wp_slash( $query ), true );

		// bail on any error.
		if ( is_wp_error( $post_id ) ) {
			// log this event.
			Log::get_instance()->add( __( 'Error during import of new entry:', 'threadi-now-pages' ) . ' <code>' . esc_html( Format::get_json( $post_id ) ) . '</code>', 'error', 'system' );

			$this->set_last_add_result( 0, 'insert_failed', $post_id->get_error_message() );

			// do nothing more.
			return;
		}

		// two requests at the same time can both pass the check for an existing
		// entry above: the oldest of the resulting entries wins, the others go.
		if ( '' !== $entry->external_id && $this->get_oldest_entry_id( $entry->external_id, $sources_id ) !== (int) $post_id ) {
			// delete the entry without remembering it.
			$this->remember_deletions = false;
			try {
				wp_delete_post( (int) $post_id, true );
			} finally {
				$this->remember_deletions = true;
			}

			$this->set_last_add_result( 0, 'duplicate', __( 'An entry with this external ID already exists for this source.', 'threadi-now-pages' ) );
			return;
		}

		// Assign thematic category (explicit on Entry → Source default → none).
		$term_id = Categories::get_instance()->resolve_term_id_for_entry( $entry, $sources_id );
		Categories::get_instance()->assign_to_entry( (int) $post_id, $term_id );

		$this->set_last_add_result( (int) $post_id, '', '' );

		/**
		 * Fires after a new entry has been stored.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param int   $post_id    The ID of the new entry.
		 * @param Entry $entry      The entry object it was created from.
		 * @param int   $sources_id The ID of the source it came from (0 = none).
		 */
		do_action( 'threadi_now_pages_entry_added', (int) $post_id, $entry, $sources_id );
	}

	/**
	 * Remember the outcome of add_entry().
	 *
	 * @param int    $post_id The ID of the new entry, 0 if none was added.
	 * @param string $reason  Why nothing was added, empty on success.
	 * @param string $message A human-readable explanation of the reason.
	 *
	 * @return void
	 */
	private function set_last_add_result( int $post_id, string $reason, string $message ): void {
		$this->last_add_result = array(
			'added'   => $post_id > 0,
			'post_id' => $post_id,
			'reason'  => $reason,
			'message' => $message,
		);
	}

	/**
	 * Return the outcome of the last add_entry() call and forget it.
	 *
	 * The hook `threadi_now_pages_entry_received` returns nothing, so callers
	 * that need to know what happened (e.g. the abilities) read it here.
	 *
	 * @return array{added:bool,post_id:int,reason:string,message:string}|null Null if add_entry() did not run.
	 */
	public function pull_last_add_result(): ?array {
		$result                = $this->last_add_result;
		$this->last_add_result = null;

		return $result;
	}

	/**
	 * Resolve the language to use for a new entry.
	 *
	 * Priority: the language configured on its Source > the value on the Entry
	 * (e.g. from a feed or a webhook request) > the site's own language. The
	 * setting of the user wins over what a feed or service claims.
	 *
	 * @param Entry $entry      The entry object.
	 * @param int   $sources_id The ID of the used source (0 = none).
	 *
	 * @return string
	 */
	private function resolve_language( Entry $entry, int $sources_id ): string {
		if ( $sources_id > 0 ) {
			$source_language = Languages::normalize_locale( (string) get_post_meta( $sources_id, 'language', true ) );

			if ( '' !== $source_language ) {
				return $source_language;
			}
		}

		if ( ! empty( $entry->language ) ) {
			return Languages::normalize_locale( $entry->language );
		}

		// return the default language as fallback.
		return Languages::get_current_language();
	}

	/**
	 * Return the text of an entry as its title.
	 *
	 * Entries are stored without a title: what a source delivers is a single
	 * sentence, and that sentence is the entry. Without this, every entry is
	 * called "(no title)" in the backend - in the list, in the row actions and
	 * in every message about it - and they cannot be told apart.
	 *
	 * The title is derived, not stored: a stored copy would have to follow
	 * every change of the text.
	 *
	 * @param mixed $title   The title WordPress found.
	 * @param mixed $post_id The ID of the post.
	 *
	 * @return mixed
	 */
	public function get_title( mixed $title, mixed $post_id = 0 ): mixed {
		// bail if there is a title: entries written before the title field was removed may have one.
		if ( ! is_string( $title ) || '' !== trim( $title ) ) {
			return $title;
		}

		$post = get_post( absint( $post_id ) );

		// bail if this is not an entry.
		if ( ! $post instanceof WP_Post || $this->get_slug() !== $post->post_type ) {
			return $title;
		}

		return wp_trim_words( Format::get_entry_text( $post ), self::TITLE_WORDS, '…' );
	}

	/**
	 * Add our own columns right after the title column.
	 *
	 * @param array<string,string> $columns The existing columns.
	 *
	 * @return array<string,string>
	 */
	public function add_source_column( array $columns ): array {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			if ( 'title' === $key ) {
				// what is shown there is the text of the entry.
				$new_columns[ $key ] = __( 'Entry', 'threadi-now-pages' );

				$new_columns['threadi_np_source']          = __( 'Source', 'threadi-now-pages' );
				$new_columns['threadi_now_pages_language'] = __( 'Language', 'threadi-now-pages' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render the "Source" column: a link to the parent threadi_np_source post.
	 *
	 * @param string $column  The column being rendered.
	 * @param mixed  $post_id The current entry's post ID.
	 *
	 * @return void
	 */
	public function render_source_column( string $column, mixed $post_id ): void {
		// show the source.
		if ( 'threadi_np_source' === $column ) {
			// get the parent ID.
			$parent_id = wp_get_post_parent_id( absint( $post_id ) );

			// no source assigned (e.g. a webhook add without a matched source).
			if ( ! $parent_id ) {
				// show warning.
				echo '&#8212;';

				// do nothing more.
				return;
			}

			// get the link to the parent ID.
			$edit_link = get_edit_post_link( $parent_id );

			// if no link could be loaded, show their title.
			if ( ! $edit_link ) {
				echo esc_html( get_the_title( $parent_id ) );

				// do nothing more.
				return;
			}

			// link to the source.
			printf( '<a href="%s">%s</a>', esc_url( $edit_link ), esc_html( get_the_title( $parent_id ) ) );
		}

		// show the language.
		if ( 'threadi_now_pages_language' === $column ) {
			// get the configured provider.
			$language = (string) get_post_meta( absint( $post_id ), 'language', true );

			// no provider configured yet.
			if ( '' === $language ) {
				// show warning.
				echo '&#8212;';

				// do nothing more.
				return;
			}

			echo esc_html( Languages::get_display_name( $language ) );
		}
	}

	/**
	 * Make the "Source" column sortable.
	 *
	 * 'parent' is one of WP_Query's built-in orderby keys (maps to the
	 * native post_parent column), so no extra pre_get_posts hook is needed.
	 *
	 * @param array<string,string> $columns The existing sortable columns.
	 *
	 * @return array<string,string>
	 */
	public function add_sortable_source_column( array $columns ): array {
		$columns['threadi_np_source'] = 'parent';
		$columns['author']            = 'author';

		// entries have no stored title to sort by.
		unset( $columns['title'] );

		return $columns;
	}

	/**
	 * Return list of WP-functions this post type supports.
	 *
	 * @return string[]
	 */
	protected function get_supports(): array {
		// no title: the text of an entry is its content, a title would never be shown anywhere.
		return array( 'editor', 'author' );
	}

	/**
	 * Return whether this post type is visible in REST API.
	 *
	 * Hint: using false will disable the block editor.
	 *
	 * @return bool
	 */
	protected function is_shown_in_rest(): bool {
		return true;
	}

	/**
	 * Check whether an entry with this external_id already exists for this source.
	 *
	 * @param string $external_id The provider's stable ID for this entry.
	 * @param int    $sources_id  The source post ID (0 = no specific source, e.g. a webhook).
	 *
	 * @return bool
	 */
	private function entry_exists( string $external_id, int $sources_id ): bool {
		$query = new WP_Query(
			array(
				'post_type'      => $this->get_slug(),
				'post_status'    => array( 'any', 'trash' ),
				'post_parent'    => $sources_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Query for specific external ID.
					array(
						'key'   => 'external_id',
						'value' => $external_id,
					),
				),
			)
		);
		return $query->have_posts();
	}

	/**
	 * Return the ID of the oldest entry with this external_id for this source.
	 *
	 * @param string $external_id The provider's stable ID for this entry.
	 * @param int    $sources_id  The source post ID (0 = no specific source, e.g. a webhook).
	 *
	 * @return int Zero if there is none.
	 */
	private function get_oldest_entry_id( string $external_id, int $sources_id ): int {
		$query = new WP_Query(
			array(
				'post_type'      => $this->get_slug(),
				'post_status'    => array( 'any', 'trash' ),
				'post_parent'    => $sources_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Query for specific external ID.
					array(
						'key'   => 'external_id',
						'value' => $external_id,
					),
				),
			)
		);

		$post_id = $query->posts[0] ?? 0;

		return absint( $post_id instanceof WP_Post ? $post_id->ID : $post_id );
	}

	/**
	 * Resolve the author for a new entry.
	 *
	 * Priority: the author of its source > the current user (manual/CLI).
	 *
	 * @param int $sources_id The ID of the used source (0 = none).
	 *
	 * @return int
	 */
	private function resolve_author( int $sources_id ): int {
		if ( $sources_id > 0 ) {
			$source_author = absint( get_post_field( 'post_author', $sources_id ) );

			if ( $source_author > 0 ) {
				return $source_author;
			}
		}

		return get_current_user_id();
	}

	/**
	 * Add an author dropdown above the entries list table.
	 *
	 * @param string $post_type The current post type.
	 *
	 * @return void
	 */
	public function add_author_filter( string $post_type ): void {
		// bail if this is not our cpt.
		if ( $this->get_slug() !== $post_type ) {
			return;
		}

		// users who only see their own entries have nothing to choose from.
		if ( ! $this->can_see_all_entries() ) {
			return;
		}

		// get the author IDs.
		$author_ids = $this->get_entry_author_ids();

		// nothing to filter with a single author.
		if ( count( $author_ids ) < 2 ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list table filter.
		$selected = isset( $_GET['author'] ) ? absint( $_GET['author'] ) : 0;

		wp_dropdown_users(
			array(
				'name'            => 'author',
				'show_option_all' => __( 'All authors', 'threadi-now-pages' ),
				'selected'        => $selected,
				'include'         => $author_ids,
			)
		);
	}

	/**
	 * Return the IDs of all users who own at least one entry.
	 *
	 * @return array<int,int>
	 */
	private function get_entry_author_ids(): array {
		$cache_key  = 'threadi_now_pages_entry_authors_' . md5( wp_cache_get_last_changed( 'posts' ) );
		$author_ids = wp_cache_get( $cache_key );

		if ( false === $author_ids ) {
			global $wpdb;

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Get list of all authors.
			$author_ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT post_author FROM ' . $wpdb->posts . ' WHERE post_type = %s AND post_author > 0',
					$this->get_slug()
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery

			wp_cache_set( $cache_key, $author_ids, '', HOUR_IN_SECONDS );
		}

		return array_map( 'absint', $author_ids );
	}

	/**
	 * Remember a permanently deleted entry so it is not imported again.
	 *
	 * Trashed entries do not need this: entry_exists() already looks into the
	 * trash. Only the final deletion loses the external_id. The list is
	 * limited to MAX_DELETED items.
	 *
	 * @param mixed $post_id The post ID, as passed by WordPress.
	 * @param mixed $post    The post object, as passed by WordPress.
	 *
	 * @return void
	 */
	public function remember_deletion( mixed $post_id, mixed $post ): void {
		// bail if deletions should not be remembered at the moment.
		if ( ! $this->remember_deletions ) {
			return;
		}

		// bail if this is not one of our entries.
		if ( ! $post instanceof WP_Post || $this->get_slug() !== $post->post_type ) {
			return;
		}

		// bail if the entry has no external ID (manually created).
		$external_id = (string) get_post_meta( absint( $post_id ), 'external_id', true );

		if ( '' === $external_id ) {
			return;
		}

		// log this event, only in debug mode: bulk deletions would flood the log.
		if ( 1 === absint( get_option( 'threadi_now_pages_debug' ) ) ) {
			Log::get_instance()->add( __( 'Added an entry to the list to remember its deletion:', 'threadi-now-pages' ) . ' <code>' . esc_html( $external_id ) . '</code>', 'info', 'system' );
		}

		// save the deletions of this request once at its end: a bulk deletion
		// would rewrite the complete list for every single entry otherwise.
		if ( empty( $this->pending_deletions ) ) {
			add_action( 'shutdown', array( $this, 'save_pending_deletions' ) );
		}

		// remember source and external ID.
		$this->pending_deletions[] = absint( $post->post_parent ) . '|' . $external_id;
	}

	/**
	 * Save the deletions of this request.
	 *
	 * @return void
	 */
	public function save_pending_deletions(): void {
		// bail if nothing has been deleted.
		if ( empty( $this->pending_deletions ) ) {
			return;
		}

		$this->update_deleted_entries( $this->get_deleted_entries() );
	}

	/**
	 * Forget the deletions of this request which have not been saved yet.
	 *
	 * Called when the list is removed, e.g. by the reset in the settings.
	 *
	 * @return void
	 */
	public function forget_pending_deletions(): void {
		$this->pending_deletions = array();
	}

	/**
	 * Save the list of permanently deleted entries.
	 *
	 * @param array<int,string> $deleted The list.
	 *
	 * @return void
	 */
	private function update_deleted_entries( array $deleted ): void {
		// keep only the newest items.
		$deleted = array_slice( array_values( array_unique( $deleted ) ), -self::MAX_DELETED );

		// save the list, it is not needed on every request.
		update_option( self::OPTION_DELETED, $deleted, false );

		// the list contains the deletions of this request now.
		$this->pending_deletions = array();
	}

	/**
	 * Forget the remembered deletions of a source when the source is deleted.
	 *
	 * @param mixed $post_id The post ID, as passed by WordPress.
	 * @param mixed $post    The post object, as passed by WordPress.
	 *
	 * @return void
	 */
	public function forget_deletions_of_source( mixed $post_id, mixed $post ): void {
		// bail if this is not a source.
		if ( ! $post instanceof WP_Post || Sources::get_instance()->get_slug() !== $post->post_type ) {
			return;
		}

		$prefix  = absint( $post_id ) . '|';
		$deleted = $this->get_deleted_entries();

		// remove the items of this source.
		$filtered = array_filter(
			$deleted,
			static function ( string $item ) use ( $prefix ): bool {
				return ! str_starts_with( $item, $prefix );
			}
		);

		// bail if nothing changed.
		if ( count( $filtered ) === count( $deleted ) ) {
			return;
		}

		$this->update_deleted_entries( $filtered );
	}

	/**
	 * Return the list of permanently deleted entries.
	 *
	 * Includes the deletions of this request which have not been saved yet.
	 *
	 * @return array<int,string> Each item is "{source_id}|{external_id}".
	 */
	private function get_deleted_entries(): array {
		$deleted = get_option( self::OPTION_DELETED, array() );
		$deleted = is_array( $deleted ) ? array_values( array_map( 'strval', $deleted ) ) : array();

		return array_merge( $deleted, $this->pending_deletions );
	}

	/**
	 * Delete the imported entries with the given external ID.
	 *
	 * Used if the original content is not public anymore. These deletions are
	 * not remembered, so the content can be imported again if it becomes
	 * public again.
	 *
	 * @param string $external_id   The external ID.
	 * @param string $provider_slug Only delete entries of sources using this provider.
	 *
	 * @return void
	 */
	public function delete_entries_by_external_id( string $external_id, string $provider_slug ): void {
		// bail without an external ID.
		if ( '' === $external_id ) {
			return;
		}

		$query = new WP_Query(
			array(
				'post_type'      => $this->get_slug(),
				'post_status'    => array( 'any', 'trash' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Query for specific external ID.
					array(
						'key'   => 'external_id',
						'value' => $external_id,
					),
				),
			)
		);

		foreach ( (array) $query->posts as $post_id ) {
			$post_id   = absint( $post_id instanceof WP_Post ? $post_id->ID : $post_id );
			$source_id = absint( wp_get_post_parent_id( $post_id ) );

			// bail if the entry does not belong to a source of this provider.
			if ( 0 === $source_id || (string) get_post_meta( $source_id, 'provider', true ) !== $provider_slug ) {
				continue;
			}

			// delete the entry without remembering it.
			$this->remember_deletions = false;
			try {
				wp_delete_post( $post_id, true );
			} finally {
				$this->remember_deletions = true;
			}
		}
	}

	/**
	 * Make sure every entry has a language, whoever created it.
	 *
	 * @param mixed $post_id The post ID, as passed by WordPress.
	 * @param mixed $post    The post object, as passed by WordPress.
	 *
	 * @return void
	 */
	public function ensure_language( mixed $post_id, mixed $post ): void {
		$post_id = absint( $post_id );

		// bail if this is an autosave or revision.
		if ( ! $post instanceof WP_Post || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// bail if a language is already set.
		if ( '' !== (string) get_post_meta( $post_id, 'language', true ) ) {
			return;
		}

		// fall back to the language of the source, then to the site's own.
		$language = absint( $post->post_parent ) > 0
			? Languages::normalize_locale( (string) get_post_meta( absint( $post->post_parent ), 'language', true ) )
			: '';

		update_post_meta( $post_id, 'language', '' !== $language ? $language : Languages::get_current_language() );
	}

	/**
	 * Return whether an entry was created by hand.
	 *
	 * Manual entries have neither a source nor an external ID - the webhook
	 * always sets the latter, even when it could not match a source.
	 *
	 * @param WP_Post $entry The entry.
	 *
	 * @return bool
	 */
	public function is_manual( WP_Post $entry ): bool {
		// an entry with a source is always imported.
		if ( absint( $entry->post_parent ) > 0 ) {
			return false;
		}

		$is_manual = '' === (string) get_post_meta( absint( $entry->ID ), 'external_id', true );

		/**
		 * Filter whether an entry counts as manually created.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param bool    $is_manual Whether the entry was created by hand.
		 * @param WP_Post $entry     The entry.
		 */
		return (bool) apply_filters( 'threadi_now_pages_entry_is_manual', $is_manual, $entry );
	}

	/**
	 * {@inheritDoc}
	 */
	public function post_updated_messages( array $messages ): array {
		// get the post date.
		$post      = get_post();
		$post_date = '';

		if ( $post instanceof WP_Post ) {
			$post_date = $post->post_date;
		}

		// get the date.
		$date = date_i18n(
			'd. F Y H:i',
			strtotime( $post_date )
		);

		// define the messages for our cpt.
		$messages[ $this->get_slug() ] = array(
			0  => '',
			1  => __( 'The entry has been updated.', 'threadi-now-pages' ),
			4  => __( 'The entry has been updated.', 'threadi-now-pages' ),
			6  => __( 'The entry has been saved.', 'threadi-now-pages' ),
			7  => __( 'The entry has been saved.', 'threadi-now-pages' ),
			/* translators: %1$s will be replaced by a date. */
			9  => sprintf( __( 'The entry should be published on %1$s.', 'threadi-now-pages' ), $date ),
			10 => __( 'The entry has been updated.', 'threadi-now-pages' ),
		);

		// return the resulting messages.
		return $messages;
	}

	/**
	 * {@inheritDoc}
	 */
	public function bulk_post_updated_messages( array $bulk_messages, array $bulk_counts ): array {
		/* translators: %1$s will be replaced by a number. */
		$bulk_messages[ $this->get_slug() ]['deleted'] = _n(
			'%1$d entry has been deleted.',
			'%1$d entries have been deleted.',
			absint( $bulk_counts['deleted'] ),
			'threadi-now-pages'
		);
		/* translators: %1$s will be replaced by a number. */
		$bulk_messages[ $this->get_slug() ]['trashed'] = _n(
			'%1$d entry has been moved to trash.',
			'%1$d entries have been moved to trash.',
			absint( $bulk_counts['trashed'] ),
			'threadi-now-pages'
		);
		/* translators: %1$s will be replaced by a number. */
		$bulk_messages[ $this->get_slug() ]['untrashed'] = _n(
			'%1$d entry has been revived from trash.',
			'%1$d entries have been revived from trash.',
			absint( $bulk_counts['untrashed'] ),
			'threadi-now-pages'
		);
		return $bulk_messages;
	}

	/**
	 * Add a source dropdown above the entries list table.
	 */
	public function add_source_filter( string $post_type ): void {
		if ( $this->get_slug() !== $post_type ) {
			return;
		}

		$source_ids = $this->get_entry_source_ids();

		// nothing to filter with a single source.
		if ( count( $source_ids ) < 2 ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list table filter.
		$selected = isset( $_GET['post_parent'] ) ? absint( $_GET['post_parent'] ) : 0;

		$posts = get_posts(
			array(
				'post_type'      => Sources::get_instance()->get_slug(),
				'post__in'       => $source_ids,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post_status'    => 'publish',
			)
		);

		echo '<select name="post_parent" id="post_parent">';

		printf(
			'<option value="0"%s>%s</option>',
			selected( $selected, 0, false ),
			esc_html__( 'All sources', 'threadi-now-pages' )
		);

		foreach ( $posts as $post ) {
			printf(
				'<option value="%d"%s>%s</option>',
				absint( $post->ID ),
				selected( $selected, $post->ID, false ),
				esc_html( get_the_title( $post ) )
			);
		}

		echo '</select>';
	}

	/**
	 * Return the IDs of all sources that have at least one entry.
	 *
	 * @return array<int,int>
	 */
	private function get_entry_source_ids(): array {
		$restricted = ! $this->can_see_all_entries();

		// the key changes whenever a post is saved or deleted, so nothing has to be deleted by hand:
		// the old entry is simply not found anymore and expires on its own.
		$cache_key  = 'threadi_now_pages_entry_sources_' . ( $restricted ? get_current_user_id() : 'all' ) . '_' . md5( wp_cache_get_last_changed( 'posts' ) );
		$source_ids = wp_cache_get( $cache_key );

		if ( false === $source_ids ) {
			global $wpdb;

			if ( $restricted ) {
				// only the sources of the own entries.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Get list of sources with entries, cached above.
				$source_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_type = %s AND post_parent > 0 AND post_author = %d",
						$this->get_slug(),
						get_current_user_id()
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Get list of sources with entries, cached above.
				$source_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_type = %s AND post_parent > 0",
						$this->get_slug()
					)
				);
			}

			wp_cache_set( $cache_key, $source_ids, '', HOUR_IN_SECONDS );
		}

		return array_map( 'absint', $source_ids );
	}

	/**
	 * Extend the query for entries.
	 *
	 * @param WP_Query $query The query object.
	 *
	 * @return void
	 */
	public function pre_get_posts( WP_Query $query ): void {
		// use the base restriction.
		parent::pre_get_posts( $query );

		// bail if we are not in backend or this is not the main query.
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== $this->get_slug() ) {
			return;
		}

		if ( ! empty( $_GET['post_parent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list table filter.
			$query->set( 'post_parent', absint( $_GET['post_parent'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list table filter.
		}
	}

	/**
	 * Return our own rest controller class.
	 *
	 * @return string
	 */
	protected function get_rest_controller_class(): string {
		return Entries_Rest_Controller::class;
	}

	/**
	 * Return whether the actual user may see the entries of other users.
	 *
	 * @return bool
	 */
	private function can_see_all_entries(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'edit_others_' . $this->get_slug() . 's' );
	}
}
