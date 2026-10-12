<?php
/**
 * This file defines tasks during the deactivation of this plugin.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Digests\Display_Settings;
use ThreadiNowPages\Digests\Summarizer_Stats;
use ThreadiNowPages\Fediverse\Fediverse;
use ThreadiNowPages\Helper\Plugin;
use ThreadiNowPages\NowPages\Categories;
use ThreadiNowPages\NowPages\Entries;
use ThreadiNowPages\NowPages\Now_Page;
use ThreadiNowPages\Providers\Webhook_Controller;
use ThreadiNowPages\Sources\Sources;
use WP_Query;

/**
 * Object, which handles the tasks during the deactivation of this plugin.
 */
class Uninstallation {

	/**
	 * Instance of this object.
	 *
	 * @var ?Uninstallation
	 */
	private static ?Uninstallation $instance = null;

	/**
	 * Constructor for this object.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning of this object.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Return the instance of this Singleton object.
	 */
	public static function get_instance(): Uninstallation {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Uninstall the plugin.
	 *
	 * @param bool $network_wide True to clean up every site of the network (only uninstall.php does this).
	 *
	 * @return void
	 */
	public function run( bool $network_wide = false ): void {
		// clean up every blog only if this is requested explicitly.
		if ( $network_wide && is_multisite() ) {
			foreach ( Plugin::get_blogs( true ) as $blog_id ) {
				// switch to the blog.
				switch_to_blog( $blog_id );

				// run tasks for deactivation in this single blog.
				$this->uninstallation_tasks( true );

				// switch back to the original blog.
				restore_current_blog();
			}
			return;
		}

		// otherwise only the actual blog.
		$this->uninstallation_tasks( false );
	}

	/**
	 * Define the tasks to run during uninstallation of the plugin.
	 *
	 * Hint: do not run anything regarding extensions. This will be done at the end of the setup.
	 *
	 * @param bool $network_wide True to clean up every site of the network (only uninstall.php does this).
	 *
	 * @return void
	 */
	private function uninstallation_tasks( bool $network_wide ): void {
		// delete all entries and sources, in batches: the list of all IDs and the
		// caches filled while deleting must not grow with the number of entries.
		$query = array(
			'post_type'      => Post_Types::get_instance()->get_post_type_slugs(),
			'post_status'    => array( 'any', 'trash', 'auto-draft' ),
			'posts_per_page' => 100,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);

		// get the number of posts for the cli hint.
		$count    = new WP_Query( array_merge( $query, array( 'posts_per_page' => 1 ) ) );
		$progress = Plugin::is_cli() ? \WP_CLI\Utils\make_progress_bar( 'Deleting all entries and sources', $count->found_posts ) : false;

		// the list of deleted entries is removed below anyway, do not rewrite it for each entry.
		remove_action( 'before_delete_post', array( Entries::get_instance(), 'remember_deletion' ), 10 );
		wp_defer_term_counting( true );

		$failed = array();
		do {
			$results = new WP_Query(
				array_merge(
					$query,
					array(
						'no_found_rows' => true,
						// skip what could not be deleted, otherwise this loop would never end.
						'post__not_in'  => $failed, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- only runs on uninstall, usually empty.
					)
				)
			);

			foreach ( $results->posts as $post_id ) {
				$post_id = absint( $post_id );

				if ( ! wp_delete_post( $post_id, true ) ) {
					$failed[] = $post_id;
				}

				if ( $progress ) {
					$progress->tick();
				}
			}

			// free the memory used for the deleted posts.
			wp_cache_flush_runtime();
		} while ( ! empty( $results->posts ) );

		wp_defer_term_counting( false );

		// finish the progress.
		if ( $progress ) {
			$progress->finish();
		}

		// initialize the plugin.
		Init::get_instance()->init();

		// run the init hooks to set all settings.
		Settings::get_instance()->add_settings();

		// get the settings object.
		$settings_obj = Settings::get_instance()->get_settings_obj();

		// enable the settings.
		$settings_obj->activation();

		// clean managed settings.
		$settings_obj->delete_settings();

		// uninstall the setup.
		Setup::get_instance()->uninstall();

		// remove our cron events.
		Schedules::get_instance()->delete_all();

		// delete custom options.
		delete_option( Summarizer_Stats::OPTION );
		delete_option( 'threadi_now_pages_email_report_last_run' );
		delete_option( Entries::OPTION_DELETED );
		// the hook on deleting the option does not fire if it was never saved: forget the pending ones, too.
		Entries::get_instance()->forget_pending_deletions();
		delete_option( Entries::OPTION_CLEANED_BEFORE );
		delete_option( Now_Page::OPTION );
		delete_option( Webhook_Controller::OPTION_TOKEN );
		delete_option( 'threadi_now_pages_provider_webhook_enabled' );
		delete_option( 'threadi_now_pages_slug' );
		delete_option( 'threadi_now_pages_update_slugs' );
		delete_option( 'threadi_now_pages_rewrite_version' );
		delete_option( 'threadi_now_pages_log_error_count' );
		delete_option( 'threadi_now_pages_process_values' );
		delete_option( Sources::IMPORT_LOCK_OPTION );
		delete_option( 'threadi_now_pages_schedules' );
		delete_option( Fediverse::OPTION_SINCE );
		delete_option( Fediverse::OPTION_LAST_DIGEST );
		delete_option( Fediverse::OPTION_AUTHOR );

		global $wpdb;

		// remove our transients.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time cleanup during uninstallation.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $wpdb->options . ' WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
				$wpdb->esc_like( '_transient_threadi_now_pages_summary_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_threadi_now_pages_summary_' ) . '%',
				$wpdb->esc_like( '_transient_threadi_now_pages_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_threadi_now_pages_' ) . '%',
				$wpdb->esc_like( 'threadi_now_pages_summary_' ) . '%',
			)
		);

