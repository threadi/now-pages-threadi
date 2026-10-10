<?php
/**
 * File to handle every schedule in this plugin.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use easySettingsForWordPress\Fields\Checkbox;
use easySettingsForWordPress\Page;
use easySettingsForWordPress\Tab;

/**
 * The object, which handles schedules.
 */
class Schedules {
	/**
	 * Instance of this object.
	 *
	 * @var ?Schedules
	 */
	private static ?Schedules $instance = null;

	/**
	 * Constructor for Schedules-Handler.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning of this object.
	 *
	 * @return void
	 */
	private function __clone() { }

	/**
	 * Return the instance of this Singleton object.
	 */
	public static function get_instance(): Schedules {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize all schedules of this plugin.
	 *
	 * @return void
	 */
	public function init(): void {
		// initialize the intervals.
		Intervals::get_instance()->init();

		// use our own hooks.
		if ( is_admin() || wp_doing_cron() ) {
			add_filter( 'now_pages_threadi_schedule_our_events', array( $this, 'check_events' ) );
		}
		add_action( 'init', array( $this, 'add_the_settings' ), 20 );
		add_action( 'init', array( $this, 'init_schedules' ), 100 );

		// action to create all registered schedules.
		add_filter( 'schedule_event', array( $this, 'add_schedule_to_list' ) );
		add_action( 'shutdown', array( $this, 'check_events_on_shutdown' ) );
	}

	/**
	 * Initialize the schedules via init-hook.
	 *
	 * @return void
	 */
	public function init_schedules(): void {
		// loop through our own events.
		foreach ( $this->get_events() as $event ) {
			// get the schedule object.
			$schedule_obj = $this->get_schedule_object_by_name( $event['name'] );

			// bail if the object could not be loaded.
			if ( ! $schedule_obj instanceof Schedules_Base ) {
				continue;
			}

			// set attributes in the object, if available.
			if ( ! empty( $event['settings'][ array_key_first( $event['settings'] ) ]['args'] ) ) {
				$schedule_obj->set_args( $event['settings'][ array_key_first( $event['settings'] ) ]['args'] );
			}

			// define action hook to run the schedule.
			add_action( $schedule_obj->get_name(), array( $schedule_obj, 'run' ), 10, 0 );
		}
	}

	/**
	 * Add settings for this extension.
	 *
	 * @return void
	 */
	public function add_the_settings(): void {
		// bail if the settings are not needed in this request.
		if ( ! \NowPagesThreadi\Helper\Plugin::is_settings_request() ) {
			return;
		}

		// get settings object.
		$settings_obj = Settings::get_instance()->get_settings_obj();

		// get the settings page.
		$settings_page = $settings_obj->get_page( $settings_obj->get_menu_slug() );

		// bail if page could not be found.
		if ( ! $settings_page instanceof Page ) {
			return;
		}

		// get the tab.
		$tab = $settings_page->get_tab( 'now_pages_threadi_general' );

		// bail if tab could not be found.
		if ( ! $tab instanceof Tab ) {
			return;
		}

		// get the section.
		$advanced_section = $tab->get_section( 'now_pages_threadi_general' );

		// bail if the tab does not exist.
		if ( ! $advanced_section ) {
			return;
		}

		// add setting.
		$setting = $settings_obj->add_setting( 'now_pages_threadi_enable_cron_check_in_frontend' );
		$setting->set_section( $advanced_section );
		$setting->set_default( 0 );
		$field = new Checkbox( $settings_obj );
		$field->set_title( __( 'Check for schedules in frontend', 'now-pages-threadi' ) );
		$field->set_description( __( 'If enabled the plugin will check our own schedules on each request in the frontend. This could be slow the performance of your website.', 'now-pages-threadi' ) );
		$setting->set_field( $field );
	}

	/**
	 * Get our own active events from WP-list.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_events(): array {
		// get our own events from the events list in WordPress.
		$our_events = $this->get_wp_events();

		/**
		 * Filter the list of our own events, e.g., to check if all, which are enabled in setting are active.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<string,array<string,mixed>> $our_events List of our own events in WP-cron.
		 */
		return apply_filters( 'now_pages_threadi_schedule_our_events', $our_events );
	}

