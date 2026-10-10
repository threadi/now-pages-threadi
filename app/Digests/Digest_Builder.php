<?php
/**
 * This file contains the central digest builder.
 *
 * This is deliberately the ONLY place with actual query/render logic, so
 * any page-builder widgets can all be thin adapters that call build() and nothing else.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Digests;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Helper\Languages;
use NowPagesThreadi\Helper\Plugin;
use NowPagesThreadi\NowPages\Categories;
use NowPagesThreadi\NowPages\Entries;
use NowPagesThreadi\NowPages\Frontend;
use NowPagesThreadi\PageBuilder\Page_Builders;
use NowPagesThreadi\Plugin\Log;
use NowPagesThreadi\Plugin\Settings;
use NowPagesThreadi\Plugin\Templates;
use WP_Post;
use WP_Query;

/**
 * Builds a rendered digest for a given period, filter and format.
 */
class Digest_Builder {
	/**
	 * Maximum number of entries loaded for one digest.
	 *
	 * Protects the page against huge queries (e.g. a range starting in 1970).
	 * Can be changed via the filter "now_pages_threadi_digest_query_args".
	 */
	private const MAX_ENTRIES = 1000;

	/**
	 * Trace of the last build() run, for diagnostics.
	 *
	 * @var array<string,mixed>
	 */
	private array $trace = array();

	/**
	 * Results of this request, keyed by the arguments they were built with.
	 *
	 * A now page can be assembled by more than one caller - the route, a
	 * page builder template, a shortcode. They would all run the same query
	 * and, with the AI summarizer, the same API request.
	 *
	 * @var array<string,mixed>
	 */
	private static array $cache = array();

	/**
	 * Per-request cache of the user settings which restrict the digest across all users.
	 *
	 * @var array{disabled:array<int,int>,delays:array<int,int>}|null
	 */
	private static ?array $user_restrictions = null;

	/**
	 * Whether the personal settings of users (disabled now page, own delay) are
	 * ignored across all users. Only for internal output like the report email.
	 *
	 * @var bool
	 */
	private bool $ignore_user_restrictions = false;

	/**
	 * Whether a missing format has already been logged in this request.
	 *
	 * @var bool
	 */
	private static bool $format_error_logged = false;

	/**
	 * Whether a build error is logged or not.
	 *
	 * @var bool
	 */
	private static bool $build_error_logged = false;

	/**
	 * Return the trace of the last build() run.
	 *
	 * @return array<string,mixed> Empty before build() has run.
	 */
	public function get_trace(): array {
		return $this->trace;
	}

	/**
	 * Build the rendered digest.
	 *
	 * @param array<string,mixed> $args {
	 *     Optional. Any missing key falls back via Display_Settings hierarchy.
	 *
	 *     @type string $period            'day' or 'range'. Default 'day'.
	 *     @type string $date              Y-m-d date, used when period=day. Default: today.
	 *     @type string $start             Y-m-d H:i:s start, used when period=range.
	 *     @type string $end               Y-m-d H:i:s end, used when period=range.
	 *     @type string $format            Digest_Format_Interface slug. Default 'verbatim'.
	 *     @type string $language          Language code. Default: current language.
	 *     @type int    $category          now_pages_threadi_category term ID, 0 = all categories.
	 *     @type int|string $user User ID, login or slug whose entries to show.
	 *     @type int $user_id Resolved user ID (set internally).
	 *     @type bool   $show_time         Show timestamps in the verbatim format. Default false.
	 *     @type bool   $show_source       Show the originating source. Default false.
	 *     @type bool   $group_by_category Group the verbatim format by category. Default true.
	 *     @type int    $delay_hours       Hide entries newer than this many hours (anti-stalking).
	 *     @type string $summarizer        Summarizer_Interface slug, used by the narrative format.
	 * }
	 *
	 * @return string
	 */
	public function build( array $args = array() ): string {
		try {
			return $this->build_digest( $args );
		} catch ( \Throwable $e ) {
			// a broken digest must not take the whole page down with it.
			if ( ! self::$build_error_logged ) {
				self::$build_error_logged = true;
				Log::get_instance()->add(
					__( 'The digest could not be built:', 'now-pages-threadi' ) . ' <code>' . esc_html( $e->getMessage() ) . '</code>',
					'error',
					'system'
				);
			}

			return '';
		}
	}

