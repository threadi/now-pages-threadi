<?php
/**
 * This file contains the RSS/Atom provider.
 *
 * Pull-based adapter: fetches entries from any RSS or Atom feed URL,
 * using WordPress' built-in feed parser (SimplePie), so no extra
 * dependency is needed. This is deliberately generic - it acts as the
 * "lingua franca" adapter: many services either offer a native feed
 * (Mastodon/Fediverse, most blogs, YouTube, Reddit, ...) or can be
 * bridged into one (e.g. via a self-hosted RSS-Bridge instance for
 * services without a native feed).
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Providers;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use Exception;
use ThreadiNowPages\Helper\Format;
use ThreadiNowPages\Helper\Languages;
use ThreadiNowPages\Helper\Url;
use ThreadiNowPages\NowPages\Entry;
use ThreadiNowPages\Plugin\Log;
use WP_Error;

/**
 * Provider to read entries from an RSS or Atom feed.
 */
class RSS_Provider extends Provider_Base {

	/**
	 * The max response size.
	 *
	 * @var int
	 */
	private const MAX_RESPONSE_SIZE = 5 * MB_IN_BYTES;

	/**
	 * Return the provider slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'rss';
	}

	/**
	 * Return the provider label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'RSS / Atom feed', 'threadi-now-pages' );
	}

	/**
	 * Return the configuration fields for this provider.
	 *
	 * Rendered by the settings-UI; kept generic (type + label) so the
	 * form-builder does not need to know about individual providers.
	 *
	 * @return array<string,mixed>
	 */
	public function get_config_fields(): array {
		return array(
			'feed_url'  => array(
				'label'       => __( 'Feed-URL', 'threadi-now-pages' ),
				'type'        => 'url',
				'required'    => true,
				'description' => __( 'Also works with feeds from an RSS-Bridge instance for services without a native feed.', 'threadi-now-pages' ),
				'placeholder' => 'https://rss.feed.atom',
			),
			'max_items' => array(
				'label'   => __( 'Max. items per run', 'threadi-now-pages' ),
				'type'    => 'number',
				'default' => 10,
				'min'     => 1,
				'max'     => 100,
				'step'    => 1,
			),
		);
	}

	/**
	 * Fetch entries from the configured feed and normalize them.
	 *
	 * @param array<string,mixed> $config  The configuration of the source.
	 * @param Provider_Context    $context The context of this run.
	 *
	 * @return Entry[] List of normalized entries. Empty on error (logged via threadi_now_pages_log action).
	 */
	public function fetch( array $config, Provider_Context $context ): array {
		$entries = array();

		if ( empty( $config['feed_url'] ) ) {
			return $entries;
		}

		// make sure the feed-API is available (it usually already is on frontend requests).
		if ( ! function_exists( 'fetch_feed' ) ) {
			require_once ABSPATH . WPINC . '/feed.php';
		}

		// disable feed-caching noise for now, our own schedule already controls the interval.
		add_filter( 'wp_feed_cache_transient_lifetime', array( $this, 'get_cache_lifetime' ) );
		add_filter( 'http_request_args', array( $this, 'limit_response_size' ) );
		add_filter( 'http_response', array( $this, 'reject_oversized_response' ), 10, 3 );
		$feed = fetch_feed( $config['feed_url'] );
		remove_filter( 'http_response', array( $this, 'reject_oversized_response' ), 10 );
		remove_filter( 'http_request_args', array( $this, 'limit_response_size' ) );
		remove_filter( 'wp_feed_cache_transient_lifetime', array( $this, 'get_cache_lifetime' ) );

		if ( is_wp_error( $feed ) ) {
			/**
			 * Fires when a provider fails to fetch data, for the central logging requirement.
			 *
			 * @param string   $slug  The provider slug.
			 * @param WP_Error $error The resulting error.
			 */
			do_action( 'threadi_now_pages_provider_error', $this->get_slug(), $this->redact_error( $feed ) );

			// do nothing more.
			return $entries;
		}

		// get the max items to read from the feed, if given.
		$max_items = isset( $config['max_items'] ) ? absint( $config['max_items'] ) : 10;

		// get the category entries to add from the feed, if given.
		$category_id = isset( $config['category_term_id'] ) ? absint( $config['category_term_id'] ) : 0;
		$items       = $feed->get_items( 0, $max_items );

		// get the feed language.
		$feed_language = $feed->get_language();
		$feed_language = $feed_language ? Languages::normalize_locale( $feed_language ) : null;

		// read the items in the feed.
		foreach ( $items as $item ) {
			$date_string = '';
			try {
				$date_string = $item->get_date( 'c' );
				$date        = $date_string ? new DateTimeImmutable( $date_string ) : new DateTimeImmutable();
			} catch ( Exception $e ) {
				// log this event.
				Log::get_instance()->add( __( 'Error during formatting of a timestamp from an RSS-feed.', 'threadi-now-pages' ) . ' <code>' . esc_html( $e->getMessage() ) . '</code>', 'error', 'provider' );

				// create a simple datetime object.
				$date = new DateTimeImmutable();
			}

			// plain text only, per our unified format: no markup, no shortcodes.
			$text = Format::html_to_plain_text( (string) $item->get_content() );
			if ( '' === trim( $text ) ) {
				$text = Format::html_to_plain_text( (string) $item->get_description() );
			}
			$text = trim( $text );

			if ( '' === $text ) {
				continue;
			}

			// a feed may deliver complete articles, an entry is a short text.
			$text = $this->limit_text_length( $text );

			$entry                   = new Entry();
			$entry->text             = $text;
			$entry->date             = $date;
			$entry->source_slug      = $this->get_slug();
			$entry->source_url       = $item->get_permalink() ? esc_url_raw( $item->get_permalink() ) : null;
			$entry->category_term_id = $category_id;
			$entry->language         = $feed_language;
			$entry->external_id      = $item->get_id() ? $item->get_id() : md5( $entry->source_url . $date_string );

			// the author, if the feed declares one - used for content filtering.
			$author = $item->get_author();
			if ( $author instanceof \SimplePie\Author ) {
				$author_name = ! empty( $author->get_name() ) ? $author->get_name() : $author->get_email();

				if ( $author_name ) {
					$entry->meta['author'] = $author_name;
				}
			}

			$entries[] = $entry;
		}

		return $entries;
	}