	/**
	 * Check the available events with the ones that should be active.
	 *
	 * Re-installs missing events. Log this event.
	 *
	 * Does only run in wp-admin, not frontend.
	 *
	 * @param array<string,array<string,mixed>> $our_events List of our own events.
	 *
	 * @return array<string,array<string,mixed>>
	 * @noinspection PhpUnused
	 */
	public function check_events( array $our_events ): array {
		// bail if check should be disabled.
		$false = false;
		/**
		 * Disable the additional cron check.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param bool $false True if the check should be disabled.
		 *
		 * @noinspection PhpConditionAlreadyCheckedInspection
		 */
		if ( apply_filters( 'now_pages_threadi_disable_cron_check', $false ) ) {
			return $our_events;
		}

		// bail during deactivation or uninstallation: our schedules are removed there
		// and must not be re-created by the check at the end of the same request.
		if ( defined( 'NOW_PAGES_DEACTIVATION_RUNNING' ) || defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			return $our_events;
		}

		// bail if plugin activation is running.
		if ( defined( 'NOW_PAGES_ACTIVATION_RUNNING' ) ) {
			return $our_events;
		}

		// run the reconcile of our schedule events (guards passed).
		return $this->reconcile_events( $our_events );
	}

	/**
	 * Reconcile our schedule events against the WP-cron array: install missing
	 * enabled events, remove present disabled events and heal interval drift on
	 * existing enabled events.
	 *
	 * Split out from check_events() so the reconcile can run - and be tested -
	 * independently of the activation guard in check_events().
	 *
	 * @param array<string,mixed> $our_events The currently scheduled events.
	 *
	 * @return array<string,mixed>
	 */
	public function reconcile_events( array $our_events ): array {
		// check the schedule objects if they are set.
		foreach ( $this->get_schedule_object_names() as $object_name ) {
			// bail if the class name does not exist.
			if ( ! class_exists( $object_name ) ) {
				continue;
			}

			// get the object.
			$obj = new $object_name();

			// bail if an object is not "Schedules_Base".
			if ( ! $obj instanceof Schedules_Base ) {
				continue;
			}

			// install if the schedule is enabled and not in the list of our schedules.
			if ( $obj->is_enabled() && ! isset( $our_events[ $obj->get_name() ] ) ) {
				// reinstall the missing event.
				$obj->install();

				// log this event.
				/* translators: %1$s will be replaced by the event name. */
				Log::get_instance()->add( sprintf( __( 'Missing cron event <i>%1$s</i> automatically re-installed.', 'now-pages-threadi' ), esc_html( $obj->get_name() ) ), 'success', $obj->get_log_category() );

				// re-run the check for WP-cron-events.
				$our_events = $this->get_wp_events();
			} elseif ( $obj->is_enabled() && isset( $our_events[ $obj->get_name() ] ) ) {
				// remove duplicates of this event, e.g. from two requests which installed it at the same time.
				if ( $obj->get_event_count() > 1 ) {
					$obj->delete();

					// log this event.
					/* translators: %1$s will be replaced by the event name. */
					Log::get_instance()->add( sprintf( __( 'Duplicate cron event <i>%1$s</i> automatically removed.', 'now-pages-threadi' ), esc_html( $obj->get_name() ) ), 'success', $obj->get_log_category() );
				}

				// the event exists and is enabled: make sure its interval still
				// matches the configuration. install() reschedules only if the
				// interval drifted (e.g. after the user picked another interval);
				// otherwise it is a no-op. This is what makes a changed interval
				// take effect without any extra wiring.
				$obj->install();
			}

			// delete it if the schedule is in the list of our events and not enabled.
			if ( ! $obj->is_enabled() && isset( $our_events[ $obj->get_name() ] ) ) {
				$obj->delete();

				// log this event.
				/* translators: %1$s will be replaced by the event name. */
				Log::get_instance()->add( sprintf( __( 'Not enabled cron event <i>%1$s</i> automatically removed.', 'now-pages-threadi' ), esc_html( $obj->get_name() ) ), 'success', $obj->get_log_category() );

				// re-run the check for WP-cron-events.
				$our_events = $this->get_wp_events();
			}
		}

		// return the resulting list.
		return $our_events;
	}

	/**
	 * Delete all of our own registered schedules.
	 *
	 * @return void
	 */
	public function delete_all(): void {
		// get the list of our own schedules.
		$events = $this->get_wp_events();

		// delete the simple schedules from our plugin.
		foreach ( $this->get_schedule_object_names() as $obj_name ) {
			// get the object.
			$schedule_obj = new $obj_name();

			// bail if the object is not a Schedules_Base object.
			if ( ! $schedule_obj instanceof Schedules_Base ) {
				continue;
			}

			// get the schedule name.
			$schedule_name = $schedule_obj->get_name();

			// set attributes on the object, if available.
			if ( isset( $events[ $schedule_name ] ) && ! empty( $events[ $schedule_name ]['settings'][ array_key_first( $events[ $schedule_name ]['settings'] ) ]['args'] ) ) {
				$schedule_obj->set_args( $events[ $schedule_name ]['settings'][ array_key_first( $events[ $schedule_name ]['settings'] ) ]['args'] );
			}

			// delete the schedule.
			$schedule_obj->delete();
		}
	}

