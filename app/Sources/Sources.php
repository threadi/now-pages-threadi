<?php
/**
 * This file contains the main initialization for sources.
 *
 * Definition:
 * Sources are the way we manage the usage of providers in backend.
 * We register our own cpt for it and manage their configuration.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Sources;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Dependencies\easyTransientsForWordPress\Transients;
use NowPagesThreadi\Fediverse\Fediverse;
use NowPagesThreadi\Helper\Format;
use NowPagesThreadi\Helper\Languages;
use NowPagesThreadi\Helper\Plugin;
use NowPagesThreadi\NowPages\Categories;
use NowPagesThreadi\NowPages\Content_Filters;
use NowPagesThreadi\NowPages\Entries;
use NowPagesThreadi\NowPages\Entry;
use NowPagesThreadi\NowPages\Frontend;
use NowPagesThreadi\Plugin\Crypt;
use NowPagesThreadi\Plugin\Log;
use NowPagesThreadi\Plugin\Post_Type_Base;
use NowPagesThreadi\Plugin\ProcessHandler;
use NowPagesThreadi\Plugin\Setup;
use NowPagesThreadi\Providers\Provider_Context;
use NowPagesThreadi\Providers\Provider_Interface;
use NowPagesThreadi\Providers\Providers;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * Object to initialize the source.
 */
class Sources extends Post_Type_Base {
	/**
	 * Name of the option used as lock against parallel imports.
	 */
	public const IMPORT_LOCK_OPTION = 'now_pages_threadi_import_lock';

	/**
	 * Seconds after which an import lock is treated as left over from a crashed run.
	 */
	private const IMPORT_LOCK_TIMEOUT = 15 * MINUTE_IN_SECONDS;

	/**
	 * Default time budget in seconds for one import run (not on CLI).
	 */
	private const IMPORT_TIME_BUDGET = 5 * MINUTE_IN_SECONDS;

	/**
	 * How many sources with the same identity are compared when looking for a duplicate.
	 */
	private const MAX_DUPLICATE_CANDIDATES = 50;

	/**
	 * The token of the import lock held by this request.
	 *
	 * @var string
	 */
	private string $import_lock_token = '';

	/**
	 * The slug.
	 *
	 * @var string
	 */
	protected string $slug = 'np_threadi_source';

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		parent::init();

