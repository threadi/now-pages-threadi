<?php
/**
 * File to for the base-object for each email.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use easySettingsForWordPress\Fields\Button;
use easySettingsForWordPress\Fields\Checkbox;
use easySettingsForWordPress\Fields\MultiField;
use easySettingsForWordPress\Fields\Text;
use easySettingsForWordPress\Settings;
use easySettingsForWordPress\Tab;
use NowPagesThreadi\Helper\Format;
use NowPagesThreadi\Helper\Plugin;

/**
 * Object to handle email objects as a base-object.
 */
class Email_Base {
	/**
	 * Name of the method.
	 *
	 * @var string
	 */
	protected string $name = '';

	/**
	 * Subject for the email.
	 *
	 * @var string
	 */
	protected string $subject = '';

	/**
	 * Body for the email.
	 *
	 * @var string
	 */
	protected string $body = '';

	/**
	 * Disabled by default on plugin installation.
	 *
	 * @var bool
	 */
	protected bool $default_enabled = false;

	/**
	 * Test-mode if true.
	 *
	 * @var bool
	 */
	private bool $test = false;

	/**
	 * Return the name of the method.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Add settings for this email.
	 *
	 * @param Settings $settings_obj The settings object.
	 * @param Tab      $email_tab    The email tab in the settings.
	 *
	 * @return void
	 */
	public function add_settings( Settings $settings_obj, Tab $email_tab ): void {
		// get the URL for the main settings of WordPress.
		$wp_general_settings_url = add_query_arg(
			array(),
			get_admin_url() . 'options-general.php'
		);

		// add a section.
		$email_tab_main = $email_tab->add_section( 'settings_section_email_' . $this->get_name(), 10 );
		/* translators: %1$s will be replaced by the email title. */
		$email_tab_main->set_title( sprintf( __( 'Settings for %1$s', 'now-pages-threadi' ), $this->get_title() ) );
		$email_tab_main->set_callback( array( $this, 'show_description' ) );
		$email_tab_main->set_setting( $settings_obj );
		if ( method_exists( $email_tab_main, 'set_collapsed' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			$email_tab_main->set_collapsible( true );
			$email_tab_main->set_collapsed( ! $this->is_enabled() );
		}

		// add setting.
		$enable_setting = $settings_obj->add_setting( 'now_pages_threadi_email_' . $this->get_name() );
		$enable_setting->set_section( $email_tab_main );
		$enable_setting->set_default( $this->is_default_enabled() ? 1 : 0 );
		$field = new Checkbox( $settings_obj );
		$field->set_title( __( 'Enable', 'now-pages-threadi' ) );
		$field->set_description( $this->get_description() );
		$enable_setting->set_field( $field );

		// create the field description.
		$description = __( 'Add one or more recipients for this email. One email pro field. Get more fields after saving the settings.', 'now-pages-threadi' ) . ' ';
		if ( '' !== $this->get_recipient_hint() ) {
			$description .= $this->get_recipient_hint();
		} elseif ( ! empty( $this->get_default_recipient() ) ) {
			/* translators: %1$s will be replaced by the email address. */
			$description .= sprintf( __( '<strong>If no email is set, we use the admin-email %1$s as the recipient.</strong> You can edit the admin-email of your WordPress <a href="%2$s">here</a>.', 'now-pages-threadi' ), '<code>' . $this->get_default_recipient() . '</code>', $wp_general_settings_url );
		} else {
			$description .= '<strong>' . __( 'Without recipient this email will not be sent.', 'now-pages-threadi' ) . '</strong>';
		}

		// add setting.
		$setting = $settings_obj->add_setting( 'now_pages_threadi_email_recipients_' . $this->get_name() );
		$setting->set_section( $email_tab_main );
		$setting->set_type( 'array' );
		$setting->set_default( array() );
		$field = new MultiField( $settings_obj );
		$field->set_title( __( 'Add recipients', 'now-pages-threadi' ) );
		$field->set_description( $description );
		$text_field = new Text( $settings_obj );
		$text_field->set_title( __( 'Recipient', 'now-pages-threadi' ) );
		$text_field->set_placeholder( 'info@example.com' );
		$text_field->add_depend( $enable_setting, 1 );
		$field->set_field( $text_field );
		$field->set_quantity( count( $setting->get_value() ? $setting->get_value() : array() ) + 1 );
		$field->add_depend( $enable_setting, 1 );
		$setting->set_field( $field );

		// add setting.
		$setting = $settings_obj->add_setting( 'now_pages_threadi_email_from_' . $this->get_name() );
		$setting->set_section( $email_tab_main );
		$setting->set_type( 'string' );
		$setting->set_default( '' );
		$setting->set_read_callback( array( $this, 'set_from' ) );
		$field = new Text( $settings_obj );
		$field->set_title( __( 'Sender Email', 'now-pages-threadi' ) );
		$field->set_description( __( 'If no email is set, the default sender of WordPress is used. Use an address of the domain of your website, otherwise the email might be rejected.', 'now-pages-threadi' ) );
		$field->set_placeholder( 'info@example.com' );
		$field->add_depend( $enable_setting, 1 );
		$setting->set_field( $field );

		// create dialog before test email is sent.
		$dialog = array(
			'title'   => __( 'Send test email', 'now-pages-threadi' ),
			'texts'   => array(
				'<p><strong>' . __( 'Are you sure you want to send this test email?', 'now-pages-threadi' ) . '</strong></p>',
				'<p>' . __( 'If the email does not reach the specified recipient, check whether your project will be able to send emails at all.', 'now-pages-threadi' ) . '</p>',
			),
			'buttons' => array(
				array(
					'action'  => 'now_pages_threadi_send_testmail( "' . $this->get_name() . '" );',
					'variant' => 'primary',
					'text'    => __( 'Send now', 'now-pages-threadi' ),
				),
				array(
					'action'  => 'closeDialog();',
					'variant' => 'secondary',
					'text'    => __( 'Cancel', 'now-pages-threadi' ),
				),
			),
		);

		// add setting.
		$setting = $settings_obj->add_setting( 'now_pages_threadi_email_test_' . $this->get_name() );
		$setting->set_section( $email_tab_main );
		$setting->set_autoload( false );
		$setting->prevent_export( true );
		$field = new Button( $settings_obj );
		$field->set_title( __( 'Test-Email', 'now-pages-threadi' ) );
		$field->set_button_title( __( 'Send now', 'now-pages-threadi' ) );
		$field->set_button_url(
			add_query_arg(
				array(
					'action' => 'now_pages_threadi_email_test',
					'object' => $this->get_name(),
					'nonce'  => wp_create_nonce( 'now-pages-threadi-email-test' ),
				),
				get_admin_url() . 'admin.php'
			)
		);
		$field->add_class( 'easy-dialog-for-wordpress' );
		$field->add_data( 'dialog', Format::get_json( $dialog ) );
		$field->add_depend( $enable_setting, 1 );
		$setting->set_field( $field );
	}

	/**
	 * Return the title.
	 *
	 * @return string
	 */
	protected function get_title(): string {
		return '';
	}

	/**
	 * Return the description.
	 *
	 * @return string
	 */
	protected function get_description(): string {
		return '';
	}

	/**
	 * Return the list of configured recipients.
	 *
	 * @return array<int,string>
	 */
	public function get_recipients(): array {
		// get the setting and use only valid email addresses.
		$recipients = self::get_valid_emails( (array) get_option( 'now_pages_threadi_email_recipients_' . $this->get_name(), array() ) );

		// if the setting is empty and if we are using the test mode, use the admin email.
		if ( empty( $recipients ) && $this->is_test() ) {
			return self::get_valid_emails( array( get_option( 'admin_email' ) ) );
		}

		// if setting is empty use the default email.
		if ( empty( $recipients ) ) {
			return self::get_valid_emails( array( $this->get_default_recipient() ) );
		}

		// return the list of configured recipients.
		return $recipients;
	}

	/**
	 * Return only the valid email addresses of a given list.
	 *
	 * @param array<int|string,mixed> $emails List of email addresses.
	 *
	 * @return array<int,string>
	 */
	protected static function get_valid_emails( array $emails ): array {
		$valid = array();

		// check each email.
		foreach ( $emails as $email ) {
			// bail if this is not a string.
			if ( ! is_string( $email ) ) {
				continue;
			}

			// sanitize it.
			$email = sanitize_email( $email );

			// bail if it is not a valid email.
			if ( ! is_email( $email ) ) {
				continue;
			}

			$valid[] = $email;
		}

		// return the unique list.
		return array_values( array_unique( $valid ) );
	}

	/**
	 * Return the subject for the email.
	 *
	 * @return string
	 */
	public function get_subject(): string {
		return $this->subject;
	}

	/**
	 * Set the subject for the email.
	 *
	 * @param string $subject The subject to use.
	 *
	 * @return void
	 */
	public function set_subject( string $subject ): void {
		$this->subject = $subject;
	}

	/**
	 * Return the body for the email.
	 *
	 * @return string
	 */
	public function get_body(): string {
		// get the body.
		$body = $this->body;

		// bail if the body is empty.
		if ( empty( $body ) ) {
			return '';
		}

		// get the URL where the email configuration could be found.
		$email_config_url = \NowPagesThreadi\Plugin\Settings::get_instance()->get_url( 'emails' );

		// get the website domain.
		$domain = get_option( 'siteurl' );

		$support_part = '<div id="signature">---------------------------------------------------------';
		/* translators: %1$s will be replaced by a URL. */
		$support_part .= '<br><br>' . sprintf( __( 'This email was sent to you by the WordPress-plugin Now Pages that installed in your website under <a href="%1$s">%2$s</a>. You can disable this email <a href="%3$s">here</a>.', 'now-pages-threadi' ), esc_url( $domain ), esc_html( $domain ), esc_url( $email_config_url ) );
		/* translators: %1$s will be replaced by a URL. */
		$support_part .= '<br><br>' . sprintf( __( 'If you have any questions about the message, please feel free to contact us in <a href="%1$s">our support forum</a>.', 'now-pages-threadi' ), esc_url( Plugin::get_plugin_support_url() ) );
		$support_part .= '</div>';

		/**
		 * Filter the support part of an email.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param string $support_part The text to use.
		 */
		$support_part = apply_filters( 'now_pages_threadi_import_error_support_hint', $support_part );

		// return the body with the footer.
		return $body . $support_part;
	}

	/**
	 * Set the body for the email.
	 *
	 * @param string $body The body to use.
	 *
	 * @return void
	 * @noinspection PhpUnused
	 */
	public function set_body( string $body ): void {
		$this->body = $body;
	}

	/**
	 * Send the email.
	 *
	 * @return bool True if the email has been sent.
	 */
	public function send(): bool {
		// bail if this email is not enabled and this is not a test.
		if ( ! $this->is_test() && ! $this->is_enabled() ) {
			return false;
		}

		// bail if no recipients are set.
		if ( empty( $this->get_recipients() ) ) {
			/* translators: %1$s will be replaced by the title of the email object. */
			Log::get_instance()->add( sprintf( __( 'Recipients missing to sent email for %1$s!', 'now-pages-threadi' ), $this->get_title() ), 'error', 'emails' );
			return false;
		}

		// bail if no subject is set.
		if ( empty( $this->get_subject() ) ) {
			/* translators: %1$s will be replaced by the title of the email object. */
			Log::get_instance()->add( sprintf( __( 'Subject missing to sent email for %1$s!', 'now-pages-threadi' ), $this->get_title() ), 'error', 'emails' );
			return false;
		}

		// bail if body is not set.
		if ( empty( $this->get_body() ) ) {
			/* translators: %1$s will be replaced by the title of the email object. */
			Log::get_instance()->add( sprintf( __( 'Body missing to sent email for %1$s!', 'now-pages-threadi' ), $this->get_title() ), 'error', 'emails' );
			return false;
		}

		// get JSON of this email-configuration.
		$configuration_json = wp_json_encode( $this->get_debug() );
		if ( ! $configuration_json ) {
			return false;
		}

		// strip all HTML from JSON.
		$configuration_json = wp_strip_all_tags( $configuration_json );

		// send email.
		if ( wp_mail( $this->get_recipients(), $this->get_subject(), $this->get_body(), $this->get_headers() ) ) {
			// log this event.
			Log::get_instance()->add( __( 'Sent email:', 'now-pages-threadi' ) . ' <code>' . $configuration_json . '</code>', 'info', 'emails' );

			// run individual tasks after a successful send, but not for a test.
			if ( ! $this->is_test() ) {
				$this->after_send();
			}
			return true;
		}

		// log that mail could not be sent.
		Log::get_instance()->add( __( 'Email could not be sent:', 'now-pages-threadi' ) . ' <code>' . $configuration_json . '</code>', 'error', 'emails' );

		// return that the email could not be sent.
		return false;
	}

	/**
	 * Run individual tasks after the email has been sent successfully.
	 *
	 * Not called for test emails.
	 *
	 * @return void
	 */
	protected function after_send(): void {}

	/**
	 * Return a hint about the recipient used if none is set, or an empty string for the default hint.
	 *
	 * @return string
	 */
	protected function get_recipient_hint(): string {
		return '';
	}

	/**
	 * Return the default recipient.
	 *
	 * @return string
	 */
	protected function get_default_recipient(): string {
		return '';
	}

	/**
	 * Return the mail headers.
	 *
	 * @return array<int,string>
	 */
	private function get_headers(): array {
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'X-Mailer: ' . Plugin::get_plugin_name(),
		);

		// add the sender only if it is a valid email, WordPress uses its default otherwise.
		$from = $this->get_from();
		if ( is_email( $from ) ) {
			$headers[] = 'From: ' . $from;
		}

		/**
		 * Filter the email header.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param array<int,string> $headers List of headers.
		 */
		return apply_filters( 'now_pages_threadi_email_headers', $headers );
	}

