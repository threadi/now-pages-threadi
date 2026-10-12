<?php
/**
 * This file contains the Last.fm provider.
 *
 * Reads the recently scrobbled tracks of a Last.fm account. Needs an API
 * key, but no OAuth: the read methods of the Last.fm API work with a
 * plain key, which the account owner creates once at
 * https://www.last.fm/api/account/create
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Providers;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;
use ThreadiNowPages\NowPages\Entry;
use WP_Error;

/**
 * Object for the Last.fm provider.
 */
class LastFm_Provider extends HTTP_Provider_Base {
	/**
	 * The API endpoint.
	 *
	 * @var string
	 */
	private const API = 'https://ws.audioscrobbler.com/2.0/';

	/**
	 * The state key holding the timestamp of the newest track of the last run.
	 *
	 * @var string
	 */
	private const STATE_LAST_TRACK = 'lastfm_last_track';

	/**
	 * Return the provider slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'lastfm';
	}

	/**
	 * Return the provider label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Last.fm', 'threadi-now-pages' );
	}

	/**
	 * Return a short description shown when choosing this provider.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Reads the tracks you scrobbled to Last.fm. Needs a free API key, but no app approval.', 'threadi-now-pages' );
	}

	/**
	 * Return the configuration fields for this provider.
	 *
	 * @return array<string,mixed>
	 */
	public function get_config_fields(): array {
		return array(
			'lastfm_username'  => array(
				'label'       => __( 'Username', 'threadi-now-pages' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'The Last.fm account whose scrobbles should be read. Its listening history has to be public.', 'threadi-now-pages' ),
				'placeholder' => 'rj',
			),
			'lastfm_api_key'   => array(
				'label'       => __( 'API key', 'threadi-now-pages' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'Create one at <a href="https://www.last.fm/api/account/create" target="_blank">last.fm/api/account/create</a>. Only the API key is needed, not the shared secret.', 'threadi-now-pages' ),
			),
			'lastfm_summarize' => array(
				'label'       => __( 'Summarize instead of listing', 'threadi-now-pages' ),
				'type'        => 'checkbox',
				'default'     => 1,
				'description' => __( 'Recommended. Creates one entry per run instead of one per track - a normal listening day would otherwise fill the whole now page.', 'threadi-now-pages' ),
			),
			'lastfm_max_items' => array(
				'label'       => __( 'Max. tracks per run', 'threadi-now-pages' ),
				'type'        => 'number',
				'default'     => 10,
				'min'         => 1,
				'max'         => 200,
				'step'        => 1,
				'description' => __( 'When summarizing, this is how many tracks are read to build the summary.', 'threadi-now-pages' ),
			),
		);
	}

