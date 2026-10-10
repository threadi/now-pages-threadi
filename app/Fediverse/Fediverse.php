<?php
/**
 * This file contains the sharing of the now page in the Fediverse.
 *
 * Nothing is federated by this plugin itself. It creates posts of the type
 * "update" (see Updates), the ActivityPub plugin delivers them to the
 * followers of the website or of the author, and stores what comes back -
 * replies, likes and boosts - as comments on the update.
 *
 * There are two ways to share:
 * - a summary: one update per day or week, built like the now page.
 * - single entries: one update per entry.
 *
 * In both cases only what visitors of the now page can see is shared, and
 * not before they can see it: the privacy delay applies here as well. Once
 * something has reached other servers it cannot be taken back reliably,
 * so sharing too early would defeat the delay for good.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Fediverse;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use DateInterval;
use DateTimeImmutable;
use easySettingsForWordPress\Fields\Select;
use NowPagesThreadi\Digests\Digest_Builder;
use NowPagesThreadi\Digests\Digests;
use NowPagesThreadi\Digests\Display_Settings;
use NowPagesThreadi\Helper\Format;
use NowPagesThreadi\Helper\Singleton_Interface;
use NowPagesThreadi\Helper\Singleton_Trait;
use NowPagesThreadi\NowPages\Entries;
use NowPagesThreadi\NowPages\Frontend;
use NowPagesThreadi\NowPages\Now_Page;
use NowPagesThreadi\Plugin\Log;
use NowPagesThreadi\Plugin\Settings;
use NowPagesThreadi\Presets\Presets;
use WP_Post;
use WP_Query;

/**
 * Object to share the now page in the Fediverse.
 */
class Fediverse implements Singleton_Interface {
	use Singleton_Trait;

	/**
	 * Mode: nothing is shared.
	 *
	 * @var string
	 */
	public const MODE_OFF = 'off';

	/**
	 * Mode: a summary per period is shared.
	 *
	 * @var string
	 */
	public const MODE_DIGEST = 'digest';

	/**
	 * Mode: each entry is shared on its own.
	 *
	 * @var string
	 */
	public const MODE_ENTRIES = 'entries';

	/**
	 * Option: what is shared (off, digest or entries).
	 *
	 * @var string
	 */
	public const OPTION_MODE = 'now_pages_threadi_fediverse_mode';

	/**
	 * Option: the period a summary covers (day or week).
	 *
	 * @var string
	 */
	public const OPTION_INTERVAL = 'now_pages_threadi_fediverse_interval';

	/**
	 * Option: the GMT timestamp since when things are shared. Nothing older is ever shared.
	 *
	 * @var string
	 */
	public const OPTION_SINCE = 'now_pages_threadi_fediverse_since';

	/**
	 * Option: the end of the last period a summary was handled for, in the time of the website.
	 *
	 * @var string
	 */
	public const OPTION_LAST_DIGEST = 'now_pages_threadi_fediverse_last_digest';

	/**
	 * Option: the ID of the user summaries are published as.
	 *
	 * @var string
	 */
	public const OPTION_AUTHOR = 'now_pages_threadi_fediverse_author';

	/**
	 * Post meta on an entry: the ID of the update it was shared with, 0 if it was skipped.
	 *
	 * @var string
	 */
	public const META_UPDATE = '_now_pages_threadi_fediverse_update';

	/**
	 * Post meta on an update: the ID of the entry it shares.
	 *
	 * @var string
	 */
	public const META_ENTRY = '_now_pages_threadi_fediverse_entry';

	/**
	 * Post meta on a source: 1 if its entries are not shared, 0 if they are.
	 *
	 * @var string
	 */
	public const META_SOURCE_EXCLUDED = 'fediverse_exclude';

	/**
	 * The name of our settings tab.
	 *
	 * @var string
	 */
	public const SETTINGS_TAB = 'now_pages_threadi_fediverse';

	/**
	 * How many single entries are shared per run. Protects followers from a flood after a big import.
	 *
	 * @var int
	 */
	private const ENTRIES_PER_RUN = 5;