	/**
	 * Build the rendered digest.
	 *
	 * @param array<string,mixed> $args {
	 *     Optional. Any missing key falls back via Display_Settings hierarchy.
	 *
	 *     @type string $period            'day' or 'range'. Default 'day'.
	 *     @type string $date              Y-m-d date, used when period=day. Default: today.
	 *     @type string $start             Y-m-d H:i:s start, used when period=range.
	 *     @type string $end               Y-m-d H:i:s end, used when period=range.
	 *     @type string $format            Digest_Format_Interface slug. Default 'verbatim'.
	 *     @type string $language          Language code. Default: current language.
	 *     @type int    $category          now_pages_threadi_category term ID, 0 = all categories.
	 *     @type int|string $user User ID, login or slug whose entries to show.
	 *     @type int $user_id Resolved user ID (set internally).
	 *     @type bool   $show_time         Show timestamps in the verbatim format. Default false.
	 *     @type bool   $show_source       Show the originating source. Default false.
	 *     @type bool   $group_by_category Group the verbatim format by category. Default true.
	 *     @type int    $delay_hours       Hide entries newer than this many hours (anti-stalking).
	 *     @type string $summarizer        Summarizer_Interface slug, used by the narrative format.
	 * }
	 *
	 * @return string
	 */
	private function build_digest( array $args = array() ): string {
		// show nothing inside the page behind the now route as long as this route is
		// not public: its URL answers with a 404, but the REST API and every other
		// loop that renders the content of this page would show the digest anyway.
		if ( ! $this->ignore_user_restrictions && Frontend::get_instance()->is_hidden_now_page() ) {
			$this->trace = array( 'error' => 'now_page_not_public' );

			return '';
		}

		// add the public CSS, if no block theme is used.
		if ( ! wp_is_block_theme() ) {
			wp_enqueue_style(
				'now-pages-threadi',
				Plugin::get_plugin_url() . 'css/style.css',
				array(),
				Plugin::get_file_version( Plugin::get_plugin_path() . 'css/style.css' )
			);
		}

		$args = $this->resolve_args( $args );

		// return an identical build of this request instead of repeating it.
		$cache_key = md5( (string) wp_json_encode( $args ) . ( $this->ignore_user_restrictions ? '|all-users' : '' ) );

		if ( isset( self::$cache[ $cache_key ] ) ) {
			$this->trace = self::$cache[ $cache_key ]['trace'];

			return self::$cache[ $cache_key ]['content'];
		}

		// start a fresh trace for this run.
		$this->trace = array( 'args' => $args );

		$format = Digests::get_instance()->get_format( (string) $args['format'] );

		// fall back to the default format silently, e.g. after a format has been removed.
		if ( ! $format instanceof Digest_Format_Interface ) {
			$this->trace['error'] = 'unknown_format';
			$format               = Digests::get_instance()->get_format( Digests::get_instance()->get_default_format() );

			// remember the fallback for the diagnostics.
			if ( $format instanceof Digest_Format_Interface ) {
				$this->trace['format_fallback'] = $format->get_slug();
			}
		}

		// bail if even the default format is unknown.
		if ( ! $format instanceof Digest_Format_Interface ) {
			// log this only once per request, not for every digest on the page.
			if ( ! self::$format_error_logged ) {
				self::$format_error_logged = true;
				/* translators: %1$s will be replaced by a format name */
				Log::get_instance()->add( sprintf( __( 'The configured format %1$s could not be found.', 'now-pages-threadi' ), '<code>' . esc_html( (string) $args['format'] ) . '</code>' ), 'error', 'system' );
			}
			return '';
		}

		$entries = $this->query_entries( $args );
		$content = $format->render( $entries, $args );

		// show a delaye notice for users with capability only.
		if ( current_user_can( 'edit_posts' ) ) {
			$delay_notice = $this->render_delay_notice( $args );
			if ( '' !== $delay_notice ) {
				$content = ( '' === $content )
					? $delay_notice
					: $content . $delay_notice;
			}
		}

		// bail if the format produced nothing.
		if ( '' === $content ) {
			return '';
		}

		// prepend the period, if enabled.
		if ( ! empty( $args['show_period'] ) ) {
			$content = $this->render_period( $args, $entries ) . $content;
		}

		self::$cache[ $cache_key ] = array(
			'content' => $content,
			'trace'   => $this->trace,
		);

		return $content;
	}

	/**
	 * Return the entries a digest with the given arguments would show.
	 *
	 * For everything that needs the entries themselves instead of their
	 * rendered form, with the same rules applied: the period, the privacy
	 * delay, the language and the restrictions of single authors.
	 *
	 * @param array<string,mixed> $args The digest args, see build().
	 *
	 * @return array<int,WP_Post>
	 */
	public function get_entries( array $args = array() ): array {
		$args = $this->resolve_args( $args );

		// start a fresh trace for this run.
		$this->trace = array( 'args' => $args );

		return $this->query_entries( $args );
	}

