<?php
/**
 * This file contains the Steam provider.
 *
 * Unlike every other provider this one does not read a stream of events.
 * The Steam API returns counters - total minutes played per game - so an
 * entry is created from the difference to the previous run. The counters
 * are kept in the provider context.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Providers;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use ThreadiNowPages\NowPages\Entry;
use WP_Error;

/**
 * Object for the Steam provider.
 */
class Steam_Provider extends HTTP_Provider_Base {
	/**
	 * The API endpoint for the recently played games.
	 *
	 * @var string
	 */
	private const API = 'https://api.steampowered.com/IPlayerService/GetRecentlyPlayedGames/v0001/';

	/**
	 * The state key holding the playtime counters of the last run.
	 *
	 * @var string
	 */
	private const STATE_PLAYTIME = 'steam_playtime';

	/**
	 * The state key telling that Steam answered once without any recently played game.
	 *
	 * @var string
	 */
	private const STATE_IDLE_SEEN = 'steam_idle_seen';

	/**
	 * Return the provider slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'steam';
	}

	/**
	 * Return the provider label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Steam', 'threadi-now-pages' );
	}

	/**
	 * Return a short description shown when choosing this provider.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Reads what you played on Steam. Needs a free API key and a public profile.', 'threadi-now-pages' );
	}

	/**
	 * Return the configuration fields for this provider.
	 *
	 * @return array<string,mixed>
	 */
	public function get_config_fields(): array {
		return array(
			'steam_id'          => array(
				'label'       => __( 'SteamID64', 'threadi-now-pages' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'The 17 digit ID of your account, not the display name. You can look it up at steamid.io. Your profile and its game details have to be set to public.', 'threadi-now-pages' ),
				'placeholder' => '76561197960287930',
			),
			'steam_api_key'     => array(
				'label'       => __( 'API key', 'threadi-now-pages' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'Create one at <a href="https://www.steamcommunity.com/dev/apikey" target="_blank">steamcommunity.com/dev/apikey</a>. It is bound to your account, so keep it to yourself.', 'threadi-now-pages' ),
			),
			'steam_min_minutes' => array(
				'label'       => __( 'Minimum minutes', 'threadi-now-pages' ),
				'type'        => 'number',
				'default'     => 15,
				'min'         => 1,
				'max'         => 600,
				'step'        => 1,
				'description' => __( 'How long a session has to be before it becomes an entry. Prevents an entry for every short look into a game.', 'threadi-now-pages' ),
			),
			'steam_max_items'   => array(
				'label'   => __( 'Max. items per run', 'threadi-now-pages' ),
				'type'    => 'number',
				'default' => 5,
				'min'     => 1,
				'max'     => 20,
				'step'    => 1,
			),
		);
	}

	/**
	 * Fetch the games played since the last run.
	 *
	 * @param array<string,mixed> $config  The configuration of the source.
	 * @param Provider_Context    $context The context of this run.
	 *
	 * @return array<int,Entry>
	 */
	public function fetch( array $config, Provider_Context $context ): array {
		$entries  = array();
		$steam_id = trim( (string) ( $config['steam_id'] ?? '' ) );
		$api_key  = trim( (string) ( $config['steam_api_key'] ?? '' ) );

		// bail if the source is not configured completely.
		if ( '' === $steam_id || '' === $api_key ) {
			return $entries;
		}

		$min_minutes = 15;

		if ( isset( $config['steam_min_minutes'] ) ) {
			$min_minutes = absint( $config['steam_min_minutes'] );
		}

		$max_items = 5;

		if ( isset( $config['steam_max_items'] ) ) {
			$max_items = absint( $config['steam_max_items'] );
		}

		$url = self::API . '?' . http_build_query(
			array(
				'key'     => $api_key,
				'steamid' => $steam_id,
				'format'  => 'json',
			)
		);

		$data = $this->get_json( $url, array(), $context );

		// bail if the request failed or nothing changed since the last run.
		if ( null === $data ) {
			return $entries;
		}

		$games = $this->get_games( $data );

		// an empty response usually means the profile is not public.
		if ( empty( $games ) ) {
			// nothing played lately is an answer as well: remember it, so the
			// next game which shows up is a session and not the first run.
			if ( $this->is_idle( $data ) ) {
				$context->set_state( self::STATE_IDLE_SEEN, true );
			}

			$this->report_empty_response( $data, $context );

			return $entries;
		}

		// get the counters of the previous run.
		$previous = $context->get_state( self::STATE_PLAYTIME, array() );

		if ( ! is_array( $previous ) ) {
			$previous = array();
		}

		// whether there is something to compare against.
		$is_first_run = empty( $previous ) && empty( $context->get_state( self::STATE_IDLE_SEEN, false ) );

		// keep the counters of games Steam does not list anymore: it only lists
		// the last two weeks, and a game which comes back would look like new.
		$current = $previous;
		$played  = array();

		foreach ( $games as $game ) {
			$app_id = absint( $game['appid'] ?? 0 );
			$total  = absint( $game['playtime_forever'] ?? 0 );

			// skip anything without a usable identifier.
			if ( 0 === $app_id ) {
				continue;
			}

			$current[ $app_id ] = $total;

			// on the very first run there is nothing to compare against. store
			// the counters and report nothing, otherwise the first import would
			// claim the complete playtime of every game as one session.
			if ( $is_first_run ) {
				continue;
			}

			$before = absint( $previous[ $app_id ] ?? 0 );

			// a counter can only grow. anything else means Steam reset it, or
			// the game was reinstalled - treat it as no session.
			if ( $total <= $before ) {
				continue;
			}

			$minutes = $total - $before;

			// a game we have no counter of brings everything ever played. What
			// Steam counted for the last two weeks is the most which can be new.
			if ( isset( $game['playtime_2weeks'] ) ) {
				$minutes = min( $minutes, absint( $game['playtime_2weeks'] ) );
			}

			// skip sessions that are too short to be worth an entry.
			if ( $minutes < $min_minutes ) {
				continue;
			}

			$played[] = array(
				'app_id'  => $app_id,
				'name'    => trim( (string) ( $game['name'] ?? '' ) ),
				'minutes' => $minutes,
				'total'   => $total,
			);
		}

		// remember the counters for the next run, even when nothing is reported.
		// they are stored only once the entries of this run have been saved.
		$context->set_pending_state( self::STATE_PLAYTIME, $current );

		// the longest session first.
		usort(
			$played,
			static function ( array $a, array $b ): int {
				return $b['minutes'] <=> $a['minutes'];
			}
		);

		foreach ( array_slice( $played, 0, $max_items ) as $session ) {
			$entries[] = $this->get_entry_from_session( $session );
		}

		return $entries;
	}

