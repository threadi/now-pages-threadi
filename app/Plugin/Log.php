<?php
/**
 * File for handling logging in this plugin.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Handler for logging in this plugin.
 */
class Log {
	/**
	 * The option holding the version of the table schema.
	 *
	 * @var string
	 */
	public const OPTION_DB_VERSION = 'threadi_now_pages_log_db_version';

	/**
	 * The option holding the time of the last cleanup of the log.
	 *
	 * @var string
	 */
	public const OPTION_LAST_CLEANUP = 'threadi_now_pages_log_last_cleanup';

	/**
	 * The actual version of the table schema.
	 *
	 * Raise it on every change of the schema in create_table().
	 *
	 * @var string
	 */
	private const DB_VERSION = '3';

	/**
	 * Minimal interval between two cleanups of the log in seconds.
	 *
	 * @var int
	 */
	private const CLEANUP_INTERVAL = HOUR_IN_SECONDS;

	/**
	 * The md5 hash.
	 *
	 * @var string
	 */
	private string $md5 = '';

	/**
	 * Instance of this object.
	 *
	 * @var ?Log
	 */
	private static ?Log $instance = null;

	/**
	 * Constructor for this object.
	 */
	public function __construct() {}

	/**
	 * Prevent cloning of this object.
	 *
	 * @return void
	 */
	private function __clone() { }