	/**
	 * Fetch the recent tracks of the configured account.
	 *
	 * @param array<string,mixed> $config  The configuration of the source.
	 * @param Provider_Context    $context The context of this run.
	 *
	 * @return array<int,Entry>
	 */
	public function fetch( array $config, Provider_Context $context ): array {
		$entries  = array();
		$username = trim( (string) ( $config['lastfm_username'] ?? '' ) );
		$api_key  = trim( (string) ( $config['lastfm_api_key'] ?? '' ) );

		// bail if the source is not configured completely.
		if ( '' === $username || '' === $api_key ) {
			return $entries;
		}

		$max_items = 10;

		if ( isset( $config['lastfm_max_items'] ) ) {
			$max_items = absint( $config['lastfm_max_items'] );
		}

		$query = array(
			'method'  => 'user.getrecenttracks',
			'user'    => $username,
			'api_key' => $api_key,
			'format'  => 'json',
			'limit'   => $max_items,
		);

		// only ask for what happened since the last run.
		$last = absint( $context->get_state( self::STATE_LAST_TRACK, 0 ) );

		if ( $last > 0 ) {
			$query['from'] = $last + 1;
		}

		$data = $this->get_json( self::API . '?' . http_build_query( $query ), array(), $context );

		// bail if the request failed or nothing changed since the last run.
		if ( null === $data ) {
			return $entries;
		}

		// Last.fm answers some errors with status 200 and an error in the body.
		if ( isset( $data['error'] ) ) {
			$this->report_error(
				new WP_Error(
					'threadi_now_pages_lastfm_error',
					sprintf(
					/* translators: %1$s will be replaced by an error message from Last.fm. */
						__( 'Last.fm answered with an error: %1$s', 'threadi-now-pages' ),
						(string) ( $data['message'] ?? '' )
					)
				)
			);

			return $entries;
		}

		$tracks = $this->get_tracks( $data );

		// bail if nothing was scrobbled.
		if ( empty( $tracks ) ) {
			return $entries;
		}

		// remember the newest timestamp for the next run.
		$newest = 0;

		foreach ( $tracks as $track ) {
			$timestamp = absint( $track['date']['uts'] ?? 0 );

			if ( $timestamp > $newest ) {
				$newest = $timestamp;
			}
		}

		// it is stored only once the entries of this run have been saved.
		if ( $newest > 0 ) {
			$context->set_pending_state( self::STATE_LAST_TRACK, $newest );
		}

		// one entry summarizing the whole run.
		if ( ! empty( $config['lastfm_summarize'] ) ) {
			$entry     = $this->get_summary_entry( $tracks, $newest );
			$entries[] = $entry;
			return $entries;
		}

		// one entry per track.
		foreach ( $tracks as $track ) {
			$entry = $this->get_entry_from_track( $track );

			if ( $entry instanceof Entry ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * Return the scrobbled tracks from the API response.
	 *
	 * Tracks without a timestamp are the one currently playing. They are
	 * skipped on purpose: the same track arrives again as a real scrobble a
	 * minute later, and both would become separate entries.
	 *
	 * @param array<mixed> $data The decoded response.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_tracks( array $data ): array {
		$tracks = array();

		if ( ! isset( $data['recenttracks']['track'] ) || ! is_array( $data['recenttracks']['track'] ) ) {
			return $tracks;
		}

		$raw = $data['recenttracks']['track'];

		// with a single result the API returns one object instead of a list.
		if ( isset( $raw['name'] ) ) {
			$raw = array( $raw );
		}

		foreach ( $raw as $track ) {
			// skip anything unexpected.
			if ( ! is_array( $track ) ) {
				continue;
			}

			// skip the track that is playing right now, see the description.
			if ( ! isset( $track['date']['uts'] ) ) {
				continue;
			}

			$tracks[] = $track;
		}

		return $tracks;
	}

	/**
	 * Turn a single track into an entry.
	 *
	 * @param array<string,mixed> $track The track as returned by the API.
	 *
	 * @return Entry|null Null if the track is unusable.
	 */
	private function get_entry_from_track( array $track ): ?Entry {
		$title  = trim( (string) ( $track['name'] ?? '' ) );
		$artist = trim( (string) ( $track['artist']['#text'] ?? '' ) );

		// bail if the track has no title.
		if ( '' === $title ) {
			return null;
		}

		$entry              = new Entry();
		$entry->date        = $this->get_date( absint( $track['date']['uts'] ) );
		$entry->source_slug = $this->get_slug();
		$entry->source_url  = esc_url_raw( (string) ( $track['url'] ?? '' ) );
		$entry->language    = null;
		$entry->external_id = 'lastfm-' . md5( $artist . '|' . $title . '|' . (string) $track['date']['uts'] );

		if ( '' === $artist ) {
			$entry->text = sprintf(
			/* translators: %1$s will be replaced by the title of a track. */
				__( 'Listened to "%1$s"', 'threadi-now-pages' ),
				$title
			);

			return $entry;
		}

		$entry->text = sprintf(
		/* translators: %1$s will be replaced by the title of a track, %2$s by an artist. */
			__( 'Listened to "%1$s" by %2$s', 'threadi-now-pages' ),
			$title,
			$artist
		);

		return $entry;
	}

	/**
	 * Turn a whole run into a single summarizing entry.
	 *
	 * @param array<int,array<string,mixed>> $tracks The tracks of this run.
	 * @param int                            $newest The newest timestamp.
	 *
	 * @return Entry Null if the tracks are unusable.
	 */
	private function get_summary_entry( array $tracks, int $newest ): Entry {
		$count   = count( $tracks );
		$artists = array();

		foreach ( $tracks as $track ) {
			$artist = trim( (string) ( $track['artist']['#text'] ?? '' ) );

			if ( '' === $artist ) {
				continue;
			}

			if ( ! isset( $artists[ $artist ] ) ) {
				$artists[ $artist ] = 0;
			}

			++$artists[ $artist ];
		}

		$entry              = new Entry();
		$entry->date        = $this->get_date( $newest );
		$entry->source_slug = $this->get_slug();
		$entry->source_url  = null;
		$entry->language    = null;
		$entry->external_id = 'lastfm-summary-' . $newest;

		// without a known artist, only the number is left to say.
		if ( empty( $artists ) ) {
			$entry->text = sprintf(
			/* translators: %1$d will be replaced by a number of tracks. */
				_n( 'Listened to %1$d track', 'Listened to %1$d tracks', $count, 'threadi-now-pages' ),
				$count
			);

			return $entry;
		}

		arsort( $artists );

		$entry->text = sprintf(
		/* translators: %1$d will be replaced by a number of tracks, %2$s by an artist. */
			_n( 'Listened to %1$d track, most recently %2$s', 'Listened to %1$d tracks, mostly %2$s', $count, 'threadi-now-pages' ),
			$count,
			(string) array_key_first( $artists )
		);

		return $entry;
	}

	/**
	 * Turn a unix timestamp into a date object.
	 *
	 * @param int $timestamp The timestamp.
	 *
	 * @return DateTimeImmutable
	 */
	private function get_date( int $timestamp ): DateTimeImmutable {
		if ( $timestamp < 1 ) {
			return new DateTimeImmutable();
		}

		return new DateTimeImmutable( '@' . $timestamp, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Do not use ETags for Last.fm.
	 *
	 * Every request contains the changing "from" parameter, so an ETag would
	 * never be reused, but a new one would be stored with every run.
	 *
	 * @param string $url The requested URL.
	 *
	 * @return string
	 */
	protected function get_etag_key( string $url ): string {
		return '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_identifying_fields(): array {
		return array( 'lastfm_username' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_test_url( array $config ): string {
		return self::API;
	}
}
