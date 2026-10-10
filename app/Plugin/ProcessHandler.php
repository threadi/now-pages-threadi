<?php
/**
 * File for an object to handle AJAX-driven processes.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Helper\Plugin;

/**
 * Object to handle AJAX-driven processes.
 */
class ProcessHandler {
	/**
	 * Prefix of the transients which hold the values of a process.
	 */
	private const TRANSIENT_PREFIX = 'now_pages_threadi_process_';

	/**
	 * Seconds the values of a process are kept.
	 */
	private const TTL = HOUR_IN_SECONDS;

	/**
	 * Seconds after which a running process is treated as failed.
	 */
	private const STALE_AFTER = 10 * MINUTE_IN_SECONDS;

	/**
	 * Name of the option used by older versions for all process values.
	 */
	private const LEGACY_OPTION = 'now_pages_threadi_process_values';

	/**
	 * The process ID for the actual request.
	 *
	 * @var string
	 */
	private string $process_id = '';

	/**
	 * Instance of this object.
	 *
	 * @var ?ProcessHandler
	 */
	private static ?ProcessHandler $instance = null;

	/**
	 * Constructor, which sets the active method.
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
	public static function get_instance(): ProcessHandler {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'add_js' ), 10, 0 );
		add_action( 'wp_ajax_now_pages_threadi_process_info', array( $this, 'get_process_info_by_ajax' ) );
		add_action( 'wp_ajax_now_pages_threadi_get_import_dialog', array( $this, 'get_import_dialog_by_ajax' ) );
		add_action( 'admin_init', array( $this, 'delete_legacy_option' ) );
	}

	/**
	 * Delete the option older versions used for all process values.
	 *
	 * @return void
	 */
	public function delete_legacy_option(): void {
		// bail if the option does not exist.
		if ( false === get_option( self::LEGACY_OPTION, false ) ) {
			return;
		}

		delete_option( self::LEGACY_OPTION );
	}

	/**
	 * Add our own JS and CSS in the backend.
	 *
	 * @return void
	 */
	public function add_js(): void {
		// backend-JS.
		wp_enqueue_script(
			'now-pages-threadi-processhandler-admin',
			Plugin::get_plugin_url() . 'admin/progresshandler.js',
			array( 'jquery', 'now-pages-threadi-admin' ),
			Plugin::get_file_version( Plugin::get_plugin_path() . 'admin/progresshandler.js' ),
			true
		);

		// add php-vars to our js-script.
		wp_localize_script(
			'now-pages-threadi-processhandler-admin',
			'nowPagesThreadiProgressHandlerJsVars',
			array(
				'ajax_url'           => admin_url( 'admin-ajax.php' ),
				'test_email_nonce'   => wp_create_nonce( 'now-pages-threadi-test-email' ),
				'start_nonce'        => wp_create_nonce( 'now-pages-threadi-import-start' ),
				'process_info_nonce' => wp_create_nonce( 'get-now-pages-threadi-process-info' ),
				'title'              => __( 'Import new entries', 'now-pages-threadi' ),
			)
		);
	}

	/**
	 * Return a unique ID for the current process.
	 *
	 * @return string
	 */
	public function create_id(): string {
		return wp_generate_uuid4();
	}

