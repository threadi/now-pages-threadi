<?php
/**
 * File for handling the report about the entries.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Plugin\Emails;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use easySettingsForWordPress\Fields\Select;
use easySettingsForWordPress\Section;
use easySettingsForWordPress\Setting;
use easySettingsForWordPress\Settings;
use easySettingsForWordPress\Tab;
use NowPagesThreadi\Helper\Plugin;
use NowPagesThreadi\NowPages\Frontend;
use NowPagesThreadi\Plugin\Email_Base;
use NowPagesThreadi\Plugin\Intervals;
use NowPagesThreadi\Sources\Sources;
use WP_User;

/**
 * Object that handles the report about the entries.
 */
class EmptyNowPages extends Email_Base {
	/**
	 * THe internal name of this object.
	 *
	 * @var string
	 */
	protected string $name = 'empty_now_pages_threadi';

	/**
	 * Name of the user meta which marks that a user got this email for the actual empty phase of their now page.
	 *
	 * @var string
	 */
	public const USER_META_SENT = 'now_pages_threadi_empty_now_page_sent';

	/**
	 * The user, which should be recipient of this mail.
	 *
	 * @var false|WP_User
	 */
	private false|WP_User $user = false;

	/**
	 * Constructor for this object.
	 */
	public function __construct() {}

	/**
	 * Return the title.
	 *
	 * @return string
	 */
	protected function get_title(): string {
		return __( 'Empty now pages', 'now-pages-threadi' );
	}

	/**
	 * Return the description.
	 *
	 * @return string
	 */
	protected function get_description(): string {
		return __( 'If activated, an email is sent to the specified email address if your now page is empty.', 'now-pages-threadi' );
	}

	/**
	 * Show the description for this email object.
	 *
	 * @return void
	 */
	public function show_description(): void {
		echo esc_html__( 'The report informs you if your now pages are empty.', 'now-pages-threadi' );
	}

	/**
	 * Add settings for this email.
	 *
	 * @param Settings $settings_obj The settings object.
	 * @param Tab      $email_tab The email tab in the settings.
	 *
	 * @return void
	 */
	public function add_settings( Settings $settings_obj, Tab $email_tab ): void {
		// add the main settings from the parent object.
		parent::add_settings( $settings_obj, $email_tab );

		// get possible intervals.
		$intervals = Intervals::get_instance()->get_intervals_for_settings( true );

		// get our own section.
		$email_tab_main = $email_tab->get_section( 'settings_section_email_' . $this->get_name() );

		// bail if the section does not exist.
		if ( ! $email_tab_main instanceof Section ) {
			return;
		}

		// get the main setting.
		$main_setting = $settings_obj->get_setting( 'now_pages_threadi_email_' . $this->get_name() );

		// bail if settings could not be loaded.
		if ( ! $main_setting instanceof Setting ) {
			return;
		}

		// add a callback for the main setting.
		$main_setting->set_save_callback( array( $this, 'save_interval' ) );

		// add setting.
		$setting = $settings_obj->add_setting( 'now_pages_threadi_email_interval_' . $this->get_name() );
		$setting->set_section( $email_tab_main );
		$setting->set_type( 'string' );
		$setting->set_default( 'now_pages_threadi_weekly' );
		$field = new Select( $settings_obj );
		$field->set_title( __( 'Choose interval', 'now-pages-threadi' ) );
		$field->set_options( $intervals );
		$field->add_depend( $main_setting, 1 );
		$field->set_sanitize_callback( array( $this, 'validate_interval' ) );
		$setting->set_field( $field );
	}

	/**
	 * Return the subject of the email.
	 *
	 * @return string
	 */
	public function get_subject(): string {
		// set our custom subject.
		$this->subject = '[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] ' . __( 'Your Now Page is empty', 'now-pages-threadi' );

		// return the parent tasks for the subject.
		return parent::get_subject();
	}

	/**
	 * Return the body.
	 *
	 * @return string
	 */
	public function get_body(): string {
		// get the now page URL.
		$now_page_url = Frontend::get_instance()->get_global_now_page_url();
		if ( $this->user instanceof WP_User ) {
			$now_page_url = Frontend::get_instance()->get_author_now_page_url( $this->user );
		}

		// set the body.
		$this->body  = __( 'Your now pages are empty:', 'now-pages-threadi' ) . ' <a href="' . esc_url( $now_page_url ) . '" target="_blank">' . esc_url( $now_page_url ) . '</a>';
		$this->body .= '<br><br>' . __( 'Be more active in the linked sources or change the display settings here:', 'now-pages-threadi' ) . ' <a href="' . \NowPagesThreadi\Plugin\Settings::get_instance()->get_url() . '" target="_blank">' . \NowPagesThreadi\Plugin\Settings::get_instance()->get_url() . '</a>';

		// show the sources for the global mail.
		if ( ! $this->user instanceof WP_User ) {
			$this->body .= '<br><br>' . __( 'Your active sources:', 'now-pages-threadi' );

			foreach ( Sources::get_instance()->get_configured_sources() as $source ) {
				$this->body .= '<br>' . esc_html( $source['title'] ) . ' (' . esc_html( $source['provider']->get_label() ) . ')';
			}
		}

		// return the parent tasks for body.
		return parent::get_body();
	}

	/**
	 * Save the interval.
	 *
	 * @param mixed $value The given value.
	 *
	 * @return mixed
	 */
	public static function save_interval( mixed $value ): mixed {
		// bail if the setting has not been changed: keep the existing event and its next run.
		if ( absint( get_option( 'now_pages_threadi_email_empty_now_pages_threadi' ) ) === absint( $value ) ) {
			return $value;
		}

		// let reconcile_events() handle the (re)installation on shutdown.
		( new \NowPagesThreadi\Plugin\Schedules\EmptyNowPages() )->delete();

		// return the new value to save it via WP.
		return $value;
	}

	/**
	 * Return the list of configured recipients.
	 *
	 * @return array<int,string>
	 */
	public function get_recipients(): array {
		// user custom recipient, if set.
		if ( $this->user instanceof WP_User ) {
			return self::get_valid_emails( array( $this->user->user_email ) );
		}

		// also use the parent function.
		return parent::get_recipients();
	}

	/**
	 * Remember that the user got this email, so it is not repeated until their now page had entries again.
	 *
	 * @return void
	 */
	protected function after_send(): void {
		// bail if this is the email for the global now page.
		if ( ! $this->user instanceof WP_User ) {
			return;
		}

		// set the marker.
		update_user_meta( $this->user->ID, self::USER_META_SENT, 1 );
	}

	/**
	 * Set the user which should be recipient.
	 *
	 * @param WP_User $user The user.
	 *
	 * @return void
	 */
	public function set_user( WP_User $user ): void {
		$this->user = $user;
	}
}
