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
use NowPagesThreadi\Digests\Digest_Builder;
use NowPagesThreadi\NowPages\Entries;
use NowPagesThreadi\Plugin\Email_Base;
use NowPagesThreadi\Plugin\Intervals;
use NowPagesThreadi\Sources\Sources;
use WP_Query;

/**
 * Object that handles the report about the entries.
 */
class Report extends Email_Base {
	/**
	 * THe internal name of this object.
	 *
	 * @var string
	 */
	protected string $name = 'report';

	/**
	 * The period this report covers, see get_period().
	 *
	 * @var array{0:string,1:string}|null
	 */
	private ?array $period = null;

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
		return __( 'Report about your entries', 'now-pages-threadi' );
	}

	/**
	 * Return the description.
	 *
	 * @return string
	 */
	protected function get_description(): string {
		return __( 'If activated, an email is sent to the specified email address with a report about the entries for your now page in your website.', 'now-pages-threadi' );
	}

	/**
	 * Show the description for this email object.
	 *
	 * @return void
	 */
	public function show_description(): void {
		echo esc_html__( 'The report contains information about the entries on your website.', 'now-pages-threadi' );
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
		$this->subject = '[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] ' . __( 'Report about your Now Page entries', 'now-pages-threadi' );

		// return the parent tasks for the subject.
		return parent::get_subject();
	}

	/**
	 * Return the body.
	 *
	 * @return string
	 */
	public function get_body(): string {
		list( $start, $end ) = $this->get_period();

		// intro with the covered period.
		$body = '<p>' . sprintf(
			/* translators: %1$s will be replaced by a date, %2$s by a date. */
			esc_html__( 'This report covers your Now Page entries between %1$s and %2$s.', 'now-pages-threadi' ),
			esc_html( $this->format_date( $start ) ),
			esc_html( $this->format_date( $end ) )
		) . '</p>';

		// the numbers.
		$count = $this->count_entries( $start, $end );

		$body .= '<p><strong>' . esc_html(
			sprintf(
				/* translators: %1$d will be replaced by a number. */
				_n( '%1$d new entry', '%1$d new entries', $count, 'now-pages-threadi' ),
				$count
			)
		) . '</strong></p>';

		// the entries themselves.
		$digest = $this->get_digest( $start, $end );

		if ( '' !== $digest ) {
			$body .= '<h2>' . esc_html__( 'Your entries', 'now-pages-threadi' ) . '</h2>' . $digest;
		}

		// sources which delivered nothing.
		$silent = $this->get_silent_sources( $start, $end );

		if ( ! empty( $silent ) ) {
			$body .= '<h2>' . esc_html__( 'Sources without new entries', 'now-pages-threadi' ) . '</h2>';
			$body .= '<p>' . esc_html__( 'These sources delivered nothing in this period. If that is unexpected, check their configuration.', 'now-pages-threadi' ) . '</p><ul>';

			foreach ( $silent as $source_id => $title ) {
				// build the edit link directly, get_edit_post_link() returns nothing without a logged-in user (e.g. in cron).
				$body .= '<li><a href="' . esc_url( admin_url( 'post.php?post=' . $source_id . '&action=edit' ) ) . '">' . esc_html( $title ) . '</a></li>';
			}

			$body .= '</ul>';
		}

		// set the body.
		$this->body = $body;

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
		if ( absint( get_option( 'now_pages_threadi_email_report' ) ) === absint( $value ) ) {
			return $value;
		}

		// let reconcile_events() handle the (re)installation on shutdown.
		( new \NowPagesThreadi\Plugin\Schedules\Report() )->delete();

		// return the new value to save it via WP.
		return $value;
	}

	/**
	 * Return the period this report covers.
	 *
	 * @return array{0:string,1:string} Start and end in Y-m-d H:i:s.
	 */
	private function get_period(): array {
		// use the period already calculated for this email, as the body is requested multiple times.
		if ( is_array( $this->period ) ) {
			return $this->period;
		}

		$end   = current_time( 'mysql' );
		$start = (string) get_option( 'now_pages_threadi_email_report_last_run', '' );

		// no last run known (first report or test): derive it from the interval.
		if ( '' === $start ) {
			$schedules = wp_get_schedules();
			$interval  = (string) get_option( 'now_pages_threadi_email_interval_report', 'now_pages_threadi_weekly' );
			$seconds   = absint( $schedules[ $interval ]['interval'] ?? WEEK_IN_SECONDS );

			$start = gmdate( 'Y-m-d H:i:s', strtotime( $end ) - $seconds );
		}

		$this->period = array( $start, $end );

		return $this->period;
	}

	/**
	 * Remember the end of the covered period after the report has been sent.
	 *
	 * The next report starts there, so a delayed run of the schedule leaves no gap.
	 *
	 * @return void
	 */
	protected function after_send(): void {
		list( , $end ) = $this->get_period();

		update_option( 'now_pages_threadi_email_report_last_run', $end, false );
	}

	/**
	 * Return all sources which delivered nothing in the given period.
	 *
	 * @param string $start Start of the period in Y-m-d H:i:s.
	 * @param string $end   End of the period in Y-m-d H:i:s.
	 *
	 * @return array<int,string> Map of source ID => source title.
	 */
	private function get_silent_sources( string $start, string $end ): array {
		$silent = array();

		foreach ( Sources::get_instance()->get_configured_sources() as $source ) {
			$query = new WP_Query(
				array(
					'post_type'      => Entries::get_instance()->get_slug(),
					'post_status'    => 'publish',
					'post_parent'    => $source['source_id'],
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'date_query'     => array(
						array(
							'after'     => $start,
							'before'    => $end,
							'inclusive' => true,
						),
					),
				)
			);

			if ( ! $query->have_posts() ) {
				$silent[ $source['source_id'] ] = get_the_title( $source['source_id'] );
			}
		}

		return $silent;
	}

	/**
	 * Count the entries created in the given period.
	 *
	 * Deliberately unfiltered: no language, no author, no delay. The report
	 * shows the owner what actually arrived, not what the now page displays.
	 *
	 * @param string $start Start of the period in Y-m-d H:i:s.
	 * @param string $end   End of the period in Y-m-d H:i:s.
	 *
	 * @return int
	 */
	private function count_entries( string $start, string $end ): int {
		$query = new WP_Query(
			array(
				'post_type'      => Entries::get_instance()->get_slug(),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'orderby'        => 'none',
				'date_query'     => array(
					array(
						'after'     => $start,
						'before'    => $end,
						'inclusive' => true,
					),
				),
			)
		);

		// return the number of matching entries.
		return absint( $query->found_posts );
	}

	/**
	 * Format a MySQL datetime for display in the email.
	 *
	 * @param string $value The datetime in Y-m-d H:i:s.
	 *
	 * @return string
	 */
	private function format_date( string $value ): string {
		// bail if the value is empty.
		if ( '' === $value ) {
			return '';
		}

		// use the date and time format of this website.
		return (string) mysql2date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$value
		);
	}

	/**
	 * Return the entries of the given period as rendered digest.
	 *
	 * Deliberately overrides the privacy and language filters: this report goes
	 * to the site owner, who should see everything that arrived - including the
	 * entries the now page still hides behind the configured delay.
	 *
	 * @param string $start Start of the period in Y-m-d H:i:s.
	 * @param string $end   End of the period in Y-m-d H:i:s.
	 *
	 * @return string Empty string if the period holds no entries.
	 */
	private function get_digest( string $start, string $end ): string {
		$args = array(
			'period'            => 'range',
			'start'             => $start,
			'end'               => $end,
			'format'            => 'verbatim',
			'language'          => '',
			'delay_hours'       => 0,
			'group_by_category' => true,
			'show_time'         => true,
			'show_source'       => true,
			'link_source'       => true,
			'ignore_overrides'  => true,
		);

		/**
		 * Filter the digest args used for the report email.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<string,mixed> $args  Builder args.
		 * @param string              $start Start of the period.
		 * @param string              $end   End of the period.
		 */
		$args = apply_filters( 'now_pages_threadi_report_digest_args', $args, $start, $end );

		// the report goes to the administrator only: show every entry of the period.
		return ( new Digest_Builder() )->set_ignore_user_restrictions( true )->build( $args );
	}
}
