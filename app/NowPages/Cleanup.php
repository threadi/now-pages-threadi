<?php
/**
 * This file contains the cleanup of old entries.
 *
 * Deliberately manual: a now page is a record of somebody's life, and a
 * shortcode with period="range" in a blog post keeps showing entries from
 * years ago. Deleting on a schedule would empty such a post without a
 * word, so this asks every time.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\NowPages;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use easySettingsForWordPress\Fields\Button;
use easySettingsForWordPress\Page;
use easySettingsForWordPress\Tab;
use NowPagesThreadi\Fediverse\Fediverse;
use NowPagesThreadi\Helper\Format;
use NowPagesThreadi\Helper\Plugin;
use NowPagesThreadi\Helper\Singleton_Interface;
use NowPagesThreadi\Helper\Singleton_Trait;
use NowPagesThreadi\Plugin\Log;
use NowPagesThreadi\Plugin\Settings;
use WP_Post;
use WP_Query;

/**
 * Object to delete entries which are older than a given age.
 */
class Cleanup implements Singleton_Interface {
	use Singleton_Trait;

	/**
	 * How many entries are deleted in one run at most.
	 *
	 * A cleanup on a website which has been collecting for years can mean
	 * tens of thousands of posts, and the request would run into the
	 * timeout long before it is done. Better to delete a portion, say so,
	 * and let the user press the button again.
	 *
	 * @var int
	 */
	private const MAX_PER_RUN = 2000;

	/**
	 * How many entries are deleted per query.
	 *
	 * @var int
	 */
	private const BATCH_SIZE = 100;

	/**
	 * How many seconds one run deletes entries at most.
	 *
	 * Below the usual PHP timeout of 30 seconds, so the answer still
	 * arrives and tells the user what is left.
	 *
	 * @var int
	 */
	private const TIME_BUDGET = 20;

