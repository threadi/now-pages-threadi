<?php
/**
 * Per-user display settings (profile fields).
 *
 * Same keys as the global options; empty = inherit from global / default.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Digests\Display_Settings;
use NowPagesThreadi\Helper\Plugin;
use NowPagesThreadi\NowPages\Entries;
use NowPagesThreadi\NowPages\Frontend;
use NowPagesThreadi\Sources\Sources;
use WP_User;

/**
 * Adds and saves the display options on the user profile screen.
 */
class User_Settings {

	/**
	 * Instance of this object.
	 *
	 * @var ?User_Settings
	 */
	private static ?User_Settings $instance = null;

	/**
	 * Constructor, not used as this a Singleton object.
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
	public static function get_instance(): User_Settings {
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
		add_action( 'show_user_profile', array( $this, 'render_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'render_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_fields' ) );
		add_filter( 'manage_users_columns', array( $this, 'manage_users_columns' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'manage_users_custom_column' ), 10, 3 );
	}

	/**
	 * Render the Now Pages section on the profile form.
	 *
	 * @param WP_User $user The user being edited.
	 *
	 * @return void
	 */
	public function render_fields( WP_User $user ): void {
		// bail if capability is not set.
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		// the opt-out is shown to every user, as the posts and comments of every user
		// could be imported. the privacy settings need the plugin capabilities, the
		// display settings also need own now pages.
		$show_privacy = $this->current_user_can_manage_privacy();
		$show_display = $this->current_user_can_display();

		$defaults = Display_Settings::defaults();
		$keys     = Display_Settings::user_meta_keys();

		$disabled          = get_user_meta( $user->ID, $keys['disabled'], true );
		$show_time         = get_user_meta( $user->ID, $keys['show_time'], true );
		$show_source       = get_user_meta( $user->ID, $keys['show_source'], true );
		$link_source       = get_user_meta( $user->ID, $keys['link_source'], true );
		$group_by_category = get_user_meta( $user->ID, $keys['group_by_category'], true );
		$delay_hours       = get_user_meta( $user->ID, $keys['delay_hours'], true );
		$order             = get_user_meta( $user->ID, $keys['order'], true );

		// Empty string means “inherit”; convert stored values for the form.
		$disabled_val          = ( '' === $disabled ) ? '' : ( '1' === $disabled ? ' checked' : '' );
		$show_time_val         = ( '' === $show_time ) ? '' : ( $show_time ? '1' : '0' );
		$show_source_val       = ( '' === $show_source ) ? '' : ( $show_source ? '1' : '0' );
		$link_source_val       = ( '' === $link_source ) ? '' : ( $link_source ? '1' : '0' );
		$group_by_category_val = ( '' === $group_by_category ) ? '' : ( $group_by_category ? '1' : '0' );
		$delay_hours_val       = ( '' === $delay_hours ) ? '' : (string) absint( $delay_hours );
		$order_val             = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : '';

		// get the user URL.
		$user_url = $this->get_user_now_page_url( $user->ID );

		?>
		<h2><?php esc_html_e( 'Now Pages – Display settings', 'now-pages-threadi' ); ?></h2>
		<?php
		if ( $show_display ) {
			?>
			<p class="description">
				<?php
				/* translators: %1$s will be replaced by an URL. */
				echo wp_kses_post( sprintf( __( 'When enabled, the content of your Now Page will be displayed under %1$s. Adjust the settings below to suit your needs.', 'now-pages-threadi' ), '<a href="' . esc_url( $user_url ) . '" target="_blank">' . esc_url( $user_url ) . '</a>' ) );
				?>
			</p>
			<?php
		}
		// mark this form as ours, the settings are only saved if this field is sent.
		wp_nonce_field( 'now-pages-threadi-user-settings', 'now_pages_threadi_user_settings_nonce', false );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="now_pages_threadi_disable_now_page"><?php esc_html_e( 'Disable my now page', 'now-pages-threadi' ); ?></label></th>
				<td>
					<input type="checkbox" name="now_pages_threadi_disable_now_page" id="now_pages_threadi_disable_now_page" value="1"<?php echo esc_attr( $disabled_val ); ?>>
				</td>
			</tr>
			<?php
			// bail if the privacy settings are not available.
			if ( ! $show_privacy ) {
				?>
				</table>
				<?php
				return;
			}
			?>
			<tr>
				<th><label for="now_pages_threadi_show_time"><?php esc_html_e( 'Show times', 'now-pages-threadi' ); ?></label></th>
				<td>
					<select name="now_pages_threadi_show_time" id="now_pages_threadi_show_time">
						<option value="" <?php selected( $show_time_val, '' ); ?>><?php esc_html_e( '- inherit global -', 'now-pages-threadi' ); ?></option>
						<option value="1" <?php selected( $show_time_val, '1' ); ?>><?php esc_html_e( 'Yes', 'now-pages-threadi' ); ?></option>
						<option value="0" <?php selected( $show_time_val, '0' ); ?>><?php esc_html_e( 'No', 'now-pages-threadi' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="now_pages_threadi_delay_hours"><?php esc_html_e( 'Delay (hours)', 'now-pages-threadi' ); ?></label></th>
				<td>
					<input type="number" min="0" step="1" name="now_pages_threadi_delay_hours" id="now_pages_threadi_delay_hours"
						value="<?php echo esc_attr( $delay_hours_val ); ?>"
						placeholder="<?php echo esc_attr( (string) $defaults['delay_hours'] ); ?>"
						max="<?php echo esc_attr( (string) Display_Settings::MAX_HOURS ); ?>"
						class="small-text" />
					<p class="description"><?php esc_html_e( 'Leave empty to inherit the site-wide delay. 0 = live.', 'now-pages-threadi' ); ?></p>
				</td>
			</tr>
			<?php
			// bail if the display settings are not available.
			if ( ! $show_display ) {
				?>
				</table>
				<?php
				return;
			}
			?>
			<tr>
				<th><label for="now_pages_threadi_show_source"><?php esc_html_e( 'Show data source', 'now-pages-threadi' ); ?></label></th>
				<td>
					<select name="now_pages_threadi_show_source" id="now_pages_threadi_show_source">
						<option value="" <?php selected( $show_source_val, '' ); ?>><?php esc_html_e( '- inherit global -', 'now-pages-threadi' ); ?></option>
						<option value="1" <?php selected( $show_source_val, '1' ); ?>><?php esc_html_e( 'Yes', 'now-pages-threadi' ); ?></option>
						<option value="0" <?php selected( $show_source_val, '0' ); ?>><?php esc_html_e( 'No', 'now-pages-threadi' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="now_pages_threadi_link_source"><?php esc_html_e( 'Link data source', 'now-pages-threadi' ); ?></label></th>
				<td>
					<select name="now_pages_threadi_link_source" id="now_pages_threadi_link_source">
						<option value="" <?php selected( $link_source_val, '' ); ?>><?php esc_html_e( '- inherit global -', 'now-pages-threadi' ); ?></option>
						<option value="1" <?php selected( $link_source_val, '1' ); ?>><?php esc_html_e( 'Yes', 'now-pages-threadi' ); ?></option>
						<option value="0" <?php selected( $link_source_val, '0' ); ?>><?php esc_html_e( 'No', 'now-pages-threadi' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="now_pages_threadi_group_by_category"><?php esc_html_e( 'Group by category', 'now-pages-threadi' ); ?></label></th>
				<td>
					<select name="now_pages_threadi_group_by_category" id="now_pages_threadi_group_by_category">
						<option value="" <?php selected( $group_by_category_val, '' ); ?>><?php esc_html_e( '- inherit global -', 'now-pages-threadi' ); ?></option>
						<option value="1" <?php selected( $group_by_category_val, '1' ); ?>><?php esc_html_e( 'Yes', 'now-pages-threadi' ); ?></option>
						<option value="0" <?php selected( $group_by_category_val, '0' ); ?>><?php esc_html_e( 'No', 'now-pages-threadi' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="now_pages_threadi_order"><?php esc_html_e( 'Order of entries', 'now-pages-threadi' ); ?></label></th>
				<td>
					<select name="now_pages_threadi_order" id="now_pages_threadi_order">
						<option value="" <?php selected( $order_val, '' ); ?>><?php esc_html_e( '- inherit global -', 'now-pages-threadi' ); ?></option>
						<option value="ASC" <?php selected( $order_val, 'ASC' ); ?>><?php esc_html_e( 'Oldest first', 'now-pages-threadi' ); ?></option>
						<option value="DESC" <?php selected( $order_val, 'DESC' ); ?>><?php esc_html_e( 'Newest first', 'now-pages-threadi' ); ?></option>
					</select>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Persist the profile fields.
	 *
	 * @param mixed $user_id The user ID being saved.
	 *
	 * @return void
	 */
	public function save_fields( mixed $user_id ): void {
		$user_id = absint( $user_id );

		// bail if capability is missing.
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		// bail if our fields were not part of the sent form (e.g. a profile form of another plugin).
		if ( ! isset( $_POST['now_pages_threadi_user_settings_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['now_pages_threadi_user_settings_nonce'] ) ), 'now-pages-threadi-user-settings' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$keys = Display_Settings::user_meta_keys();

		// the opt-out is saved for every user.
		$disabled_key = $keys['disabled'];
		$raw_disabled = isset( $_POST[ $disabled_key ] ) ? absint( $_POST[ $disabled_key ] ) : 0;
		if ( 0 === $raw_disabled ) {
			delete_user_meta( $user_id, $disabled_key );
		} else {
			update_user_meta( $user_id, $disabled_key, $raw_disabled );
		}

		// bail if the privacy settings are not available.
		if ( ! $this->current_user_can_manage_privacy() ) {
			return;
		}

		// the privacy settings are always saved, the display settings only with own now pages.
		$boolean_keys = array( 'show_time' );
		if ( $this->current_user_can_display() ) {
			$boolean_keys = array( 'show_time', 'show_source', 'link_source', 'group_by_category' );
		}

		foreach ( $boolean_keys as $key ) {
			$raw = isset( $_POST[ $keys[ $key ] ] ) ? sanitize_text_field( wp_unslash( $_POST[ $keys[ $key ] ] ) ) : '';
			if ( '' === $raw ) {
				delete_user_meta( $user_id, $keys[ $key ] );
			} else {
				update_user_meta( $user_id, $keys[ $key ], filter_var( $raw, FILTER_VALIDATE_BOOLEAN ) ? 1 : 0 );
			}
		}

		$delay_key = $keys['delay_hours'];
		$raw_delay = isset( $_POST[ $delay_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $delay_key ] ) ) : '';
		if ( '' === $raw_delay ) {
			delete_user_meta( $user_id, $delay_key );
		} else {
			update_user_meta( $user_id, $delay_key, Display_Settings::limit_hours( $raw_delay ) );
		}

		// bail if the display settings are not available.
		if ( ! $this->current_user_can_display() ) {
			return;
		}

		// the order has exactly two valid values, anything else means "inherit".
		$order_key = $keys['order'];
		$raw_order = isset( $_POST[ $order_key ] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST[ $order_key ] ) ) ) : '';
		if ( ! in_array( $raw_order, array( 'ASC', 'DESC' ), true ) ) {
			delete_user_meta( $user_id, $order_key );
		} else {
			update_user_meta( $user_id, $order_key, $raw_order );
		}
		// phpcs:enable
	}

	/**
	 * Return whether actual user is allowed to manage display settings for now pages on their own profile.
	 *
	 * @return bool
	 */
	public function current_user_can_display(): bool {
		// bail if user-now-pages-threadi are disabled.
		if ( 1 !== absint( get_option( 'now_pages_threadi_frontend_user_url' ) ) ) {
			return false;
		}

		// return the capability result.
		return $this->current_user_can_manage_privacy();
	}

	/**
	 * Return whether actual user is allowed to manage the privacy settings for now pages on their own profile.
	 *
	 * These settings (disable, delay, show times) also affect the global now
	 * page and the blocks, so they do not depend on the user now pages.
	 *
	 * @return bool
	 */
	public function current_user_can_manage_privacy(): bool {
		return current_user_can( 'edit_' . Entries::get_instance()->get_slug() . 's' ) && current_user_can( 'edit_' . Sources::get_instance()->get_slug() . 's' );
	}

	/**
	 * Return a user-specific now page URL.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return string
	 */
	public function get_user_now_page_url( int $user_id ): string {
		// get the user object.
		$user = get_user_by( 'id', $user_id );

		// use the fallback if the user could not be loaded.
		if ( ! $user instanceof WP_User ) {
			return trailingslashit( get_author_posts_url( $user_id ) ) . 'now/';
		}

		return Frontend::get_instance()->get_author_now_page_url( $user );
	}

	/**
	 * Extend the user table with a custom column.
	 *
	 * @param array<string,string> $columns List of columns.
	 *
	 * @return array<string,string>
	 */
	public function manage_users_columns( array $columns ): array {
		$columns['now-page-url'] = esc_html__( 'Now Page', 'now-pages-threadi' );
		return $columns;
	}

	/**
	 * Show the now page URL on every user.
	 *
	 * @param mixed  $output The output (could be anything if another plugin returned it).
	 * @param string $column_name The column name.
	 * @param int    $user_id The user ID.
	 *
	 * @return mixed
	 */
	public function manage_users_custom_column( mixed $output, string $column_name, int $user_id ): mixed {
		// bail if this is not our column.
		if ( 'now-page-url' !== $column_name ) {
			return $output;
		}

		// bail if user-now-pages-threadi are disabled.
		if ( 1 !== absint( get_option( 'now_pages_threadi_frontend_user_url' ) ) ) {
			// show hint for admins.
			$settings_obj = Settings::get_instance();
			if ( current_user_can( $settings_obj->get_settings_obj()->get_capability() ) ) {
				return '<a href="' . esc_url( $settings_obj->get_url() ) . '" title="' . esc_attr__( 'Enable Now Pages for users', 'now-pages-threadi' ) . '"><span class="dashicons dashicons-dismiss"></span></a>';
			}

			// show hint vor all others.
			return '<span class="dashicons dashicons-dismiss"></span>';
		}

		// bail if user has disabled its now page.
		$keys = Display_Settings::user_meta_keys();
		if ( 1 === absint( get_user_meta( $user_id, $keys['disabled'], true ) ) ) {
			return '<span class="dashicons dashicons-dismiss" title="' . esc_attr__( 'Disabled by user', 'now-pages-threadi' ) . '"></span>';
		}

		// get the user object.
		$user = get_user_by( 'id', $user_id );

		// bail if user could not be loaded.
		if ( ! $user instanceof WP_User ) {
			return '';
		}

		// get the URL.
		$url = Frontend::get_instance()->get_author_now_page_url( $user );

		// return the user URL.
		return '<a href="' . esc_url( $url ) . '" target="_blank" title="' . esc_attr__( 'View the Now Page', 'now-pages-threadi' ) . '"><span class="dashicons dashicons-admin-links"></span>' . Plugin::get_a11n_window_hint() . '</a>';
	}
}