	/**
	 * Return the games from the API response.
	 *
	 * @param array<mixed> $data The decoded response.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_games( array $data ): array {
		if ( ! isset( $data['response']['games'] ) || ! is_array( $data['response']['games'] ) ) {
			return array();
		}

		return $data['response']['games'];
	}

	/**
	 * Return whether Steam said that nothing was played lately.
	 *
	 * A private profile is answered with an empty object, a public one
	 * without recent games with a total count of zero.
	 *
	 * @param array<mixed> $data The decoded response.
	 *
	 * @return bool
	 */
	private function is_idle( array $data ): bool {
		return isset( $data['response']['total_count'] ) && 0 === absint( $data['response']['total_count'] );
	}

	/**
	 * Report an empty response, but only once per source.
	 *
	 * Steam answers a private profile with an empty object instead of an
	 * error, so this is the only place where it can be noticed. Without the
	 * marker the message would be written on every single run.
	 *
	 * @param array<mixed>     $data    The decoded response.
	 * @param Provider_Context $context The context of this run.
	 *
	 * @return void
	 */
	private function report_empty_response( array $data, Provider_Context $context ): void {
		// a response with a total count of zero simply means nothing was played.
		if ( $this->is_idle( $data ) ) {
			return;
		}

		// bail if we already reported this.
		if ( ! empty( $context->get_state( 'steam_reported_empty', false ) ) ) {
			return;
		}

		$context->set_state( 'steam_reported_empty', true );

		$this->report_error(
			new WP_Error(
				'threadi_now_pages_steam_empty',
				__( 'Steam returned no games. Set your profile and its game details to public, otherwise the API stays silent.', 'threadi-now-pages' )
			)
		);
	}

	/**
	 * Turn a single session into an entry.
	 *
	 * @param array<string,mixed> $session The session.
	 *
	 * @return Entry
	 */
	private function get_entry_from_session( array $session ): Entry {
		$name = (string) $session['name'];

		if ( '' === $name ) {
			$name = sprintf(
			/* translators: %1$d will be replaced by a Steam application ID. */
				__( 'App %1$d', 'threadi-now-pages' ),
				absint( $session['app_id'] )
			);
		}

		$minutes = absint( $session['minutes'] );

		$entry              = new Entry();
		$entry->date        = new DateTimeImmutable();
		$entry->source_slug = $this->get_slug();
		$entry->source_url  = 'https://store.steampowered.com/app/' . absint( $session['app_id'] ) . '/';
		$entry->language    = null;

		// the total playtime makes the ID unique without needing a timestamp.
		$entry->external_id = 'steam-' . absint( $session['app_id'] ) . '-' . absint( $session['total'] );

		$entry->text = $this->get_text( $name, $minutes );

		return $entry;
	}

	/**
	 * Return the text describing a session.
	 *
	 * @param string $name    The name of the game.
	 * @param int    $minutes The length of the session in minutes.
	 *
	 * @return string
	 */
	private function get_text( string $name, int $minutes ): string {
		// below an hour, minutes read better than a fraction.
		if ( $minutes < 60 ) {
			return sprintf(
			/* translators: %1$d will be replaced by a number of minutes, %2$s by the name of a game. */
				_n( 'Played %2$s for %1$d minute', 'Played %2$s for %1$d minutes', $minutes, 'threadi-now-pages' ),
				$minutes,
				$name
			);
		}

		$hours = round( $minutes / 60, 1 );

		return sprintf(
		/* translators: %1$s will be replaced by a number of hours, %2$s by the name of a game. */
			__( 'Played %2$s for %1$s hours', 'threadi-now-pages' ),
			number_format_i18n( $hours, 1 ),
			$name
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_identifying_fields(): array {
		return array( 'steam_id' );
	}
}