		// use hooks.
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ), 10, 2 );
		add_action( 'save_post_' . $this->get_slug(), array( $this, 'save_post' ), 10, 3 );
		add_action( 'add_meta_boxes', array( $this, 'move_author_box' ), PHP_INT_MAX );
		add_action( 'load-post-new.php', array( $this, 'redirect_to_setup' ) );
		add_filter( 'post_row_actions', array( $this, 'add_row_actions' ), 10, 2 );

		// add some columns to the sources list-table.
		add_filter( 'manage_' . $this->get_slug() . '_posts_columns', array( $this, 'add_columns' ) );
		add_action( 'manage_' . $this->get_slug() . '_posts_custom_column', array( $this, 'render_columns' ), 10, 2 );
		add_filter( 'manage_edit-' . $this->get_slug() . '_sortable_columns', array( $this, 'add_sortable_provider_column' ) );
		add_action( 'pre_get_posts', array( $this, 'sort_by_provider_column' ) );
	}

	/**
	 * Return the labels.
	 *
	 * @return array<string,string>
	 */
	protected function get_labels(): array {
		return array(
			'name'                  => __( 'Now Page Sources', 'now-pages-threadi' ),
			'singular_name'         => __( 'Now Page Source', 'now-pages-threadi' ),
			'menu_name'             => __( 'Now Page Sources', 'now-pages-threadi' ),
			'parent_item_colon'     => __( 'Parent Now Page Source', 'now-pages-threadi' ),
			'all_items'             => __( 'All Now Page Sources', 'now-pages-threadi' ),
			'view_item'             => __( 'View Now Page Source in frontend', 'now-pages-threadi' ),
			'view_items'            => __( 'View Now Page Sources', 'now-pages-threadi' ),
			'edit_item'             => __( 'View Now Page Source', 'now-pages-threadi' ),
			'search_items'          => __( 'Search Now Page Source', 'now-pages-threadi' ),
			'not_found'             => __( 'No Now Page sources found', 'now-pages-threadi' ),
			'item_link'             => _x( 'Now Page Source Link', 'navigation link block title', 'now-pages-threadi' ),
			'item_link_description' => _x( 'A link to a Now Page Source.', 'navigation link block description', 'now-pages-threadi' ),
			'add_new_item'          => __( 'Add New Now Page Source', 'now-pages-threadi' ),
		);
	}

	/**
	 * Register this custom post-type.
	 *
	 * @return void
	 */
	public function register(): void {
		// run the parent function.
		parent::register();

		// register the provider field.
		register_meta(
			'post',
			'provider',
			array(
				'object_subtype' => $this->get_slug(),
				'type'           => 'string',
				'single'         => true,
				'show_in_rest'   => true,
			)
		);

		register_meta(
			'post',
			'language',
			array(
				'object_subtype' => $this->get_slug(),
				'type'           => 'string',
				'single'         => true,
				'show_in_rest'   => true,
			)
		);

		register_meta(
			'post',
			Categories::SOURCE_DEFAULT_META,
			array(
				'object_subtype' => $this->get_slug(),
				'type'           => 'integer',
				'single'         => true,
				'show_in_rest'   => true,
			)
		);

		register_meta(
			'post',
			'override_text',
			array(
				'object_subtype' => $this->get_slug(),
				'type'           => 'string',
				'single'         => true,
				'show_in_rest'   => true,
			)
		);
	}

	/**
	 * Add boxes to the edit-page of our own cpt.
	 *
	 * @param string $post_type The requested post-type.
	 * @param mixed  $post The post-object.
	 *
	 * @return void
	 * @noinspection PhpUnusedParameterInspection
	 */
	public function add_meta_boxes( string $post_type, mixed $post ): void {
		// Bail if the second parameter is not "WP_Post".
		// This is necessary as backward compatibility because of a bug on WooCommerce: https://github.com/woocommerce/woocommerce/issues/61909.
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		// bail if this is not our cpt.
		if ( get_post_type( $post->ID ) !== $this->get_slug() ) {
			return;
		}

		// add a meta-box to choose the provider.
		add_meta_box(
			$this->get_slug() . '-provider',
			__( 'Provider', 'now-pages-threadi' ),
			array( $this, 'show_provider_selection' ),
			$this->get_slug()
		);

		// add a meta-box to choose the provider.
		add_meta_box(
			$this->get_slug() . '-provider-settings',
			__( 'Settings', 'now-pages-threadi' ),
			array( $this, 'show_provider_settings' ),
			$this->get_slug()
		);

		add_meta_box(
			$this->get_slug() . '-provider-additional',
			__( 'Additional settings', 'now-pages-threadi' ),
			array( $this, 'show_provider_additional_settings' ),
			$this->get_slug()
		);
	}

	/**
	 * Show a selection to choose the provider for this source.
	 *
	 * @param WP_Post $post The post object.
	 *
	 * @return void
	 */
	public function show_provider_selection( WP_Post $post ): void {
		// get the list of supported providers.
		$providers = Providers::get_instance()->get_enabled_providers();

		// get the actual provider.
		$actual_provider = (string) get_post_meta( $post->ID, 'provider', true );

		// Keep the currently configured provider visible, even if it has
		// subsequently been disabled by the administrator.
		if ( '' !== $actual_provider && ! isset( $providers[ $actual_provider ] ) ) {
			$provider = Providers::get_instance()->get_provider( $actual_provider );

			if ( $provider instanceof Provider_Interface ) {
				$providers[ $actual_provider ] = $provider;
			}
		}

		// bail if list is empty.
		if ( empty( $providers ) ) {
			echo '<p>' . esc_html__( 'No providers are enabled.', 'now-pages-threadi' ) . '</p>';
			return;
		}

		// show them.
		?>
		<input type="hidden" name="now_pages_threadi_source_form" value="1">
		<fieldset>
			<label for="now-pages-threadi-provider"><?php echo esc_html__( 'Choose a provider', 'now-pages-threadi' ); ?></label>
			<select id="now-pages-threadi-provider" name="now-pages-threadi-provider">
				<option value=""><?php echo esc_html__( 'Please choose', 'now-pages-threadi' ); ?></option>
				<?php
				foreach ( $providers as $provider ) {
					$label = $provider->get_label();
					if ( ! $provider->is_enabled() ) {
						$label .= ' ' . __( '(disabled)', 'now-pages-threadi' );
					}

					echo '<option value="' . esc_attr( $provider->get_slug() ) . '"' . ( $actual_provider === $provider->get_slug() ? ' selected="selected"' : '' ) . '>' . esc_html( $label ) . '</option>';
				}

				if ( '' !== $actual_provider && isset( $providers[ $actual_provider ] ) && ! $providers[ $actual_provider ]->is_enabled() ) {
					?>
					<p class="notice notice-warning inline">
						<?php
						echo esc_html__( 'This provider has been disabled. Its configuration is preserved, but it will not be executed.', 'now-pages-threadi' );
						?>
					</p>
					<?php
				}
				?>
			</select>
			<p>
				<?php
				/* translators: %1$s will be replaced by a URL */
				echo wp_kses_post( sprintf( __( 'Choose how you want to get the data for the entries on your Now Page. If you do not see a provider listed here, feel free <a href="%1$s" target="_blank">to post in our forum%2$s</a>. ', 'now-pages-threadi' ), Plugin::get_plugin_support_url(), Plugin::get_a11n_window_hint() ) );
				?>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Show a selection to choose the provider for this source.
	 *
	 * @param WP_Post $post The post object.
	 *
	 * @return void
	 */
	public function show_provider_additional_settings( WP_Post $post ): void {
		// get the list of supported providers.
		$providers = Providers::get_instance()->get_providers();

		// bail if list is empty.
		if ( empty( $providers ) ) {
			echo '<p>' . esc_html__( 'No providers found.', 'now-pages-threadi' ) . '</p>';
			return;
		}

		// read the actual setting.
		$actual_provider = get_post_meta( $post->ID, 'provider', true );
		$actual_language = Languages::normalize_locale( (string) get_post_meta( $post->ID, 'language', true ) );
		if ( empty( $actual_language ) ) {
			$actual_language = Languages::get_current_language();
		}

		// get the categories.
		$actual_category = absint( get_post_meta( $post->ID, Categories::SOURCE_DEFAULT_META, true ) );
		$categories      = Categories::get_instance()->get_terms_for_select();

		// show them.
		?>
		<fieldset>
			<label for="now-pages-threadi-default-category"><?php echo esc_html__( 'Default category for imported entries', 'now-pages-threadi' ); ?></label>
			<select id="now-pages-threadi-default-category" name="now-pages-threadi-default-category"<?php echo ( empty( $actual_provider ) ? ' disabled="disabled"' : '' ); ?>>
				<option value="0"><?php echo esc_html__( '- none -', 'now-pages-threadi' ); ?></option>
				<?php
				foreach ( $categories as $term_id => $term_name ) {
					printf(
						'<option value="%1$d"%2$s>%3$s</option>',
						absint( $term_id ),
						selected( $actual_category, (int) $term_id, false ),
						esc_html( $term_name )
					);
				}
				?>
			</select>
			<p>
				<?php
				echo esc_html__( 'If you change the category, the entries this source has already imported are moved to it as well.', 'now-pages-threadi' );

				// link to the place where the categories are managed, if the user may do that.
				$categories_url = Categories::get_instance()->get_management_url();
				if ( '' !== $categories_url ) {
					echo ' ' . wp_kses_post( '<a href="' . esc_url( $categories_url ) . '" target="_blank">' . esc_html__( 'Manage the categories', 'now-pages-threadi' ) . Plugin::get_a11n_window_hint() . '</a>' );
				}
				?>
			</p>
		</fieldset>
		<fieldset>
			<label for="now-pages-threadi-language"><?php echo esc_html__( 'Language of this source', 'now-pages-threadi' ); ?></label>
			<select id="now-pages-threadi-language" name="now-pages-threadi-language"<?php echo ( empty( $actual_provider ) ? ' disabled="disabled"' : '' ); ?>>
				<?php
				// add the WP-default language to the list, each language in the normalized form only once.
				$languages = array_unique( array_filter( array_map( array( Languages::class, 'normalize_locale' ), array_merge( array( 'en_US' ), get_available_languages() ) ) ) );
				foreach ( $languages as $language ) {
					?>
					<option value="<?php echo esc_attr( $language ); ?>"<?php echo ( $actual_language === $language ? ' selected="selected"' : '' ); ?>><?php echo esc_html( Languages::get_display_name( $language ) ); ?></option>
					<?php
				}
				?>
			</select>
		</fieldset>
		<?php
		// let the user decide whether the entries of this source are shared in the Fediverse one by one.
		if ( $actual_provider && Fediverse::MODE_ENTRIES === Fediverse::get_instance()->get_mode() && Fediverse::get_instance()->is_enabled() ) {
			?>
			<fieldset>
				<label for="now-pages-threadi-fediverse-share"><?php echo esc_html__( 'Share in the Fediverse', 'now-pages-threadi' ); ?></label>
				<input type="hidden" name="now-pages-threadi-fediverse-share" value="0">
				<input type="checkbox" id="now-pages-threadi-fediverse-share" name="now-pages-threadi-fediverse-share" value="1"<?php checked( Fediverse::get_instance()->is_source_shared( $post->ID ) ); ?>>
				<p><?php echo esc_html__( 'If checked, each entry of this source is shared as a post of its own. Uncheck it for sources with many entries, or with entries your followers already get elsewhere.', 'now-pages-threadi' ); ?></p>
			</fieldset>
			<?php
		}

		if ( $actual_provider ) {
			?>
			<p>
				<label for="now-pages-threadi-override-text"><?php esc_html_e( 'Fixed override text', 'now-pages-threadi' ); ?></label>
				<?php
				wp_editor(
					(string) get_post_meta( $post->ID, 'override_text', true ),
					'now-pages-threadi-override-text',
					array(
						'textarea_name' => 'now-pages-threadi-override-text',
						'textarea_rows' => 4,
						'media_buttons' => false,
						'tinymce'       => true,
						'quicktags'     => true,
					)
				);
				?>
				<br>
				<span class="description"><?php echo esc_html__( 'If set, whenever this source has 1 or more entries in a digest period AND no category-level override applies, this exact text is shown instead of the individual entries.', 'now-pages-threadi' ); ?></span>
			</p>
			<p>
				<label for="now-pages-threadi-content-filter-rules"><?php echo esc_html__( 'Additional content filter rules', 'now-pages-threadi' ); ?></label>
				<textarea id="now-pages-threadi-content-filter-rules" name="now-pages-threadi-content-filter-rules" rows="4" class="widefat"><?php echo esc_textarea( (string) get_post_meta( $post->ID, Content_Filters::SOURCE_META, true ) ); ?></textarea>
				<br>
				<span class="description">
					<?php
					echo esc_html__( 'One rule per line, syntax: action:field:operator:value (e.g. include:author:equals:Jane Doe). These are added to the site-wide list, they do not replace it. Operators: equals, not_equals, contains, not_contains, word (the value as a whole word), regex. To split one feed into several sources, add it once per part and let each keep its part, e.g. include:url:contains:/support/topic/.', 'now-pages-threadi' );
					?>
				</span>
				<?php
				$provider_obj      = Providers::get_instance()->get_provider( (string) $actual_provider );
				$filterable_fields = $provider_obj instanceof Provider_Interface ? $provider_obj->get_filterable_fields() : array();
				?>
				<br>
				<span class="description">
					<?php
					if ( empty( $filterable_fields ) ) {
						echo esc_html__( 'Available fields for this provider: text, url, source_slug.', 'now-pages-threadi' );
					} else {
						printf(
						/* translators: %s will be replaced by a comma-separated list of field names. */
							esc_html__( 'Available fields for this provider: text, url, source_slug, %s.', 'now-pages-threadi' ),
							esc_html( implode( ', ', array_keys( $filterable_fields ) ) )
						);
					}
					?>
				</span>
			</p>
			<?php
		}
	}

	/**
	 * Save the custom configuration for a single post.
	 *
	 * @param mixed   $post_id The given post ID.
	 * @param WP_Post $post The post object.
	 * @param mixed   $update Whether this is an update.
	 *
	 * @return void
	 * @noinspection PhpUnusedParameterInspection
	 */
	public function save_post( mixed $post_id, WP_Post $post, mixed $update ): void {
		$post_id = absint( $post_id );

		// bail on autosave and revisions.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// bail if our own metabox was not submitted.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core verifies update-post_{ID} in edit_post() before save_post fires.
		if ( ! isset( $_POST['now_pages_threadi_source_form'] ) ) {
			return;
		}

		// bail if the current user may not edit this source.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$update = (bool) $update;

		// bail if this is not an update.
		if ( ! $update ) {
			return;
		}

		// bail if post_status is trash.
		if ( 'trash' === $post->post_status ) {
			return;
		}

		// get and save the provider (only if it is valid and enabled).
		$provider         = sanitize_key( $this->get_string_from_request( 'now-pages-threadi-provider' ) );
		$current_provider = (string) get_post_meta( $post_id, 'provider', true );
		$provider_obj     = Providers::get_instance()->get_provider( $provider );
		$provider_chosen  = false;
		if ( $provider_obj instanceof Provider_Interface && ( $provider_obj->is_enabled() || $provider === $current_provider ) ) {
			update_post_meta( $post_id, 'provider', $provider );

			// remember whether the provider has just been chosen or changed.
			$provider_chosen = $provider !== $current_provider;
		}

		// get and save the given language.
		$language = Languages::normalize_locale( sanitize_text_field( $this->get_string_from_request( 'now-pages-threadi-language' ) ) );
		update_post_meta( $post_id, 'language', $language );

		// get and save the given default category.
		$previous_category = absint( get_post_meta( $post_id, Categories::SOURCE_DEFAULT_META, true ) );
		$default_category  = absint( $this->get_string_from_request( 'now-pages-threadi-default-category' ) );
		update_post_meta( $post_id, Categories::SOURCE_DEFAULT_META, $default_category );

		// the entries this source has already imported follow its category. Only if the
		// field was sent: it is disabled as long as no provider is chosen.
		if ( null !== $this->get_from_request( 'now-pages-threadi-default-category' ) ) {
			$this->report_moved_entries(
				Categories::get_instance()->assign_to_source_entries( $post_id, $default_category, $previous_category ),
				$default_category,
				$previous_category
			);
		}

		// get the fixed override text.
		$override_text = wp_kses_post( $this->get_string_from_request( 'now-pages-threadi-override-text' ) );

		// an override of nothing but markup would replace a whole group with
		// an empty paragraph, so store it as empty.
		if ( '' === trim( wp_strip_all_tags( $override_text ) ) ) {
			$override_text = '';
		}

		// the value is already unslashed, update_post_meta() would unslash it again.
		update_post_meta( $post_id, 'override_text', wp_slash( $override_text ) );

		// get and save the additional content filter rules.
		$content_filter_rules = Content_Filters::sanitize_rules( $this->get_string_from_request( 'now-pages-threadi-content-filter-rules' ) );

		// the value is already unslashed, update_post_meta() would unslash it again and remove the backslashes of a regex.
		update_post_meta( $post_id, Content_Filters::SOURCE_META, wp_slash( $content_filter_rules ) );

		// tell the user if one of these rules does not work: an invalid line is ignored without a word otherwise.
		Content_Filters::get_instance()->report( $content_filter_rules, Providers::get_instance()->get_provider( $provider ) );

		// save whether the entries of this source are shared in the Fediverse, if this was asked.
		$fediverse_share = $this->get_from_request( 'now-pages-threadi-fediverse-share' );
		if ( null !== $fediverse_share ) {
			update_post_meta( $post_id, Fediverse::META_SOURCE_EXCLUDED, '1' === $this->get_string_from_request( 'now-pages-threadi-fediverse-share' ) ? 0 : 1 );
		}

		// get the provider object.
		$provider_obj = Providers::get_instance()->get_provider( $provider );

		// bail if provider could not be loaded.
		if ( ! $provider_obj instanceof Provider_Interface ) {
			return;
		}

		// the fields of a provider are only part of the form if they were rendered for this provider.
		// a missing value does not mean empty then: the form is sent when a provider is chosen.
		if ( sanitize_key( $this->get_string_from_request( 'now-pages-threadi-provider-fields' ) ) !== $provider ) {
			// set the defaults of the fields of a provider which has just been chosen.
			if ( $provider_chosen ) {
				$this->save_default_config( $provider_obj, $post_id );
			}

			// do nothing more.
			return;
		}

		// collect the submitted values of the identifying fields, to check
		// for a duplicate after they have been saved.
		$identity = array();

		// loop through the fields of this provider and save their settings.
		foreach ( $provider_obj->get_config_fields() as $name => $field ) {
			// bail if the field definition is unusable.
			if ( ! is_array( $field ) || empty( $field['type'] ) || empty( $field['label'] ) ) {
				continue;
			}

			// get the field object for the given type.
			$field_obj = Fields::get_instance()->get_field_by_slug( (string) $field['type'] );

			// bail if object could not be loaded.
			if ( ! $field_obj instanceof Field_Base ) {
				continue;
			}

			// hand the field its settings, some types need them to validate.
			$field_obj->set_settings( $field );

			// get the raw value. a field with name="…[]" arrives as array.
			$raw = $this->get_from_request( (string) $name );

			// a secret is stored encrypted, and an empty input keeps the old value.
			if ( $field_obj->is_secret() ) {
				// remove the stored value if the user asked for it.
				if ( '1' === $this->get_string_from_request( (string) $name . '_clear' ) ) {
					delete_post_meta( $post_id, (string) $name );
					continue;
				}

				$secret = sanitize_text_field( $this->get_string_from_request( (string) $name ) );

				// bail on an empty value.
				if ( '' === $secret ) {
					continue;
				}

				// encrypt the value.
				$encrypted = Crypt::get_instance()->encrypt( $secret );

				// keep the stored value if the encryption failed.
				if ( '' === $encrypted ) {
					Log::get_instance()->add(
						sprintf(
						/* translators: %1$s will be replaced by a field name. */
							__( 'The value for the field %1$s could not be encrypted, the previous value has been kept.', 'now-pages-threadi' ),
							'<code>' . esc_html( (string) $name ) . '</code>'
						),
						'error',
						'system'
					);
					continue;
				}

				// save the value encrypted.
				update_post_meta( $post_id, $name, $encrypted );

				// do nothing more.
				continue;
			}

			// let the field decide how its value is cleaned up.
			$value = $field_obj->sanitize( $raw );

			// remember it if this field identifies the source.
			if ( in_array( (string) $name, $provider_obj->get_identifying_fields(), true ) ) {
				$identity[ $name ] = $value;
			}

			// let the field decide how its value is cleaned up.
			update_post_meta( $post_id, $name, $value );
		}

		// warn if another source with the same configuration exists. this is
		// deliberately not blocked: two nearly identical sources can be
		// intentional, for example with different categories.
		$this->check_for_duplicate( $provider_obj, $identity, $post_id );
	}

	/**
	 * Save the defaults of the fields of a provider for a source.
	 *
	 * Used when a provider has just been chosen: its fields were not part of
	 * the form, so there are no values to save. Secrets are not touched.
	 *
	 * @param Provider_Interface $provider_obj The provider.
	 * @param int                $post_id The ID of the source.
	 *
	 * @return void
	 */
	private function save_default_config( Provider_Interface $provider_obj, int $post_id ): void {
		foreach ( $provider_obj->get_config_fields() as $name => $field ) {
			// bail if the field definition is unusable.
			if ( ! is_array( $field ) || empty( $field['type'] ) || empty( $field['label'] ) ) {
				continue;
			}

			// get the field object for the given type.
			$field_obj = Fields::get_instance()->get_field_by_slug( (string) $field['type'] );

			// bail if object could not be loaded or is a secret.
			if ( ! $field_obj instanceof Field_Base || $field_obj->is_secret() ) {
				continue;
			}

			// hand the field its settings, some types need them to validate.
			$field_obj->set_settings( $field );

			// get the default from a callback.
			if ( ! empty( $field['default_callback'] ) && is_callable( $field['default_callback'] ) ) {
				update_post_meta( $post_id, (string) $name, $field_obj->sanitize( call_user_func( $field['default_callback'], $post_id ) ) );
				continue;
			}

			// remove a value of another provider if this field has no default.
			if ( ! array_key_exists( 'default', $field ) ) {
				delete_post_meta( $post_id, (string) $name );
				continue;
			}

			// save the default.
			update_post_meta( $post_id, (string) $name, $field_obj->sanitize( $field['default'] ) );
		}
	}

	/**
	 * Show the provider-specific settings.
	 *
	 * @param WP_Post $post The post ID.
	 *
	 * @return void
	 */
	public function show_provider_settings( WP_Post $post ): void {
		// get provider slug.
		$provider_slug = get_post_meta( $post->ID, 'provider', true );

		// bail if no slug is given.
		if ( empty( $provider_slug ) ) {
			echo '<p>' . esc_html__( 'Please choose a provider first.', 'now-pages-threadi' ) . '</p>';
			return;
		}

		// get the provider object.
		$provider_obj = Providers::get_instance()->get_provider( $provider_slug );

		// bail if provider could not be loaded.
		if ( ! $provider_obj instanceof Provider_Interface ) {
			echo '<p>' . esc_html__( 'The configured provider could not be found.', 'now-pages-threadi' ) . '</p>';
			return;
		}

		// mark for which provider the fields are part of the form.
		echo '<input type="hidden" name="now-pages-threadi-provider-fields" value="' . esc_attr( $provider_obj->get_slug() ) . '">';

		// extend the kses filter.
		add_filter( 'wp_kses_allowed_html', array( $this, 'add_kses_html' ), 10, 2 );

		// output the fields for this provider.
		foreach ( $provider_obj->get_config_fields() as $name => $field ) {
			// bail if type or label is missing.
			if ( empty( $field['type'] ) || empty( $field['label'] ) ) {
				continue;
			}

			// get the field object for the given type.
			$field_obj = Fields::get_instance()->get_field_by_slug( $field['type'] );

			// bail if object could not be loaded.
			if ( ! $field_obj instanceof Field_Base ) {
				continue;
			}

			// get the value for this field on this source.
			$value = get_post_meta( $post->ID, $name, true );

			// a field which has never been saved may get its default from a callback.
			if ( ! empty( $field['default_callback'] ) && is_callable( $field['default_callback'] ) && ! metadata_exists( 'post', $post->ID, $name ) ) {
				$value = call_user_func( $field['default_callback'], $post->ID );
			}

			// set entities.
			$field_obj->set_name( $name );
			$field_obj->set_label( $field['label'] );
			$field_obj->set_description( ! empty( $field['description'] ) ? $field['description'] : '' );
			$field_obj->set_required( ! empty( $field['required'] ) ? $field['required'] : false );
			// use the default only for a field which has never been saved: a saved 0 or an empty value is what the user chose.
			$field_obj->set_value( empty( $value ) && ! empty( $field['default'] ) && ! metadata_exists( 'post', $post->ID, $name ) ? $field['default'] : $value );
			$field_obj->set_placeholder( ! empty( $field['placeholder'] ) ? $field['placeholder'] : '' );
			$field_obj->set_settings( $field );

			// output the HTML-code for this field.
			$field_obj->get();
		}

		// remove the kses filter.
		remove_filter( 'wp_kses_allowed_html', array( $this, 'add_kses_html' ) );
	}

	/**
	 * Extend kses-filter if our own cpt is called.
	 *
	 * @param array<string,mixed> $allowed_tags List of allowed tags and attributes.
	 * @param string              $context The context where this is called.
	 *
	 * @return array<string,mixed>
	 */
	public function add_kses_html( array $allowed_tags, string $context ): array {
		// bail if context is not "post".
		if ( 'post' !== $context ) {
			return $allowed_tags;
		}

		// add the necessary fields for the filter, if not already set.
		if ( empty( $allowed_tags['form'] ) ) {
			$allowed_tags['form'] = array(
				'action' => true,
				'method' => true,
				'class'  => true,
				'id'     => true,
			);
		}
		if ( empty( $allowed_tags['select'] ) ) {
			$select = array();

			if ( isset( $allowed_tags['select'] ) && is_array( $allowed_tags['select'] ) ) {
				$select = $allowed_tags['select'];
			}

			$allowed_tags['select'] = array_merge(
				$select,
				array(
					'class'    => true,
					'id'       => true,
					'name'     => true,
					'required' => true,
					'multiple' => true,
					'size'     => true,
				)
			);
		}
		if ( empty( $allowed_tags['option'] ) ) {
			$allowed_tags['option'] = array(
				'class'    => true,
				'id'       => true,
				'selected' => true,
				'value'    => true,
			);
		}
		if ( empty( $allowed_tags['input'] ) ) {
			$allowed_tags['input'] = array(
				'class'       => true,
				'id'          => true,
				'name'        => true,
				'type'        => true,
				'value'       => true,
				'placeholder' => true,
				'min'         => true,
				'max'         => true,
				'step'        => true,
				'data-*'      => true,
				'required'    => true,
				'checked'     => true,
			);
		}

		// return the list of allowed tags.
		return $allowed_tags;
	}

	/**
	 * Return the resolved provider + configuration for every published source.
	 *
	 * Centralizes what Cli::request_sources() and Scheduler::run() both need,
	 * so pull-based add stays consistent regardless of who triggers it -
	 * and so the source_id always travels along in the exact same shape.
	 *
	 * @param bool $report_errors Whether to report sources whose provider does not exist. Only the import does
	 *                            this, otherwise every caller would log the same error again.
	 *
	 * @return array<int,array{source_id:int,provider:Provider_Interface,config:array<string,mixed>,title:string}>
	 */
	public function get_configured_sources( bool $report_errors = false ): array {
		$query   = array(
			'post_type'      => $this->get_slug(),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);
		$results = new WP_Query( $query );

		$sources = array();

		foreach ( $results->get_posts() as $post_id ) {
			// bail on wrong object type.
			if ( $post_id instanceof WP_Post ) {
				continue;
			}

			// get the configured provider.
			$provider_slug = (string) get_post_meta( $post_id, 'provider', true );

			// bail without a word if the source is not configured yet. this is
			// a normal state for a freshly created source, not an error.
			if ( '' === $provider_slug ) {
				continue;
			}

			// get the provider.
			$provider_obj = Providers::get_instance()->get_provider( $provider_slug );

			// bail if the configured provider does not exist. this one is a
			// real problem: the source will never deliver anything.
			if ( ! $provider_obj instanceof Provider_Interface ) {
				// skip the report if the caller does not ask for it.
				if ( ! $report_errors ) {
					continue;
				}

				do_action(
					'now_pages_threadi_provider_error',
					$provider_slug,
					new WP_Error(
						'now_pages_threadi_unknown_provider',
						sprintf(
						/* translators: %1$s will be replaced by a provider slug, %2$s by the title of a source. */
							__( 'The provider %1$s configured on the source %2$s does not exist. It was probably provided by a plugin that is no longer active.', 'now-pages-threadi' ),
							'<code>' . esc_html( $provider_slug ) . '</code>',
							'<a href="' . esc_url( (string) get_edit_post_link( $post_id, '' ) ) . '">' . esc_html( get_the_title( $post_id ) ) . '</a>'
						),
						array(
							'source_id' => $post_id,
							'formatted' => true,
						)
					)
				);

				continue;
			}

			// get the configuration on the source.
			$config = $this->get_source_config( $post_id, $provider_obj );

			// bail if no configuration could be loaded.
			if ( null === $config ) {
				continue;
			}

			$sources[] = array(
				'title'     => get_the_title( $post_id ),
				'source_id' => $post_id,
				'provider'  => $provider_obj,
				'config'    => $config,
			);
		}

		return $sources;
	}

	/**
	 * Return the configuration stored on a source for its provider.
	 *
	 * Secrets are stored encrypted and returned in plain text, the way a
	 * provider needs them.
	 *
	 * @param int                $post_id      The ID of the source.
	 * @param Provider_Interface $provider_obj The provider of the source.
	 *
	 * @return array<string,mixed>|null Null if the source has no meta at all.
	 */
	public function get_source_config( int $post_id, Provider_Interface $provider_obj ): ?array {
		// get the configuration on the source.
		$post_config = get_post_meta( $post_id );

		// bail if no configuration could be loaded.
		if ( empty( $post_config ) || ! is_array( $post_config ) ) {
			return null;
		}

		// get only the configuration for the active provider from this list.
		$config = array();
		foreach ( $provider_obj->get_config_fields() as $slug => $field ) {
			// bail if this slug does not exist in post config.
			if ( ! isset( $post_config[ $slug ] ) ) {
				continue;
			}

			// get the value.
			$value = $post_config[ $slug ][0];

			// get_post_meta() without a key does not unserialize.
			if ( is_string( $value ) && is_serialized( $value ) ) {
				// this configuration holds only scalars and arrays: never unserialize anything which could contain an object.
				if ( 1 === preg_match( '/(?:^|[;{}])[OCE]:\d+:"/', $value ) ) {
					continue;
				}
				$value = maybe_unserialize( $value );
			}

			// secrets are stored encrypted, hand them to the provider in plain text.
			$field_obj = Fields::get_instance()->get_field_by_slug( (string) ( $field['type'] ?? '' ) );

			// get encrypted value.
			if ( is_string( $value ) && '' !== $value && $field_obj instanceof Field_Base && $field_obj->is_secret() ) {
				$value = Crypt::get_instance()->decrypt( $value );
			}

			// add it to the list.
			$config[ $slug ] = $value;
		}

		return $config;
	}

	/**
	 * Add some columns right after the title column.
	 *
	 * @param array<string,string> $columns The existing columns.
	 *
	 * @return array<string,string>
	 */
	public function add_columns( array $columns ): array {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			if ( 'title' === $key ) {
				$new_columns['now_pages_threadi_provider'] = __( 'Provider', 'now-pages-threadi' );
				$new_columns['now_pages_threadi_category'] = __( 'Category', 'now-pages-threadi' );
				$new_columns['now_pages_threadi_language'] = __( 'Language', 'now-pages-threadi' );
				$new_columns['now_pages_threadi_entries']  = __( 'Entries', 'now-pages-threadi' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render our columns: provider, category, language and the number of entries.
	 *
	 * @param string $column  The column being rendered.
	 * @param int    $post_id The current source's post ID.
	 *
	 * @return void
	 */
	public function render_columns( string $column, mixed $post_id ): void {
		// show the provider in the list.
		if ( 'now_pages_threadi_provider' === $column ) {
			// get the configured provider.
			$provider_slug = (string) get_post_meta( absint( $post_id ), 'provider', true );

			// no provider configured yet.
			if ( '' === $provider_slug ) {
				// show warning.
				echo '&#8212;';

				// do nothing more.
				return;
			}

			// get the provider object.
			$provider_obj = Providers::get_instance()->get_provider( $provider_slug );

			// fall back to the raw slug if the provider no longer exists (e.g. after deactivating an extension).
			echo esc_html( $provider_obj instanceof Provider_Interface ? $provider_obj->get_label() : $provider_slug );
		}

		// show the category the entries of this source get.
		if ( 'now_pages_threadi_category' === $column ) {
			$term_id = absint( get_post_meta( absint( $post_id ), Categories::SOURCE_DEFAULT_META, true ) );
			$term    = $term_id > 0 ? get_term( $term_id, Categories::TAXONOMY ) : null;

			// no category chosen, or the category has been deleted since.
			if ( ! $term instanceof WP_Term ) {
				echo '&#8212;';

				// do nothing more.
				return;
			}

			// link to the category, if the user may edit it.
			$edit_url = get_edit_term_link( $term, Categories::TAXONOMY, Entries::get_instance()->get_slug() );

			if ( ! empty( $edit_url ) ) {
				printf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html( $term->name ) );
				return;
			}

			echo esc_html( $term->name );
		}

		// show the language in the list.
		if ( 'now_pages_threadi_language' === $column ) {
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

		// show the number of entries belonging to this source.
		if ( 'now_pages_threadi_entries' === $column ) {
			$source_id     = absint( $post_id );
			$entries_count = $this->count_entries_for_source( $source_id );

			if ( 0 === $entries_count ) {
				echo '&#8212;';
				return;
			}

			// link to the entries list, already filtered by this source.
			$entries_url = add_query_arg(
				array(
					'post_type'   => Entries::get_instance()->get_slug(),
					'post_parent' => $source_id,
				),
				admin_url( 'edit.php' )
			);

			printf( '<a href="%s">%s</a>', esc_url( $entries_url ), esc_html( (string) number_format_i18n( $entries_count ) ) );
		}
	}

	/**
	 * Make the "Provider" column sortable.
	 *
	 * Unlike the parent-based "Source" column on entries, this sorts by
	 * post meta, so it needs pre_get_posts to translate the orderby key
	 * into an actual meta-based ordering.
	 *
	 * @param array<string,string> $columns The existing sortable columns.
	 *
	 * @return array<string,string>
	 */
	public function add_sortable_provider_column( array $columns ): array {
		$columns['now_pages_threadi_provider'] = 'now_pages_threadi_provider';

		return $columns;
	}

	/**
	 * Translate the "Provider" column's orderby key into a meta-based sort.
	 *
	 * @param WP_Query $query The current query.
	 *
	 * @return void
	 */
	public function sort_by_provider_column( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( $this->get_slug() !== $query->get( 'post_type' ) ) {
			return;
		}

		if ( 'now_pages_threadi_provider' !== $query->get( 'orderby' ) ) {
			return;
		}

		$query->set( 'meta_key', 'provider' );
		$query->set( 'orderby', 'meta_value' );
	}

	/**
	 * Return list of WP-functions this post type supports.
	 *
	 * @return string[]
	 */
	protected function get_supports(): array {
		return array( 'title', 'author' );
	}

	/**
	 * Return the menu icon for this cpt.
	 *
	 * @return string
	 */
	protected function get_menu_icon(): string {
		return 'dashicons-admin-site-alt3';
	}

	/**
	 * Import the entries from configured sources.
	 *
	 * @param string $process_id The process ID.
	 *
	 * @return bool False if the import was skipped because another import is running.
	 */
	public function import( string $process_id = '' ): bool {
		// get the process handler and set the process ID.
		$process_handler = ProcessHandler::get_instance();
		$process_handler->set_id( $process_id );

		// bail if another import is running (cron, manual import, CLI, …):
		// parallel runs would create duplicate entries.
		if ( ! $this->acquire_import_lock() ) {
			Log::get_instance()->add( __( 'An import was skipped because another import is still running.', 'now-pages-threadi' ), 'info', 'system' );

			$process_handler->set_message(
				array(
					'detail' => array(
						'title'   => __( 'Import is already running', 'now-pages-threadi' ),
						'texts'   => array(
							'<p>' . esc_html__( 'Another import is running at the moment. Please try again in a few minutes.', 'now-pages-threadi' ) . '</p>',
						),
						'buttons' => array(
							array(
								'action'  => 'closeDialog();',
								'variant' => 'primary',
								'text'    => __( 'OK', 'now-pages-threadi' ),
							),
						),
					),
				)
			);
			$process_handler->set_status( __( 'Import is already running', 'now-pages-threadi' ) );
			$process_handler->set_running( 0 );
			return false;
		}

		try {
			$this->run_import( $process_handler );
		} finally {
			// always release the lock and the running state, even if a provider throws.
			$this->release_import_lock();
			$process_handler->set_running( 0 );
		}

		// return that the import has been run.
		return true;
	}

	/**
	 * Try to get the lock for an import.
	 *
	 * Uses an INSERT IGNORE, which is atomic: only one request can create the option.
	 * The option holds "timestamp|token", the token identifies the run owning
	 * the lock. A lock older than IMPORT_LOCK_TIMEOUT is treated as left over
	 * from a crashed run and replaced. A running import refreshes the
	 * timestamp after each source.
	 *
	 * @return bool True if the lock could be acquired.
	 */
	private function acquire_import_lock(): bool {
		$token = wp_generate_uuid4();

		if ( $this->create_import_lock( $token ) ) {
			$this->import_lock_token = $token;
			return true;
		}

		// bail if the existing lock is still fresh.
		$lock = $this->get_import_lock();
		if ( $lock['time'] > 0 && ( time() - $lock['time'] ) < self::IMPORT_LOCK_TIMEOUT ) {
			return false;
		}

		// replace a stale lock, but only if no other request replaced it meanwhile.
		$this->delete_stale_import_lock( $lock['value'] );

		// bail if another request was faster.
		if ( ! $this->create_import_lock( $token ) ) {
			return false;
		}

		$this->import_lock_token = $token;

		return true;
	}

	/**
	 * Return the timestamp and the token of the actual import lock.
	 *
	 * @return array{time:int,token:string,value:string}
	 */
	private function get_import_lock(): array {
		// read the value uncached, another request may have changed it.
		$this->clear_import_lock_cache();
		$value = get_option( self::IMPORT_LOCK_OPTION, '' );

		// older versions stored only the timestamp.
		if ( ! is_scalar( $value ) ) {
			$value = '';
		}

		$parts = explode( '|', (string) $value, 2 );

		return array(
			'time'  => absint( $parts[0] ),
			'token' => $parts[1] ?? '',
			'value' => (string) $value,
		);
	}

	/**
	 * Check whether this request still owns the import lock.
	 *
	 * @return bool
	 */
	private function owns_import_lock(): bool {
		// bail if this request has no lock.
		if ( '' === $this->import_lock_token ) {
			return false;
		}

		return hash_equals( $this->get_import_lock()['token'], $this->import_lock_token );
	}

	/**
	 * Refresh the timestamp of the import lock, so it does not become stale
	 * while the import is still running.
	 *
	 * @return void
	 */
	private function refresh_import_lock(): void {
		// bail if the lock belongs to another run meanwhile.
		if ( ! $this->owns_import_lock() ) {
			return;
		}

		update_option( self::IMPORT_LOCK_OPTION, time() . '|' . $this->import_lock_token, false );
	}

	/**
	 * Release the import lock, but only if it still belongs to this run.
	 *
	 * @return void
	 */
	private function release_import_lock(): void {
		if ( $this->owns_import_lock() ) {
			delete_option( self::IMPORT_LOCK_OPTION );
		}

		$this->import_lock_token = '';
	}

	/**
	 * Prepare the environment for a long-running import and return the time
	 * budget in seconds.
	 *
	 * @return int Zero means no limit.
	 */
	private function prepare_import_environment(): int {
		// raise the memory limit like WordPress does for admin tasks.
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		// the command line (WP-CLI, system cron, tests) has no time limit.
		if ( Plugin::is_cli() || 'cli' === PHP_SAPI ) {
			return 0;
		}

		/**
		 * Filter the time in seconds an import may use for its sources.
		 *
		 * Sources which did not get their turn are run first on the next import.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param int $time_budget The time budget in seconds (0 = no limit).
		 */
		$time_budget = absint( apply_filters( 'now_pages_threadi_import_time_budget', self::IMPORT_TIME_BUDGET ) );

		// try to get enough time for the import.
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			@set_time_limit( $time_budget > 0 ? $time_budget + MINUTE_IN_SECONDS : 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged -- the import needs more time; may be forbidden by the hosting.
		}

		// stay within the time PHP really allows us.
		$max_execution_time = absint( ini_get( 'max_execution_time' ) );
		if ( $max_execution_time > 0 ) {
			$limit       = max( 10, (int) floor( $max_execution_time * 0.8 ) );
			$time_budget = 0 === $time_budget ? $limit : min( $time_budget, $limit );
		}

		return $time_budget;
	}

	/**
	 * Return the given sources ordered by their last run, sources which never
	 * ran first.
	 *
	 * A started run counts as well, so a source which crashed the import is
	 * moved behind the others instead of crashing every following import.
	 *
	 * @param array<int,array{source_id:int,provider:Provider_Interface,config:array<string,mixed>,title:string}> $sources The sources.
	 *
	 * @return array<int,array{source_id:int,provider:Provider_Interface,config:array<string,mixed>,title:string}>
	 */
	private function sort_sources_by_last_run( array $sources ): array {
		$last_runs = array();
		foreach ( $sources as $index => $source ) {
			$context             = new Provider_Context( absint( $source['source_id'] ) );
			$last_runs[ $index ] = max( $context->get_last_run(), $context->get_last_attempt() );
		}

		// sort stable by the last run.
		uksort(
			$sources,
			static function ( int $a, int $b ) use ( $last_runs ): int {
				// keep the original order for equal last runs.
				if ( $last_runs[ $a ] === $last_runs[ $b ] ) {
					return $a <=> $b;
				}

				return $last_runs[ $a ] <=> $last_runs[ $b ];
			}
		);

		return array_values( $sources );
	}

	/**
	 * Run the import of all configured sources.
	 *
	 * @param ProcessHandler $process_handler The process handler.
	 *
	 * @return void
	 */
	private function run_import( ProcessHandler $process_handler ): void {
		// prepare the environment and get the time budget.
		$time_budget = $this->prepare_import_environment();
		$start       = time();

		// get the configured sources, the longest waiting first.
		$sources = $this->sort_sources_by_last_run( self::get_instance()->get_configured_sources( true ) );

		// set basic settings for process handling.
		$process_handler->set_count( 0 );
		$process_handler->set_max_count( count( $sources ) );
		$process_handler->set_status( __( 'Import is starting', 'now-pages-threadi' ) );
		$process_handler->set_running( time() );

		// loop through them.
		foreach ( $sources as $index => $source ) {
			// stop if the time budget is used up, the remaining sources run first next time.
			if ( $time_budget > 0 && ( time() - $start ) >= $time_budget ) {
				Log::get_instance()->add(
					sprintf(
						/* translators: %1$d will be replaced by a number of sources. */
						__( 'The import stopped after its time budget, %1$d sources will be imported during the next run.', 'now-pages-threadi' ),
						count( $sources ) - $index
					),
					'info',
					'system'
				);
				break;
			}

			// skip disabled providers.
			if ( ! $source['provider']->is_enabled() ) {
				// update the counter.
				$process_handler->set_count( $index + 1 );

				// do nothing more.
				continue;
			}

			// skip push providers, there is nothing to pull from them.
			if ( $source['provider']->is_push() ) {
				// update the counter.
				$process_handler->set_count( $index + 1 );

				// do nothing more.
				continue;
			}

			// skip providers which cannot run on this website.
			if ( ! $source['provider']->is_available() ) {
				Log::get_instance()->add(
					sprintf(
					/* translators: %1$s will be replaced by a provider label, %2$s by a reason. */
						__( 'The provider %1$s cannot run: %2$s', 'now-pages-threadi' ),
						'<code>' . esc_html( $source['provider']->get_label() ) . '</code>',
						esc_html( $source['provider']->get_unavailable_reason() )
					),
					'error',
					'provider'
				);

				// update the counter.
				$process_handler->set_count( $index + 1 );

				// do nothing more.
				continue;
			}

			// update the message.
			/* translators: %1$s will be replaced by a title. */
			$process_handler->set_status( sprintf( __( 'Importing %1$s', 'now-pages-threadi' ), $source['title'] ) );

			// import this source, one broken provider must not stop the others.
			try {
				$this->import_source( $source );
			} catch ( \Throwable $e ) {
				do_action(
					'now_pages_threadi_provider_error',
					$source['provider']->get_slug(),
					new WP_Error(
						'now_pages_threadi_provider_exception',
						$e->getMessage(),
						array( 'source_id' => absint( $source['source_id'] ) )
					)
				);
			}

			// update the counter.
			$process_handler->set_count( $index + 1 );

			// keep the lock alive.
			$this->refresh_import_lock();
		}

		// create dialog.
		$dialog = array(
			'detail' => array(
				'title'   => __( 'Import has been run', 'now-pages-threadi' ),
				'texts'   => array(
					'<p><strong>' . __( 'Your now pages are now up to date.', 'now-pages-threadi' ) . '</strong></p>',
					/* translators: %1$s will be replaced by a URL. */
					'<p>' . sprintf( __( 'Check your now page <a href="%1$s">here</a>.', 'now-pages-threadi' ), Frontend::get_instance()->get_global_now_page_url() ) . '</p>',
				),
				'buttons' => array(
					array(
						'action'  => 'closeDialog();',
						'variant' => 'primary',
						'text'    => __( 'OK', 'now-pages-threadi' ),
					),
				),
			),
		);
		$process_handler->set_message( $dialog );

		// update the counter.
		$process_handler->set_count( count( $sources ) );
		$process_handler->set_status( __( 'Import has been run', 'now-pages-threadi' ) );
	}

	/**
	 * Import the entries of a single source.
	 *
	 * @param array{source_id:int,provider:Provider_Interface,config:array<string,mixed>,title:string} $source The source.
	 *
	 * @return void
	 */
	private function import_source( array $source ): void {
		// build the context of this run.
		$context = new Provider_Context( absint( $source['source_id'] ) );

		// remember the attempt before fetching, a source which crashes the process must not run first again.
		$context->mark_as_attempted();

		// bail if a secret cannot be decrypted: the provider would silently fetch nothing.
		// the run is not marked as completed then.
		if ( $this->report_undecryptable_secret( $source ) ) {
			return;
		}

		// fetch the data.
		$entries = $source['provider']->fetch( $source['config'], $context );

		// show cli hint.
		$progress = Plugin::is_cli() ? \WP_CLI\Utils\make_progress_bar( 'Import entries from ' . $source['title'], count( $entries ) ) : false;

		// run the provider to fetch its contents.
		foreach ( $entries as $entry ) {
			/**
			 * Add a new entry for now pages.
			 *
			 * @since 1.0.0 Available since 1.0.0.
			 * @param Entry $entry     The entry object.
			 * @param int   $source_id The post ID of the np_threadi_source this entry came from (0 = none).
			 */
			do_action( 'now_pages_threadi_entry_received', $entry, $source['source_id'] );

			// show progress.
			if ( $progress ) {
				$progress->tick();
			}
		}

		// finish the progress.
		if ( $progress ) {
			$progress->finish();
		}

		// remember that this source has been run, this also stores the ETags of this run.
		$context->mark_as_run();
	}

	/**
	 * Report a secret of a source which cannot be decrypted.
	 *
	 * The function get_source_config() returns such a secret as an empty value, so the
	 * provider would run without it and silently import nothing.
	 *
	 * @param array{source_id:int,provider:Provider_Interface,config:array<string,mixed>,title:string} $source The source.
	 *
	 * @return bool True if a secret could not be decrypted.
	 */
	private function report_undecryptable_secret( array $source ): bool {
		foreach ( $source['provider']->get_config_fields() as $name => $field ) {
			// bail if the field definition is unusable.
			if ( ! is_array( $field ) || empty( $field['type'] ) ) {
				continue;
			}

			// get the field object for the given type.
			$field_obj = Fields::get_instance()->get_field_by_slug( (string) $field['type'] );

			// bail if this field holds no secret.
			if ( ! $field_obj instanceof Field_Base || ! $field_obj->is_secret() ) {
				continue;
			}

			// bail if no secret is stored.
			$stored = get_post_meta( absint( $source['source_id'] ), (string) $name, true );
			if ( ! is_string( $stored ) || '' === $stored ) {
				continue;
			}

			// bail if the secret has been decrypted.
			$value = $source['config'][ $name ] ?? '';
			if ( ! is_string( $value ) || '' !== $value ) {
				continue;
			}

			do_action(
				'now_pages_threadi_provider_error',
				$source['provider']->get_slug(),
				new WP_Error(
					'now_pages_threadi_secret_not_decryptable',
					sprintf(
					/* translators: %1$s will be replaced by a field label, %2$s by the title of a source. */
						__( 'The value of the field %1$s on the source %2$s could not be decrypted, so the source has not been imported. Probably the security keys of this website have changed. Please enter the value again.', 'now-pages-threadi' ),
						'<code>' . esc_html( (string) ( $field['label'] ?? $name ) ) . '</code>',
						'<a href="' . esc_url( (string) get_edit_post_link( absint( $source['source_id'] ), '' ) ) . '">' . esc_html( $source['title'] ) . '</a>'
					),
					array(
						'source_id' => absint( $source['source_id'] ),
						'formatted' => true,
					)
				)
			);

			return true;
		}

		return false;
	}

	/**
	 * Return a raw value from the request.
	 *
	 * @param string $name The name of the field.
	 *
	 * @return mixed Null if the field was not submitted.
	 */
	private function get_from_request( string $name ): mixed {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core verifies update-post_{ID} in edit_post() before save_post fires.
		if ( ! isset( $_POST[ $name ] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the caller sanitizes.
		return wp_unslash( $_POST[ $name ] );
	}

	/**
	 * Return a scalar value from the request as string.
	 *
	 * @param string $name The name of the field.
	 *
	 * @return string Empty string if the field is missing or an array.
	 */
	private function get_string_from_request( string $name ): string {
		$value = $this->get_from_request( $name );

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return (string) $value;
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
			1  => __( 'The source has been updated.', 'now-pages-threadi' ),
			4  => __( 'The source has been updated.', 'now-pages-threadi' ),
			6  => __( 'The source has been saved.', 'now-pages-threadi' ),
			7  => __( 'The source has been saved.', 'now-pages-threadi' ),
			/* translators: %1$s will be replaced by a date. */
			9  => sprintf( __( 'The source should be published on %1$s.', 'now-pages-threadi' ), $date ),
			10 => __( 'The source has been updated.', 'now-pages-threadi' ),
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
			'%1$d source has been deleted.',
			'%1$d sources have been deleted.',
			absint( $bulk_counts['deleted'] ),
			'now-pages-threadi'
		);
		/* translators: %1$s will be replaced by a number. */
		$bulk_messages[ $this->get_slug() ]['trashed'] = _n(
			'%1$d source has been moved to trash.',
			'%1$d sources have been moved to trash.',
			absint( $bulk_counts['trashed'] ),
			'now-pages-threadi'
		);
		/* translators: %1$s will be replaced by a number. */
		$bulk_messages[ $this->get_slug() ]['untrashed'] = _n(
			'%1$d source has been revived from trash.',
			'%1$d sources have been revived from trash.',
			absint( $bulk_counts['untrashed'] ),
			'now-pages-threadi'
		);
		return $bulk_messages;
	}

	/**
	 * Return the source an incoming entry belongs to.
	 *
	 * @param string $slug The slug submitted as "source".
	 *
	 * @return int The ID of the matching source, 0 if none was found.
	 */
	public function resolve_source( string $slug ): int {
		if ( '' === $slug ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'      => self::get_instance()->get_slug(),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Query for specific webhook slug.
					array(
						'key'   => 'provider',
						'value' => 'webhook',
					),
					array(
						'key'   => 'webhook_slug',
						'value' => $slug,
					),
				),
			)
		);

		return $query->have_posts() ? absint( $query->posts[0] ?? 0 ) : 0;
	}

	/**
	 * Move the author box to the right side of this cpt view.
	 *
	 * @return void
	 */
	public function move_author_box(): void {
		// remove the slug-box.
		remove_meta_box( 'slugdiv', $this->get_slug(), 'normal' );

		// Remove the author box from its default 'normal' bottom position.
		remove_meta_box( 'authordiv', $this->get_slug(), 'normal' );

		// Re-add the author box to the 'side' (right) position.
		add_meta_box(
			'authordiv',
			__( 'Author', 'now-pages-threadi' ),
			'post_author_meta_box',
			$this->get_slug(),
			'side',
			'default'
		);
	}

	/**
	 * Redirect the new page to the setup.
	 *
	 * @return void
	 */
	public function redirect_to_setup(): void {
		$screen = get_current_screen();

		// bail if this is not our post type.
		if ( ! $screen instanceof \WP_Screen || $this->get_slug() !== $screen->post_type ) {
			return;
		}

		// bail if the user chose to configure a source by hand.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		if ( isset( $_GET['now_pages_threadi_manual'] ) ) {
			return;
		}

		// bail if the user is not allowed to open the setup: then the source is configured by hand.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_safe_redirect( Setup::get_instance()->get_setup_link() );
		exit;
	}

	/**
	 * Return the ID of an existing source with the same configuration.
	 *
	 * Two sources are the same if they read the same thing (the identifying
	 * fields of their provider) and keep the same of it (their own content
	 * filter rules). The same feed with different rules is not a duplicate:
	 * that is how one feed is split into several sources, e.g. to give each
	 * part its own category.
	 *
	 * @param Provider_Interface  $provider     The provider to check.
	 * @param array<string,mixed> $config       The configuration to check.
	 * @param int                 $exclude      A source to ignore, e.g. the one being edited.
	 * @param string              $filter_rules The content filter rules of the source to check, as entered. Empty for a source without own rules.
	 *
	 * @return int Zero if no duplicate exists.
	 */
	public function get_duplicate( Provider_Interface $provider, array $config, int $exclude = 0, string $filter_rules = '' ): int {
		$fields = $provider->get_identifying_fields();

		$query_args = array(
			'post_type'      => $this->get_slug(),
			'post_status'    => array( 'publish', 'draft', 'pending' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- the list holds exactly one ID, the source being saved.
			'post__not_in'   => array( $exclude ),
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Query for specific provider slug.
				array(
					'key'   => 'provider',
					'value' => $provider->get_slug(),
				),
			),
		);

		// a provider that may exist only once needs no further comparison.
		if ( $provider->is_unique() ) {
			$query = new WP_Query( $query_args );

			return absint( $query->posts[0] ?? 0 );
		}

		// bail if this provider has no identity at all.
		if ( empty( $fields ) ) {
			return 0;
		}

		foreach ( $fields as $field ) {
			$value = '';

			if ( ! empty( $config[ $field ] ) ) {
				$value = $config[ $field ];
			}

			// an empty identifying value cannot identify anything.
			if ( '' === $value || ! is_scalar( $value ) ) {
				return 0;
			}

			$query_args['meta_query'][] = array(
				'key'   => $field,
				'value' => (string) $value,
			);
		}

		// get every source which reads the same, there are only a few of them.
		$query_args['posts_per_page'] = self::MAX_DUPLICATE_CANDIDATES;

		$query = new WP_Query( $query_args );

		// a push provider routes incoming entries by its identifying fields:
		// the same values are a conflict, whatever the rules are.
		if ( $provider->is_push() ) {
			$source_id = $query->posts[0] ?? 0;

			return absint( $source_id instanceof WP_Post ? $source_id->ID : $source_id );
		}

		$signature = Content_Filters::get_signature( $filter_rules );

		// a duplicate is the one which also keeps the same.
		foreach ( (array) $query->posts as $source_id ) {
			$source_id = absint( $source_id instanceof WP_Post ? $source_id->ID : $source_id );

			if ( Content_Filters::get_signature( (string) get_post_meta( $source_id, Content_Filters::SOURCE_META, true ) ) === $signature ) {
				return $source_id;
			}
		}

		return 0;
	}

	/**
	 * Show a hint if another source with the same configuration exists.
	 *
	 * @param Provider_Interface  $provider_obj The used provider.
	 * @param array<string,mixed> $identity     The identifying values.
	 * @param int                 $post_id      The source being saved.
	 *
	 * @return void
	 */
	private function check_for_duplicate( Provider_Interface $provider_obj, array $identity, int $post_id ): void {
		// compare with the rules which have just been saved for this source.
		$duplicate = $this->get_duplicate( $provider_obj, $identity, $post_id, (string) get_post_meta( $post_id, Content_Filters::SOURCE_META, true ) );

		// bail if this configuration is unique.
		if ( 0 === $duplicate ) {
			return;
		}

		// add a hint the user sees on the next page load.
		$transient_obj = Transients::get_instance()->add();
		$transient_obj->set_name( 'now_pages_threadi_duplicate_source' );
		$transient_obj->set_message(
			sprintf(
			/* translators: %1$s will be replaced by a link to another source. */
				__( 'This source has the same configuration as %1$s. Both will import the same entries. If each of them should keep another part, give them different content filter rules.', 'now-pages-threadi' ),
				'<a href="' . esc_url( (string) get_edit_post_link( $duplicate, '' ) ) . '">' . esc_html( get_the_title( $duplicate ) ) . '</a>'
			)
		);
		$transient_obj->set_type( 'error' );
		$transient_obj->set_dismissible_days( 2 );
		$transient_obj->save();

		/**
		 * Run additional tasks after a duplicate was detected.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param Provider_Interface $provider_obj The provider.
		 * @param int $duplicate Amount of duplicates.
		 * @param int $post_id The post ID which is a duplicate.
		 * @param array $identity The list of parameters to identify the duplicate.
		 */
		do_action( 'now_pages_threadi_source_duplicate', $provider_obj, $duplicate, $post_id, $identity );
	}

	/**
	 * Tell the user that the entries of a source followed its category.
	 *
	 * @param int $changed  The number of entries which were changed.
	 * @param int $term_id  The category of the source now (0 = none).
	 * @param int $previous The category of the source before (0 = none).
	 *
	 * @return void
	 */
	private function report_moved_entries( int $changed, int $term_id, int $previous ): void {
		// bail if no entry was changed.
		if ( $changed <= 0 ) {
			return;
		}

		// get the category to name.
		$term = get_term( $term_id > 0 ? $term_id : $previous, Categories::TAXONOMY );

		// bail if this category does not exist anymore.
		if ( ! $term instanceof WP_Term ) {
			return;
		}

		if ( $term_id > 0 ) {
			/* translators: %1$d will be replaced by a number, %2$s by the name of a category. */
			$message = _n( '%1$d entry of this source has been moved to the category "%2$s".', '%1$d entries of this source have been moved to the category "%2$s".', $changed, 'now-pages-threadi' );
		} else {
			/* translators: %1$d will be replaced by a number, %2$s by the name of a category. */
			$message = _n( 'The category "%2$s" has been removed from %1$d entry of this source.', 'The category "%2$s" has been removed from %1$d entries of this source.', $changed, 'now-pages-threadi' );
		}

		// shown once on the next page: no dismissible time, it is gone after that.
		$transient_obj = Transients::get_instance()->add();
		$transient_obj->set_name( 'now_pages_threadi_source_category' );
		$transient_obj->set_message( esc_html( sprintf( $message, $changed, $term->name ) ) );
		$transient_obj->set_type( 'success' );
		$transient_obj->save();
	}

	/**
	 * Count how many entries belong to a source.
	 */
	private function count_entries_for_source( int $source_id ): int {
		$query = new WP_Query(
			array(
				'post_type'      => Entries::get_instance()->get_slug(),
				'post_status'    => 'publish',
				'post_parent'    => $source_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Add our own action link to each source.
	 *
	 * @param array<string,string> $actions The existing actions.
	 * @param WP_Post              $post    The post the actions belong to.
	 *
	 * @return array<string,string>
	 */
	public function add_row_actions( array $actions, WP_Post $post ): array {
		// bail if this is not one of our sources.
		if ( $this->get_slug() !== $post->post_type ) {
			return $actions;
		}

		// bail if the user is not allowed to see this.
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		// create dialog.
		$dialog = array(
			'title'   => __( 'Test the availability', 'now-pages-threadi' ),
			'texts'   => array(
				'<p><strong>' . __( 'Do you want to check the availability of this source?', 'now-pages-threadi' ) . '</strong></p>',
			),
			'buttons' => array(
				array(
					'action'  => 'now_pages_threadi_check_source( ' . absint( $post->ID ) . ' );',
					'variant' => 'primary',
					'text'    => __( 'Yes', 'now-pages-threadi' ),
				),
				array(
					'action'  => 'closeDialog();',
					'variant' => 'secondary',
					'text'    => __( 'No', 'now-pages-threadi' ),
				),
			),
		);

		// add our source check.
		$actions['now_pages_threadi_check'] = '<a href="#" class="easy-dialog-for-wordpress" data-dialog="' . esc_attr( Format::get_json( $dialog ) ) . '">' . esc_html__( 'Check availability', 'now-pages-threadi' ) . '</a>';

		// return the resulting actions.
		return $actions;
	}

	/**
	 * Return the URL to check the availability.
	 *
	 * @param int $source_id The source ID.
	 *
	 * @return string
	 */
	public function get_check_url( int $source_id ): string {
		return add_query_arg(
			array(
				'post_type'               => $this->get_slug(),
				'now_pages_threadi_check' => $source_id,
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Try to create the lock option. Atomic: only one request can create it.
	 *
	 * Does not use add_option(): it writes with "INSERT … ON DUPLICATE KEY UPDATE"
	 * and would overwrite a lock another request created just before.
	 *
	 * @phpstan-impure
	 *
	 * @param string $token The token of this run.
	 *
	 * @return bool
	 */
	private function create_import_lock( string $token ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- The lock must be created atomically.
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . $wpdb->options . ' (option_name, option_value, autoload) VALUES (%s, %s, %s)',
				self::IMPORT_LOCK_OPTION,
				time() . '|' . $token,
				'off'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery

		// the cached values do not know about the new option.
		$this->clear_import_lock_cache();

		// an existing option is ignored and affects no row.
		return 1 === $result;
	}

	/**
	 * Delete a stale lock, but only if it still has the given value.
	 *
	 * Two requests which both found the same stale lock must not delete the
	 * lock which one of them created meanwhile.
	 *
	 * @param string $value The value of the stale lock.
	 *
	 * @return void
	 */
	private function delete_stale_import_lock( string $value ): void {
		global $wpdb;

		// a value we cannot compare against, e.g. from an older version, is simply deleted.
		if ( '' === $value ) {
			delete_option( self::IMPORT_LOCK_OPTION );
			return;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- The lock must be deleted atomically.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $wpdb->options . ' WHERE option_name = %s AND option_value = %s',
				self::IMPORT_LOCK_OPTION,
				$value
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery

		$this->clear_import_lock_cache();
	}

	/**
	 * Remove the import lock from the option caches.
	 *
	 * @return void
	 */
	private function clear_import_lock_cache(): void {
		wp_cache_delete( self::IMPORT_LOCK_OPTION, 'options' );

		// get_option() would trust a cached "this option does not exist".
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ self::IMPORT_LOCK_OPTION ] ) ) {
			unset( $notoptions[ self::IMPORT_LOCK_OPTION ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}
}
