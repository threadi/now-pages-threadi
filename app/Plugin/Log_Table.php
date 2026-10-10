<?php
/**
 * File for handling the table of logs in this plugin.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Helper\DateTime;
use NowPagesThreadi\Helper\Format;
use WP_List_Table;

/**
 * Handler for log-output in the backend.
 */
class Log_Table extends WP_List_Table {
	/**
	 * Override the parent columns method. Defines the columns to use in your listing table
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return array(
			'state'    => __( 'State', 'now-pages-threadi' ),
			'date'     => __( 'Date', 'now-pages-threadi' ),
			'log'      => __( 'Log', 'now-pages-threadi' ),
			'category' => __( 'Category', 'now-pages-threadi' ),
		);
	}

	/**
	 * Get the table data
	 *
	 * @return array<int,mixed>
	 */
	private function table_data(): array {
		return Log::get_instance()->get_entries();
	}

	/**
	 * Get the log-table for the table-view.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$columns  = $this->get_columns();
		$hidden   = $this->get_hidden_columns();
		$sortable = $this->get_sortable_columns();

		$data = $this->table_data();

		$per_page     = 100;
		$current_page = $this->get_pagenum();
		$total_items  = count( $data );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
			)
		);

		$data = array_slice( $data, ( ( $current_page - 1 ) * $per_page ), $per_page );

		$this->_column_headers = array( $columns, $hidden, $sortable );
		$this->items           = $data;
	}

	/**
	 * Define which columns are hidden
	 *
	 * @return array<string>
	 */
	public function get_hidden_columns(): array {
		return array();
	}

	/**
	 * Define the sortable columns
	 *
	 * @return array<string,mixed>
	 */
	public function get_sortable_columns(): array {
		return array( 'date' => array( 'date', false ) );
	}

	/**
	 * Define what data to show on each column of the table
	 *
	 * @param  array<string,string> $item        Data.
	 * @param  string               $column_name - Current column name.
	 *
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		return match ( $column_name ) {
			'date' => DateTime::get_format_date_time( $item[ $column_name ] ),
			'state' => $this->get_status_icon( $item[ $column_name ] ),
			'log' => nl2br( wp_kses_post( $item[ $column_name ] ) ),
			'category' => empty( $item[ $column_name ] ) ? '<i>' . esc_html__( 'not defined', 'now-pages-threadi' ) . '</i>' : esc_html( $this->get_category( $item[ $column_name ] ) ),
			default => '',
		};
	}

	/**
	 * Get a single category.
	 *
	 * @param string $category The searched category.
	 *
	 * @return string
	 */
	private function get_category( string $category ): string {
		// get the list of categories.
		$categories = Log::get_instance()->get_categories();

		// bail if the searched category is not found.
		if ( empty( $categories[ $category ] ) ) {
			return '<i>' . esc_html__( 'Unknown', 'now-pages-threadi' ) . '</i>';
		}

		// return the category-label.
		return $categories[ $category ];
	}