	/**
	 * Create our schedules.
	 *
	 * @return void
	 */
	public function create_schedules(): void {
		// install the schedules if they do not exist atm.
		foreach ( $this->get_schedule_object_names() as $obj_name ) {
			// get the object.
			$schedule_obj = new $obj_name();

			// bail if the object is not a Schedules_Base object.
			if ( ! $schedule_obj instanceof Schedules_Base ) {
				continue;
			}

			// install the schedule.
			$schedule_obj->install();
		}
	}

	/**
	 * Return the list of all schedule-object-names.
	 *
	 * @return array<string>
	 */
	public function get_schedule_object_names(): array {
		// list of schedules in this plugin.
		$list_of_schedules = array(
			'\NowPagesThreadi\Plugin\Schedules\EmptyNowPages',
			'\NowPagesThreadi\Plugin\Schedules\Fediverse',
			'\NowPagesThreadi\Plugin\Schedules\Import',
		);

		/**
		 * Add custom schedule-objects to use.
		 *
		 * They must be objects based on NowPagesThreadi\Plugin\Schedules_Base.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<string> $list_of_schedules List of additional schedules.
		 */
		return apply_filters( 'now_pages_threadi_schedules', $list_of_schedules );
	}

	/**
	 * Return the schedule object by its name.
	 *
	 * @param string $name The name of the object.
	 *
	 * @return false|Schedules_Base
	 */
	public function get_schedule_object_by_name( string $name ): false|Schedules_Base {
		foreach ( $this->get_schedule_object_names() as $object_name ) {
			// bail if class does not exist.
			if ( ! class_exists( $object_name ) ) {
				continue;
			}

			// get the object.
			$obj = new $object_name();

			// bail if the object is not a "Schedule_Base" object.
			if ( ! $obj instanceof Schedules_Base ) {
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
	 * Return our own events from the WP-cron-event-list.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function get_wp_events(): array {
		$our_events = array();
		foreach ( _get_cron_array() as $events ) {
			// bail if this is not an array.
			if ( ! is_array( $events ) ) { // @phpstan-ignore function.alreadyNarrowedType
				continue;
			}

			// check each entry.
			foreach ( $events as $event_name => $event_settings ) {
				// bail if this is not our own event (numeric hook names of other plugins are array keys of type int).
				if ( ! is_string( $event_name ) || ! str_contains( $event_name, 'now_pages_threadi_' ) ) {
					continue;
				}

				// add this event to the list.
				$our_events[ $event_name ] = array(
					'name'     => $event_name,
					'settings' => $event_settings,
				);
			}
		}

		// return the resulting list.
		return $our_events;
	}

	/**
	 * Run check for cronjob in the frontend, if enabled.
	 *
	 * @return void
	 */
	public function check_events_on_shutdown(): void {
		// bail if the check is disabled.
		if ( 1 !== absint( get_option( 'now_pages_threadi_enable_cron_check_in_frontend' ) ) ) {
			return;
		}

		// run the check.
		$this->check_events( $this->get_wp_events() );
	}

	/**
	 * Add a schedule to our list of schedules.
	 *
	 * @param mixed $event The event properties.
	 *
	 * @return mixed
	 * @noinspection PhpUnused
	 */
	public function add_schedule_to_list( mixed $event ): mixed {
		// bail if the event is not an object.
		if ( ! is_object( $event ) ) {
			return $event;
		}

		// bail if hook entity does not exist or is not a string.
		if ( ! isset( $event->hook ) || ! is_string( $event->hook ) ) {
			return $event;
		}

		// get our object.
		$schedule_obj = $this->get_schedule_object_by_name( $event->hook );

		// bail if this is not an event of our plugin.
		if ( ! $schedule_obj ) {
			return $event;
		}

		// add the args to the event.
		$schedule_obj->set_args( $event->args ); // @phpstan-ignore property.notFound

		// get the actual list.
		$list = get_option( 'now_pages_threadi_schedules' );
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		$list[ $schedule_obj->get_name() ] = $schedule_obj->get_args();
		update_option( 'now_pages_threadi_schedules', $list );

		// return the event object.
		return $event;
	}
}
