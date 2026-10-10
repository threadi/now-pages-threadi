<?php
/**
 * This file contains the GitHub provider.
 *
 * Reads the public activity of a single GitHub account. Deliberately uses
 * the unauthenticated events endpoint by default: it needs no app
 * registration and no OAuth dance, only a username. A personal access
 * token is optional and only raises the rate limit.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Providers;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use Exception;
use NowPagesThreadi\NowPages\Entry;
use NowPagesThreadi\Plugin\Crypt;
use NowPagesThreadi\Plugin\Log;

/**
 * Object for the GitHub provider.
 */
class GitHub_Provider extends HTTP_Provider_Base {
	/**
	 * The API host.
	 *
	 * @var string
	 */
	private const API = 'https://api.github.com';

	/**
	 * The state key holding the ID of the newest event of the last run.
	 *
	 * @var string
	 */
	private const STATE_LAST_EVENT = 'github_last_event_id';

	/**
	 * Return the provider slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'github';
	}

	/**
	 * Return the provider label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'GitHub', 'now-pages-threadi' );
	}

	/**
	 * Return a short description shown when choosing this provider.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Reads the public activity of a GitHub account: pushes, pull requests, issues, releases and stars. Only a username is required.', 'now-pages-threadi' );
	}

	/**
	 * Return the configuration fields for this provider.
	 *
	 * @return array<string,mixed>
	 */
	public function get_config_fields(): array {
		return array(
			'github_username'  => array(
				'label'       => __( 'Username', 'now-pages-threadi' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'The GitHub account whose public activity should be read.', 'now-pages-threadi' ),
				'placeholder' => 'octocat',
			),
			'github_token'     => array(
				'label'       => __( 'Personal access token', 'now-pages-threadi' ),
				'type'        => 'password',
				'required'    => false,
				'description' => __( 'Optional. Without a token GitHub allows 60 requests per hour and IP address, with one 5,000. A token with no scopes at all is enough - it only raises the limit.', 'now-pages-threadi' ),
			),
			'github_max_items' => array(
				'label'   => __( 'Max. items per run', 'now-pages-threadi' ),
				'type'    => 'number',
				'default' => 10,
				'min'     => 1,
				'max'     => 30,
				'step'    => 1,
			),
		);
	}

	/**
	 * Fetch the public events of the configured account and normalize them.
	 *
	 * @param array<string,mixed> $config  The configuration of the source.
	 * @param Provider_Context    $context The context of this run.
	 *
	 * @return array<int,Entry>
	 */
	public function fetch( array $config, Provider_Context $context ): array {
		$entries  = array();
		$username = trim( (string) ( $config['github_username'] ?? '' ) );

		// bail if no account is configured.
		if ( '' === $username ) {
			return $entries;
		}

		// set max items.
		$max_items = isset( $config['github_max_items'] ) ? absint( $config['github_max_items'] ) : 10;

		// prepare the header.
		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
		);

		// a token only raises the rate limit, it is not required.
		$token = ! empty( $config['github_token'] ) ? trim( (string) ( $config['github_token'] ) ) : '';
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		// create the URL we want to request.
		$url = self::API . '/users/' . rawurlencode( $username ) . '/events/public?per_page=' . min( 100, max( 1, $max_items ) );

		// send the request and get the events.
		$events = $this->get_json( $url, $headers, $context );

		// bail if the request failed or nothing changed since the last run.
		if ( null === $events ) {
			return $entries;
		}

		// remember the newest event, the API returns them in descending order.
		$last_event_id = (string) $context->get_state( self::STATE_LAST_EVENT, '' );
		$newest_id     = '';

		foreach ( $events as $event ) {
			// bail on unexpected payloads.
			if ( ! is_array( $event ) || empty( $event['id'] ) || ! is_scalar( $event['id'] ) ) {
				continue;
			}

			$event_id = (string) $event['id'];

			// remember the first, which is the newest one.
			if ( '' === $newest_id ) {
				$newest_id = $event_id;
			}

			// stop as soon as we reach the newest event of the last run.
			if ( '' !== $last_event_id && $event_id === $last_event_id ) {
				break;
			}

			// one event of an unexpected form must not stop the whole run.
			try {
				$entry = $this->get_entry_from_event( $event );
			} catch ( \Throwable $e ) {
				// log this event.
				Log::get_instance()->add(
					__( 'A GitHub event could not be read and has been skipped. Error:', 'now-pages-threadi' ) . ' <code>' . esc_html( $e->getMessage() ) . '</code>',
					'error',
					'provider'
				);

				continue;
			}

			// skip event types we do not describe.
			if ( ! $entry instanceof Entry ) {
				continue;
			}

			$entries[] = $entry;

			// stop once we collected enough.
			if ( count( $entries ) >= $max_items ) {
				break;
			}
		}

