<?php
/**
 * This file contains the diagnostics panel for rendered digests.
 *
 * It explains why a digest looks the way it does: which display settings won,
 * which time range was queried and how many entries each filter removed.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Digests;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Helper\Languages;
use ThreadiNowPages\NowPages\Categories;
use ThreadiNowPages\NowPages\Frontend;
use ThreadiNowPages\Plugin\Settings;
use WP_Post;
use WP_Query;
use WP_User;

/**
 * Object, which renders the diagnostics panel for a digest.
 */
class Diagnostics {
	/**
	 * The query parameter which enables the panel in the frontend.
	 *
	 * @var string
	 */
	public const QUERY_PARAM = 'threadi-now-pages-debug';

	/**
	 * Instance of actual object.
	 *
	 * @var ?Diagnostics
	 */
	private static ?Diagnostics $instance = null;

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
	 * Return instance of this object as singleton.
	 *
	 * @return Diagnostics
	 */
	public static function get_instance(): Diagnostics {
		if ( is_null( self::$instance ) ) {
			self::$instance = new static();
		}

		return self::$instance;
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// use hooks.
		add_filter( 'threadi_now_pages_frontend_content', array( $this, 'add_diagnostics' ), 100, 2 );
	}

	/**
	 * Append the diagnostics panel to the digest, if requested.
	 *
	 * @param mixed        $content The rendered digest markup.
	 * @param WP_User|null $user    The author for /author/…/now, null for the global route.
	 *
	 * @return string
	 */
	public function add_diagnostics( mixed $content, ?WP_User $user ): string {
		$content = (string) $content;

		// bail if diagnostics were not requested.
		if ( ! isset( $_GET[ self::QUERY_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only debug switch.
			return $content;
		}

		// bail if the current user may not see internals.
		if ( ! current_user_can( 'manage_options' ) ) {
			return $content;
		}

		// get the builder used for this request.
		$builder = Frontend::get_instance()->get_builder();

		// bail if no builder is available.
		if ( ! $builder instanceof Digest_Builder ) {
			return $content;
		}

		// append the panel.
		return $content . $this->render_panel( $builder->get_trace(), $user );
	}

	/**
	 * Render the complete panel for a given builder trace.
	 *
	 * Public because the settings preview renders the same panel.
	 *
	 * @param array<string,mixed> $trace The trace, see Digest_Builder::get_trace().
	 * @param WP_User|null        $user  The author the digest was built for, if any.
	 *
	 * @return string Empty string if the trace holds nothing to explain.
	 */
	public function render_panel( array $trace, ?WP_User $user = null ): string {
		// bail if the builder did not run.
		if ( empty( $trace['args'] ) ) {
			return '';
		}

		// collect the blocks of the panel.
		$blocks = array(
			$this->render_scope( $trace, $user ),
			$this->render_settings( $trace, $user ),
			$this->render_range( $trace ),
			$this->render_exclusions( $trace ),
		);

		// build the panel - as this is only for projects-owners, this is not in a template.
		return '<div class="threadi-now-pages-diagnostics"><h2>'
				. esc_html__( 'Now Pages – diagnostics', 'threadi-now-pages' )
				. '</h2><p class="description">'
				. esc_html__( 'Only visible to administrators. This panel explains how the output above came about.', 'threadi-now-pages' )
				. '</p>'
				. implode( '', array_filter( $blocks ) )
				. '</div>';
	}

	/**
	 * Return the same explanation as the panel, as structured data.
	 *
	 * Used by the diagnose ability, so an assistant can explain a digest
	 * without parsing HTML.
	 *
	 * @param array<string,mixed> $trace The trace, see Digest_Builder::get_trace().
	 *
	 * @return array<string,mixed> Empty array if the trace holds nothing to explain.
	 */
	public function get_report( array $trace ): array {
		// bail if the builder did not run.
		if ( empty( $trace['args'] ) || ! is_array( $trace['args'] ) ) {
			return array();
		}

		$args    = $trace['args'];
		$origins = is_array( $args['_origin'] ?? null ) ? $args['_origin'] : array();

		// the display settings in effect, and where each one came from.
		$settings = array();
		foreach ( array_keys( Display_Settings::defaults() ) as $key ) {
			$settings[] = array(
				'key'    => $key,
				'value'  => $args[ $key ] ?? null,
				'origin' => (string) ( $origins[ $key ] ?? 'default' ),
			);
		}

		// the queried range.
		$range = array(
			'after'                => '',
			'before'               => '',
			'before_without_delay' => '',
			'delay_applied'        => ! empty( $trace['delay_applied'] ),
		);
		if ( ! empty( $trace['range'] ) && is_array( $trace['range'] ) ) {
			$range['after']                = $this->to_iso_date( (string) $trace['range'][0] );
			$range['before']               = $this->to_iso_date( (string) $trace['range'][1] );
			$range['before_without_delay'] = $this->to_iso_date( (string) ( $trace['before_raw'] ?? '' ) );
		}

		return array(
			'scope'    => array(
				'period'           => (string) ( $trace['period'] ?? ( $args['period'] ?? '' ) ),
				'format'           => (string) ( $args['format'] ?? '' ),
				'format_exists'    => empty( $trace['error'] ),
				'language'         => (string) ( $args['language'] ?? '' ),
				'category_term_id' => absint( $args['category'] ?? 0 ),
				'user_id'          => absint( $args['user_id'] ?? 0 ),
				'publicly_visible' => Frontend::get_instance()->is_public( absint( $args['user_id'] ?? 0 ) > 0 ),
			),
			'settings' => $settings,
			'range'    => $range,
			'found'    => absint( $trace['found'] ?? 0 ),
			'excluded' => empty( $trace['query_args'] ) ? array() : $this->count_excluded( $trace ),
		);
	}

	/**
	 * Explain whether and why a single entry is part of a digest.
	 *
	 * @param array<string,mixed> $trace The trace, see Digest_Builder::get_trace().
	 * @param WP_Post             $entry The entry to explain.
	 *
	 * @return array{shown:bool,reasons:array<int,array{code:string,message:string}>}
	 */
	public function explain_entry( array $trace, WP_Post $entry ): array {
		$reasons = array();
		$args    = is_array( $trace['args'] ?? null ) ? $trace['args'] : array();

		// without a query (unknown format) nothing is shown at all.
		if ( empty( $trace['query_args'] ) || ! is_array( $trace['query_args'] ) ) {
			return array(
				'shown'   => false,
				'reasons' => array(
					array(
						'code'    => 'unknown_format',
						'message' => __( 'The configured format does not exist, so the digest stays empty.', 'threadi-now-pages' ),
					),
				),
			);
		}

		// ask the database with the exact query of the digest: this is the authoritative answer.
		$query_args             = $trace['query_args'];
		$query_args['post__in'] = array( $entry->ID );
		$query_args['fields']   = 'ids';
		$shown                  = ( new WP_Query( $query_args ) )->post_count > 0;

		// explain the reasons one by one.
		if ( 'publish' !== $entry->post_status ) {
			$reasons[] = array(
				'code'    => 'not_published',
				/* translators: %1$s will be replaced by a post status. */
				'message' => sprintf( __( 'The entry is not published (status: %1$s).', 'threadi-now-pages' ), $entry->post_status ),
			);
		}

		$after      = (string) ( $trace['range'][0] ?? '' );
		$before     = (string) ( $trace['range'][1] ?? '' );
		$before_raw = (string) ( $trace['before_raw'] ?? $before );

		if ( '' !== $after && $entry->post_date < $after ) {
			$reasons[] = array(
				'code'    => 'before_period',
				'message' => __( 'The entry is older than the start of the period.', 'threadi-now-pages' ),
			);
		}

		if ( '' !== $before_raw && $entry->post_date > $before_raw ) {
			$reasons[] = array(
				'code'    => 'after_period',
				'message' => __( 'The entry is dated after the end of the period.', 'threadi-now-pages' ),
			);
		} elseif ( '' !== $before && $entry->post_date > $before ) {
			$reasons[] = array(
				'code'    => 'hidden_by_delay',
				/* translators: %1$d will be replaced by a number of hours. */
				'message' => sprintf( __( 'The entry is newer than the privacy delay of %1$d hours and will show up once it is older.', 'threadi-now-pages' ), absint( $args['delay_hours'] ?? 0 ) ),
			);
		}

		if ( ! empty( $args['language'] ) ) {
			$language = (string) get_post_meta( $entry->ID, 'language', true );

			if ( ! Languages::matches( $language, (string) $args['language'] ) ) {
				$reasons[] = array(
					'code'    => 'other_language',
					/* translators: %1$s will be replaced by the language of the entry, %2$s by the language of the digest. */
					'message' => sprintf( __( 'The entry is in language "%1$s", the digest shows "%2$s".', 'threadi-now-pages' ), '' === $language ? '–' : $language, (string) $args['language'] ),
				);
			}
		}

		if ( ! empty( $args['category'] ) && ! has_term( absint( $args['category'] ), Categories::TAXONOMY, $entry ) ) {
			$reasons[] = array(
				'code'    => 'other_category',
				'message' => __( 'The entry is not in the category the digest is limited to.', 'threadi-now-pages' ),
			);
		}

		if ( ! empty( $args['user_id'] ) && absint( $entry->post_author ) !== absint( $args['user_id'] ) ) {
			$reasons[] = array(
				'code'    => 'other_author',
				'message' => __( 'The entry belongs to another author than the digest.', 'threadi-now-pages' ),
			);
		}

		// across all authors: the author may have disabled the own now page.
		if ( empty( $args['user_id'] ) && in_array( absint( $entry->post_author ), array_map( 'absint', (array) ( $query_args['author__not_in'] ?? array() ) ), true ) ) {
			$reasons[] = array(
				'code'    => 'author_disabled',
				'message' => __( 'The author of the entry disabled the own now page, so the entry is not shown across all authors.', 'threadi-now-pages' ),
			);
		}

		// across all authors: the author may have chosen a longer delay than the digest.
		if ( $shown && $this->is_hidden_by_user_delay( $entry, $args ) ) {
			$shown     = false;
			$reasons[] = array(
				'code'    => 'hidden_by_user_delay',
				/* translators: %1$d will be replaced by a number of hours. */
				'message' => sprintf( __( 'The author of the entry set a personal privacy delay of %1$d hours. The entry will show up once it is older.', 'threadi-now-pages' ), $this->get_user_delay( absint( $entry->post_author ) ) ),
			);
		}

		// something else (e.g. a filter hook) removed it.
		if ( ! $shown && empty( $reasons ) ) {
			$reasons[] = array(
				'code'    => 'filtered',
				'message' => __( 'The entry matches all settings, but a filter (threadi_now_pages_digest_query_args) removed it from the query.', 'threadi-now-pages' ),
			);
		}

		// a shown entry may still be replaced by a fixed text.
		if ( $shown && empty( $args['ignore_overrides'] ) && null !== Categories::get_instance()->get_override_text_for_entry( $entry ) ) {
			$reasons[] = array(
				'code'    => 'replaced_by_override',
				'message' => __( 'The entry is part of the digest, but its category or source replaces it with a fixed override text.', 'threadi-now-pages' ),
			);
		}

		return array(
			'shown'   => $shown,
			'reasons' => $reasons,
		);
	}

	/**
	 * Convert a MySQL datetime in the site timezone to ISO 8601.
	 *
	 * @param string $value The datetime in Y-m-d H:i:s.
	 *
	 * @return string Empty string for empty or unparsable values.
	 */
	private function to_iso_date( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$date = date_create_immutable( $value, wp_timezone() );

		return $date ? $date->format( DATE_ATOM ) : '';
	}

	/**
	 * Render the scope block: author, period and format.
	 *
	 * @param array<string,mixed> $trace The builder trace.
	 * @param WP_User|null        $user  The author the digest was built for, if any.
	 *
	 * @return string
	 */
	private function render_scope( array $trace, ?WP_User $user ): string {
		$args = $trace['args'];

		// resolve the format label.
		$format_slug = (string) ( $args['format'] ?? '' );
		$format_obj  = Digests::get_instance()->get_format( $format_slug );
		$format      = $format_obj instanceof Digest_Format_Interface
			? $format_obj->get_label()
			/* translators: %1$s will be replaced by a format slug. */
			: sprintf( __( 'unknown (%1$s)', 'threadi-now-pages' ), '' === $format_slug ? '–' : $format_slug );

		// collect the rows.
		$rows = array(
			__( 'Author', 'threadi-now-pages' )   => $user instanceof WP_User
				? $user->display_name
				: __( 'all authors (global route)', 'threadi-now-pages' ),
			__( 'Period', 'threadi-now-pages' )   => (string) ( $args['period'] ?? '' ),
			__( 'Format', 'threadi-now-pages' )   => $format,
			__( 'Language', 'threadi-now-pages' ) => '' !== (string) ( $args['language'] ?? '' )
				? (string) $args['language']
				: __( 'not restricted', 'threadi-now-pages' ),
		);

		// explain why visitors may not see this page at all.
		// check the route the digest was built for: an author is only given on the author route.
		$visibility = __( 'yes', 'threadi-now-pages' );
		if ( $user instanceof WP_User && ! Frontend::get_instance()->is_public( true ) ) {
			$visibility = __( 'no - the setup has not been finished or the user URL of now pages is disabled.', 'threadi-now-pages' );
		} elseif ( ! $user instanceof WP_User && ! Frontend::get_instance()->is_public() ) {
			$visibility = __( 'no - the setup has not been finished or global URL of now pages is disabled.', 'threadi-now-pages' );
		}
		$rows[ __( 'Publicly visible', 'threadi-now-pages' ) ] = $visibility;

		// warn if the format could not be resolved.
		$hint = '';
		if ( ! empty( $trace['error'] ) && 'unknown_format' === $trace['error'] ) {
			$hint = '<p><strong>'
					. esc_html__( 'The configured format does not exist, so the digest stayed empty.', 'threadi-now-pages' )
					. '</strong></p>';

			// the digest used the default format instead.
			if ( ! empty( $trace['format_fallback'] ) && is_string( $trace['format_fallback'] ) ) {
				$hint = '<p><strong>'
						/* translators: %1$s will be replaced by the slug of a format. */
						. sprintf( esc_html__( 'The configured format does not exist, the default format %1$s is used instead.', 'threadi-now-pages' ), '<code>' . esc_html( $trace['format_fallback'] ) . '</code>' )
						. '</strong></p>';
			}
		}

		return '<h3>' . esc_html__( 'Scope', 'threadi-now-pages' ) . '</h3>'
				. $this->render_table( $rows )
				. $hint;
	}

	/**
	 * Render the display settings block, including where each value came from.
	 *
	 * @param array<string,mixed> $trace The builder trace.
	 * @param WP_User|null        $user The user.
	 *
	 * @return string
	 */
	private function render_settings( array $trace, ?WP_User $user ): string {
		$args    = $trace['args'];
		$origins = is_array( $args['_origin'] ?? null ) ? $args['_origin'] : array();
		$labels  = Display_Settings::origin_labels();

		// human-readable names for the display settings.
		$titles = array(
			'show_time'         => __( 'Show times', 'threadi-now-pages' ),
			'show_source'       => __( 'Show data source', 'threadi-now-pages' ),
			'link_source'       => __( 'Link the data source', 'threadi-now-pages' ),
			'group_by_category' => __( 'Group by category', 'threadi-now-pages' ),
			'delay_hours'       => __( 'Delay (hours)', 'threadi-now-pages' ),
		);

		// collect the rows.
		$rows = array();
		foreach ( array_keys( Display_Settings::defaults() ) as $key ) {
			$title  = $titles[ $key ] ?? $key;
			$origin = (string) ( $origins[ $key ] ?? 'default' );

			$rows[ $title ] = array_merge(
				array(
					'text' => sprintf(
					/* translators: %1$s will be replaced by a value, %2$s by its origin. */
						__( '%1$s - from: %2$s', 'threadi-now-pages' ),
						$this->format_value( $args[ $key ] ?? null ),
						$labels[ $origin ] ?? $origin
					),
				),
				$this->get_origin_link( $origin, $key, $user )
			);
		}

		return '<h3>' . esc_html__( 'Display settings in effect', 'threadi-now-pages' ) . '</h3>'
				. $this->render_table( $rows )
				. '<p class="description">'
				. esc_html__( 'A shortcode or block attribute beats the user profile, the profile beats the site-wide setting, and that beats the plugin default.', 'threadi-now-pages' )
				. '</p>';
	}

	/**
	 * Render the queried time range, including the effect of the delay.
	 *
	 * @param array<string,mixed> $trace The builder trace.
	 *
	 * @return string
	 */
	private function render_range( array $trace ): string {
		// bail if no range was resolved.
		if ( empty( $trace['range'] ) ) {
			return '';
		}

		list( $after, $before ) = $trace['range'];

		// collect the rows.
		$rows = array(
			__( 'From', 'threadi-now-pages' ) => $this->format_date( (string) $after ),
			__( 'To', 'threadi-now-pages' )   => $this->format_date( (string) $before ),
		);

		// explain the delay, if it shortened the range.
		if ( ! empty( $trace['delay_applied'] ) ) {
			$rows[ __( 'Without delay up to', 'threadi-now-pages' ) ] = $this->format_date( (string) $trace['before_raw'] );
		}

		$rows[ __( 'Entries shown', 'threadi-now-pages' ) ] = (string) ( $trace['found'] ?? 0 );

		return '<h3>' . esc_html__( 'Queried time range', 'threadi-now-pages' ) . '</h3>'
				. $this->render_table( $rows );
	}

	/**
	 * Render how many entries each filter removed from the result.
	 *
	 * @param array<string,mixed> $trace The builder trace.
	 *
	 * @return string
	 */
	private function render_exclusions( array $trace ): string {
		// bail if we have no query to compare against.
		if ( empty( $trace['query_args'] ) ) {
			return '';
		}

		// get the numbers.
		$excluded = $this->count_excluded( $trace );

		// show a hint if nothing was filtered out.
		if ( empty( $excluded ) ) {
			return '<h3>' . esc_html__( 'Filtered out', 'threadi-now-pages' ) . '</h3>'
					. '<p>' . esc_html__( 'No entries were removed by the active filters.', 'threadi-now-pages' ) . '</p>';
		}

		// human-readable reasons.
		$reasons = array(
			'delay'           => __( 'hidden by the delay', 'threadi-now-pages' ),
			'language'        => __( 'written in another language', 'threadi-now-pages' ),
			'category'        => __( 'in another category', 'threadi-now-pages' ),
			'author'          => __( 'belonging to another author', 'threadi-now-pages' ),
			'author_disabled' => __( 'belonging to authors who disabled their now page', 'threadi-now-pages' ),
			'user_delay'      => __( 'hidden by the personal delay of their author', 'threadi-now-pages' ),
		);

		// collect the list items.
		$items = '';
		foreach ( $excluded as $reason => $count ) {
			$items .= '<li>' . esc_html(
				sprintf(
					/* translators: %1$d will be replaced by a number, %2$s by a reason. */
					_n( '%1$d entry %2$s', '%1$d entries %2$s', $count, 'threadi-now-pages' ),
					$count,
					$reasons[ $reason ] ?? $reason
				)
			) . '</li>';
		}

		return '<h3>' . esc_html__( 'Filtered out', 'threadi-now-pages' ) . '</h3>'
				. '<ul>' . $items . '</ul>'
				. '<p class="description">'
				. esc_html__( 'Each number compares the result against the same query without that single filter. An entry excluded by two filters is counted twice.', 'threadi-now-pages' )
				. '</p>';
	}

	/**
	 * Count the entries each active filter removed from the result.
	 *
	 * @param array<string,mixed> $trace The builder trace.
	 *
	 * @return array<string,int> Map of reason => number of excluded entries.
	 */
	private function count_excluded( array $trace ): array {
		$base  = $trace['query_args'];
		$found = absint( $trace['found'] ?? 0 );
		$out   = array();
		$args  = is_array( $trace['args'] ?? null ) ? $trace['args'] : array();

		// entries hidden by the personal delay of their author: they are part of the query, but not of the result.
		if ( empty( $args['user_id'] ) && ! empty( $this->get_user_delays() ) ) {
			$base_count = $this->count_query( $base );

			$out['user_delay'] = $base_count - $found;

			// compare the other filters against the query, not the result.
			$found = $base_count;
		}

		// entries removed by the delay.
		if ( ! empty( $trace['delay_applied'] ) && ! empty( $trace['before_raw'] ) ) {
			$undelayed = $base;

			$undelayed['date_query'][0]['before'] = $trace['before_raw'];

			$out['delay'] = $this->count_query( $undelayed ) - $found;
		}

		// entries removed by the language filter.
		if ( isset( $base['meta_query'] ) ) {
			$without = $base;
			unset( $without['meta_query'] );

			$out['language'] = $this->count_query( $without ) - $found;
		}

		// entries removed by the category filter.
		if ( isset( $base['tax_query'] ) ) {
			$without = $base;
			unset( $without['tax_query'] );

			$out['category'] = $this->count_query( $without ) - $found;
		}

		// entries of other authors.
		if ( isset( $base['author'] ) ) {
			$without = $base;
			unset( $without['author'] );

			$out['author'] = $this->count_query( $without ) - $found;
		}

		// entries of authors who disabled their now page.
		if ( ! empty( $base['author__not_in'] ) ) {
			$without = $base;
			unset( $without['author__not_in'] );

			$out['author_disabled'] = $this->count_query( $without ) - $found;
		}

		// drop everything that is zero or negative.
		return array_filter(
			$out,
			static fn ( int $count ): bool => $count > 0
		);
	}

	/**
	 * Return the personal delays of all users who set one.
	 *
	 * Mirrors Digest_Builder::get_user_restrictions().
	 *
	 * @return array<int,int> Map of user ID => delay in hours.
	 */
	private function get_user_delays(): array {
		static $delays = null;

		// return the already loaded delays.
		if ( is_array( $delays ) ) {
			return $delays;
		}

		$keys   = Display_Settings::user_meta_keys();
		$delays = array();

		// get the users with their own delay.
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

		return $delays;
	}

	/**
	 * Return the personal delay of a given user.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return int The delay in hours, 0 if the user did not set one.
	 */
	private function get_user_delay( int $user_id ): int {
		return $this->get_user_delays()[ $user_id ] ?? 0;
	}

	/**
	 * Return whether an entry is hidden by the personal delay of its author.
	 *
	 * Mirrors Digest_Builder::is_hidden_by_user_delay(): only across all authors,
	 * and only if the personal delay is longer than the one of the digest.
	 *
	 * @param WP_Post             $entry The entry.
	 * @param array<string,mixed> $args  The resolved digest args.
	 *
	 * @return bool
	 */
	private function is_hidden_by_user_delay( WP_Post $entry, array $args ): bool {
		// bail for the digest of a single user: their delay is already part of the args.
		if ( ! empty( $args['user_id'] ) ) {
			return false;
		}

		$user_delay  = $this->get_user_delay( absint( $entry->post_author ) );
		$query_delay = absint( $args['delay_hours'] ?? 0 );

		// bail if the personal delay is not longer than the one already applied.
		if ( $user_delay <= $query_delay ) {
			return false;
		}

		return (int) get_post_time( 'U', true, $entry ) > time() - ( $user_delay * HOUR_IN_SECONDS );
	}

	/**
	 * Count the entries a given set of query args would return.
	 *
	 * @param array<string,mixed> $query_args The query args, as built by Digest_Builder.
	 *
	 * @return int
	 */
	private function count_query( array $query_args ): int {
		// ask only for the number of rows.
		$query_args['fields']         = 'ids';
		$query_args['posts_per_page'] = 1;
		$query_args['no_found_rows']  = false;
		$query_args['orderby']        = 'none';

		// run the query.
		$result = new WP_Query( $query_args );

		// return the number of matching entries.
		return absint( $result->found_posts );
	}

	/**
	 * Render a simple two-column table.
	 *
	 * @param array<string,mixed> $rows Map of label => value.
	 *
	 * @return string
	 */
	private function render_table( array $rows ): string {
		// bail if there is nothing to show.
		if ( empty( $rows ) ) {
			return '';
		}

		// collect the rows.
		$html = '';
		foreach ( $rows as $label => $value ) {
			// a plain string is just escaped.
			if ( ! is_array( $value ) ) {
				$cell = esc_html( (string) $value );
			} else {
				// an array carries a text and an optional link.
				$cell = esc_html( (string) $value['text'] );

				if ( ! empty( $value['url'] ) ) {
					$cell .= ' <a href="' . esc_url( (string) $value['url'] ) . '">'
							. esc_html( (string) $value['url_text'] ) . '</a>';
				}
			}

			$html .= '<tr><th scope="row">' . esc_html( (string) $label ) . '</th>'
					. '<td>' . $cell . '</td></tr>';
		}

		return '<table class="threadi-now-pages-diagnostics-table"><tbody>' . $html . '</tbody></table>';
	}

	/**
	 * Format a resolved setting value for display.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function format_value( mixed $value ): string {
		// show booleans as words.
		if ( is_bool( $value ) ) {
			return $value ? __( 'on', 'threadi-now-pages' ) : __( 'off', 'threadi-now-pages' );
		}

		// show empty values as a dash.
		if ( null === $value || '' === $value ) {
			return '–';
		}

		// show everything else as a string.
		return (string) $value;
	}

	/**
	 * Format a MySQL datetime for display.
	 *
	 * @param string $value The datetime in Y-m-d H:i:s.
	 *
	 * @return string
	 */
	private function format_date( string $value ): string {
		// bail if the value is empty.
		if ( '' === $value ) {
			return '–';
		}

		// use the date and time format of this website.
		return (string) mysql2date(
			(string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ),
			$value
		);
	}

	/**
	 * Return the place where a resolved setting can be changed.
	 *
	 * @param string       $origin The origin, see Display_Settings::resolve().
	 * @param string       $key    The setting key.
	 * @param WP_User|null $user   The author the digest was built for, if any.
	 *
	 * @return array{url:string,url_text:string} Empty URL if there is nothing to link to.
	 */
	private function get_origin_link( string $origin, string $key, ?WP_User $user ): array {
		// the value comes from the profile of the resolved author.
		if ( 'user_meta' === $origin && $user instanceof WP_User ) {
			$anchor = Display_Settings::user_meta_keys()[ $key ] ?? '';

			return array(
				'url'      => get_edit_user_link( $user->ID ) . ( '' !== $anchor ? '#' . $anchor : '' ),
				'url_text' => __( 'edit profile', 'threadi-now-pages' ),
			);
		}

		// the value was passed as a shortcode or block attribute.
		if ( 'explicit' === $origin ) {
			return array(
				'url'      => '',
				'url_text' => '',
			);
		}

		// everything else is (or would be) the site-wide setting.
		return array(
			'url'      => Settings::get_instance()->get_url( 'threadi_now_pages_display' ),
			'url_text' => 'default' === $origin
				? __( 'set site-wide', 'threadi-now-pages' )
				: __( 'change setting', 'threadi-now-pages' ),
		);
	}
}