	/**
	 * Return a debug array with all value of this email.
	 *
	 * @return array<string,mixed>
	 */
	private function get_debug(): array {
		return array(
			'used_email_object' => $this->get_name(),
			'header'            => $this->get_headers(),
			'recipients'        => $this->get_recipients(),
			'subject'           => $this->get_subject(),
			'body'              => $this->get_body(),
		);
	}

	/**
	 * Show the description for this email object.
	 *
	 * @return void
	 */
	public function show_description(): void {}

	/**
	 * Return whether this email is switched on.
	 *
	 * An email which is enabled by default is on until somebody switches
	 * it off, also on a website where its setting has never been saved -
	 * like one which got this email with an update.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return 1 === absint( get_option( 'now_pages_threadi_email_' . $this->get_name(), $this->is_default_enabled() ? 1 : 0 ) );
	}

	/**
	 * Return whether this object is enabled by default.
	 *
	 * @return bool
	 */
	private function is_default_enabled(): bool {
		return $this->default_enabled;
	}

	/**
	 * Prepare the object for a test email.
	 *
	 * @return void
	 */
	protected function prepare_for_test(): void {}

	/**
	 * Clean up the object after test email.
	 *
	 * @return void
	 */
	protected function cleanup_after_test(): void {}

	/**
	 * Trigger a test email with the default content.
	 *
	 * @return bool True if the email has been sent.
	 */
	public function send_test(): bool {
		// mark as a test.
		$this->set_test();

		// prepare individual setting in the email object.
		$this->prepare_for_test();

		// send the email.
		$result = $this->send();

		// cleanup after test.
		$this->cleanup_after_test();

		// return the result.
		return $result;
	}

