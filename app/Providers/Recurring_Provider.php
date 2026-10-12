<?php
/**
 * This file contains the provider for recurring entries.
 *
 * Unlike every other provider this one reads nothing. It repeats a
 * sentence the user wrote, for the things no service knows about: doing
 * sports once a week, the weekly market, the monthly game night.
 *
 * It still is a provider, and not a setting somewhere else: that way each
 * repetition is an ordinary entry of an ordinary source. Category, author,
 * language, the delay, the content filters and the Fediverse switch work
 * as they do for every other source, and a single repetition can be
 * deleted when the routine was skipped.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Providers;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use ThreadiNowPages\Digests\Periods;
use ThreadiNowPages\NowPages\Confirmation;
use ThreadiNowPages\NowPages\Entry;

/**
 * Object for the provider of recurring entries.
 */
class Recurring_Provider extends Provider_Base {
	/**
	 * The rhythm following the period of the now page.
	 *
	 * @var string
	 */
	public const RHYTHM_PERIOD = 'period';

	/**
	 * The rhythm for an entry on every day.
	 *
	 * @var string
	 */
	public const RHYTHM_DAILY = 'daily';

	/**
	 * The rhythm for an entry on chosen days of the week.
	 *
	 * @var string
	 */
	public const RHYTHM_WEEKLY = 'weekly';

	/**
	 * The rhythm for an entry on the first day of every month.
	 *
	 * @var string
	 */
	public const RHYTHM_MONTHLY = 'monthly';

	/**
	 * The periods of the now page which are shorter than a day, or move with the clock.
	 *
	 * An entry per hour would be noise, so these get one entry per day.
	 *
	 * @var array<int,string>
	 */
	private const SHORT_PERIODS = array( 'hour', 'live' );

	/**
	 * Return the provider slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'recurring';
	}

	/**
	 * Return the provider label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Recurring entry', 'threadi-now-pages' );
	}

	/**
	 * Return a description for this provider.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Repeats a sentence of your own on a schedule, for the things no service knows about - like doing sports once a week. Reads nothing from outside.', 'threadi-now-pages' );
	}

	/**
	 * Return the configuration fields for this provider.
	 *
	 * @return array<string,mixed>
	 */
	public function get_config_fields(): array {
		return array(
			'recurring_text'     => array(
				'label'       => __( 'Text of the entry', 'threadi-now-pages' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'The sentence which is repeated, written as you would write any other entry.', 'threadi-now-pages' ),
				'placeholder' => __( 'Went to the gym', 'threadi-now-pages' ),
				// these are the words of the user: whoever proposes them has to show them first.
				'review'      => true,
			),
			'recurring_rhythm'   => array(
				'label'       => __( 'Repeat', 'threadi-now-pages' ),
				'type'        => 'select',
				'default'     => self::RHYTHM_PERIOD,
				'options'     => $this->get_rhythms(),
				'description' => __( 'How often a new entry is created. "Once per period of the now page" follows the period chosen in the settings: with a weekly now page the entry is there every week, from its first day on.', 'threadi-now-pages' ),
			),
			'recurring_weekdays' => array(
				'label'       => __( 'Days of the week', 'threadi-now-pages' ),
				'type'        => 'multiselect',
				'options'     => $this->get_weekdays(),
				'description' => __( 'Only used with "Every week": an entry is created on each of the chosen days. Nothing selected means monday.', 'threadi-now-pages' ),
			),
			'recurring_confirm'  => array(
				'label'       => __( 'Ask me first', 'threadi-now-pages' ),
				'type'        => 'checkbox',
				'default'     => 0,
				'description' => __( 'If enabled, each new entry waits until you confirm it, in the list of entries or through the link in an email. An entry you do not confirm is discarded as soon as the next one is due. Useful if the routine does not happen every time.', 'threadi-now-pages' ),
			),
		);
	}

	/**
	 * Return the rhythms an entry can be repeated in.
	 *
	 * @return array<string,string> Map of slug => label.
	 */
	public function get_rhythms(): array {
		return array(
			self::RHYTHM_PERIOD  => __( 'Once per period of the now page', 'threadi-now-pages' ),
			self::RHYTHM_DAILY   => __( 'Every day', 'threadi-now-pages' ),
			self::RHYTHM_WEEKLY  => __( 'Every week', 'threadi-now-pages' ),
			self::RHYTHM_MONTHLY => __( 'Every month', 'threadi-now-pages' ),
		);
	}

	/**
	 * Return the days of the week.
	 *
	 * The keys are what date( 'D' ) returns in lowercase, whatever language the website speaks.
	 *
	 * @return array<string,string> Map of slug => label.
	 */
	public function get_weekdays(): array {
		return array(
			'mon' => __( 'Monday', 'threadi-now-pages' ),
			'tue' => __( 'Tuesday', 'threadi-now-pages' ),
			'wed' => __( 'Wednesday', 'threadi-now-pages' ),
			'thu' => __( 'Thursday', 'threadi-now-pages' ),
			'fri' => __( 'Friday', 'threadi-now-pages' ),
			'sat' => __( 'Saturday', 'threadi-now-pages' ),
			'sun' => __( 'Sunday', 'threadi-now-pages' ),
		);
	}