	/**
	 * Return the process info via AJAX.
	 *
	 * @return void
	 */
	public function get_process_info_by_ajax(): void {
		// check nonce.
		check_ajax_referer( 'get-now-pages-threadi-process-info', 'nonce' );

		// bail if the capability check fails.
		if ( ! current_user_can( Settings::get_instance()->get_settings_obj()->get_capability() ) ) {
			return;
		}

		// get the process ID from request.
		$process_id = filter_input( INPUT_POST, 'process_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		// bail if no process ID is set.
		if ( empty( $process_id ) ) {
			wp_send_json_error();
		}

		// set the process ID on the object.
		$this->set_id( $process_id );

		// get the state of the process.
		$running = absint( $this->get( 'running' ) );
		$message = $this->get_message();

		// a process running for too long has failed, e.g. because of a fatal error.
		if ( $running > 0 && ( time() - $running ) > self::STALE_AFTER ) {
			$running = 0;

			if ( empty( $message ) ) {
				$message = $this->get_failure_dialog();
			}
		}

		// collect the response. the values are kept until their transient
		// expires, so a late request still gets the final result.
		$response = array(
			absint( $this->get( 'count' ) ),
			absint( $this->get( 'max_count' ) ),
			$running,
			wp_kses_post( (string) $this->get( 'status' ) ),
			wp_json_encode( $message ),
		);

		// return the actual process info.
		wp_send_json( $response );
	}

	/**
	 * Return the name of the transient holding the values of the actual process.
	 *
	 * @return string
	 */
	private function get_transient_name(): string {
		return self::TRANSIENT_PREFIX . md5( $this->get_id() );
	}

	/**
	 * Return all values of the actual process.
	 *
	 * @return array<string,mixed>
	 */
	private function get_values(): array {
		$values = get_transient( $this->get_transient_name() );

		// if this is not an array, create it.
		if ( ! is_array( $values ) ) {
			$values = array();
		}

		return $values;
	}

	/**
	 * Set a value for the actual process.
	 *
	 * @param string $key The key.
	 * @param mixed  $value The value.
	 *
	 * @return void
	 */
	private function set( string $key, mixed $value ): void {
		// bail if no process ID is set.
		if ( empty( $this->get_id() ) ) {
			return;
		}

		// get the actual values for the actual process ID.
		$values = $this->get_values();

		// add or update the given key with its value.
		$values[ $key ] = $value;

		// save it, it expires automatically.
		set_transient( $this->get_transient_name(), $values, self::TTL );
	}

	/**
	 * Return the value for the given key.
	 *
	 * @param string $key The key.
	 *
	 * @return mixed
	 */
	private function get( string $key ): mixed {
		// bail if no process ID is set.
		if ( empty( $this->get_id() ) ) {
			return '';
		}

		// get the values.
		$values = $this->get_values();

		// bail if the requested key is not set.
		if ( ! isset( $values[ $key ] ) ) {
			return '';
		}

		// return the value.
		return $values[ $key ];
	}

	/**
	 * Return the process ID for the actual request.
	 *
	 * @return string
	 */
	private function get_id(): string {
		return $this->process_id;
	}

	/**
	 * Set the process ID for the actual request.
	 *
	 * @param string $process_id The process ID.
	 *
	 * @return void
	 */
	public function set_id( string $process_id ): void {
		if ( empty( $process_id ) && ! empty( $this->process_id ) ) {
			return;
		}
		$this->process_id = $process_id;
	}

	/**
	 * Return the count of the actual process.
	 *
	 * @return int
	 */
	public function get_count(): int {
		return absint( $this->get( 'count' ) );
	}

	/**
	 * Set the count of the actual process.
	 *
	 * @param int $count The count.
	 *
	 * @return void
	 */
	public function set_count( int $count ): void {
		$this->set( 'count', $count );
	}

	/**
	 * Update the count of the actual process with the given value.
	 *
	 * @param int $count The count.
	 *
	 * @return void
	 */
	public function update_count( int $count ): void {
		// get the actual count.
		$actual_count = absint( $this->get( 'count' ) );
		$this->set( 'count', $actual_count + $count );
	}

	/**
	 * Return the max count of the actual process.
	 *
	 * @return int
	 */
	public function get_max_count(): int {
		return absint( $this->get( 'max_count' ) );
	}

	/**
	 * Set the max count of the actual process.
	 *
	 * @param int $count The max count.
	 *
	 * @return void
	 */
	public function set_max_count( int $count ): void {
		$this->set( 'max_count', $count );
	}

	/**
	 * Set the status of the actual process.
	 *
	 * @param string $status The status.
	 *
	 * @return void
	 */
	public function set_status( string $status ): void {
		$this->set( 'status', $status );
	}

	/**
	 * Set the time then the actual process startet.
	 *
	 * @param int $time The time when it started.
	 *
	 * @return void
	 */
	public function set_running( int $time ): void {
		$this->set( 'running', $time );
	}

	/**
	 * Return the message as an array for the library easy-dialog-for-wordpress.
	 *
	 * @return array<string,mixed>
	 */
	private function get_message(): array {
		// get the value.
		$value = $this->get( 'message' );

		// if the value is not set, return an empty array.
		if ( empty( $value ) ) {
			return array();
		}

		// if the value is not an array, return an empty array.
		if ( ! is_array( $value ) ) {
			return array();
		}

		// return the value.
		return $value;
	}

	/**
	 * Set the message for the actual process.
	 *
	 * @param array<string,mixed> $message The message.
	 *
	 * @return void
	 */
	public function set_message( array $message ): void {
		$this->set( 'message', $message );
	}

	/**
	 * Return the dialog shown if a process failed without a result.
	 *
	 * @return array<string,mixed>
	 */
	private function get_failure_dialog(): array {
		return array(
			'detail' => array(
				'title'   => __( 'The process has not been completed', 'now-pages-threadi' ),
				'texts'   => array(
					'<p>' . __( 'The process did not report a result for a long time. It may have been stopped by an error. Please check the log.', 'now-pages-threadi' ) . '</p>',
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
	}

	/**
	 * Return the import dialog via AJAX.
	 *
	 * @return void
	 */
	public function get_import_dialog_by_ajax(): void {
		// check nonce.
		check_ajax_referer( 'now-pages-threadi-get-import-dialog', 'nonce' );

		// bail if the capability check fails.
		if ( ! current_user_can( Settings::get_instance()->get_settings_obj()->get_capability() ) ) {
			return;
		}

		// return the dialog.
		wp_send_json( array( 'detail' => $this->get_import_dialog() ) );
	}

	/**
	 * Return the prepared import dialog.
	 *
	 * @return array<string,mixed>
	 */
	public function get_import_dialog(): array {
		return array(
			'title'   => __( 'Import your entries', 'now-pages-threadi' ),
			'texts'   => array(
				'<p><strong>' . __( 'Do you really want to import new entries from your sources?', 'now-pages-threadi' ) . '</strong></p>',
				'<p>' . __( 'This will update the entries for display on your Now Page.', 'now-pages-threadi' ) . '</p>',
				'<p>' . __( 'You do not have to do this manually, since the process is automated for you as well.', 'now-pages-threadi' ) . '</p>',
			),
			'buttons' => array(
				array(
					'action'  => 'now_pages_threadi_import("' . esc_attr( self::get_instance()->create_id() ) . '");',
					'variant' => 'primary',
					'text'    => __( 'Yes, import them', 'now-pages-threadi' ),
				),
				array(
					'action'  => 'closeDialog();',
					'variant' => 'primary',
					'text'    => __( 'Cancel', 'now-pages-threadi' ),
				),
			),
		);
	}
}