	/**
	 * Return whether the object is in test-mode.
	 *
	 * @return bool
	 */
	private function is_test(): bool {
		return $this->test;
	}

	/**
	 * Set test mode.
	 *
	 * @return void
	 */
	private function set_test(): void {
		$this->test = true;
	}

	/**
	 * Return the configured "from"-address we should use.
	 *
	 * @return string
	 */
	private function get_from(): string {
		// get the configured email.
		$from = sanitize_email( (string) get_option( 'now_pages_threadi_email_from_' . $this->get_name() ) );

		// return nothing if the configured one is not valid, WordPress uses its default sender then.
		if ( ! is_email( $from ) ) {
			return '';
		}

		// return the email.
		return $from;
	}

	/**
	 * Set the "from" email during reading the setting.
	 *
	 * If no "from" email is set, return an empty string: WordPress uses its default sender then.
	 *
	 * @param string|null $value The value from setting.
	 *
	 * @return string
	 */
	public function set_from( string|null $value ): string {
		// if value is null, create a string.
		if ( is_null( $value ) ) {
			$value = '';
		}

		// return the value, an empty one means the default sender of WordPress.
		return $value;
	}

	/**
	 * Validate a given interval.
	 *
	 * @param string|null $value The given value.
	 *
	 * @return string
	 */
	public static function validate_interval( null|string $value ): string {
		// get option.
		$option = str_replace( 'sanitize_option_', '', (string) current_filter() );

		// bail if the value is empty.
		if ( empty( $value ) ) {
			add_settings_error( $option, $option, __( 'An interval has to be set.', 'now-pages-threadi' ) );

			// return the old value.
			return (string) get_option( $option );
		}

		// check if the given interval exists.
		$intervals = Intervals::get_instance()->get_intervals_for_settings();
		if ( empty( $intervals[ $value ] ) ) {
			/* translators: %1$s will be replaced by the name of the used interval */
			add_settings_error( $option, $option, sprintf( __( 'The given interval %1$s does not exist.', 'now-pages-threadi' ), esc_html( $value ) ) );

			// return the old value.
			return (string) get_option( $option );
		}

		// return the value.
		return $value;
	}
}