		// store the marker for the next run, but only once the entries of this run have been saved.
		if ( '' !== $newest_id ) {
			$context->set_pending_state( self::STATE_LAST_EVENT, $newest_id );
		}

		// return the resulting entries.
		return $entries;
	}

	/**
	 * Turn a single GitHub event into an entry.
	 *
	 * @param array<string,mixed> $event The event as returned by the API.
	 *
	 * @return Entry|null Null for event types we do not describe.
	 */
	private function get_entry_from_event( array $event ): ?Entry {
		$repo    = ! empty( $event['repo'] ) && is_array( $event['repo'] ) && is_string( $event['repo']['name'] ?? null ) ? $event['repo']['name'] : '';
		$payload = ! empty( $event['payload'] ) && is_array( $event['payload'] ) ? $event['payload'] : array();
		$type    = ! empty( $event['type'] ) && is_string( $event['type'] ) ? $event['type'] : '';

		// bail if the repository is unknown, every text refers to it.
		if ( '' === $repo ) {
			return null;
		}

		// get the text.
		$text = $this->describe_event( $type, $repo, $payload );

		// bail if we do not describe this event type.
		if ( '' === $text ) {
			return null;
		}

		// get the date.
		try {
			$date = new DateTimeImmutable( (string) ( $event['created_at'] ?? 'now' ) );
		} catch ( Exception $e ) {
			// log this event.
			Log::get_instance()->add(
				__( 'A timestamp from GitHub could not be parsed. Error:', 'now-pages-threadi' ) . ' <code>' . esc_html( $e->getMessage() ) . '</code>',
				'error',
				'provider'
			);

			$date = new DateTimeImmutable();
		}

		$entry                   = new Entry();
		$entry->text             = $text;
		$entry->date             = $date;
		$entry->source_slug      = $this->get_slug();
		$entry->source_url       = $this->get_url_for_event( $type, $repo, $payload );
		$entry->external_id      = 'github-' . (string) $event['id'];
		$entry->category_term_id = 0;
		$entry->language         = null;

		// return the resulting entry object.
		return $entry;
	}

	/**
	 * Return a plain sentence describing a single event.
	 *
	 * Plain text only, per the unified entry format: no markup, no
	 * shortcodes. Unknown types return an empty string and are skipped.
	 *
	 * We only support selected event types from GitHub.
	 *
	 * @param string              $type    The event type.
	 * @param string              $repo    The repository, as "owner/name".
	 * @param array<string,mixed> $payload The event payload.
	 *
	 * @return string
	 */
	private function describe_event( string $type, string $repo, array $payload ): string {
		$text = '';

		switch ( $type ) {
			case 'PushEvent':
				$commits = ! empty( $payload['commits'] ) && is_array( $payload['commits'] ) ? count( $payload['commits'] ) : 0;

				$text = sprintf(
				/* translators: %1$d will be replaced by a number of commits, %2$s by a repository name. */
					_n( 'Pushed %1$d commit to %2$s', 'Pushed %1$d commits to %2$s', max( 1, $commits ), 'now-pages-threadi' ),
					max( 1, $commits ),
					$repo
				);
				break;

			case 'PullRequestEvent':
				$action = ! empty( $payload['action'] ) ? (string) $payload['action'] : '';
				$merged = ! empty( $payload['pull_request']['merged'] );
				$title  = ! empty( $payload['pull_request']['title'] ) ? (string) $payload['pull_request']['title'] : '';

				if ( 'opened' === $action ) {
					$text = sprintf(
					/* translators: %1$s will be replaced by a pull request title, %2$s by a repository name. */
						__( 'Opened the pull request "%1$s" in %2$s', 'now-pages-threadi' ),
						$title,
						$repo
					);
				} elseif ( 'closed' === $action && $merged ) {
					$text = sprintf(
					/* translators: %1$s will be replaced by a pull request title, %2$s by a repository name. */
						__( 'Merged the pull request "%1$s" in %2$s', 'now-pages-threadi' ),
						$title,
						$repo
					);
				}
				break;

			case 'IssuesEvent':
				$action = ! empty( $payload['action'] ) ? (string) $payload['action'] : '';
				$title  = ! empty( $payload['issue']['title'] ) ? (string) $payload['issue']['title'] : '';

				if ( 'opened' === $action ) {
					$text = sprintf(
					/* translators: %1$s will be replaced by an issue title, %2$s by a repository name. */
						__( 'Opened the issue "%1$s" in %2$s', 'now-pages-threadi' ),
						$title,
						$repo
					);
				} elseif ( 'closed' === $action ) {
					$text = sprintf(
					/* translators: %1$s will be replaced by an issue title, %2$s by a repository name. */
						__( 'Closed the issue "%1$s" in %2$s', 'now-pages-threadi' ),
						$title,
						$repo
					);
				}
				break;

			case 'IssueCommentEvent':
				$action = ! empty( $payload['action'] ) ? (string) $payload['action'] : '';
				if ( 'created' === $action ) {
					$text = sprintf(
					/* translators: %1$s will be replaced by an issue title, %2$s by a repository name. */
						__( 'Commented on "%1$s" in %2$s', 'now-pages-threadi' ),
						(string) ( $payload['issue']['title'] ?? '' ),
						$repo
					);
				}
				break;

			case 'ReleaseEvent':
				$action = ! empty( $payload['action'] ) ? (string) $payload['action'] : '';
				if ( 'published' === $action ) {
					$text = sprintf(
					/* translators: %1$s will be replaced by a release name, %2$s by a repository name. */
						__( 'Released %1$s of %2$s', 'now-pages-threadi' ),
						(string) ( $payload['release']['tag_name'] ?? '' ),
						$repo
					);
				}
				break;

			case 'CreateEvent':
				$ref_type = ! empty( $payload['ref_type'] ) ? (string) $payload['ref_type'] : '';
				if ( 'repository' === $ref_type ) {
					$text = sprintf(
					/* translators: %1$s will be replaced by a repository name. */
						__( 'Created the repository %1$s', 'now-pages-threadi' ),
						$repo
					);
				}
				break;

			case 'WatchEvent':
				$text = sprintf(
				/* translators: %1$s will be replaced by a repository name. */
					__( 'Starred %1$s', 'now-pages-threadi' ),
					$repo
				);
				break;

			case 'ForkEvent':
				$text = sprintf(
				/* translators: %1$s will be replaced by a repository name. */
					__( 'Forked %1$s', 'now-pages-threadi' ),
					$repo
				);
				break;
		}

		/**
		 * Filter the text describing a single GitHub event.
		 *
		 * Return an empty string to skip the event, or add your own text for
		 * an event type this provider does not describe.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param string              $text    The description, empty if unhandled.
		 * @param string              $type    The event type, e.g. "PushEvent".
		 * @param string              $repo    The repository, as "owner/name".
		 * @param array<string,mixed> $payload The event payload.
		 */
		return (string) apply_filters( 'now_pages_threadi_github_event_text', $text, $type, $repo, $payload );
	}

	/**
	 * Return the URL an event should link to.
	 *
	 * The events API carries no html_url of its own, so most of these are
	 * assembled from the repository name.
	 *
	 * @param string              $type    The event type.
	 * @param string              $repo    The repository, as "owner/name".
	 * @param array<string,mixed> $payload The event payload.
	 *
	 * @return string
	 */
	private function get_url_for_event( string $type, string $repo, array $payload ): string {
		$repo_url = 'https://github.com/' . $repo;

		$url = match ( $type ) {
			'PullRequestEvent'  => (string) ( $payload['pull_request']['html_url'] ?? $repo_url ),
			'IssuesEvent'       => (string) ( $payload['issue']['html_url'] ?? $repo_url ),
			'IssueCommentEvent' => (string) ( $payload['comment']['html_url'] ?? $repo_url ),
			'ReleaseEvent'      => (string) ( $payload['release']['html_url'] ?? $repo_url ),
			'PushEvent'         => $this->get_commit_url( $repo_url, $payload ),
			default             => $repo_url,
		};

		return esc_url_raw( $url );
	}

	/**
	 * Return the URL of the newest commit of a push, or the repository.
	 *
	 * @param string              $repo_url The URL of the repository.
	 * @param array<string,mixed> $payload  The event payload.
	 *
	 * @return string
	 */
	private function get_commit_url( string $repo_url, array $payload ): string {
		$head = ! empty( $payload['head'] ) ? (string) $payload['head'] : '';

		return '' !== $head ? $repo_url . '/commit/' . $head : $repo_url;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_identifying_fields(): array {
		return array( 'github_username' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_test_url( array $config ): string {
		$user = '';

		if ( ! empty( $config['github_username'] ) ) {
			$user = sanitize_text_field( (string) $config['github_username'] );
		}

		// bail if the source is not configured yet.
		if ( '' === $user ) {
			return '';
		}

		// return the URL.
		return 'https://api.github.com/users/' . rawurlencode( $user ) . '/events/public';
	}
}
