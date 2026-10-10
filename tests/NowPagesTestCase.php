<?php
/**
 * File to handle the main object for each test class.
 *
 * @package now-pages-threadi
 */

namespace NowPagesThreadi\Tests;

use NowPagesThreadi\Digests\Digest_Builder;
use WP_Error;
use WP_UnitTestCase;

/**
 * Object to handle the preparations for each test class.
 */
abstract class NowPagesTestCase extends WP_UnitTestCase {
	/**
	 * Pseudo URL delivering a valid calendar.
	 *
	 * @var string
	 */
	protected static string $ics_url = 'https://now-pages-threadi-test.example.com/calendar.ics';

	/**
	 * Pseudo URL delivering a calendar without any event.
	 *
	 * @var string
	 */
	protected static string $ics_empty_url = 'https://now-pages-threadi-empty-test.example.com/calendar.ics';

	/**
	 * Pseudo URL simulating a temporary outage (HTTP 503).
	 *
	 * @var string
	 */
	protected static string $ics_error_url = 'https://now-pages-threadi-error-test.example.com/calendar.ics';

	/**
	 * Pseudo URL delivering a valid calendar with an ETag, and 304 as soon as it is sent back.
	 *
	 * @var string
	 */
	protected static string $ics_etag_url = 'https://now-pages-threadi-etag-test.example.com/calendar.ics';

	/**
	 * GitHub account delivering a valid list of events.
	 *
	 * @var string
	 */
	protected static string $github_user = 'nowpages-test';

	/**
	 * GitHub account delivering no events at all.
	 *
	 * @var string
	 */
	protected static string $github_empty_user = 'nowpages-empty-test';

	/**
	 * GitHub account answering with HTTP 404.
	 *
	 * @var string
	 */
	protected static string $github_error_user = 'nowpages-error-test';

	/**
	 * GitHub account answering with an ETag, and with 304 on the second call.
	 *
	 * @var string
	 */
	protected static string $github_etag_user = 'nowpages-etag-test';

	/**
	 * The SteamID64 of the mocked account.
	 *
	 * @var string
	 */
	protected static string $steam_id = '76561197960287930';

	/**
	 * The games of the mocked account, keyed by application ID.
	 *
	 * A test changes this between two runs to simulate playing.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	protected static array $steam_games = array();

	/**
	 * Whether the mocked account is public: without games it then answers
	 * that nothing was played lately, instead of looking like a private profile.
	 *
	 * @var bool
	 */
	protected static bool $steam_public = false;

	/**
	 * Collect unmocked requests.
	 *
	 * @var array
	 */
	protected static array $unmocked_requests = array();