	/**
	 * Return the instance of this Singleton object.
	 */
	public static function get_instance(): Log {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Create the logging-table in the database.
	 *
	 * Also updates an existing table to the actual schema via dbDelta().
	 * A table of schema 2 gets its primary key this way: its IDs are unique
	 * already, as the column had a unique key.
	 *
	 * @return void
	 */
	public function create_table(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'threadi_now_pages_logs';

		// definition for the table for log-entries.
		// the primary key is needed on databases with "sql_require_primary_key", dbDelta() needs the two spaces.
		$sql = 'CREATE TABLE ' . $table_name . " (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `time` datetime DEFAULT '1970-01-01 00:00:00' NOT NULL,
            `log` longtext NOT NULL,
            `md5` text NOT NULL,
            `category` varchar(40) NOT NULL DEFAULT '',
            `state` varchar(40) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY time (time)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// bail if the table could not be created: without the version it is tried again.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- check for our own custom table.
		if ( $table_name !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) ) {
			return;
		}

		// remember the schema version of the table.
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, true );
	}

	/**
	 * Update the table to the actual schema, if it is outdated.
	 *
	 * Runs once after an update of the plugin, as the activation hook does not run then.
	 *
	 * @return void
	 */
	public function maybe_update_table(): void {
		// bail if the schema is up to date.
		if ( self::DB_VERSION === (string) get_option( self::OPTION_DB_VERSION, '' ) ) {
			return;
		}

		// create or update the table.
		$this->create_table();
	}

	/**
	 * Delete the logging-table in the database.
	 *
	 * @return void
	 */
	public function delete_table(): void {
		global $wpdb;

		$table_name = (string) esc_sql( $wpdb->prefix . 'threadi_now_pages_logs' ); // @phpstan-ignore cast.string

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Custom log table; fixed table name, esc_sql-escaped, identifier can't be a placeholder.
		$wpdb->query( sprintf( 'DROP TABLE IF EXISTS %s', $table_name ) );
	}

	/**
	 * Add a single log entry.
	 *
	 * If debug is disabled, we log only errors and successful import entries or system info.
	 *
	 * If debug is enabled:
	 * - successful import entries are always logged,
	 * - any entries in the configured debug categories are logged,
	 * - if no debug categories are configured, all entries are logged.
	 *
	 * @param string $log   The text to log.
	 * @param string $state The state to log.
	 * @param string $category The category for this log entry (optional).
	 * @param string $md5 Marker to identify unique entries (optional).
	 *
	 * @return void
	 */
	public function add( string $log, string $state, string $category = '', string $md5 = '' ): void {
		global $wpdb;

		// if debug is disabled, we only log errors.
		if ( 1 !== absint( get_option( 'threadi_now_pages_debug' ) ) ) {
			$is_error          = 'error' === $state;
			$is_import_success = ( 'import' === $category && 'success' === $state ) || ( 'system' === $category && 'info' === $state );

			$should_log = $is_error || $is_import_success;
			/**
			 * Filter whether a log entry should be written when debug mode is disabled.
			 *
			 * @since 1.0.0 Available since 1.0.0.
			 *
			 * @param bool   $should_log Whether the entry should be logged.
			 * @param string $category   The log entry category.
			 * @param string $state      The log entry state.
			 * @param string $log        The log message.
			 * @param string $md5        The unique marker.
			 */
			$should_log = apply_filters( 'threadi_now_pages_log_without_debug', $should_log, $category, $state, $log, $md5 );
		} else {
			// get the debug categories.
			$log_categories = get_option( 'threadi_now_pages_debug_categories' );

			// check if it is an array.
			if ( ! is_array( $log_categories ) ) {
				$log_categories = array();
			}

			// check if this is a success import entry.
			$is_import_success = 'import' === $category && 'success' === $state;

			// check if we should log this entry.
			$should_log = $is_import_success || empty( $log_categories ) || in_array( $category, $log_categories, true );

			/**
			 * Filter whether a log entry should be written when debug mode is enabled.
			 *
			 * @since 1.0.0 Available since 1.0.0.
			 *
			 * @param bool   $should_log Whether the entry should be logged.
			 * @param string $category   The log entry category.
			 * @param string $state      The log entry state.
			 * @param string $log        The log message.
			 * @param string $md5        The unique marker.
			 */
			$should_log = apply_filters( 'threadi_now_pages_log_with_debug', $should_log, $category, $state, $log, $md5 );
		}

		// bail if we should not log.
		if ( ! $should_log ) {
			return;
		}

		// add error counter if this is an error.
		if ( 'error' === $state ) {
			$error_counter = absint( get_option( 'threadi_now_pages_log_error_count' ) );
			++$error_counter;
			update_option( 'threadi_now_pages_log_error_count', $error_counter );
		}

		// insert the log entry.
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'threadi_now_pages_logs',
			array(
				'time'     => gmdate( 'Y-m-d H:i:s' ),
				'log'      => $log,
				'md5'      => $md5,
				'category' => $category,
				'state'    => $state,
			)
		);

		// clean the log, if the last cleanup is long enough ago.
		$this->maybe_clean_log();
	}

	/**
	 * Clean the log, but at most once per CLEANUP_INTERVAL.
	 *
	 * @return void
	 */
	private function maybe_clean_log(): void {
		// bail if the last cleanup is not long enough ago.
		if ( absint( get_option( self::OPTION_LAST_CLEANUP, 0 ) ) > time() - self::CLEANUP_INTERVAL ) {
			return;
		}

		// remember the time of this cleanup before running it, so a failing cleanup is not repeated on every entry.
		update_option( self::OPTION_LAST_CLEANUP, time(), true );

		// clean the log.
		$this->clean_log();
	}

	/**
	 * Delete all entries that are older than X days.
	 *
	 * @return void
	 * @noinspection PhpUnused
	 */
	public function clean_log(): void {
		// prevent re-entry: on a log-table error add() calls clean_log() again → infinite loop.
		static $is_running = false;
		if ( $is_running ) {
			return;
		}

		// bail on uninstalling.
		if ( defined( 'NOW_PAGES_DEACTIVATION_RUNNING' ) ) {
			return;
		}

		global $wpdb;

		$is_running = true;

		$table_name = (string) esc_sql( $wpdb->prefix . 'threadi_now_pages_logs' ); // @phpstan-ignore cast.string

		// get the max age in days, keep the entries at least one day: 0 would otherwise delete every entry.
		$max_age = max( 1, absint( get_option( 'threadi_now_pages_max_age_log_entries', 20 ) ) );

		// calculate the limit in GMT, as the entries are saved in GMT.
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $max_age * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Custom log table; fixed table name, esc_sql-escaped, identifier can't be a placeholder.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM `' . $table_name . '` WHERE `time` < %s LIMIT 10000', $cutoff ) );

		// log if any error occurred.
		if ( ! empty( $wpdb->last_error ) ) {
			/* translators: %1$s will be replaced by a DB-error-message. */
			$this->add( sprintf( __( 'Database error: %1$s - This usually indicates that the database system of your hosting does not meet the minimum requirements of WordPress. Please contact your hosts support team for clarification.', 'threadi-now-pages' ), '<code>' . esc_html( $wpdb->last_error ) . '</code>' ), 'error', 'system' );
		}

		$is_running = false;
	}

	/**
	 * Return the list of categories with an internal name and their label.
	 *
	 * @return array<string,string>
	 */
	public function get_categories(): array {
		$list = array(
			'system' => __( 'System', 'threadi-now-pages' ),
		);

		/**
		 * Filter the list of possible log categories.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<string,string> $list List of categories.
		 */
		return apply_filters( 'threadi_now_pages_log_categories', $list );
	}

	/**
	 * Get log entries depending on some filters.
	 *
	 * Use for each possible condition own statements to match WCS.
	 *
	 * @return array<int,mixed>
	 */
	public function get_entries(): array {
		global $wpdb;

		// order table.
		$allowed   = array( 'date', 'category', 'state' );
		$order_by  = 'date';
		$requested = filter_input( INPUT_GET, 'orderby', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( is_string( $requested ) && in_array( $requested, $allowed, true ) ) {
			$order_by = $requested;
		}
		$order = strtoupper( (string) filter_input( INPUT_GET, 'order', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
		$order = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';

		$limit = 10000;
		/**
		 * Filter limit to prevent possible errors on big tables.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param int $limit The actual limit.
		 */
		$limit = apply_filters( 'threadi_now_pages_log_limit', $limit );

		// get filter.
		$category = (string) filter_input( INPUT_GET, 'category', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		/**
		 * Filter the used category.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param string $category The category to use.
		 */
		$category = (string) apply_filters( 'threadi_now_pages_log_category', $category );

		// get md5.
		$md5 = (string) filter_input( INPUT_GET, 'md5', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		// if the request is empty, get md5 from the object if set.
		if ( empty( $md5 ) ) {
			$md5 = $this->get_md5();
		}

		/**
		 * Filter the used md5.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param string $md5 The md5 to use.
		 */
		$md5 = (string) apply_filters( 'threadi_now_pages_log_md5', $md5 );

		// get errors.
		$errors = absint( filter_input( INPUT_GET, 'errors', FILTER_SANITIZE_NUMBER_INT ) );

		/**
		 * Filter for errors.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param int $errors Should be 1 to filter only for errors.
		 */
		$errors = apply_filters( 'threadi_now_pages_log_errors', $errors );

		// add where-condition for errors.
		$where = '';
		if ( 1 === $errors ) {
			$where .= ' AND `state` = "error"';
		}

		// if only category is set.
		if ( ! empty( $category ) && empty( $md5 ) ) {
			// get and return the entries.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $order_by/$order are whitelisted identifiers, $where is a fixed literal; values use %s/%d.
			return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT `state`, `time` AS `date`, `log`, `category`
                    FROM `' . $wpdb->prefix . 'threadi_now_pages_logs`
                    WHERE `category` = %s' . $where . '
                    ORDER BY ' . $order_by . ' ' . $order . ', `id` ' . $order . '
                    LIMIT %d',
					array( $category, $limit )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		// if only md5 is set.
		if ( empty( $category ) && ! empty( $md5 ) ) {
			// get and return the entries.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $order_by/$order are whitelisted identifiers, $where is a fixed literal; values use %s/%d.
			return $wpdb->get_results(
				$wpdb->prepare(
					'SELECT `state`, `time` AS `date`, `log`, `category`
                    FROM `' . $wpdb->prefix . 'threadi_now_pages_logs`
                    WHERE `md5` = %s' . $where . '
                    ORDER BY ' . $order_by . ' ' . $order . ', `id` ' . $order . '
                    LIMIT %d',
					array( $md5, $limit )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		// if both are set.
		if ( ! empty( $category ) ) {
			// get and return the entries.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $order_by/$order are whitelisted identifiers, $where is a fixed literal; values use %s/%d.
			return $wpdb->get_results(
				$wpdb->prepare(
					'SELECT `state`, `time` AS `date`, `log`, `category`
                    FROM `' . $wpdb->prefix . 'threadi_now_pages_logs`
                    WHERE `md5` = %s AND `category` = %s' . $where . '
                    ORDER BY ' . $order_by . ' ' . $order . ', `id` ' . $order . '
                    LIMIT %d',
					array( $md5, $category, $limit )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		if ( 1 === $errors ) {
			// return all.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $order_by/$order are whitelisted identifiers, $where is a fixed literal; values use %s/%d.
			return $wpdb->get_results(
				$wpdb->prepare(
					'SELECT `state`, `time` AS `date`, `log`, `category`
                FROM `' . $wpdb->prefix . 'threadi_now_pages_logs`
                WHERE `state` = "error"
                ORDER BY ' . $order_by . ' ' . $order . ', `id` ' . $order . '
                LIMIT %d',
					array( $limit )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		// return all.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $order_by/$order are whitelisted identifiers, $where is a fixed literal; values use %s/%d.
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT `state`, `time` AS `date`, `log`, `category`
                FROM `' . $wpdb->prefix . 'threadi_now_pages_logs`
                ORDER BY ' . $order_by . ' ' . $order . ', `id` ' . $order . '
                LIMIT %d',
				array( $limit )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Return md5 hash.
	 *
	 * @return string
	 */
	private function get_md5(): string {
		return $this->md5;
	}

	/**
	 * Set the md5 hash.
	 *
	 * @param string $md5 The md5 hash.
	 *
	 * @return void
	 */
	public function set_md5( string $md5 ): void {
		$this->md5 = $md5;
	}
}