		// this covers the summaries, the locks of running summaries, the sitemap cache, the settings errors and the process states.
		// delete the ones with fixed names also via API, to reach an external object cache.
		delete_transient( 'threadi_now_pages_sitemap_entries' );
		delete_transient( 'threadi_now_pages_wp_users' );
		delete_transient( 'threadi_now_pages_wp_pages' );

		// remove the terms in our category.
		// get all terms with direct db access, as the taxonomy may not be registered.
		$terms = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT ' . $wpdb->terms . '.term_id, ' . $wpdb->term_taxonomy . '.term_taxonomy_id
                    FROM ' . $wpdb->terms . '
                    INNER JOIN
                        ' . $wpdb->term_taxonomy . '
                        ON
                         ' . $wpdb->term_taxonomy . '.term_id = ' . $wpdb->terms . '.term_id
                    WHERE ' . $wpdb->term_taxonomy . '.taxonomy = %s',
				array( Categories::get_instance()->get_slug() )
			),
			ARRAY_A
		);

		// delete them.
		foreach ( $terms as $term ) {
			// delete the meta of this term.
			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->termmeta,
				array(
					'term_id' => absint( $term['term_id'] ),
				),
				array( '%d' )
			);

			// delete the relationships of this term.
			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->term_relationships,
				array(
					'term_taxonomy_id' => absint( $term['term_taxonomy_id'] ),
				),
				array( '%d' )
			);

			// delete the term itself.
			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->terms,
				array(
					'term_id' => absint( $term['term_id'] ),
				),
				array( '%d' )
			);

			// remove it from the object cache.
			wp_cache_delete( absint( $term['term_id'] ), 'terms' );
			wp_cache_delete( absint( $term['term_id'] ), 'term_meta' );
		}

		// delete all taxonomy-entries.
		$wpdb->delete( $wpdb->term_taxonomy, array( 'taxonomy' => Categories::get_instance()->get_slug() ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// cleanup options.
		delete_option( Categories::get_instance()->get_slug() . '_children' );

		// remove capabilities and role.
		Capabilities::get_instance()->uninstall();

		// remove user meta.
		$this->delete_user_meta( $network_wide );

		// delete the log table and its options.
		Log::get_instance()->delete_table();
		delete_option( Log::OPTION_DB_VERSION );
		delete_option( Log::OPTION_LAST_CLEANUP );

		// remove the data of our admin notices.
		$this->delete_admin_notices();

		// hint: with an external object cache our transients are not stored in the options table.
		// they are not flushed here, as this would remove the transients of all other plugins, too.
		// they expire on their own.

		// remove what the crypt library keeps: the key it puts aside and its fingerprints.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time cleanup during uninstallation.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $wpdb->options . ' WHERE option_name LIKE %s',
				$wpdb->esc_like( 'threadi-now-pages_' ) . '%'
			)
		);
		delete_site_option( 'threadi-now-pages_crypt_key_id_openssl' );
		delete_site_option( 'threadi-now-pages_crypt_key_id_sodium' );

		// remove every option of this plugin, also those which are registered late
		// and are therefore unknown to the settings object during the uninstallation.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time cleanup during uninstallation.
		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT option_name FROM ' . $wpdb->options . ' WHERE option_name LIKE %s',
				$wpdb->esc_like( 'threadi_now_pages_' ) . '%'
			)
		);
		foreach ( $option_names as $option_name ) {
			delete_option( $option_name );
		}

		// remove the key of the plugin is really deinstalled.
		if ( defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			Crypt::get_instance()->uninstall();
		}
	}

	/**
	 * Remove the data our admin notices (via easy-transients-for-wordpress) left behind.
	 *
	 * Only removes the data of this plugin, the option is shared with other plugins.
	 *
	 * @return void
	 */
	private function delete_admin_notices(): void {
		global $wpdb;

		// the slug used for our notices, see Admin::init().
		$slug = 'threadi-now-pages';

		// remove our entry from the list of notices.
		$notices = get_option( 'etfw_transients', array() );
		if ( is_array( $notices ) && isset( $notices[ $slug ] ) ) {
			unset( $notices[ $slug ] );

			if ( empty( $notices ) ) {
				delete_option( 'etfw_transients' );
			} else {
				update_option( 'etfw_transients', $notices );
			}
		}

		// remove the markers for dismissed notices.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time cleanup during uninstallation.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $wpdb->options . ' WHERE option_name LIKE %s',
				$wpdb->esc_like( $slug . '-dismissed-' ) . '%'
			)
		);

		// remove them from the object cache.
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * Remove every user meta this plugin created.
	 *
	 * @param bool $network_wide True to clean up every site of the network (only uninstall.php does this).
	 *
	 * @return void
	 */
	private function delete_user_meta( bool $network_wide ): void {
		$keys   = Display_Settings::user_meta_keys();
		$keys[] = 'esfw_values_' . Setup::get_instance()->get_setup_name();
		$keys[] = \ThreadiNowPages\Plugin\Emails\EmptyNowPages::USER_META_SENT;

		// on a single site or a network-wide uninstall: remove it for every user.
		if ( $network_wide || ! is_multisite() ) {
			foreach ( $keys as $meta_key ) {
				delete_metadata( 'user', 0, $meta_key, '', true );
			}
			return;
		}

		// reset of one site in a network: only the users of this site.
		$user_ids = get_users(
			array(
				'blog_id' => get_current_blog_id(),
				'fields'  => 'ID',
			)
		);
		foreach ( $user_ids as $user_id ) {
			foreach ( $keys as $meta_key ) {
				delete_user_meta( absint( $user_id ), $meta_key );
			}
		}
	}
}
