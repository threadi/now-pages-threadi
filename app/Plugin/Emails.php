<?php
/**
 * File with the main handler for all emails this plugin will generate.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use easySettingsForWordPress\Page;
use ThreadiNowPages\Helper\Plugin;
use ThreadiNowPages\Helper\Singleton_Trait;

/**
 * Object for all emails this plugin will generate.
 */
class Emails {
	use Singleton_Trait;

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// add email settings.
		add_action( 'init', array( $this, 'add_the_settings' ), 20 );

		// use our own hooks.
		add_filter( 'threadi_now_pages_log_categories', array( $this, 'add_log_category' ) );
		add_filter( 'threadi_now_pages_schedules', array( $this, 'add_schedule' ) );

		// use actions.
		add_action( 'admin_action_threadi_now_pages_email_test', array( $this, 'send_test_email_by_request' ) );
		add_action( 'wp_ajax_threadi_now_pages_sent_test_email', array( $this, 'send_test_email_by_ajax' ) );

		// add our email template.
		add_filter( 'wp_mail', array( $this, 'set_email_template' ) );
	}

	/**
	 * Add our settings for emails.
	 *
	 * @return void
	 */
	public function add_the_settings(): void {
		// bail if the settings are not needed in this request.
		if ( ! Plugin::is_settings_request() ) {
			return;
		}

		// get settings object.
		$settings_obj = Settings::get_instance()->get_settings_obj();

		// get the main settings page.
		$settings_page = $settings_obj->get_page( Settings::get_instance()->get_settings_obj()->get_menu_slug() );

		// bail if the page does not exist.
		if ( ! $settings_page instanceof Page ) {
			return;
		}

		// the email tab.
		$email_tab = $settings_page->add_tab( 'emails', 50 );
		$email_tab->set_title( __( 'Emails', 'threadi-now-pages' ) );

		// add a setting for each supported trigger.
		foreach ( $this->get_email_trigger() as $email_class_name ) {
			// bail if class does not exist.
			if ( ! class_exists( $email_class_name ) ) {
				continue;
			}

			// get the object.
			$obj = new $email_class_name();

			// bail if an object is not "Email_Base".
			if ( ! $obj instanceof Email_Base ) {
				continue;
			}

			// get settings.
			$obj->add_settings( $settings_obj, $email_tab );
		}
	}

	/**
	 * Return a list of all available email triggers.
	 *
	 * @return array<int,string>
	 */
	private function get_email_trigger(): array {
		$trigger = array(
			'\ThreadiNowPages\Plugin\Emails\EmptyNowPages',
			'\ThreadiNowPages\Plugin\Emails\Report',
			'\ThreadiNowPages\Plugin\Emails\ConfirmEntry',
		);

		/**
		 * Filter the list of possible email objects.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param array<int,string> $trigger List of Email trigger objects.
		 */
		return apply_filters( 'threadi_now_pages_emails', $trigger );
	}

	/**
	 * Return the email object, which as the given name.
	 *
	 * @param string $name The name.
	 *
	 * @return Email_Base|false
	 */
	private function get_email_trigger_by_name( string $name ): Email_Base|false {
		// add a setting for each supported trigger.
		foreach ( $this->get_email_trigger() as $email_class_name ) {
			// bail if class does not exist.
			if ( ! class_exists( $email_class_name ) ) {
				continue;
			}

			// get the object.
			$obj = new $email_class_name();

			// bail if an object is not "Email_Base".
			if ( ! $obj instanceof Email_Base ) {
				continue;
			}

			// bail if the name does not match.
			if ( $name !== $obj->get_name() ) {
				continue;
			}

			// return this object.
			return $obj;
		}

		return false;
	}

	/**
	 * Add the log category for emails.
	 *
	 * @param array<string,string> $categories List of categories.
	 *
	 * @return array<string,string>
	 */
	public function add_log_category( array $categories ): array {
		// add categories we need for our settings.
		$categories['emails'] = __( 'Emails', 'threadi-now-pages' );

		// return the resulting list.
		return $categories;
	}

	/**
	 * Add our own schedule to the list.
	 *
	 * @param array<string> $list_of_schedules List of schedules.
	 *
	 * @return array<string>
	 */
	public function add_schedule( array $list_of_schedules ): array {
		// add the schedule-object if the report is enabled.
		$list_of_schedules[] = '\ThreadiNowPages\Plugin\Schedules\Report';

		// return the resulting list.
		return $list_of_schedules;
	}

	/**
	 * Send test-email by request.
	 *
	 * @return void
	 */
	public function send_test_email_by_request(): void {
		// check nonce.
		check_admin_referer( 'threadi-now-pages-email-test', 'nonce' );

		// bail if capability is missing.
		if ( ! current_user_can( Settings::get_instance()->get_settings_obj()->get_capability() ) ) {
			return;
		}

		// get the object name.
		$email_object_name = filter_input( INPUT_GET, 'object', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		// bail if no object name is given.
		if ( empty( $email_object_name ) ) {
			wp_safe_redirect( (string) wp_get_referer() );
			exit;
		}

		// get the object.
		$email_obj = $this->get_email_trigger_by_name( $email_object_name );

		// bail if no object could be found.
		if ( ! $email_obj instanceof Email_Base ) {
			wp_safe_redirect( (string) wp_get_referer() );
			exit;
		}

		// trigger the test-email of this object.
		$email_obj->send_test();

		// let the user return.
		wp_safe_redirect( (string) wp_get_referer() );
		exit;
	}

	/**
	 * Send test-email by ajax.
	 *
	 * @return void
	 */
	public function send_test_email_by_ajax(): void {
		// check nonce.
		check_ajax_referer( 'threadi-now-pages-test-email', 'nonce' );

		// prepare the answer.
		$answer_dialog = array(
			'title'   => __( 'Error sending test email', 'threadi-now-pages' ),
			'texts'   => array(
				'<p>' . __( 'An error occurred during sending the test email.', 'threadi-now-pages' ) . '</p>',
			),
			'buttons' => array(
				array(
					'action'  => 'closeDialog();',
					'variant' => 'secondary',
					'text'    => __( 'OK', 'threadi-now-pages' ),
				),
			),
		);

		// bail if capability is missing.
		if ( ! current_user_can( Settings::get_instance()->get_settings_obj()->get_capability() ) ) {
			wp_send_json( array( 'detail' => $answer_dialog ) );
		}

		// get the object name.
		$email_object_name = filter_input( INPUT_POST, 'object', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		// bail if no object name is given.
		if ( empty( $email_object_name ) ) {
			wp_send_json( array( 'detail' => $answer_dialog ) );
		}

		// get the object.
		$email_obj = $this->get_email_trigger_by_name( $email_object_name );

		// bail if no object could be found.
		if ( ! $email_obj instanceof Email_Base ) {
			wp_send_json( array( 'detail' => $answer_dialog ) );
		}

		// trigger the test-email of this object and bail if it could not be sent.
		if ( ! $email_obj->send_test() ) {
			wp_send_json( array( 'detail' => $answer_dialog ) );
		}

		// prepare the answer.
		$answer_dialog = array(
			'title'   => __( 'Test-Email has been sent', 'threadi-now-pages' ),
			'texts'   => array(
				/* translators: %1$s will be replaced with an email or list of emails. */
				'<p>' . sprintf( __( 'Check now your inbox in %1$s.', 'threadi-now-pages' ), '<code>' . implode( ',', $email_obj->get_recipients() ) . '</code>' ) . '</p>',
			),
			'buttons' => array(
				array(
					'action'  => 'closeDialog();',
					'variant' => 'primary',
					'text'    => __( 'OK', 'threadi-now-pages' ),
				),
			),
		);
		wp_send_json( array( 'detail' => $answer_dialog ) );
	}

	/**
	 * Add our own email template for emails we send.
	 *
	 * @param mixed $args List of arguments.
	 *
	 * @return mixed
	 */
	public function set_email_template( mixed $args ): mixed {
		// bail if args is not an array.
		if ( ! is_array( $args ) ) {
			return $args;
		}

		// bail if no header is set.
		if ( ! isset( $args['headers'] ) ) {
			return $args;
		}

		// bail if the header "X-Mailer" is not our plugin.
		if ( ! $this->is_our_email( $args['headers'] ) ) {
			return $args;
		}

		// bail if no message is set.
		if ( ! isset( $args['message'] ) || ! is_string( $args['message'] ) ) {
			return $args;
		}

		// bail if email is already HTML.
		if ( function_exists( 'str_contains' ) && ( str_contains( $args['message'], '<html' ) || str_contains( $args['message'], '<HTML' ) || str_contains( $args['message'], '<body' ) ) ) {
			return $args;
		}

		// get the contents.
		$subject = (string) ( $args['subject'] ?? '' );
		$body    = wpautop( $args['message'] );

		// load your custom email template file.
		ob_start();
		include Templates::get_instance()->get_template( 'emails/default.php' );
		$body = ob_get_clean();
		if ( ! $body ) {
			return $args;
		}

		// set the new HTML-formatted body.
		$args['message'] = $body;

		// return resulting mail configuration.
		return $args;
	}

	/**
	 * Return whether the given headers mark an email of this plugin.
	 *
	 * The headers could be a string with one header per line, a list of header
	 * lines or a map of header name => value.
	 *
	 * @param mixed $headers The headers of the email.
	 *
	 * @return bool
	 */
	private function is_our_email( mixed $headers ): bool {
		// convert a string into a list of lines.
		if ( is_string( $headers ) ) {
			$headers = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
		}

		// bail if headers are not an array.
		if ( ! is_array( $headers ) ) {
			return false;
		}

		// search the value of the header "X-Mailer".
		$mailer = '';
		foreach ( $headers as $name => $value ) {
			// bail if value is not a string.
			if ( ! is_string( $value ) ) {
				continue;
			}

			// a map of header name => value.
			if ( is_string( $name ) ) {
				if ( 'x-mailer' === strtolower( trim( $name ) ) ) {
					$mailer = trim( $value );
					break;
				}
				continue;
			}

			// a single header line.
			$parts = explode( ':', $value, 2 );
			if ( 2 === count( $parts ) && 'x-mailer' === strtolower( trim( $parts[0] ) ) ) {
				$mailer = trim( $parts[1] );
				break;
			}
		}

		// bail if no "X-Mailer" is set.
		if ( '' === $mailer ) {
			return false;
		}

		// return whether it is our plugin.
		return Plugin::get_plugin_name() === $mailer;
	}
}
