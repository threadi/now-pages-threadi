<?php
/**
 * Taxonomy "threadi_now_pages_category" for thematic grouping of now-page entries.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\NowPages;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Helper\Format;
use ThreadiNowPages\Helper\Singleton_Interface;
use ThreadiNowPages\Helper\Singleton_Trait;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Response;
use WP_Term;

/**
 * Registers and helpers for the "threadi_now_pages_category" taxonomy.
 */
class Categories implements Singleton_Interface {
	use Singleton_Trait;

	/**
	 * Taxonomy slug.
	 *
	 * @var string
	 */
	public const TAXONOMY = 'threadi_now_pages_category';

	/**
	 * Post-meta key on Sources for the default category term ID.
	 *
	 * @var string
	 */
	public const SOURCE_DEFAULT_META = 'default_category';

	/**
	 * Term-meta key: sentence template for the template summarizer (%s = entry text).
	 *
	 * @var string
	 */
	public const SENTENCE_TEMPLATE_META = 'sentence_template';

	/**
	 * Term-meta key for the override text.
	 */
	public const OVERRIDE_TEXT_META = 'override_text';

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
		add_action( 'init', array( $this, 'register' ) );

		// use hooks.
		add_action( self::TAXONOMY . '_add_form_fields', array( $this, 'render_add_fields' ) );
		add_action( self::TAXONOMY . '_edit_form_fields', array( $this, 'render_edit_fields' ), 10, 1 );
		add_action( 'created_' . self::TAXONOMY, array( $this, 'save_term_meta' ) );
		add_action( 'edited_' . self::TAXONOMY, array( $this, 'save_term_meta' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		// do not reveal the activity through counts or sitemaps.
		add_filter( 'rest_prepare_' . self::TAXONOMY, array( $this, 'remove_count_from_rest' ) );
		add_filter( 'rest_prepare_' . self::TAXONOMY, array( $this, 'remove_meta_from_rest' ) );
		add_filter( 'rest_' . self::TAXONOMY . '_query', array( $this, 'restrict_rest_query' ) );
		add_filter( 'wp_sitemaps_taxonomies', array( $this, 'remove_from_sitemaps' ) );
	}

	/**
	 * Remove the count of entries from the REST response for users who could
	 * not edit entries.
	 *
	 * The count changes with every imported entry and would reveal the
	 * activity in real time, bypassing any delay.
	 *
	 * @param mixed $response The response object.
	 *
	 * @return mixed
	 */
	public function remove_count_from_rest( mixed $response ): mixed {
		// bail if this is not a response.
		if ( ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		// bail if the user could edit entries.
		if ( current_user_can( 'edit_' . Entries::get_instance()->get_slug() . 's' ) ) {
			return $response;
		}

		// get the data.
		$data = $response->get_data();

		// bail if no count is set.
		if ( ! is_array( $data ) || ! isset( $data['count'] ) ) {
			return $response;
		}

		// remove the count.
		$data['count'] = 0;
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Remove our term meta from the REST response for users who could not
	 * manage categories.
	 *
	 * The sentence template and the override text are only meant for the
	 * management of categories, not for every visitor of the REST API.
	 *
	 * @param mixed $response The response object.
	 *
	 * @return mixed
	 */
	public function remove_meta_from_rest( mixed $response ): mixed {
		// bail if this is not a response.
		if ( ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		// bail if the user could manage categories.
		if ( current_user_can( 'manage_categories' ) ) {
			return $response;
		}

		// get the data.
		$data = $response->get_data();

		// bail if no meta is set.
		if ( ! is_array( $data ) || ! isset( $data['meta'] ) || ! is_array( $data['meta'] ) ) {
			return $response;
		}

		// remove our term meta.
		unset( $data['meta'][ self::SENTENCE_TEMPLATE_META ], $data['meta'][ self::OVERRIDE_TEXT_META ] );
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Prevent REST queries which reveal the count of entries in another way.
	 *
	 * Sorting by count or hiding empty terms would show which category just
	 * got a new entry, for users who could not edit entries.
	 *
	 * @param array<string,mixed> $prepared_args The arguments for WP_Term_Query.
	 *
	 * @return array<string,mixed>
	 */
	public function restrict_rest_query( array $prepared_args ): array {
		// bail if the user could edit entries.
		if ( current_user_can( 'edit_' . Entries::get_instance()->get_slug() . 's' ) ) {
			return $prepared_args;
		}

		// sort by name instead of count.
		if ( isset( $prepared_args['orderby'] ) && 'count' === $prepared_args['orderby'] ) {
			$prepared_args['orderby'] = 'name';
		}

		// always return empty terms, too.
		$prepared_args['hide_empty'] = false;

		return $prepared_args;
	}

	/**
	 * Remove our taxonomy from the sitemaps.
	 *
	 * @param array<string,mixed> $taxonomies List of taxonomies.
	 *
	 * @return array<string,mixed>
	 */
	public function remove_from_sitemaps( array $taxonomies ): array {
		unset( $taxonomies[ self::TAXONOMY ] );

		return $taxonomies;
	}

	/**
	 * Return the taxonomy slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return self::TAXONOMY;
	}

	/**
	 * Register the taxonomy on entries (and sources, for the default-category UI).
	 *
	 * Hierarchical like WP categories so parent/child themes are possible
	 * (e.g. "Sport" → "Cycling").
	 *
	 * @return void
	 */
	public function register(): void {
		$labels = array(
			'name'                       => _x( 'Categories', 'taxonomy general name', 'threadi-now-pages' ),
			'singular_name'              => _x( 'Category', 'taxonomy singular name', 'threadi-now-pages' ),
			'search_items'               => __( 'Search Categories', 'threadi-now-pages' ),
			'popular_items'              => __( 'Popular Categories', 'threadi-now-pages' ),
			'all_items'                  => __( 'All Categories', 'threadi-now-pages' ),
			'parent_item'                => __( 'Parent Category', 'threadi-now-pages' ),
			'parent_item_colon'          => __( 'Parent Category:', 'threadi-now-pages' ),
			'edit_item'                  => __( 'Edit Category', 'threadi-now-pages' ),
			'view_item'                  => __( 'View Category', 'threadi-now-pages' ),
			'update_item'                => __( 'Update Category', 'threadi-now-pages' ),
			'add_new_item'               => __( 'Add New Category', 'threadi-now-pages' ),
			'new_item_name'              => __( 'New Category Name', 'threadi-now-pages' ),
			'separate_items_with_commas' => __( 'Separate categories with commas', 'threadi-now-pages' ),
			'add_or_remove_items'        => __( 'Add or remove categories', 'threadi-now-pages' ),
			'choose_from_most_used'      => __( 'Choose from the most used categories', 'threadi-now-pages' ),
			'not_found'                  => __( 'No categories found.', 'threadi-now-pages' ),
			'no_terms'                   => __( 'No categories', 'threadi-now-pages' ),
			'filter_by_item'             => __( 'Filter by category', 'threadi-now-pages' ),
			'items_list_navigation'      => __( 'Categories list navigation', 'threadi-now-pages' ),
			'items_list'                 => __( 'Categories list', 'threadi-now-pages' ),
			'back_to_items'              => __( '← Go to Categories', 'threadi-now-pages' ),
			'item_link'                  => _x( 'Category Link', 'navigation link block title', 'threadi-now-pages' ),
			'item_link_description'      => _x( 'A link to a category.', 'navigation link block description', 'threadi-now-pages' ),
			'menu_name'                  => __( 'Categories', 'threadi-now-pages' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => true,
			'hierarchical'       => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_nav_menus'  => true,
			'show_admin_column'  => true,
			'show_in_rest'       => true,
			'rewrite'            => array(
				'slug'         => 'now-category',
				'hierarchical' => true,
			),
			'query_var'          => true,
			'capabilities'       => array(
				'manage_terms' => 'manage_categories',
				'edit_terms'   => 'manage_categories',
				'delete_terms' => 'manage_categories',
				'assign_terms' => 'edit_posts',
			),
		);

		/**
		 * Filter the arguments passed to register_taxonomy() for "threadi_now_pages_category".
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<string,mixed> $args Taxonomy args.
		 */
		$args = apply_filters( 'threadi_now_pages_category_taxonomy_args', $args );

		// set the object types this taxonomy is assigned to.
		$object_types = array(
			Entries::get_instance()->get_slug(),
		);

		/**
		 * Filter which post types the "threadi_now_pages_category" taxonomy is attached to.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<int,string> $object_types Post type slugs.
		 */
		$object_types = apply_filters( 'threadi_now_pages_category_object_types', $object_types );

		// register this taxonomy.
		register_taxonomy( self::TAXONOMY, $object_types, $args );

		// register our term meta for the sentence template.
		register_term_meta(
			self::TAXONOMY,
			self::SENTENCE_TEMPLATE_META,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( $this, 'sanitize_sentence_template' ),
				'auth_callback'     => static function (): bool {
					return current_user_can( 'manage_categories' );
				},
			)
		);

		// register our term meta for the override text.
		register_term_meta(
			self::TAXONOMY,
			self::OVERRIDE_TEXT_META,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'wp_kses_post',
				'auth_callback'     => static function (): bool {
					return current_user_can( 'manage_categories' );
				},
			)
		);
	}

	/**
	 * Sanitize a sentence template: must contain %s or be empty (inherit default).
	 *
	 * Also used as sanitize callback of the term meta, which gets the value
	 * already unslashed: unslashing it here would remove its backslashes.
	 *
	 * @param mixed $value Raw value, not slashed.
	 *
	 * @return string
	 */
	public function sanitize_sentence_template( mixed $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return '';
		}
		// Soft check: warn-level only; still store if no %s so authors can use plain text.
		return sanitize_text_field( $value );
	}

	/**
	 * Field on the "Add category" screen.
	 *
	 * @return void
	 */
	public function render_add_fields(): void {
		?>
		<div class="form-field">
			<label for="threadi_now_pages_sentence_template"><?php esc_html_e( 'Narrative sentence template', 'threadi-now-pages' ); ?></label>
			<input type="text" name="threadi_now_pages_sentence_template" id="threadi_now_pages_sentence_template" value="" class="regular-text" />
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
					/* translators: %s is the literal placeholder characters. */
						__( 'Used by the template summarizer. Include %s where the entry text should appear. Leave empty for the default (“I did …”).', 'threadi-now-pages' ),
						'%s'
					)
				);
				?>
			</p>
		</div>
		<div class="form-field">
			<label for="threadi_now_pages_override_text"><?php esc_html_e( 'Summary shown instead of the entries', 'threadi-now-pages' ); ?></label>
			<?php
			wp_editor(
				'',
				'threadi_now_pages_override_text',
				array(
					'textarea_name' => 'threadi_now_pages_override_text',
					'textarea_rows' => 5,
					'teeny'         => true,
					'media_buttons' => false,
					'quicktags'     => true,
				)
			);
			?>
			<p class="description">
				<?php
				echo wp_kses_post(
					__( 'Replaces every entry of this category with your own text. Instead of listing twelve commits, your now page could say: <em>“Spent the week rewriting the import, it finally handles feeds without dates.”</em> Leave empty to list the entries as usual.', 'threadi-now-pages' )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Field on the "Edit category" screen.
	 *
	 * @param WP_Term $term The term being edited.
	 *
	 * @return void
	 */
	public function render_edit_fields( WP_Term $term ): void {
		// get the values.
		$value    = (string) get_term_meta( $term->term_id, self::SENTENCE_TEMPLATE_META, true );
		$override = (string) get_term_meta( $term->term_id, self::OVERRIDE_TEXT_META, true );

		// output.
		?>
		<tr class="form-field">
			<th scope="row">
				<label for="threadi_now_pages_sentence_template"><?php esc_html_e( 'Narrative sentence template', 'threadi-now-pages' ); ?></label>
			</th>
			<td>
				<input type="text" name="threadi_now_pages_sentence_template" id="threadi_now_pages_sentence_template" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
						/* translators: %s is the literal placeholder characters. */
							__( 'Used by the template summarizer. Include %s where the entry text should appear. Leave empty for the default (“I did …”).', 'threadi-now-pages' ),
							'%s'
						)
					);
					?>
				</p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row">
				<label for="threadi_now_pages_override_text"><?php esc_html_e( 'Fixed override text', 'threadi-now-pages' ); ?></label>
			</th>
			<td>
				<?php
				wp_editor(
					$override,
					'threadi_now_pages_override_text',
					array(
						'textarea_name' => 'threadi_now_pages_override_text',
						'textarea_rows' => 5,
						'teeny'         => true,
						'media_buttons' => false,
						'quicktags'     => true,
					)
				);
				?>
				<p class="description">
					<?php esc_html_e( 'If set, whenever this category has 1 or more entries in a digest period, this exact text is shown instead of the individual entries. Leave empty to show entries normally.', 'threadi-now-pages' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Persist term meta from the category form.
	 *
	 * @param mixed $term_id Term ID.
	 *
	 * @return void
	 */
	public function save_term_meta( mixed $term_id ): void {
		$term_id = absint( $term_id );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core taxonomy form nonce.
		if ( isset( $_POST['threadi_now_pages_sentence_template'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw = wp_unslash( (string) $_POST['threadi_now_pages_sentence_template'] );
			$raw = $this->sanitize_sentence_template( $raw );

			if ( '' === $raw ) {
				delete_term_meta( $term_id, self::SENTENCE_TEMPLATE_META );
			} else {
				// the value is already unslashed, update_term_meta() would unslash it again.
				update_term_meta( $term_id, self::SENTENCE_TEMPLATE_META, wp_slash( $raw ) );
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core taxonomy form nonce.
		if ( isset( $_POST['threadi_now_pages_override_text'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$override = wp_kses_post( wp_unslash( (string) $_POST['threadi_now_pages_override_text'] ) );

			// save the field value, if set.
			if ( '' === trim( wp_strip_all_tags( $override ) ) ) {
				delete_term_meta( $term_id, self::OVERRIDE_TEXT_META );
			} else {
				// the value is already unslashed, update_term_meta() would unslash it again.
				update_term_meta( $term_id, self::OVERRIDE_TEXT_META, wp_slash( $override ) );
			}
		}
	}

	/**
	 * Assign a category term to an entry post.
	 *
	 * @param int $post_id Entry post ID.
	 * @param int $term_id Category term ID (0 = do nothing).
	 *
	 * @return void
	 */
	public function assign_to_entry( int $post_id, int $term_id ): void {
		if ( $post_id <= 0 || $term_id <= 0 ) {
			return;
		}

		$result = wp_set_object_terms( $post_id, array( $term_id ), self::TAXONOMY );

		if ( is_wp_error( $result ) ) {
			/**
			 * Fires when assigning a category to an entry failed.
			 *
			 * @param WP_Error $result  The error.
			 * @param int      $post_id Entry post ID.
			 * @param int      $term_id Requested term ID.
			 */
			do_action( 'threadi_now_pages_category_assign_error', $result, $post_id, $term_id );
		}
	}

	/**
	 * Let the entries a source has already imported follow the category of this source.
	 *
	 * Called when the category of a source changed. A new category is given
	 * to every entry of the source, whatever category it had before. If the
	 * source has no category anymore, only the category it gave is taken
	 * back: an entry which was moved somewhere else keeps its place.
	 *
	 * @param int $source_id The source post ID.
	 * @param int $term_id   The category of the source now (0 = none).
	 * @param int $previous  The category of the source before (0 = none).
	 *
	 * @return int The number of entries which were changed.
	 */
	public function assign_to_source_entries( int $source_id, int $term_id, int $previous = 0 ): int {
		// bail if nothing changed.
		if ( $source_id <= 0 || $term_id === $previous ) {
			return 0;
		}

		// bail if the new category does not exist.
		if ( $term_id > 0 && ! term_exists( $term_id, self::TAXONOMY ) ) {
			return 0;
		}

		// get every entry of this source, including those in the trash: they would come back with the old category.
		$query = new WP_Query(
			array(
				'post_type'              => Entries::get_instance()->get_slug(),
				'post_status'            => array( 'any', 'trash' ),
				'post_parent'            => $source_id,
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- Query must be independent of language filters.
				'suppress_filters'       => true,
			)
		);

		// collect the IDs of the entries.
		$entry_ids = array();
		foreach ( (array) $query->posts as $entry_id ) {
			if ( is_int( $entry_id ) ) {
				$entry_ids[] = $entry_id;
			}
		}

		// bail if this source has no entries.
		if ( empty( $entry_ids ) ) {
			return 0;
		}

		// load the categories these entries have now, with one query.
		update_object_term_cache( $entry_ids, Entries::get_instance()->get_slug() );

		$assigned = array();
		foreach ( $entry_ids as $entry_id ) {
			$terms                 = get_the_terms( $entry_id, self::TAXONOMY );
			$assigned[ $entry_id ] = is_array( $terms ) ? array_values( wp_list_pluck( $terms, 'term_id' ) ) : array();
		}

		$changed = 0;

		// count the terms once at the end instead of after each entry.
		wp_defer_term_counting( true );

		foreach ( $assigned as $entry_id => $term_ids ) {
			if ( $term_id > 0 ) {
				// skip an entry which is already where it belongs.
				if ( array( $term_id ) === $term_ids ) {
					continue;
				}

				$result = wp_set_object_terms( $entry_id, array( $term_id ), self::TAXONOMY );
			} else {
				// skip an entry which does not have the category the source gave.
				if ( ! in_array( $previous, $term_ids, true ) ) {
					continue;
				}

				$result = wp_remove_object_terms( $entry_id, $previous, self::TAXONOMY );
			}

			if ( is_wp_error( $result ) ) {
				/**
				 * This hook is documented in app/NowPages/Categories.php.
				 */
				do_action( 'threadi_now_pages_category_assign_error', $result, $entry_id, $term_id );
				continue;
			}

			++$changed;
		}

		wp_defer_term_counting( false );

		/**
		 * Fires after the entries of a source followed the category of this source.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param int $source_id The source post ID.
		 * @param int $term_id   The category of the source now (0 = none).
		 * @param int $previous  The category of the source before (0 = none).
		 * @param int $changed   The number of entries which were changed.
		 */
		do_action( 'threadi_now_pages_source_category_changed', $source_id, $term_id, $previous, $changed );

		return $changed;
	}

	/**
	 * Return the URL of the screen where the categories are managed.
	 *
	 * @return string Empty if the current user may not manage them.
	 */
	public function get_management_url(): string {
		$taxonomy = get_taxonomy( self::TAXONOMY );

		// bail if the taxonomy is not registered or the user may not manage it.
		if ( ! $taxonomy || ! $taxonomy->show_ui || ! current_user_can( $taxonomy->cap->manage_terms ) ) {
			return '';
		}

		return add_query_arg(
			array(
				'taxonomy'  => self::TAXONOMY,
				'post_type' => Entries::get_instance()->get_slug(),
			),
			admin_url( 'edit-tags.php' )
		);
	}

	/**
	 * Resolve which category term ID to use for a new entry.
	 *
	 * Priority:
	 * 1. Explicit term on the Entry object ($entry->category_term_id)
	 * 2. Default category configured on the Source
	 * 3. 0 (uncategorized)
	 *
	 * @param Entry $entry      The entry DTO.
	 * @param int   $sources_id Source post ID (0 = none).
	 *
	 * @return int Term ID or 0.
	 */
	public function resolve_term_id_for_entry( Entry $entry, int $sources_id ): int {
		// return the already resolved category.
		if ( ! empty( $entry->category_term_id ) && $entry->category_term_id > 0 ) {
			return absint( $entry->category_term_id );
		}

		// check the given "sources_id".
		if ( $sources_id > 0 ) {
			$from_source = absint( get_post_meta( $sources_id, self::SOURCE_DEFAULT_META, true ) );
			if ( $from_source > 0 && term_exists( $from_source, self::TAXONOMY ) ) {
				return $from_source;
			}
		}

		// return 0 for no assigned category.
		return 0;
	}

	/**
	 * Return all category terms (id => name), for settings / selects.
	 *
	 * @return array<int,string>
	 */
	public function get_terms_for_select(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return array();
		}

		$list = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) { // @phpstan-ignore instanceof.alwaysTrue
				continue;
			}
			$list[ $term->term_id ] = $term->name;
		}

		return $list;
	}

	/**
	 * Return category terms assigned to an entry.
	 *
	 * @param WP_Post $entry Entry post.
	 *
	 * @return array<int,WP_Term>
	 */
	public function get_terms_for_entry( WP_Post $entry ): array {
		$terms = get_the_terms( $entry, self::TAXONOMY );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return array();
		}

		$out = array();
		foreach ( $terms as $term ) {
			if ( $term instanceof WP_Term ) { // @phpstan-ignore instanceof.alwaysTrue
				$out[] = $term;
			}
		}

		return $out;
	}

	/**
	 * Resolve the narrative sentence template for an entry.
	 *
	 * Priority:
	 * 1. Term meta `sentence_template` on the first assigned category that has one
	 * 2. Slug map from filter `threadi_now_pages_narrative_category_templates`
	 * 3. Global default “I did %s.”
	 *
	 * @param WP_Post $entry Entry post.
	 *
	 * @return string Template containing %s for the entry text.
	 */
	public function get_sentence_template_for_entry( WP_Post $entry ): string {
		$terms = $this->get_terms_for_entry( $entry );
		$slugs = array();

		foreach ( $terms as $term ) {
			$slugs[]   = $term->slug;
			$from_meta = (string) get_term_meta( $term->term_id, self::SENTENCE_TEMPLATE_META, true );
			if ( '' !== $from_meta ) {
				return $from_meta;
			}
		}

		$map = $this->get_slug_template_map();
		foreach ( $slugs as $slug ) {
			if ( isset( $map[ $slug ] ) && '' !== $map[ $slug ] ) {
				return $map[ $slug ];
			}
		}

		/* translators: %s: the entry's text. */
		return __( 'I did %s.', 'threadi-now-pages' );
	}

	/**
	 * Default / filtered map of category slug → sentence template.
	 *
	 * @return array<string,string>
	 */
	public function get_slug_template_map(): array {
		/**
		 * Filter category-slug → sentence template map (fallback when term meta is empty).
		 *
		 * @param array<string,string> $map slug => template with %s.
		 */
		return apply_filters( 'threadi_now_pages_narrative_category_templates', array() );
	}

	/**
	 * Return the override text for a group of entries.
	 *
	 * All entries of a real category-grouped block share the same terms,
	 * so checking the first entry is representative for the category-level
	 * check. See get_override_text_for_entry() for the single-entry
	 * version used when the output is not grouped by category.
	 *
	 * @param array<int,WP_Post> $entries List of entries.
	 *
	 * @return string|null
	 */
	public function get_override_text_for_group( array $entries ): ?string {
		// bail if no entries are given.
		if ( empty( $entries ) ) {
			return null;
		}

		$override = $this->get_category_override_for_entry( $entries[0] );

		if ( null !== $override ) {
			return $override;
		}

		// get the sources of these entries.
		$source_ids = array_unique(
			array_map(
				static fn ( WP_Post $entry ): int => (int) $entry->post_parent,
				$entries
			)
		);

		// if it is one source ID get their override text.
		if ( 1 === count( $source_ids ) && $source_ids[0] > 0 ) {
			$override = trim( (string) get_post_meta( $source_ids[0], 'override_text', true ) );

			if ( '' !== $override ) {
				return $override;
			}
		}

		// return null if no override text is set.
		return null;
	}

	/**
	 * Return the override text for a single entry.
	 *
	 * Unlike get_override_text_for_group(), this resolves an entry's
	 * override on its own - category-level override first, then the
	 * entry's own source. Grouping the output by category is only a
	 * display choice; it must not be a precondition for an entry's
	 * override text to apply, so this is what the ungrouped render paths
	 * use.
	 *
	 * @param WP_Post $entry The entry.
	 *
	 * @return string|null
	 */
	public function get_override_text_for_entry( WP_Post $entry ): ?string {
		$override = $this->get_category_override_for_entry( $entry );

		if ( null !== $override ) {
			return $override;
		}

		$source_id = (int) $entry->post_parent;

		if ( $source_id > 0 ) {
			$override = trim( (string) get_post_meta( $source_id, 'override_text', true ) );

			if ( '' !== $override ) {
				return $override;
			}
		}

		return null;
	}

	/**
	 * Return the category-level override text for an entry's first
	 * assigned category that has one.
	 *
	 * @param WP_Post $entry The entry.
	 *
	 * @return string|null
	 */
	private function get_category_override_for_entry( WP_Post $entry ): ?string {
		foreach ( $this->get_terms_for_entry( $entry ) as $term ) {
			$override = trim( (string) get_term_meta( $term->term_id, self::OVERRIDE_TEXT_META, true ) );

			if ( '' !== $override ) {
				return $override;
			}
		}

		return null;
	}

	/**
	 * Return the category label for grouping, or a fallback if uncategorized.
	 *
	 * @param WP_Post $entry The entry.
	 *
	 * @return string
	 */
	public function get_category_label( WP_Post $entry ): string {
		// get the category terms of this entry.
		$terms = get_the_terms( $entry, $this->get_slug() );

		// bail with "uncategorized" if no terms are set or error occurred.
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return __( 'Uncategorized', 'threadi-now-pages' );
		}

		// get the names of each term.
		$names = array();
		foreach ( $terms as $term ) {
			if ( $term instanceof WP_Term ) { // @phpstan-ignore instanceof.alwaysTrue
				$names[ $term->name ] = $term->name;
			}
		}

		// bail if no names could be found.
		if ( empty( $names ) ) { // @phpstan-ignore empty.variable
			return __( 'Uncategorized', 'threadi-now-pages' );
		}

		// sort them.
		$names = Format::get_nat_sorted_array( $names );

		/* translators: used between list items, there is a space after the comma. */
		$separator = __( ', ', 'threadi-now-pages' );

		// implode this list.
		return implode( $separator, $names );
	}

	/**
	 * Enqueue additional helper for the new-term-form.
	 *
	 * @param string $hook The used hook.
	 *
	 * @return void
	 */
	public function enqueue_admin_scripts( string $hook ): void {
		// bail if this is not the required hook.
		if ( 'edit-tags.php' !== $hook ) {
			return;
		}

		// bail if this is not our taxonomy.
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . $this->get_slug() !== $screen->id ) {
			return;
		}

		// add helper script.
		wp_add_inline_script(
			'jquery',
			"
        jQuery(document).ready(function($) {
            $('#submit').on('mousedown', function() {
                if (typeof tinyMCE !== 'undefined') {
                    tinyMCE.triggerSave();
                }
            });

            $(document).ajaxSuccess(function(event, xhr, settings) {
                if (settings.data && settings.data.indexOf('action=add-tag') !== -1) {
                    if (typeof tinyMCE !== 'undefined' && tinyMCE.get('threadi_now_pages_override_text')) {
                        tinyMCE.get('threadi_now_pages_override_text').setContent('');
                    }
                    $('#threadi_now_pages_override_text').val('');
                }
            });
        });
        "
		);
	}
}