	/**
	 * How far back single entries are looked for, in days.
	 *
	 * @var int
	 */
	private const LOOKBACK_DAYS = 7;

	/**
	 * Constructor, not used as this a Singleton object.
	 */
	private function __construct() {}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'add_settings' ), 22 );
		add_action( 'init', array( $this, 'add_post_type_support' ), 20 );
		add_filter( 'post_type_link', array( $this, 'get_update_link' ), 10, 2 );

		// start to share from the moment it is switched on, not from whatever exists already.
		add_action( 'add_option_' . self::OPTION_MODE, array( $this, 'mode_added' ), 10, 2 );
		add_action( 'update_option_' . self::OPTION_MODE, array( $this, 'mode_changed' ), 10, 2 );

		// replies from the Fediverse belong to something which is not a page of this website: keep them out of its comment lists.
		add_filter( 'comment_feed_where', array( $this, 'hide_replies_from_comment_feed' ), 10, 2 );
		add_filter( 'widget_comments_args', array( $this, 'hide_replies_from_comment_list' ) );

		// take back what was shared of an entry which is removed.
		add_action( 'wp_trash_post', array( $this, 'entry_removed' ) );
		add_action( 'before_delete_post', array( $this, 'entry_removed' ) );
		add_action( 'transition_post_status', array( $this, 'entry_unpublished' ), 10, 3 );
	}

	/**
	 * Return whether the ActivityPub plugin is active.
	 *
	 * @return bool
	 */
	public function is_activitypub_active(): bool {
		/**
		 * Filter whether something is there which federates our updates.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param bool $active True if the ActivityPub plugin is active.
		 */
		return (bool) apply_filters( 'now_pages_threadi_fediverse_is_available', defined( 'ACTIVITYPUB_PLUGIN_VERSION' ) );
	}

	/**
	 * Return what is shared.
	 *
	 * @return string One of the MODE_* constants.
	 */
	public function get_mode(): string {
		$mode = (string) get_option( self::OPTION_MODE, self::MODE_OFF );

		return in_array( $mode, array( self::MODE_DIGEST, self::MODE_ENTRIES ), true ) ? $mode : self::MODE_OFF;
	}

	/**
	 * Return whether something is shared at all.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return self::MODE_OFF !== $this->get_mode() && $this->is_activitypub_active();
	}

	/**
	 * Tell the ActivityPub plugin that updates are meant to be federated.
	 *
	 * @return void
	 */
	public function add_post_type_support(): void {
		// bail if nothing is shared: without the support the ActivityPub plugin ignores the post type.
		if ( ! $this->is_enabled() ) {
			return;
		}

		add_post_type_support( $this->get_post_type(), 'activitypub' );
	}

	/**
	 * Return the post type of the updates.
	 *
	 * @return string
	 */
	public function get_post_type(): string {
		return Updates::get_instance()->get_slug();
	}

	/**
	 * Point the link of an update to the now page.
	 *
	 * An update has no page of its own. Whoever follows its link - a
	 * follower who opens the original, or anybody in the backend - gets to
	 * the place its content is shown.
	 *
	 * @param mixed $link The link WordPress built.
	 * @param mixed $post The post.
	 *
	 * @return mixed
	 */
	public function get_update_link( mixed $link, mixed $post ): mixed {
		// bail if this is not an update.
		if ( ! $post instanceof WP_Post || $this->get_post_type() !== $post->post_type ) {
			return $link;
		}

		return $this->get_now_page_url();
	}

	/**
	 * Return the address of the now page.
	 *
	 * @return string
	 */
	public function get_now_page_url(): string {
		$page = Now_Page::get_instance()->get_page();

		if ( $page instanceof WP_Post ) {
			return (string) get_permalink( $page );
		}

		return Frontend::get_instance()->get_global_now_page_url();
	}

	/**
	 * Start to share from now on, when the mode is saved for the first time.
	 *
	 * @param mixed $option The name of the option.
	 * @param mixed $value  The value.
	 *
	 * @return void
	 * @noinspection PhpUnusedParameterInspection
	 */
	public function mode_added( mixed $option, mixed $value ): void {
		$this->mode_changed( self::MODE_OFF, $value );
	}

	/**
	 * Start to share from now on, whenever the mode changes.
	 *
	 * Without this, switching to single entries would send everything of
	 * the last days at once, and switching to summaries would send one for
	 * a period nobody asked to share.
	 *
	 * @param mixed $old_value The mode before.
	 * @param mixed $value     The mode now.
	 *
	 * @return void
	 */
	public function mode_changed( mixed $old_value, mixed $value ): void {
		// bail if nothing changed.
		if ( $old_value === $value ) {
			return;
		}

		update_option( self::OPTION_SINCE, time(), false );
		delete_option( self::OPTION_LAST_DIGEST );

		// summaries are published in the name of whoever switched this on.
		if ( get_current_user_id() > 0 ) {
			update_option( self::OPTION_AUTHOR, get_current_user_id(), false );
		}
	}

	/**
	 * Share what is due. Called by our schedule.
	 *
	 * @return int How many updates were created.
	 */
	public function run(): int {
		// bail if nothing is shared.
		if ( ! $this->is_enabled() ) {
			return 0;
		}

		// bail if visitors cannot see the now page: what is not public there is not public anywhere.
		if ( ! Frontend::get_instance()->is_public() ) {
			return 0;
		}

		// the first run only sets the starting point.
		if ( 0 === $this->get_since() ) {
			update_option( self::OPTION_SINCE, time(), false );

			return 0;
		}

		if ( self::MODE_DIGEST === $this->get_mode() ) {
			return $this->share_digest();
		}

		return $this->share_entries();
	}

	/**
	 * Return the GMT timestamp since when things are shared.
	 *
	 * @return int Zero if it has not been set yet.
	 */
	private function get_since(): int {
		return absint( get_option( self::OPTION_SINCE, 0 ) );
	}

	/**
	 * Return the privacy delay of the global now page in hours.
	 *
	 * @return int
	 */
	private function get_delay_hours(): int {
		return absint( Display_Settings::resolve( array( 'user_id' => 0 ) )['delay_hours'] ?? 0 );
	}

	/**
	 * Return the last complete period a summary can be shared for.
	 *
	 * @param DateTimeImmutable $now The current time, in the timezone of the website.
	 *
	 * @return array{start:DateTimeImmutable,end:DateTimeImmutable}
	 */
	public function get_last_period( DateTimeImmutable $now ): array {
		$today = $now->setTime( 0, 0 );

		// a week starts on the day set in the general settings of WordPress.
		if ( 'week' === get_option( self::OPTION_INTERVAL, 'day' ) ) {
			$days_into_week = ( (int) $today->format( 'w' ) - absint( get_option( 'start_of_week', 1 ) ) + 7 ) % 7;
			$week_start     = $today->sub( new DateInterval( 'P' . $days_into_week . 'D' ) );

			return array(
				'start' => $week_start->sub( new DateInterval( 'P7D' ) ),
				'end'   => $week_start->sub( new DateInterval( 'PT1S' ) ),
			);
		}

		return array(
			'start' => $today->sub( new DateInterval( 'P1D' ) ),
			'end'   => $today->sub( new DateInterval( 'PT1S' ) ),
		);
	}

	/**
	 * Return the period following the given end of a period.
	 *
	 * @param DateTimeImmutable $end The end of a period, in the timezone of the website.
	 *
	 * @return array{start:DateTimeImmutable,end:DateTimeImmutable}
	 */
	private function get_next_period( DateTimeImmutable $end ): array {
		$interval = 'week' === get_option( self::OPTION_INTERVAL, 'day' ) ? 'P7D' : 'P1D';

		// seen from the start of the period after it, the next period is the last complete one.
		return $this->get_last_period( $end->add( new DateInterval( 'PT1S' ) )->add( new DateInterval( $interval ) ) );
	}

	/**
	 * Share the summary of the next period which is due.
	 *
	 * Starts after the period handled last, so a period missed by the
	 * schedule is caught up on, one per run. Without a handled period
	 * it is the last one whose privacy delay is over.
	 *
	 * @return int How many updates were created.
	 */
	private function share_digest(): int {
		$now   = current_datetime();
		$delay = $this->get_delay_hours() * HOUR_IN_SECONDS;

		// the last complete period at the time the privacy delay looks back to.
		$period = $this->get_last_period( $now->setTimestamp( $now->getTimestamp() - $delay ) );

		// continue after the period handled last, if there is one and it is not before sharing was switched on.
		$last_digest = (string) get_option( self::OPTION_LAST_DIGEST, '' );
		$last_end    = '' !== $last_digest ? DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $last_digest, wp_timezone() ) : false;
		if ( $last_end instanceof DateTimeImmutable ) {
			$next_period = $this->get_next_period( $last_end );

			if ( $next_period['end']->getTimestamp() > $this->get_since() ) {
				$period = $next_period;
			}
		}

		$end = $period['end']->format( 'Y-m-d H:i:s' );

		// bail if this period has been handled already.
		if ( $last_digest >= $end ) {
			return 0;
		}

		// bail if this period ended before sharing was switched on.
		if ( $period['end']->getTimestamp() <= $this->get_since() ) {
			return 0;
		}

		// bail if the end of the period is still within the privacy delay: its last entries
		// would be missing now, and no later summary would ever make up for them.
		if ( $period['end']->getTimestamp() + $delay > $now->getTimestamp() ) {
			return 0;
		}

		// the first summary must not tell what happened before sharing was switched on.
		$start = $period['start'];
		if ( $start->getTimestamp() < $this->get_since() ) {
			$start = $start->setTimestamp( $this->get_since() );
		}

		$content = $this->get_digest_content( $start, $period['end'] );

		// this period is done, whether there was something to tell or not.
		update_option( self::OPTION_LAST_DIGEST, $end, false );

		// bail if nothing happened in this period.
		if ( '' === $content ) {
			return 0;
		}

		$update_id = $this->create_update( $content, $this->get_digest_author(), 0 );

		return $update_id > 0 ? 1 : 0;
	}

	/**
	 * Return the summary of a period as the content of an update.
	 *
	 * Built by the same builder as the now page, with its format, its
	 * categories and its override texts, so both tell the same story.
	 *
	 * @param DateTimeImmutable $start The start of the period.
	 * @param DateTimeImmutable $end   The end of the period.
	 *
	 * @return string Empty string if there is nothing to share.
	 */
	public function get_digest_content( DateTimeImmutable $start, DateTimeImmutable $end ): string {
		// the notice about the delay is meant for somebody looking at the now page.
		add_filter( 'now_pages_threadi_digest_delay_notice', '__return_empty_string', PHP_INT_MAX );

		$digest = ( new Digest_Builder() )->build(
			array(
				'period'      => 'range',
				'start'       => $start->format( 'Y-m-d H:i:s' ),
				'end'         => $end->format( 'Y-m-d H:i:s' ),
				'format'      => (string) get_option( 'now_pages_threadi_frontend_format', Digests::get_instance()->get_default_format() ),
				'show_period' => false,
				'show_time'   => false,
				'show_source' => false,
			)
		);

		remove_filter( 'now_pages_threadi_digest_delay_notice', '__return_empty_string', PHP_INT_MAX );

		$digest = $this->get_note_html( $digest );

		// bail if nothing is left.
		if ( '' === trim( wp_strip_all_tags( $digest ) ) ) {
			return '';
		}

		$content = '<p>' . esc_html( $this->get_digest_headline( $start, $end ) ) . '</p>' . $digest;

		/**
		 * Filter the content of a summary before it is shared in the Fediverse.
		 *
		 * Return an empty string to not share this summary.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param string            $content The content, simple HTML.
		 * @param DateTimeImmutable $start   The start of the period.
		 * @param DateTimeImmutable $end     The end of the period.
		 */
		return (string) apply_filters( 'now_pages_threadi_fediverse_digest_content', $content, $start, $end );
	}

	/**
	 * Return the first line of a summary: what it is and which period it covers.
	 *
	 * @param DateTimeImmutable $start The start of the period.
	 * @param DateTimeImmutable $end   The end of the period.
	 *
	 * @return string
	 */
	private function get_digest_headline( DateTimeImmutable $start, DateTimeImmutable $end ): string {
		$format = (string) get_option( 'date_format', 'Y-m-d' );

		// a single day.
		if ( $start->format( 'Y-m-d' ) === $end->format( 'Y-m-d' ) ) {
			/* translators: %1$s will be replaced by a date. */
			return sprintf( __( 'What I was up to on %1$s:', 'now-pages-threadi' ), wp_date( $format, $start->getTimestamp() ) );
		}

		/* translators: %1$s and %2$s will be replaced by dates. */
		return sprintf( __( 'What I was up to from %1$s to %2$s:', 'now-pages-threadi' ), wp_date( $format, $start->getTimestamp() ), wp_date( $format, $end->getTimestamp() ) );
	}

	/**
	 * Reduce the markup of the now page to what other servers show.
	 *
	 * Most of them accept paragraphs, lists, links and emphasis, and drop
	 * everything else including its text structure.
	 *
	 * @param string $html The markup of a digest.
	 *
	 * @return string
	 */
	private function get_note_html( string $html ): string {
		// a headline becomes a bold line.
		$html = (string) preg_replace( '#<h[1-6][^>]*>(.*?)</h[1-6]>#is', '<p><strong>$1</strong></p>', $html );

		// a wrapper becomes a paragraph, unless it holds paragraphs itself.
		$html = (string) preg_replace_callback(
			'#<div[^>]*>(.*?)</div>#is',
			static fn( array $matches ): string => str_contains( $matches[1], '<p' ) ? $matches[1] : '<p>' . $matches[1] . '</p>',
			$html
		);

		$allowed = array(
			'p'          => array(),
			'br'         => array(),
			'ul'         => array(),
			'ol'         => array(),
			'li'         => array(),
			'strong'     => array(),
			'em'         => array(),
			'b'          => array(),
			'i'          => array(),
			'blockquote' => array(),
			'a'          => array( 'href' => true ),
		);

		return trim( wp_kses( $html, $allowed ) );
	}

	/**
	 * Return the user summaries are published as.
	 *
	 * Only matters if the ActivityPub plugin federates per author. With
	 * its blog profile every update is sent in the name of the website.
	 *
	 * @return int
	 */
	private function get_digest_author(): int {
		$author = absint( get_option( self::OPTION_AUTHOR, 0 ) );

		// use the configured user, if it still exists.
		if ( $author > 0 && get_userdata( $author ) ) {
			return $author;
		}

		// fall back to the first administrator.
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'fields'  => 'ID',
			)
		);

		return absint( $admins[0] ?? 0 );
	}

	/**
	 * Share the single entries which are due.
	 *
	 * @return int How many updates were created.
	 */
	private function share_entries(): int {
		$now = current_datetime();

		// look back to when sharing was switched on, but not endlessly.
		$since = max( $this->get_since(), $now->getTimestamp() - ( self::LOOKBACK_DAYS * DAY_IN_SECONDS ) );

		// get the entries visitors of the now page can see: the builder applies the privacy
		// delay, the delay of single authors and everything else that hides an entry there.
		$entries = ( new Digest_Builder() )->get_entries(
			array(
				'period' => 'range',
				'start'  => wp_date( 'Y-m-d H:i:s', $since ),
				'end'    => $now->format( 'Y-m-d H:i:s' ),
				'order'  => 'ASC',
			)
		);

		/**
		 * Filter how many single entries are shared in the Fediverse per run.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param int $limit The number of entries.
		 */
		$limit   = max( 1, absint( apply_filters( 'now_pages_threadi_fediverse_entries_per_run', self::ENTRIES_PER_RUN ) ) );
		$created = 0;

		foreach ( $entries as $entry ) {
			// bail if this entry has been handled already.
			if ( metadata_exists( 'post', $entry->ID, self::META_UPDATE ) ) {
				continue;
			}

			// remember entries which are not shared, so they are not looked at again.
			if ( ! $this->is_entry_shared( $entry ) ) {
				update_post_meta( $entry->ID, self::META_UPDATE, 0 );
				continue;
			}

			$update_id = $this->create_update( $this->get_entry_content( $entry ), absint( $entry->post_author ), $entry->ID );

			// bail if the update could not be created, the next run tries again.
			if ( 0 === $update_id ) {
				continue;
			}

			update_post_meta( $entry->ID, self::META_UPDATE, $update_id );

			++$created;

			if ( $created >= $limit ) {
				break;
			}
		}

		return $created;
	}

	/**
	 * Return whether an entry is shared on its own.
	 *
	 * @param WP_Post $entry The entry.
	 *
	 * @return bool
	 */
	private function is_entry_shared( WP_Post $entry ): bool {
		$source_id = absint( $entry->post_parent );
		$shared    = 0 === $source_id || $this->is_source_shared( $source_id );

		/**
		 * Filter whether a single entry is shared in the Fediverse.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param bool    $shared    Whether it is shared.
		 * @param WP_Post $entry     The entry.
		 * @param int     $source_id The ID of its source, 0 for an entry written by hand.
		 */
		return (bool) apply_filters( 'now_pages_threadi_fediverse_share_entry', $shared, $entry, $source_id );
	}

	/**
	 * Return whether the entries of a source are shared on their own.
	 *
	 * What comes from the Fediverse is not sent back to it by default:
	 * followers would get every post a second time.
	 *
	 * @param int $source_id The ID of the source.
	 *
	 * @return bool
	 */
	public function is_source_shared( int $source_id ): bool {
		// the choice made on the source wins.
		if ( metadata_exists( 'post', $source_id, self::META_SOURCE_EXCLUDED ) ) {
			return 1 !== absint( get_post_meta( $source_id, self::META_SOURCE_EXCLUDED, true ) );
		}

		return 'mastodon' !== (string) get_post_meta( $source_id, Presets::SOURCE_META, true );
	}

	/**
	 * Return a single entry as the content of an update.
	 *
	 * @param WP_Post $entry The entry.
	 *
	 * @return string
	 */
	private function get_entry_content( WP_Post $entry ): string {
		$content = '<p>' . Format::linkify_text( Format::get_entry_text( $entry ) ) . '</p>';

		// name where it comes from, if the now page does so as well.
		$source_url = (string) get_post_meta( $entry->ID, 'source_url', true );

		if ( '' !== $source_url && ! empty( Display_Settings::resolve( array( 'user_id' => 0 ) )['link_source'] ) && ! str_contains( $content, esc_url( $source_url ) ) ) {
			$content .= '<p><a href="' . esc_url( $source_url ) . '">' . esc_html( $source_url ) . '</a></p>';
		}

		/**
		 * Filter the content of a single entry before it is shared in the Fediverse.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param string  $content The content, simple HTML.
		 * @param WP_Post $entry   The entry.
		 */
		return (string) apply_filters( 'now_pages_threadi_fediverse_entry_content', $content, $entry );
	}

	/**
	 * Create an update. The ActivityPub plugin takes it from there.
	 *
	 * @param string $content  The content, simple HTML.
	 * @param int    $author   The ID of the user it is published as.
	 * @param int    $entry_id The ID of the entry it shares, 0 for a summary.
	 *
	 * @return int The ID of the update, 0 if it could not be created.
	 */
	private function create_update( string $content, int $author, int $entry_id ): int {
		// bail if there is nothing to share.
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return 0;
		}

		$meta = array();

		if ( $entry_id > 0 ) {
			$meta[ self::META_ENTRY ] = $entry_id;
		}

		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'      => $this->get_post_type(),
					'post_status'    => 'publish',
					'post_title'     => '',
					'post_content'   => wp_kses_post( $content ),
					'post_author'    => $author,
					// replies from the Fediverse arrive through the ActivityPub plugin, not through the comment form.
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
					'meta_input'     => $meta,
				)
			),
			true
		);

		// bail if the update could not be created.
		if ( is_wp_error( $post_id ) ) {
			Log::get_instance()->add( __( 'An update for the Fediverse could not be created:', 'now-pages-threadi' ) . ' <code>' . esc_html( $post_id->get_error_message() ) . '</code>', 'error', 'system' );

			return 0;
		}

		/**
		 * Fires after an update for the Fediverse has been created.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param int $post_id  The ID of the update.
		 * @param int $entry_id The ID of the entry it shares, 0 for a summary.
		 */
		do_action( 'now_pages_threadi_fediverse_update_created', absint( $post_id ), $entry_id );

		return absint( $post_id );
	}

	/**
	 * Keep the replies to updates out of the comment feed of the website.
	 *
	 * Replies arrive as comments on an update. They are managed in the
	 * backend, but an update has no page they could be read on - a feed
	 * item would lead nowhere.
	 *
	 * @param mixed $where The WHERE clause.
	 * @param mixed $query The query.
	 *
	 * @return mixed
	 */
	public function hide_replies_from_comment_feed( mixed $where, mixed $query ): mixed {
		global $wpdb;

		// bail if this is the feed of a single post: only then the posts table is not part of the query.
		if ( ! is_string( $where ) || ! $query instanceof WP_Query || $query->is_singular ) {
			return $where;
		}

		return $where . $wpdb->prepare( " AND {$wpdb->posts}.post_type != %s", $this->get_post_type() );
	}

	/**
	 * Keep the replies to updates out of the list of the latest comments.
	 *
	 * @param mixed $args The arguments of the comment query.
	 *
	 * @return mixed
	 */
	public function hide_replies_from_comment_list( mixed $args ): mixed {
		// bail if somebody chose the post types already.
		if ( ! is_array( $args ) || ! empty( $args['post_type'] ) ) {
			return $args;
		}

		$args['post_type'] = array_values( array_diff( get_post_types(), array( $this->get_post_type() ) ) );

		return $args;
	}

	/**
	 * Take back the update of an entry which is removed.
	 *
	 * The ActivityPub plugin tells the other servers to delete it. Whether
	 * they do is up to them.
	 *
	 * @param mixed $post_id The ID of the post being trashed or deleted.
	 *
	 * @return void
	 */
	public function entry_removed( mixed $post_id ): void {
		$post_id = absint( $post_id );

		// bail if this is not an entry.
		if ( Entries::get_instance()->get_slug() !== get_post_type( $post_id ) ) {
			return;
		}

		$update_id = absint( get_post_meta( $post_id, self::META_UPDATE, true ) );

		// bail if this entry has not been shared, or its update is gone already.
		if ( 0 === $update_id || $this->get_post_type() !== get_post_type( $update_id ) || 'trash' === get_post_status( $update_id ) ) {
			return;
		}

		wp_trash_post( $update_id );
	}

	/**
	 * Return how many updates have been shared.
	 *
	 * @return int
	 */
	public function count_updates(): int {
		$query = new WP_Query(
			array(
				'post_type'      => $this->get_post_type(),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return absint( $query->found_posts );
	}

	/**
	 * Add our settings tab.
	 *
	 * @return void
	 */
	public function add_settings(): void {
		// bail if the settings are not needed in this request.
		if ( ! \NowPagesThreadi\Helper\Plugin::is_settings_request() ) {
			return;
		}

		$settings_obj = Settings::get_instance()->get_settings_obj();

		// add the tab behind "AI".
		$tab = Settings::get_instance()->get_settings_page()->add_tab( self::SETTINGS_TAB, 46 );
		$tab->set_title( __( 'Fediverse', 'now-pages-threadi' ) );

		$section = $tab->add_section( 'now_pages_threadi_fediverse_settings', 10 );
		$section->set_title( __( 'Share your now page in the Fediverse', 'now-pages-threadi' ) );
		$section->set_callback( array( $this, 'show_intro' ) );

		// add setting.
		$mode_setting = $settings_obj->add_setting( self::OPTION_MODE );
		$mode_setting->set_section( $section );
		$mode_setting->set_type( 'string' );
		$mode_setting->set_default( self::MODE_OFF );
		$field = new Select( $settings_obj );
		$field->set_title( __( 'What to share', 'now-pages-threadi' ) );
		$field->set_description( __( 'A summary is one post per day or week, written like your now page. Single entries are one post per entry - choose this only if your sources do not produce many entries, your followers get each one. Either way only what visitors of your now page can see is shared, and not before the privacy delay has passed. Sharing starts with what happens from now on.', 'now-pages-threadi' ) );
		$field->set_options(
			array(
				self::MODE_OFF     => __( 'Nothing', 'now-pages-threadi' ),
				self::MODE_DIGEST  => __( 'A summary', 'now-pages-threadi' ),
				self::MODE_ENTRIES => __( 'Single entries', 'now-pages-threadi' ),
			)
		);
		$field->set_readonly( ! $this->is_activitypub_active() );
		$mode_setting->set_field( $field );

		// add setting.
		$setting = $settings_obj->add_setting( self::OPTION_INTERVAL );
		$setting->set_section( $section );
		$setting->set_type( 'string' );
		$setting->set_default( 'day' );
		$field = new Select( $settings_obj );
		$field->set_title( __( 'Summary of', 'now-pages-threadi' ) );
		$field->set_description( __( 'The summary of a day or week is shared once it is over and the privacy delay has passed. Nothing is shared for a period without entries.', 'now-pages-threadi' ) );
		$field->set_options(
			array(
				'day'  => __( 'each day', 'now-pages-threadi' ),
				'week' => __( 'each week', 'now-pages-threadi' ),
			)
		);
		$field->add_depend( $mode_setting, self::MODE_DIGEST );
		$setting->set_field( $field );
	}

	/**
	 * Explain the sharing and its state.
	 *
	 * @return void
	 */
	public function show_intro(): void {
		echo '<p>' . esc_html__( 'People on Mastodon and other services of the Fediverse can follow your website and reply to what you share. Replies, likes and boosts show up as comments on the shared post in your backend.', 'now-pages-threadi' ) . '</p>';
		echo '<p>' . esc_html__( 'What is shared does not become a page of your website. It is only delivered to your followers, and its link leads to your now page. Keep in mind that something which has reached other servers cannot be taken back reliably.', 'now-pages-threadi' ) . '</p>';

		// bail with a hint if nothing federates our updates.
		if ( ! $this->is_activitypub_active() ) {
			$text = __( 'This needs the plugin <strong>ActivityPub</strong>, which connects your website with the Fediverse.', 'now-pages-threadi' );

			if ( current_user_can( 'install_plugins' ) ) {
				$text .= ' <a href="' . esc_url( admin_url( 'plugin-install.php?s=activitypub&tab=search&type=term' ) ) . '">' . esc_html__( 'Install it', 'now-pages-threadi' ) . '</a>';
			}

			echo '<p><span class="dashicons dashicons-info-outline" aria-hidden="true"></span> ' . wp_kses_post( $text ) . '</p>';

			return;
		}

		// bail if nothing is shared.
		if ( ! $this->is_enabled() ) {
			return;
		}

		// say what has been shared so far.
		$count = $this->count_updates();
		$url   = admin_url( 'edit.php?post_type=' . $this->get_post_type() );

		echo '<p><span class="dashicons dashicons-yes-alt" style="color:#008a20" aria-hidden="true"></span> ';
		echo wp_kses_post(
			sprintf(
				/* translators: %1$d will be replaced by a number, %2$s by a URL. */
				_n( '%1$d post has been shared so far. <a href="%2$s">Show what was shared</a>', '%1$d posts have been shared so far. <a href="%2$s">Show what was shared</a>', $count, 'now-pages-threadi' ),
				$count,
				esc_url( $url )
			)
		);
		echo '</p>';
	}

	/**
	 * Take back the update of an entry which is no longer published.
	 *
	 * @param mixed $new_status The new status.
	 * @param mixed $old_status The old status.
	 * @param mixed $post       The post object.
	 *
	 * @return void
	 */
	public function entry_unpublished( mixed $new_status, mixed $old_status, mixed $post ): void {
		// bail if the entry was not public before, or still is.
		if ( 'publish' !== $old_status || 'publish' === $new_status || ! $post instanceof WP_Post ) {
			return;
		}

		$this->entry_removed( $post->ID );
	}
}
