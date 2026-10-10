<?php
/**
 * File to manage our own sitemap provider for now pages.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\NowPages;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Digests\Digest_Builder;
use NowPagesThreadi\Digests\Display_Settings;
use WP_Sitemaps_Provider;
use WP_User;

/**
 * Object to manage our own sitemap provider for now pages.
 */
class Sitemap extends WP_Sitemaps_Provider {
	/**
	 * Name of the transient which caches the sitemap entries.
	 */
	private const CACHE_KEY = 'now_pages_threadi_sitemap_entries';

	/**
	 * Lifetime of the cached sitemap entries in seconds.
	 */
	private const CACHE_TIME = HOUR_IN_SECONDS;

	/**
	 * Cached user_id => last-entry-timestamp for users with their own, enabled, non-empty now page.
	 *
	 * Cached per-request (a fresh Sitemap instance is used per request by
	 * Core), so get_max_num_pages() and get_url_list() - both called for
	 * every requested page - don't repeat the same per-user queries.
	 *
	 * @var array<int,int>|null
	 */
	private ?array $eligible_authors = null;

	/**
	 * Initialize this object.
	 */
	public function __construct() {
		// only lowercase a-z allowed here - see the WordPress core rewrite
		// rule in WP_Sitemaps::register_rewrites(), which does not accept
		// digits, underscores or dashes in the provider name segment.
		$this->name        = 'nowpages';
		$this->object_type = 'post';
	}

	/**
	 * Delete the cached sitemap entries.
	 *
	 * @return void
	 */
	public static function clear_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Return the list of URLs for the given page.
	 *
	 * @param int    $page_num       Page of results.
	 * @param string $object_subtype Optional. Object subtype name. Default empty.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_url_list( $page_num, $object_subtype = '' ): array {
		$per_page = wp_sitemaps_get_max_urls( $this->object_type );
		$offset   = ( max( 1, (int) $page_num ) - 1 ) * $per_page;

		return array_slice( $this->get_all_entries(), $offset, $per_page );
	}

	/**
	 * Return the amount of pages.
	 *
	 * @param string $object_subtype Optional. Object subtype name. Default empty.
	 *
	 * @return int
	 */
	public function get_max_num_pages( $object_subtype = '' ): int {
		// count the entries.
		$total = count( $this->get_all_entries() );

		// return 0 if there are no entries.
		if ( 0 === $total ) {
			return 0;
		}

		return (int) ceil( $total / wp_sitemaps_get_max_urls( $this->object_type ) );
	}

	/**
	 * Build the full, ordered list of sitemap entries: the global now page
	 * (if enabled and non-empty) first, then one entry per eligible user.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_all_entries(): array {
		// return the cached list: building it runs one query per author and the
		// sitemap is public, so every request would repeat this work.
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$entries = array();

		if ( Frontend::get_instance()->is_public() ) {
			$timestamp = ( new Digest_Builder() )->get_last_entry_date( $this->get_global_args() );

			if ( null !== $timestamp ) {
				$entry = array(
					'loc'        => Frontend::get_instance()->get_global_now_page_url(),
					'lastmod'    => gmdate( DATE_W3C, $timestamp ),
					'changefreq' => 'weekly',
					'priority'   => 1.0,
				);

				/**
				 * Filter a single now-page sitemap entry.
				 *
				 * @param array<string,mixed> $entry The sitemap entry (loc/lastmod/changefreq/priority only).
				 * @param WP_User|null        $user  The user this entry is for, null for the global now page.
				 */
				$entries[] = apply_filters( 'now_pages_threadi_sitemap_entry', $entry, null );
			}
		}

		foreach ( $this->get_eligible_authors() as $user_id => $timestamp ) {
			$user = get_user_by( 'id', $user_id );

			if ( ! $user instanceof WP_User ) {
				continue;
			}

			$entry = array(
				'loc'        => Frontend::get_instance()->get_author_now_page_url( $user ),
				'lastmod'    => gmdate( DATE_W3C, $timestamp ),
				'changefreq' => 'weekly',
				'priority'   => 0.6,
			);

			/** This filter is documented above. */
			$entries[] = apply_filters( 'now_pages_threadi_sitemap_entry', $entry, $user );
		}

		set_transient( self::CACHE_KEY, $entries, self::CACHE_TIME );

		return $entries;
	}

	/**
	 * Return user_id => last-entry-timestamp for every user with their own,
	 * enabled, non-empty now page.
	 *
	 * @return array<int,int>
	 */
	private function get_eligible_authors(): array {
		if ( null !== $this->eligible_authors ) {
			return $this->eligible_authors;
		}

		$this->eligible_authors = array();

		// bail if per-user now pages are disabled site-wide.
		if ( ! Frontend::get_instance()->is_public( true ) ) {
			return $this->eligible_authors;
		}

		$keys = Display_Settings::user_meta_keys();

		// every user with published entries, except those who individually disabled their own now page.
		$user_ids = get_users(
			array(
				'fields'              => 'ID',
				'has_published_posts' => array( Entries::get_instance()->get_slug() ),
				'meta_query'          => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, one-off query.
					'relation' => 'OR',
					array(
						'key'     => $keys['disabled'],
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => $keys['disabled'],
						'value'   => '1',
						'compare' => '!=',
					),
				),
			)
		);

		$builder = new Digest_Builder();

		foreach ( $user_ids as $user_id ) {
			$args            = $this->get_global_args();
			$args['user_id'] = (int) $user_id;
			$timestamp       = $builder->get_last_entry_date( $args );

			// skip users without any entries yet - an empty page has no place in a sitemap.
			if ( null !== $timestamp ) {
				$this->eligible_authors[ (int) $user_id ] = $timestamp;
			}
		}

		// return list of authors.
		return $this->eligible_authors;
	}

	/**
	 * Return the same Digest_Builder args Frontend::prepare() builds for the /now routes.
	 *
	 * @return array<string,mixed>
	 */
	private function get_global_args(): array {
		$args = array(
			'period' => get_option( 'now_pages_threadi_frontend_period' ),
			'format' => get_option( 'now_pages_threadi_frontend_format' ),
		);

		/** This filter is documented in Frontend::prepare(). */
		return apply_filters( 'now_pages_threadi_frontend_digest_args', $args, null );
	}
}