	/**
	 * Prepare the test environment for each test class.
	 *
	 * @return void
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		// prepare to load just one time.
		if ( ! did_action( 'now_pages_threadi_test_preparation_loaded' ) ) {
			// initialize the plugin.
			\NowPagesThreadi\Plugin\Activation::get_instance()->run();

			// prevent external requests from our providers.
			add_filter( 'pre_http_request', array( self::class, 'add_url_filter' ), 10, 3 );
			add_filter( 'now_pages_threadi_pre_address_problem', '__return_empty_string' );

			// mark as loaded.
			do_action( 'now_pages_threadi_test_preparation_loaded' );
		}
	}

	/**
	 * Run before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// prepare list of unmocked requests.
		self::$unmocked_requests = array();

		// the builder caches its results for the whole request, which in a
		// test run means across tests.
		Digest_Builder::reset_cache();
	}

	/**
	 * Define the URL filter for external requests to prevent any external
	 * request for our pseudo URLs.
	 *
	 * @param false|array|WP_Error $preempt     The return value of the filter.
	 * @param array                $parsed_args The used parameters for the request.
	 * @param string               $url         The requested URL.
	 *
	 * @return false|array|WP_Error
	 */
	public static function add_url_filter( false|array|WP_Error $preempt, array $parsed_args, string $url ): false|array|WP_Error {
		// deliver a valid calendar.
		if ( str_starts_with( $url, self::$ics_url ) ) {
			return self::mock_http_response( 200, self::get_fixture( 'calendar.ics' ) );
		}

		// deliver a calendar without any event.
		if ( str_starts_with( $url, self::$ics_empty_url ) ) {
			return self::mock_http_response( 200, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR" );
		}

		// deliver a valid calendar with an ETag, and 304 as soon as the client sends it back.
		if ( str_starts_with( $url, self::$ics_etag_url ) ) {
			if ( ! empty( $parsed_args['headers']['If-None-Match'] ) ) {
				return self::mock_http_response( 304 );
			}

			return self::mock_http_response( 200, self::get_fixture( 'calendar.ics' ), array( 'etag' => '"now-pages-threadi-test-ics-etag"' ) );
		}

		// simulate a temporary outage.
		if ( str_starts_with( $url, self::$ics_error_url ) ) {
			return self::mock_http_response( 503 );
		}

		// deliver a valid list of GitHub events.
		if ( str_contains( $url, '/users/' . self::$github_user . '/events/public' ) ) {
			return self::mock_http_response( 200, self::get_fixture( 'github_events.json' ) );
		}

		// deliver an empty list of GitHub events.
		if ( str_contains( $url, '/users/' . self::$github_empty_user . '/events/public' ) ) {
			return self::mock_http_response( 200, '[]' );
		}

		// answer as GitHub does for an unknown account.
		if ( str_contains( $url, '/users/' . self::$github_error_user . '/events/public' ) ) {
			return self::mock_http_response( 404, '{"message":"Not Found"}' );
		}

		// answer with an ETag, and with 304 as soon as the client sends it back.
		if ( str_contains( $url, '/users/' . self::$github_etag_user . '/events/public' ) ) {
			if ( ! empty( $parsed_args['headers']['If-None-Match'] ) ) {
				return self::mock_http_response( 304 );
			}

			return self::mock_http_response( 200, self::get_fixture( 'github_events.json' ), array( 'etag' => '"now-pages-threadi-test-etag"' ) );
		}

		// answer the Steam API from the mocked account.
		if ( str_contains( $url, 'IPlayerService/GetRecentlyPlayedGames' ) ) {
			// an empty list without a total count is what a private profile looks like.
			$response = array( 'response' => new \stdClass() );

			// a public profile without recently played games.
			if ( self::$steam_public ) {
				$response = array( 'response' => array( 'total_count' => 0 ) );
			}

			if ( ! empty( self::$steam_games ) ) {
				$response = array(
					'response' => array(
						'total_count' => count( self::$steam_games ),
						'games'       => array_values( self::$steam_games ),
					),
				);
			}

			return self::mock_http_response( 200, (string) wp_json_encode( $response ) );
		}

		if ( str_starts_with( $url, 'https://mastodon.social/@' ) ) {
			return self::mock_http_response( 200, '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Test</title><link>https://mastodon.social/</link><description>Test</description></channel></rss>', array( 'content-type' => 'application/rss+xml; charset=UTF-8' ) );
		}


		// another filter answered already (AdvisorTest, SetupAdvisorTest).
		if ( false !== $preempt ) {
			// return the given value for every other URL - we should never come here.
			return $preempt;
		}

		self::$unmocked_requests[] = $url;

		return new WP_Error( 'now_pages_threadi_unmocked_request', 'Unmocked HTTP request in a test: ' . $url );
	}

	/**
	 * Return the content of a fixture, with its date tokens replaced.
	 *
	 * @param string $filename The name of the file in tests/Data.
	 *
	 * @return string
	 */
	protected static function get_fixture( string $filename ): string {
		$path = UNIT_TESTS_DATA_PLUGIN_DIR . $filename;

		if ( ! is_readable( $path ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
		return self::replace_tokens( (string) file_get_contents( $path ) );
	}

	/**
	 * Replace the date tokens of a fixture with dates relative to now.
	 *
	 * A calendar fixture cannot use fixed dates: whether an appointment is
	 * running is decided against the current time, so the dates have to move
	 * with the test run.
	 *
	 * Supported are {{DATE+3}} and {{DATE-1}} for days in YYYYMMDD, and
	 * {{DATETIME+2}} / {{DATETIME-2}} for hours in the UTC form used by ICS.
	 *
	 * @param string $content The raw fixture.
	 *
	 * @return string
	 */
	protected static function replace_tokens( string $content ): string {
		$content = (string) preg_replace_callback(
			'/\{\{DATE([+-]\d+)}}/',
			static fn ( array $matches ): string => gmdate( 'Ymd', strtotime( $matches[1] . ' days' ) ),
			$content
		);

		return (string) preg_replace_callback(
			'/\{\{DATETIME([+-]\d+)}}/',
			static fn ( array $matches ): string => gmdate( 'Ymd\THis\Z', strtotime( $matches[1] . ' hours' ) ),
			$content
		);
	}

	/**
	 * Prepare a mock response for an HTTP request.
	 *
	 * @param int                  $status_code The status code of the response.
	 * @param string               $body        The body of the response.
	 * @param array<string,string> $headers     The headers of the response.
	 *
	 * @return array<string,mixed>
	 */
	protected static function mock_http_response( int $status_code, string $body = '', array $headers = array() ): array {
		return array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => $status_code,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Test for an HTTP post condition.
	 *
	 * @return void
	 */
	protected function assert_post_conditions(): void {
		parent::assert_post_conditions();

		$this->assertSame( array(), self::$unmocked_requests, 'A test made an HTTP request, which is not mocked.' );
	}
}