	/**
	 * Add export- and delete-buttons on top of the table.
	 *
	 * @param string $which The position.
	 * @return void
	 */
	public function extra_tablenav( $which ): void {
		if ( 'top' === $which ) {
			// define hint text.
			$contains = '<p>' . __( 'The file will contain ALL entries. Be aware of this before you send this file to someone.', 'now-pages-threadi' ) . '</p>';

			// define export-URL.
			$download_url = add_query_arg(
				array(
					'action' => 'now_pages_threadi_log_export',
					'nonce'  => wp_create_nonce( 'now-pages-threadi-log-export' ),
				),
				get_admin_url() . 'admin.php'
			);

			// get filter.
			$category = $this->get_category_filter();
			if ( ! empty( $category ) ) {
				$download_url = add_query_arg( array( 'category' => $category ), $download_url );
				$contains     = '<p>' . __( 'The file will contain ALL entries of the chosen filter. Be aware of this before you send this file to someone.', 'now-pages-threadi' ) . '</p>';
			}

			// get md5.
			$md5 = $this->get_md5_filter();
			if ( ! empty( $md5 ) ) {
				$download_url = add_query_arg( array( 'md5' => $md5 ), $download_url );
				$contains     = '<p>' . __( 'The file will contain ALL entries of the chosen filter. Be aware of this before you send this file to someone.', 'now-pages-threadi' ) . '</p>';
			}

			// create download-dialog.
			$download_dialog = array(
				'title'   => __( 'Export log entries', 'now-pages-threadi' ),
				'texts'   => array(
					'<p>' . __( 'Click on the button below to download the log entries as CSV.', 'now-pages-threadi' ) . '</p>',
					$contains,
				),
				'buttons' => array(
					array(
						'action'  => 'location.href="' . esc_url( $download_url ) . '";closeDialog();',
						'variant' => 'primary',
						'text'    => __( 'Export log entries', 'now-pages-threadi' ),
					),
					array(
						'action'  => 'closeDialog();',
						'variant' => 'secondary',
						'text'    => __( 'Cancel', 'now-pages-threadi' ),
					),
				),
			);

			// define empty-URL.
			$empty_url = add_query_arg(
				array(
					'action' => 'now_pages_threadi_log_empty',
					'nonce'  => wp_create_nonce( 'now-pages-threadi-log-empty' ),
				),
				get_admin_url() . 'admin.php'
			);

			// create empty-log-dialog.
			$empty_dialog = array(
				'title'   => __( 'Empty log entries', 'now-pages-threadi' ),
				'texts'   => array(
					'<p><strong>' . __( 'Are you sure you want to empty the log?', 'now-pages-threadi' ) . '</strong></p>',
					'<p>' . __( 'You will lose any log until now.', 'now-pages-threadi' ) . '</p>',
				),
				'buttons' => array(
					array(
						'action'  => 'location.href="' . esc_url( $empty_url ) . '";',
						'variant' => 'primary',
						'text'    => __( 'Yes, empty the log', 'now-pages-threadi' ),
					),
					array(
						'action'  => 'closeDialog();',
						'variant' => 'secondary',
						'text'    => __( 'Cancel', 'now-pages-threadi' ),
					),
				),
			);

			?>
			<a href="<?php echo esc_url( $download_url ); ?>" class="button button-secondary easy-dialog-for-wordpress<?php echo ( 0 === count( $this->items ) ? ' disabled' : '' ); ?>" data-dialog="<?php echo esc_attr( Format::get_json( $download_dialog ) ); ?>"><?php echo esc_html__( 'Export as CSV', 'now-pages-threadi' ); ?></a>
			<a href="<?php echo esc_url( $empty_url ); ?>" class="button button-secondary easy-dialog-for-wordpress<?php echo ( 0 === count( $this->items ) ? ' disabled' : '' ); ?>" data-dialog="<?php echo esc_attr( Format::get_json( $empty_dialog ) ); ?>"><?php echo esc_html__( 'Empty the log', 'now-pages-threadi' ); ?></a>
			<?php

			// show button only if setting is enabled.
			if ( 1 === absint( get_option( 'now_pages_threadi_enable_log_error_count' ) ) ) {
				// define reset-marker-URL.
				$reset_error_marker_url = add_query_arg(
					array(
						'action' => 'now_pages_threadi_log_reset_marker',
						'nonce'  => wp_create_nonce( 'now-pages-threadi-log-reset-marker' ),
					),
					get_admin_url() . 'admin.php'
				);

				// create reset-marker-dialog.
				$reset_error_marker_dialog = array(
					'title'   => __( 'Reset error marker', 'now-pages-threadi' ),
					'texts'   => array(
						'<p><strong>' . __( 'Are you sure you want to reset the error marker?', 'now-pages-threadi' ) . '</strong></p>',
						'<p>' . __( 'As soon as a new error occurs, the marker reappears on the screen..', 'now-pages-threadi' ) . '</p>',
					),
					'buttons' => array(
						array(
							'action'  => 'location.href="' . esc_url( $reset_error_marker_url ) . '";',
							'variant' => 'primary',
							'text'    => __( 'Yes, reset the marker', 'now-pages-threadi' ),
						),
						array(
							'action'  => 'closeDialog();',
							'variant' => 'secondary',
							'text'    => __( 'Cancel', 'now-pages-threadi' ),
						),
					),
				);

				// get the error count.
				$errors = absint( get_option( 'now_pages_threadi_log_error_count' ) );

				?>
					<a href="<?php echo esc_url( $reset_error_marker_url ); ?>" class="button button-secondary easy-dialog-for-wordpress<?php echo ( 0 === count( $this->items ) || 0 === $errors ? ' disabled' : '' ); ?>" data-dialog="<?php echo esc_attr( Format::get_json( $reset_error_marker_dialog ) ); ?>"><?php echo esc_html__( 'Reset error marker', 'now-pages-threadi' ); ?></a>
				<?php
			}
		}
	}