	/**
	 * Query the np_threadi_entry posts matching the given digest args.
	 *
	 * @param array<string,mixed> $args The resolved digest args, see build().
	 *
	 * @return array<int,WP_Post>
	 */
	private function query_entries( array $args ): array {
		$query_args = $this->build_query_args( $args );

		// remember the query for diagnostics.
		$this->trace['query_args'] = $query_args;

		$result = new WP_Query( $query_args );

		// get the result.
		$results = array();
		foreach ( (array) $result->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			// skip entries which are still hidden by the personal delay of their author.
			if ( $this->is_hidden_by_user_delay( $post, $args ) ) {
				continue;
			}

			$results[] = $post;
		}

		// the query loads the newest entries first, restore the requested order.
		if ( 'DESC' !== ( $args['order'] ?? 'ASC' ) && 'DESC' === ( $query_args['order'] ?? '' ) ) {
			$results = array_reverse( $results );
		}

		$this->trace['found'] = count( $results );

		// return the result.
		return $results;
	}

	/**
	 * Resolve the digest args into a concrete after/before datetime range.
	 *
	 * Applies the configured delay (anti-stalking): the effective "before"
	 * is never later than (now − delay_hours).
	 *
	 * @param array<string,mixed> $args The resolved digest args, see build().
	 *
	 * @return array{0:string,1:string}
	 */
	private function resolve_range( array $args ): array {
		// default to day if period is not set.
		$slug = strtolower( ( ! empty( $args['period'] ) ? (string) $args['period'] : 'day' ) );

		// get the period by given slug.
		$period_obj = Periods::get_instance()->get_period_or_fallback( $slug );

		// get the range from this period.
		$range = $period_obj->get_range( $args );

		$after  = $range['after'];
		$before = $range['before'];

		// Privacy: upper bound ≤ now − delay_hours. this stays here on
		// purpose - a period must not be able to bypass it.
		$delay_hours = Display_Settings::limit_hours( ! empty( $args['delay_hours'] ) ? $args['delay_hours'] : 0 );

		$this->trace['period']        = $period_obj->get_slug();
		$this->trace['before_raw']    = $before;
		$this->trace['delay_applied'] = false;

		if ( $delay_hours > 0 ) {
			$delayed = gmdate( 'Y-m-d H:i:s', (int) strtotime( current_time( 'mysql' ) ) - ( $delay_hours * HOUR_IN_SECONDS ) );

			if ( $delayed < $before ) {
				$before                       = $delayed;
				$this->trace['delay_applied'] = true;
			}
		}

		$range = apply_filters( 'now_pages_threadi_digest_range', array( $after, $before ), $args );

		$this->trace['range'] = $range;

		return array( (string) $range[0], (string) $range[1] );
	}

	/**
	 * Render the note about the period the shown entries are from.
	 *
	 * @param array<string,mixed> $args    The resolved args.
	 * @param array<int,WP_Post>  $entries The entries being shown.
	 *
	 * @return string
	 */
	private function render_period( array $args, array $entries ): string {
		$parts = array( $this->get_period_label( $args ) );

		// add when the newest shown entry is from.
		if ( 'ASC' === $args['order'] ) {
			$newest = end( $entries );
		} else {
			$newest = reset( $entries );
		}

		if ( $newest instanceof WP_Post ) {
			$parts[] = sprintf(
			/* translators: %1$s will be replaced by a relative time, e.g. "3 hours". */
				__( 'last updated %1$s ago', 'now-pages-threadi' ),
				human_time_diff( (int) get_post_time( 'U', true, $newest ) )
			);
		}

		$text = implode( ' · ', array_filter( $parts ) );

		/**
		 * Filter the note about the period of a digest.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param string              $text The text, already assembled.
		 * @param array<string,mixed> $args The resolved args.
		 */
		$text = (string) apply_filters( 'now_pages_threadi_digest_period_text', $text, $args );

		if ( '' === $text ) {
			return '';
		}

		ob_start();
		require Templates::get_instance()->get_template( 'parts/period.php' );
		$content = ob_get_clean();
		if ( ! $content ) {
			return '';
		}
		return $content;
	}

	/**
	 * Return the period label.
	 *
	 * @param array<string,mixed> $args List of arguments.
	 *
	 * @return string
	 */
	private function get_period_label( array $args ): string {
		$period_obj = Periods::get_instance()->get_period_or_fallback( (string) ( ! empty( $args['period'] ) ? $args['period'] : 'day' ) );

		$range = array(
			'after'  => (string) ( $this->trace['range'][0] ?? '' ),
			'before' => (string) ( $this->trace['range'][1] ?? '' ),
		);

		return $period_obj->get_note( $args, $range );
	}