	/**
	 * Return the entry which is due.
	 *
	 * Always the latest one, on every run: whether it exists already is
	 * decided where every entry is stored, by its external ID. So nothing
	 * is caught up after a pause, and a repetition which was deleted by
	 * hand does not come back.
	 *
	 * @param array<string,mixed> $config  The configuration of the source.
	 * @param Provider_Context    $context The context of this run.
	 *
	 * @return array<int,Entry>
	 */
	public function fetch( array $config, Provider_Context $context ): array {
		$text = '';

		if ( isset( $config['recurring_text'] ) && is_scalar( $config['recurring_text'] ) ) {
			$text = trim( sanitize_text_field( (string) $config['recurring_text'] ) );
		}

		// bail if there is nothing to repeat.
		if ( '' === $text ) {
			return array();
		}

		$occurrence = $this->get_occurrence( $config, new DateTimeImmutable( 'now', wp_timezone() ) );

		$entry              = new Entry();
		$entry->text        = $text;
		$entry->date        = $occurrence;
		$entry->source_slug = $this->get_slug();
		$entry->language    = null;

		// the day makes the ID unique: one entry per day it is due on.
		$entry->external_id = 'recurring-' . $occurrence->format( 'Y-m-d' );

		// let it wait for the user if the source asks for that.
		if ( ! empty( $config['recurring_confirm'] ) ) {
			$entry->status = Confirmation::STATUS;
		}

		return array( $entry );
	}

	/**
	 * Return the day the latest entry is due on.
	 *
	 * @param array<string,mixed> $config The configuration of the source.
	 * @param DateTimeImmutable   $now    The current time, in the time zone of the website.
	 *
	 * @return DateTimeImmutable The start of that day, never in the future.
	 */
	public function get_occurrence( array $config, DateTimeImmutable $now ): DateTimeImmutable {
		$rhythm = self::RHYTHM_PERIOD;

		if ( isset( $config['recurring_rhythm'] ) && is_string( $config['recurring_rhythm'] ) && isset( $this->get_rhythms()[ $config['recurring_rhythm'] ] ) ) {
			$rhythm = $config['recurring_rhythm'];
		}

		$today = $now->setTime( 0, 0 );

		switch ( $rhythm ) {
			case self::RHYTHM_DAILY:
				return $today;

			case self::RHYTHM_WEEKLY:
				return $this->get_latest_weekday( $config, $today );

			case self::RHYTHM_MONTHLY:
				return $today->setDate( (int) $today->format( 'Y' ), (int) $today->format( 'n' ), 1 );

			default:
				return $this->get_start_of_period( $today );
		}
	}

	/**
	 * Return the latest of the chosen days of the week.
	 *
	 * @param array<string,mixed> $config The configuration of the source.
	 * @param DateTimeImmutable   $today  The start of today.
	 *
	 * @return DateTimeImmutable
	 */
	private function get_latest_weekday( array $config, DateTimeImmutable $today ): DateTimeImmutable {
		$days = array();

		if ( isset( $config['recurring_weekdays'] ) && is_array( $config['recurring_weekdays'] ) ) {
			$days = array_values( array_intersect( array_map( 'strval', $config['recurring_weekdays'] ), array_keys( $this->get_weekdays() ) ) );
		}

		// without a choice the week starts on monday.
		if ( empty( $days ) ) {
			$days = array( 'mon' );
		}

		// go back day by day, one of the seven is always chosen.
		for ( $back = 0; $back < 7; $back++ ) {
			$day = $today->modify( '-' . $back . ' days' );

			if ( in_array( strtolower( $day->format( 'D' ) ), $days, true ) ) {
				return $day;
			}
		}

		return $today;
	}

	/**
	 * Return the first day of the period the now page shows at the moment.
	 *
	 * @param DateTimeImmutable $today The start of today.
	 *
	 * @return DateTimeImmutable
	 */
	private function get_start_of_period( DateTimeImmutable $today ): DateTimeImmutable {
		$slug = (string) get_option( 'threadi_now_pages_frontend_period' );

		// bail for periods which are shorter than a day or move with the clock.
		if ( in_array( $slug, self::SHORT_PERIODS, true ) ) {
			return $today;
		}

		$range = Periods::get_instance()->get_period_or_fallback( $slug )->get_range( array() );
		$start = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $range['after'], wp_timezone() );

		// bail if the period could not tell where it starts, or starts later than today.
		if ( ! $start instanceof DateTimeImmutable || $start > $today ) {
			return $today;
		}

		return $start->setTime( 0, 0 );
	}

	/**
	 * Return the title for a new source of this provider: the sentence it repeats.
	 *
	 * Without it every source would be called "Recurring entry".
	 *
	 * @param array<string,mixed> $config The configuration of the source.
	 *
	 * @return string
	 */
	public function get_source_title( array $config ): string {
		if ( ! isset( $config['recurring_text'] ) || ! is_scalar( $config['recurring_text'] ) ) {
			return '';
		}

		return wp_trim_words( sanitize_text_field( (string) $config['recurring_text'] ), 8, '…' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_identifying_fields(): array {
		return array( 'recurring_text' );
	}
}