	/**
	 * Message to be displayed when there are no items.
	 *
	 * @since 1.0.0
	 */
	public function no_items(): void {
		// get actual filter.
		$category = $this->get_category_filter();

		// if filter is set show other text.
		if ( ! empty( $category ) ) {
			// get all categories to get the title.
			$categories = Log::get_instance()->get_categories();

			// show text.
			/* translators: %1$s will be replaced by the category name. */
			printf( esc_html__( 'No log entries for %1$s found.', 'now-pages-threadi' ), esc_html( $categories[ $category ] ?? $category ) );
			return;
		}

		// show default text.
		echo esc_html__( 'No log entries found.', 'now-pages-threadi' );
	}

	/**
	 * Define filter for categories.
	 *
	 * @return array<string,string>
	 */
	protected function get_views(): array {
		// get the main url without the filter.
		$url = remove_query_arg( array( 'category', 'md5', 'errors' ) );

		// get actual filter.
		$category = $this->get_category_filter();

		// define initial list.
		$list = array(
			'all' => '<a href="' . esc_url( $url ) . '"' . ( empty( $category ) ? ' class="current"' : '' ) . '>' . esc_html__( 'All', 'now-pages-threadi' ) . '</a>',
		);

		// get all log categories.
		$log_obj = Log::get_instance();
		foreach ( $log_obj->get_categories() as $key => $label ) {
			$url          = add_query_arg( array( 'category' => $key ) );
			$list[ $key ] = '<a href="' . esc_url( $url ) . '"' . ( $category === $key ? ' class="current"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}

		// add filter for errors.
		$url            = add_query_arg( array( 'errors' => 1 ) );
		$list['errors'] = '<a href="' . esc_url( $url ) . '"' . ( 1 === absint( filter_input( INPUT_GET, 'errors', FILTER_SANITIZE_NUMBER_INT ) ) ? ' class="current"' : '' ) . '>' . esc_html__( 'Errors', 'now-pages-threadi' ) . '</a>';

		/**
		 * Filter the list before output.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param array<string,string> $list List of filter.
		 */
		return apply_filters( 'now_pages_threadi_log_table_filter', $list );
	}

	/**
	 * Get actual category-filter-value.
	 *
	 * @return string
	 */
	private function get_category_filter(): string {
		// get category from request.
		$category = filter_input( INPUT_GET, 'category', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		// return empty if nothing has been in request, or not a single value (e.g. an array).
		if ( ! is_string( $category ) ) {
			return '';
		}

		// return the category.
		return $category;
	}

	/**
	 * Get actual category-filter-value.
	 *
	 * @return string
	 */
	private function get_md5_filter(): string {
		// get md5 from request.
		$md5 = filter_input( INPUT_GET, 'md5', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		// return empty if nothing has been in request, or not a single value (e.g. an array).
		if ( ! is_string( $md5 ) ) {
			return '';
		}

		// return the md5 hash.
		return $md5;
	}

	/**
	 * Return the HTML code for the icon of the given status.
	 *
	 * @param string $status The requested status.
	 *
	 * @return string
	 */
	private function get_status_icon( string $status ): string {
		$list = array(
			'success' => '<span class="dashicons dashicons-yes" title="' . __( 'Ended successfully', 'now-pages-threadi' ) . '"></span>',
			'info'    => '<span class="dashicons dashicons-info-outline" title="' . __( 'Just an info', 'now-pages-threadi' ) . '"></span>',
			'error'   => '<span class="dashicons dashicons-no" title="' . __( 'Error occurred', 'now-pages-threadi' ) . '"></span>',
		);

		/**
		 * Filter the list of possible states in the log table.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 */
		$list = apply_filters( 'now_pages_threadi_status_list', $list );

		// bail if status is unknown.
		if ( empty( $list[ $status ] ) ) {
			return '';
		}

		// return the HTML code for the icon of this status.
		return $list[ $status ];
	}
}