	/**
	 * Resolve the arguments to generate the digest.
	 *
	 * @param array<string,mixed> $args List of arguments.
	 *
	 * @return array<string,mixed>
	 */
	private function resolve_args( array $args ): array {
		$args = wp_parse_args(
			$args,
			array(
				'period'         => 'day',
				'date'           => current_time( 'Y-m-d' ),
				'hour'           => (int) current_time( 'G' ),
				'lookback_hours' => 24,
				'start'          => '',
				'end'            => '',
				'format'         => 'verbatim',
				'language'       => Languages::get_current_language(),
				'category'       => 0,
				'summarizer'     => '',
			)
		);

		if ( ! empty( $args['user'] ) && empty( $args['user_id'] ) ) {
			$args['user_id'] = Display_Settings::resolve_user_id( $args['user'] );
		} elseif ( ! isset( $args['user_id'] ) ) {
			$args['user_id'] = 0;
		}

		// return the resolved arguments.
		return Display_Settings::resolve( $args );
	}

	/**
	 * Build the query arguments.
	 *
	 * @param array<string,mixed> $args The arguments to build the query.
	 *
	 * @return array<string,mixed>
	 */
	private function build_query_args( array $args ): array {
		list( $after, $before ) = $this->resolve_range( $args );

		$query_args = array(
			'post_type'      => Entries::get_instance()->get_slug(),
			'post_status'    => 'publish',
			'posts_per_page' => self::MAX_ENTRIES,
			'no_found_rows'  => true,
			'orderby'        => 'date',
			// always load the newest entries: with the limit above, ASC would drop them.
			// query_entries() restores the requested order.
			'order'          => 'DESC',
			'date_query'     => array(
				array(
					'after'     => $after,
					'before'    => $before,
					'inclusive' => true,
				),
			),
		);

		// Restrict to a single author's entries when requested.
		if ( ! empty( $args['user_id'] ) ) {
			$query_args['author'] = absint( $args['user_id'] );
		} elseif ( ! empty( $args['user'] ) ) {
			// a requested user who does not exist has no entries: never show those of all users instead.
			$query_args['post__in'] = array( 0 );
		} else {
			// across all users: leave out the entries of users who disabled their now page.
			$disabled = $this->get_user_restrictions()['disabled'];
			if ( ! empty( $disabled ) ) {
				$query_args['author__not_in'] = $disabled;
			}
		}

		if ( ! empty( $args['language'] ) ) {
			$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Query for specific language.
				Languages::get_meta_query( (string) $args['language'] ),
			);
		}

