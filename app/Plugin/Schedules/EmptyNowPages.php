<?php
/**
 * File to handle the report-schedule.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Plugin\Schedules;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Digests\Digest_Builder;
use NowPagesThreadi\Digests\Display_Settings;
use NowPagesThreadi\NowPages\Entries;
use NowPagesThreadi\NowPages\Frontend;
use NowPagesThreadi\Plugin\Schedules_Base;
use NowPagesThreadi\Sources\Sources;
use WP_User;
use WP_User_Query;

/**
 * Object for this schedule.
 */
class EmptyNowPages extends Schedules_Base {

	/**
	 * Name of this event.
	 *
	 * @var string
	 */
	protected string $name = 'now_pages_threadi_schedule_empty_now_pages_threadi';

	/**
	 * Name of the option used to enable this event.
	 *
	 * @var string
	 */
	protected string $option_name = 'now_pages_threadi_email_empty_now_pages_threadi';

	/**
	 * Name of the option used for the interval of this event.
	 *
	 * @var string
	 */
	protected string $interval_option_name = 'now_pages_threadi_email_interval_empty_now_pages_threadi';

	/**
	 * Define the default interval.
	 *
	 * @var string
	 */
	protected string $default_interval = 'now_pages_threadi_daily';

	/**
	 * Send the first email one interval after the event has been created, not directly.
	 *
	 * @var bool
	 */
	protected bool $delay_first_run = true;

	/**
	 * Name of the log category.
	 *
	 * @var string
	 */
	protected string $log_category = 'emails';

	/**
	 * Initialize this schedule.
	 */
	public function __construct() {
		// get interval from settings.
		$this->interval = (string) get_option( $this->get_interval_option_name() );

		// fall back to the class default if the option is empty.
		if ( empty( $this->interval ) ) {
			$this->interval = $this->get_default_interval();
		}
	}

	/**
	 * Run this schedule.
	 *
	 * @return void
	 */
	public function run(): void {
		// bail if setting is not enabled.
		if ( ! $this->is_enabled() ) {
			return;
		}

		// bail if no sources are configured.
		if ( empty( Sources::get_instance()->get_configured_sources() ) ) {
			return;
		}

		// get the entries on the now page.
		$args    = array(
			'period' => get_option( 'now_pages_threadi_frontend_period' ),
			// use the verbatim format: a narrative summary may be empty for a moment
			// (e.g. AI provider down), which would send a wrong hint.
			'format' => 'verbatim',
		);
		$builder = new Digest_Builder();
		$content = $builder->build( $args );

		// bail if list of entries is not empty.
		if ( empty( $content ) ) {
			// send the report.
			$mail_object = new \NowPagesThreadi\Plugin\Emails\EmptyNowPages();
			$mail_object->send();
		}

		// if user pages are enabled and public, check them too.
		if ( 1 === absint( get_option( 'now_pages_threadi_frontend_user_url' ) ) && Frontend::get_instance()->is_public( true ) ) {
			$this->check_user_pages();
		}
	}

	/**
	 * Check the now pages of all users, who could have entries, and inform them if theirs is empty.
	 *
	 * Only users with the capability to edit entries, who own at least one
	 * published source or entry, get this email, not every subscriber or
	 * customer. Each user gets it only once per empty phase of their now page.
	 * The users are loaded in batches.
	 *
	 * @return void
	 */
	private function check_user_pages(): void {
		$keys = Display_Settings::user_meta_keys();

		// get the capability to create entries.
		$capability = 'edit_' . Entries::get_instance()->get_slug() . 's';

		// the number of users per batch.
		$per_page = 100;

		$page = 1;
		do {
			// get the users with enabled now page, who use the now pages (with at least one published source or entry).
			$query = array(
				'capability'          => $capability,
				'number'              => $per_page,
				'paged'               => $page,
				'orderby'             => 'ID',
				'order'               => 'ASC',
				'count_total'         => false,
				'has_published_posts' => array( Sources::get_instance()->get_slug(), Entries::get_instance()->get_slug() ),
				'meta_query'          => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Query for specific user meta field.
					'relation' => 'OR',
					array(
						'key'     => $keys['disabled'],
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => $keys['disabled'],
						'value'   => 0,
						'compare' => '=',
					),
				),
			);
			$users = ( new WP_User_Query( $query ) )->get_results();

			foreach ( $users as $user ) {
				// bail if this is not a user object.
				if ( ! $user instanceof WP_User ) {
					continue;
				}

				// get the entries on the now page.
				$args    = array(
					'period' => get_option( 'now_pages_threadi_frontend_period' ),
					'format' => 'verbatim',
					'user'   => $user->ID,
				);
				$builder = new Digest_Builder();
				$content = $builder->build( $args );

				// bail if list of entries is not empty, and allow a new email for the next empty phase.
				if ( ! empty( $content ) ) {
					if ( get_user_meta( $user->ID, \NowPagesThreadi\Plugin\Emails\EmptyNowPages::USER_META_SENT, true ) ) {
						delete_user_meta( $user->ID, \NowPagesThreadi\Plugin\Emails\EmptyNowPages::USER_META_SENT );
					}
					continue;
				}

				// bail if the user already got this email for the actual empty phase.
				if ( get_user_meta( $user->ID, \NowPagesThreadi\Plugin\Emails\EmptyNowPages::USER_META_SENT, true ) ) {
					continue;
				}

				// send the report.
				$mail_object = new \NowPagesThreadi\Plugin\Emails\EmptyNowPages();
				$mail_object->set_user( $user );
				$mail_object->send();
			}

			// clean the cache of users of this batch.
			Digest_Builder::reset_cache();

			// load the next batch, if this one was full.
			$has_more = count( $users ) === $per_page;
			++$page;
		} while ( $has_more );
	}

	/**
	 * Install this schedule.
	 *
	 * @return void
	 */
	public function install(): void {
		// bail if it is not enabled.
		if ( ! $this->is_enabled() ) {
			return;
		}

		// install this schedule.
		parent::install();
	}
}