	/**
	 * Return the cache lifetime used while fetching a feed.
	 *
	 * Kept short: our own schedule (default hourly) already decides how
	 * often we poll, we don't want SimplePie's own transient cache to
	 * serve stale data on the following run.
	 *
	 * @return int
	 */
	public function get_cache_lifetime(): int {
		return 5 * MINUTE_IN_SECONDS;
	}

	/**
	 * Return a description for this provider.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Reads any RSS or Atom feed. Also works with an RSS-Bridge instance for services without a native feed.', 'threadi-now-pages' );
	}

	/**
	 * Return whether this provider can run on this website at all.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return function_exists( 'fetch_feed' ) || file_exists( ABSPATH . WPINC . '/feed.php' );
	}

	/**
	 * Return why this provider cannot run, for the user.
	 *
	 * @return string
	 */
	public function get_unavailable_reason(): string {
		return __( 'The WordPress feed API is not available on this installation.', 'threadi-now-pages' );
	}

	/**
	 * Return the entry attributes this provider fills, usable for content filtering.
	 *
	 * @return array<string,string>
	 */
	public function get_filterable_fields(): array {
		return array(
			'author' => __( 'Author', 'threadi-now-pages' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_identifying_fields(): array {
		return array( 'feed_url' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_test_url( array $config ): string {
		$url = '';

		if ( ! empty( $config['feed_url'] ) ) {
			$url = esc_url_raw( (string) $config['feed_url'] );
		}

		return $url;
	}

	/**
	 * Limit the size of a feed download.
	 *
	 * @param array<string,mixed> $args The request arguments.
	 *
	 * @return array<string,mixed>
	 */
	public function limit_response_size( array $args ): array {
		$args['limit_response_size'] = self::MAX_RESPONSE_SIZE;

		return $args;
	}

	/**
	 * Return the error with every URL in its messages redacted.
	 *
	 * The messages of the feed parser contain the complete feed address,
	 * which may carry a token in its query or its credentials.
	 *
	 * @param WP_Error $error The error.
	 *
	 * @return WP_Error
	 */
	private function redact_error( WP_Error $error ): WP_Error {
		$result = new WP_Error();

		foreach ( $error->get_error_codes() as $code ) {
			foreach ( $error->get_error_messages( $code ) as $message ) {
				// replace every URL in the message by its redacted form.
				$message = (string) preg_replace_callback(
					'#[a-z][a-z0-9+.\-]*://[^\s`\'"<>]+#i',
					static function ( array $matches ): string {
						return Url::redact( $matches[0] );
					},
					$message
				);

				$result->add( $code, $message );
			}

			// keep the data of this code.
			$data = $error->get_error_data( $code );
			if ( null !== $data ) {
				$result->add_data( $data, $code );
			}
		}

		return $result;
	}

	/**
	 * Reject a response which hit the limit: it is cut off and would be parsed incompletely.
	 *
	 * @param array<string,mixed>|WP_Error $response The response.
	 * @param array<string,mixed>          $args     The request arguments.
	 * @param string                       $url      The requested URL.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function reject_oversized_response( array|WP_Error $response, array $args, string $url ): array|WP_Error {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( strlen( wp_remote_retrieve_body( $response ) ) >= self::MAX_RESPONSE_SIZE ) {
			return new WP_Error(
				'threadi_now_pages_response_too_large',
				sprintf(
				/* translators: %1$s will be replaced by a URL, %2$s by a file size. */
					__( 'The answer of %1$s is larger than %2$s and has been ignored.', 'threadi-now-pages' ),
					Url::redact( $url ),
					size_format( self::MAX_RESPONSE_SIZE )
				)
			);
		}

		return $response;
	}
}