		if ( ! empty( $args['category'] ) ) {
			$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Query for specific category.
				array(
					'taxonomy' => Categories::get_instance()->get_slug(),
					'field'    => 'term_id',
					'terms'    => absint( $args['category'] ),
				),
			);
		}

		/**
		 * Filter the WP_Query arguments used to fetch entries for a digest.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<string,mixed> $query_args The query args, as passed to WP_Query.
		 * @param array<string,mixed> $args       The resolved digest args, see Digest_Builder::build().
		 */
		return apply_filters( 'now_pages_threadi_digest_query_args', $query_args, $args );
	}

	/**
	 * Return the last entry date.
	 *
	 * @param array<string,mixed> $args List of arguments.
	 *
	 * @return int|null
	 */
	public function get_last_entry_date( array $args = array() ): ?int {
		$args = $this->resolve_args( $args );

		// load some more entries if personal delays could hide the newest ones.
		$needs_filter = empty( $args['user_id'] ) && ! empty( $this->get_user_restrictions()['delays'] );

		$query_args                   = $this->build_query_args( $args );
		$query_args['posts_per_page'] = $needs_filter ? 100 : 1;
		$query_args['order']          = 'DESC';
		$query_args['no_found_rows']  = true;

		$result = new WP_Query( $query_args );

		foreach ( (array) $result->posts as $post ) {
			if ( ! $post instanceof WP_Post || $this->is_hidden_by_user_delay( $post, $args ) ) {
				continue;
			}

			return (int) get_post_time( 'U', true, $post );
		}

		return null;
	}

	/**
	 * Ignore the personal settings of users (disabled now page, own delay).
	 *
	 * Only for internal output which is never shown publicly, like the report
	 * email to the administrator. Deliberately not available as digest argument,
	 * so blocks, widgets and shortcodes cannot use it.
	 *
	 * @param bool $ignore True to ignore them.
	 *
	 * @return Digest_Builder
	 */
	public function set_ignore_user_restrictions( bool $ignore ): Digest_Builder {
		$this->ignore_user_restrictions = $ignore;

		return $this;
	}

	/**
	 * Return the user settings which restrict a digest across all users.
	 *
	 * - disabled: IDs of users who disabled their now page.
	 * - delays:   user ID => personal delay in hours, for users who set one.
	 *
	 * @return array{disabled:array<int,int>,delays:array<int,int>}
	 */
	private function get_user_restrictions(): array {
		// return nothing to restrict for internal output.
		if ( $this->ignore_user_restrictions ) {
			return array(
				'disabled' => array(),
				'delays'   => array(),
			);
		}

		if ( null !== self::$user_restrictions ) {
			return self::$user_restrictions;
		}

		$keys = Display_Settings::user_meta_keys();

		// get the users who disabled their now page.
		$disabled = array();
		foreach ( get_users(
			array(
				'fields'     => 'ID',
				'meta_key'   => $keys['disabled'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small query, cached per request.
				'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- small query, cached per request.
			)
		) as $user_id ) {
			$disabled[] = absint( $user_id );
		}

		// get the users with their own delay.
		$delays = array();
		foreach ( get_users(
			array(
				'fields'       => 'ID',
				'meta_key'     => $keys['delay_hours'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small query, cached per request.
				'meta_compare' => 'EXISTS',
			)
		) as $user_id ) {
			$value = get_user_meta( absint( $user_id ), $keys['delay_hours'], true );
			if ( is_numeric( $value ) ) {
				$delays[ absint( $user_id ) ] = absint( $value );
			}
		}

		self::$user_restrictions = array(
			'disabled' => $disabled,
			'delays'   => $delays,
		);

		return self::$user_restrictions;
	}

	/**
	 * Return whether an entry is hidden by the personal delay of its author.
	 *
	 * Only used across all users (e.g. the global now page): the query already
	 * applies the delay of the digest, but an author may have chosen a longer one
	 * for their own entries. The longer delay always wins.
	 *
	 * @param WP_Post             $post The entry.
	 * @param array<string,mixed> $args The resolved digest args.
	 *
	 * @return bool
	 */
	private function is_hidden_by_user_delay( WP_Post $post, array $args ): bool {
		// bail for the digest of a single user: their delay is already part of the args.
		if ( ! empty( $args['user_id'] ) ) {
			return false;
		}

		$delays      = $this->get_user_restrictions()['delays'];
		$author_id   = absint( $post->post_author );
		$user_delay  = Display_Settings::limit_hours( ! empty( $delays[ $author_id ] ) ? $delays[ $author_id ] : 0 );
		$query_delay = Display_Settings::limit_hours( ! empty( $args['delay_hours'] ) ? $args['delay_hours'] : 0 );

		// bail if the personal delay is not longer than the one already applied.
		if ( $user_delay <= $query_delay ) {
			return false;
		}

		return (int) get_post_time( 'U', true, $post ) > time() - ( $user_delay * HOUR_IN_SECONDS );
	}

	/**
	 * Reset the cache.
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$cache               = array();
		self::$user_restrictions   = null;
		self::$format_error_logged = false;
	}

	/**
	 * Prepare a delay notice.
	 *
	 * @param array<string,mixed> $args List of arguments.
	 *
	 * @return string
	 */
	private function render_delay_notice( array $args ): string {
		$delay_hours = Display_Settings::limit_hours( ! empty( $args['delay_hours'] ) ? $args['delay_hours'] : 0 );

		if ( $delay_hours <= 0 ) {
			return '';
		}

		$text = sprintf(
		/* translators: %1$d: number of hours */
			_n(
				'Entries from the last %1$d hour are not shown yet (privacy delay).',
				'Entries from the last %1$d hours are not shown yet (privacy delay).',
				$delay_hours,
				'now-pages-threadi'
			),
			$delay_hours
		);

		/**
		 * Filter the frontend delay notice. Empty string = hide.
		 *
		 * @param string              $text
		 * @param array<string,mixed> $args
		 */
		$text = (string) apply_filters( 'now_pages_threadi_digest_delay_notice', $text, $args );

		if ( '' === $text ) {
			return '';
		}

		// add link to settings for users.
		if ( current_user_can( 'manage_options' ) ) {
			$text .= '[<a href="' . esc_url( Settings::get_instance()->get_url( 'now_pages_threadi_display' ) ) . '">' . esc_html__( 'Settings', 'now-pages-threadi' ) . '</a>]';
		}

		// return resulting text.
		return '<p class="now-pages-threadi-digest-delay">' . wp_kses_post( $text ) . '</p>';
	}
}