	/**
	 * The age proposed in the dialog, in days.
	 *
	 * @var int
	 */
	private const DEFAULT_DAYS = 365;

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
		add_action( 'init', array( $this, 'add_settings' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'add_scripts' ) );
		add_action( 'wp_ajax_now_pages_threadi_cleanup', array( $this, 'cleanup_via_ajax' ) );
	}

	/**
	 * Add the button which starts the cleanup.
	 *
	 * @return void
	 */
	public function add_settings(): void {
		// bail if the settings are not needed in this request.
		if ( ! Plugin::is_settings_request() ) {
			return;
		}

		// get settings object.
		$settings_obj = Settings::get_instance()->get_settings_obj();

		// get the settings page.
		$settings_page = $settings_obj->get_page( $settings_obj->get_menu_slug() );

		// bail if the page could not be loaded.
		if ( ! $settings_page instanceof Page ) {
			return;
		}

		// get the import tab: this belongs next to where the entries arrive.
		$import_tab = $settings_page->get_tab( 'now_pages_threadi_import' );

		// bail if the tab could not be loaded.
		if ( ! $import_tab instanceof Tab ) {
			return;
		}

		// add our own section at the end of the tab.
		$cleanup_section = $import_tab->add_section( 'now_pages_threadi_cleanup', 50 );
		$cleanup_section->set_title( __( 'Cleanup', 'now-pages-threadi' ) );
		$cleanup_section->set_callback( array( $this, 'show_hint' ) );
		if ( method_exists( $cleanup_section, 'set_collapsed' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			$cleanup_section->set_collapsible( true );
			$cleanup_section->set_collapsed( true );
		}

		// add the setting.
		$setting = $settings_obj->add_setting( 'now_pages_threadi_cleanup' );
		$setting->set_section( $cleanup_section );
		$setting->set_autoload( false );
		$setting->prevent_export( true );
		$field = new Button( $settings_obj );
		$field->set_title( __( 'Delete old entries', 'now-pages-threadi' ) );
		$field->set_button_title( __( 'Choose an age', 'now-pages-threadi' ) );
		$field->set_button_url( '#' );
		$field->add_class( 'easy-dialog-for-wordpress' );
		$field->add_data( 'dialog', Format::get_json( $this->get_dialog() ) );
		$setting->set_field( $field );
	}

	/**
	 * Show what this section is for.
	 *
	 * @return void
	 */
	public function show_hint(): void {
		echo '<p>';
		echo esc_html__( 'Your now page keeps every entry it ever imported. That is what makes it possible to look back at a single month later on - and it also means the list grows for as long as you use the plugin. Here you can remove what has become too old for you.', 'now-pages-threadi' );
		echo '</p>';
	}

	/**
	 * Return the dialog asking for the age.
	 *
	 * @return array<string,mixed>
	 */
	private function get_dialog(): array {
		$texts = array(
			'<p><strong>' . esc_html__( 'Which entries should be deleted?', 'now-pages-threadi' ) . '</strong></p>',
			'<p>' . esc_html__( 'Everything older than the given age is removed permanently. This cannot be undone.', 'now-pages-threadi' ) . '</p>',
			sprintf(
				'<p><label for="now-pages-threadi-cleanup-days">%1$s</label> <input type="number" id="now-pages-threadi-cleanup-days" class="small-text" value="%2$d" min="1" step="1"> %3$s</p>',
				esc_html__( 'Delete entries older than', 'now-pages-threadi' ),
				absint( self::DEFAULT_DAYS ),
				esc_html__( 'days', 'now-pages-threadi' )
			),
		);

		// warn if a post shows a period which may reach further back than
		// the entries would survive. this is the case the user cannot see:
		// the post stays, its digest turns up empty.
		if ( $this->has_fixed_range_somewhere() ) {
			$texts[] = '<p class="notice notice-warning inline" style="margin-left:0;padding:8px 12px;">'
						. esc_html__( 'Somewhere on your website a post shows a fixed period of your now page. If its entries are deleted here, that post will be empty from then on.', 'now-pages-threadi' )
						. '</p>';
		}

		return array(
			'title'   => __( 'Delete old entries', 'now-pages-threadi' ),
			'texts'   => $texts,
			'buttons' => array(
				array(
					'action'  => 'now_pages_threadi_cleanup_entries();',
					'variant' => 'primary',
					'text'    => __( 'Delete', 'now-pages-threadi' ),
				),
				array(
					'action'  => 'closeDialog();',
					'variant' => 'secondary',
					'text'    => __( 'Cancel', 'now-pages-threadi' ),
				),
			),
		);
	}

	/**
	 * Add the script which runs the cleanup.
	 *
	 * @param string $hook The requested hook.
	 *
	 * @return void
	 */
	public function add_scripts( string $hook ): void {
		// bail if this is not our settings page.
		if ( ! Settings::get_instance()->get_settings_obj()->enqueue_styles_and_scripts( $hook ) ) {
			return;
		}

		// embed our own JS script.
		wp_enqueue_script(
			'now-pages-threadi-cleanup',
			Plugin::get_plugin_url() . 'admin/cleanup.js',
			array( 'easy-dialog-for-wordpress' ),
			Plugin::get_file_version( Plugin::get_plugin_path() . 'admin/cleanup.js' ),
			true
		);

		// add php-vars to our js-script.
		wp_localize_script(
			'now-pages-threadi-cleanup',
			'nowPagesThreadiCleanupVars',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'now-pages-threadi-cleanup' ),
				'title'    => __( 'Delete old entries', 'now-pages-threadi' ),
				'running'  => __( 'Deleting the entries …', 'now-pages-threadi' ),
				'invalid'  => __( 'Please give an age of at least one day.', 'now-pages-threadi' ),
				'error'    => __( 'The entries could not be deleted. Please try again.', 'now-pages-threadi' ),
				'ok'       => __( 'OK', 'now-pages-threadi' ),
			)
		);
	}

	/**
	 * Run the cleanup and answer with the dialog to show.
	 *
	 * @return void
	 */
	public function cleanup_via_ajax(): void {
		// check the nonce.
		check_ajax_referer( 'now-pages-threadi-cleanup', 'nonce' );

		// bail if the capability is missing.
		if ( ! current_user_can( Settings::get_instance()->get_settings_obj()->get_capability() ) ) {
			wp_send_json_error();
		}

		// get the requested age.
		// only a plain integer is valid: sanitizing would turn e.g. "1e3" into another number.
		$days = absint( filter_input( INPUT_POST, 'days', FILTER_VALIDATE_INT ) );

		// bail on an age nobody can have meant.
		if ( $days < 1 ) {
			wp_send_json_error();
		}

		wp_send_json_success( $this->get_result_dialog( $days ) );
	}

	/**
	 * Return the dialog describing what the cleanup did.
	 *
	 * @param int $days The age in days.
	 *
	 * @return array<string,mixed>
	 */
	private function get_result_dialog( int $days ): array {
		$deleted   = $this->delete_older_than( $days );
		$remaining = $this->count_older_than( $days );

		if ( 0 === $deleted ) {
			$texts = array(
				'<p>' . sprintf(
					/* translators: %1$d will be replaced by a number of days. */
					esc_html( _n( 'No entry is older than %1$d day, so nothing was deleted.', 'No entry is older than %1$d days, so nothing was deleted.', $days, 'now-pages-threadi' ) ),
					absint( $days )
				) . '</p>',
			);

			return $this->get_dialog_around( $texts );
		}

		$texts = array(
			'<p><strong>' . sprintf(
				/* translators: %1$s will be replaced by a number of entries. */
				esc_html( _n( '%1$s entry has been deleted.', '%1$s entries have been deleted.', $deleted, 'now-pages-threadi' ) ),
				esc_html( number_format_i18n( $deleted ) )
			) . '</strong></p>',
		);

		// say so if the run hit its limit: silently leaving entries behind
		// would look like the cleanup did not work.
		if ( $remaining > 0 ) {
			$texts[] = '<p>' . sprintf(
				/* translators: %1$s will be replaced by a number of entries. */
				esc_html( _n( '%1$s entry of that age is still there, because one run deletes a limited number. Press the button again to continue.', '%1$s entries of that age are still there, because one run deletes a limited number. Press the button again to continue.', $remaining, 'now-pages-threadi' ) ),
				esc_html( number_format_i18n( $remaining ) )
			) . '</p>';
		}

		return $this->get_dialog_around( $texts );
	}

	/**
	 * Return the dialog around the given texts.
	 *
	 * @param array<int,string> $texts The texts to show.
	 *
	 * @return array<string,mixed>
	 */
	private function get_dialog_around( array $texts ): array {
		return array(
			'title'   => __( 'Delete old entries', 'now-pages-threadi' ),
			'texts'   => $texts,
			'buttons' => array(
				array(
					'action'  => 'closeDialog();',
					'variant' => 'primary',
					'text'    => __( 'OK', 'now-pages-threadi' ),
				),
			),
		);
	}

	/**
	 * Delete every entry older than the given age.
	 *
	 * @param int $days The age in days.
	 *
	 * @return int How many entries were deleted.
	 */
	public function delete_older_than( int $days ): int {
		$days = max( 1, $days );

		$max_per_run = self::MAX_PER_RUN;

		/**
		 * Filter how many entries one cleanup run deletes at most.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param int $max_per_run The limit of one run.
		 * @param int $days        The age in days.
		 */
		$max_per_run = absint( apply_filters( 'now_pages_threadi_cleanup_max_per_run', $max_per_run, $days ) );

		// an entry deleted because of its age gets no gravestone of its own:
		// keeping the hook would grow the list of remembered deletions by one
		// line per entry, push out what the user deleted by hand, and write a
		// log entry for each of them. A source like a feed still delivers its
		// newest items regardless of their age, though, so the import skips
		// everything older than the time remembered at the end of this run.
		remove_action( 'before_delete_post', array( Entries::get_instance(), 'remember_deletion' ), 10 );

		// what was shared of such an entry in the Fediverse stays there: tidying up
		// the database is no reason to take back posts people may have replied to.
		remove_action( 'before_delete_post', array( Fediverse::get_instance(), 'entry_removed' ) );

		$deleted = 0;

		// stop after the time budget, even if the limit is not reached.
		$end_time = microtime( true ) + self::TIME_BUDGET;

		while ( $deleted < $max_per_run && microtime( true ) < $end_time ) {
			$post_ids = $this->get_older_than( $days, min( self::BATCH_SIZE, $max_per_run - $deleted ) );

			// stop once nothing of that age is left.
			if ( empty( $post_ids ) ) {
				break;
			}

			$deleted_in_batch = 0;

			foreach ( $post_ids as $post_id ) {
				// stop if the time budget is used up.
				if ( microtime( true ) >= $end_time ) {
					break;
				}

				if ( ! wp_delete_post( $post_id, true ) ) {
					continue;
				}

				++$deleted_in_batch;
			}

			// stop if a whole batch could not be deleted, otherwise the same
			// entries would be queried again forever.
			if ( 0 === $deleted_in_batch ) {
				break;
			}

			$deleted += $deleted_in_batch;
		}

		// put the hook back for everything else that deletes an entry.
		add_action( 'before_delete_post', array( Entries::get_instance(), 'remember_deletion' ), 10, 2 );
		add_action( 'before_delete_post', array( Fediverse::get_instance(), 'entry_removed' ) );

		// bail if nothing happened.
		if ( 0 === $deleted ) {
			return 0;
		}

		// remember up to which time the entries are gone, so the import does not bring them back.
		// a later cleanup with a larger age must not move this time back.
		$cleaned_before = time() - $days * DAY_IN_SECONDS;
		update_option( Entries::OPTION_CLEANED_BEFORE, max( $cleaned_before, absint( get_option( Entries::OPTION_CLEANED_BEFORE, 0 ) ) ), false );

		// log this event: it is not reversible, so it should be traceable.
		Log::get_instance()->add(
			sprintf(
				/* translators: %1$d will be replaced by a number of entries, %2$d by a number of days. */
				__( 'Deleted %1$d entries which were older than %2$d days.', 'now-pages-threadi' ),
				$deleted,
				$days
			),
			'info',
			'system'
		);

		/**
		 * Run additional tasks after old entries have been deleted.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param int $deleted How many entries were deleted.
		 * @param int $days    The age in days.
		 */
		do_action( 'now_pages_threadi_cleanup_done', $deleted, $days );

		return $deleted;
	}

	/**
	 * Return how many entries are older than the given age.
	 *
	 * @param int $days The age in days.
	 *
	 * @return int
	 */
	public function count_older_than( int $days ): int {
		$query = new WP_Query(
			array(
				'post_type'      => Entries::get_instance()->get_slug(),
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'date_query'     => array(
					array(
						'before'    => $this->get_limit( $days ),
						'inclusive' => false,
					),
				),
			)
		);

		return absint( $query->found_posts );
	}

	/**
	 * Return the IDs of entries older than the given age.
	 *
	 * @param int $days  The age in days.
	 * @param int $limit How many IDs to return at most.
	 *
	 * @return array<int,int>
	 */
	private function get_older_than( int $days, int $limit ): array {
		$query = new WP_Query(
			array(
				'post_type'      => Entries::get_instance()->get_slug(),
				'post_status'    => 'any',
				'posts_per_page' => max( 1, $limit ),
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'date_query'     => array(
					array(
						'before'    => $this->get_limit( $days ),
						'inclusive' => false,
					),
				),
			)
		);

		// prepare the list.
		$ids = array();

		// WP_Query types its posts as int[]|WP_Post[], regardless of the
		// 'fields' argument. With 'ids' only integers arrive, but a filter on
		// 'the_posts' could hand objects over, so both are taken.
		foreach ( (array) $query->posts as $post ) {
			$ids[] = absint( $post instanceof WP_Post ? $post->ID : $post );
		}

		// return the list.
		return $ids;
	}

	/**
	 * Return the date an entry has to be older than.
	 *
	 * In the time of the website, the same way the entries were stored.
	 *
	 * @param int $days The age in days.
	 *
	 * @return string
	 */
	private function get_limit( int $days ): string {
		$now = (int) strtotime( current_time( 'mysql' ) );

		return gmdate( 'Y-m-d H:i:s', $now - ( max( 1, $days ) * DAY_IN_SECONDS ) );
	}

	/**
	 * Return whether some post shows a fixed period of the now page.
	 *
	 * Only the shortcode can do this - no widget offers the custom range -
	 * so a look at the post content is enough.
	 *
	 * @return bool
	 */
	private function has_fixed_range_somewhere(): bool {
		$cache_key = 'now_pages_threadi_fixed_range_exists';
		$cached    = get_transient( $cache_key );

		// a stored value is either a 1 or a 0.
		if ( is_numeric( $cached ) ) {
			return 1 === absint( $cached );
		}

		// bail outside our settings page: only there the dialog with this warning is shown,
		// so the content search does not run on any other request.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		if ( ! is_admin() || ! isset( $_GET['page'] ) || Settings::get_instance()->get_settings_obj()->get_menu_slug() !== sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no API for searching content, the result is cached below.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND ( post_content LIKE %s OR post_content LIKE %s )",
				'%' . $wpdb->esc_like( 'period="range"' ) . '%',
				'%' . $wpdb->esc_like( "period='range'" ) . '%'
			)
		);

		$exists = absint( $count ) > 0;

		// short lived: this only decides whether a warning is shown, and a
		// full content search is not something to run on every page load.
		set_transient( $cache_key, absint( $exists ), HOUR_IN_SECONDS );

		return $exists;
	}
}
