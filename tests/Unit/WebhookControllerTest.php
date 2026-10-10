<?php
/**
 * File to test the webhook endpoint.
 *
 * This is the only publicly reachable entry point of the plugin, so its
 * authentication and validation deserve the closest coverage.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use NowPagesThreadi\NowPages\Entries;
use NowPagesThreadi\Providers\Webhook_Controller;
use NowPagesThreadi\Providers\Webhook_Provider;
use NowPagesThreadi\Tests\NowPagesTestCase;
use WP_Query;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Object to test Webhook_Controller.
 */
class WebhookControllerTest extends NowPagesTestCase {
	/**
	 * The route of the endpoint.
	 *
	 * @var string
	 */
	private const ROUTE = '/now-pages-threadi/v1/add';

	/**
	 * A token the test accepts.
	 *
	 * @var string
	 */
	private const TOKEN = 'test-token';

	/**
	 * The own token of a source: long enough for the minimal length of configured tokens.
	 */
	private const SOURCE_TOKEN = 'source-token-0123456789-0123456789-abcdef';

	/**
	 * Boot the REST server before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;

		update_option( 'now_pages_threadi_provider_webhook_enabled', 1 );

		$wp_rest_server = new WP_REST_Server();

		do_action( 'rest_api_init', $wp_rest_server );

		delete_option( Entries::OPTION_DELETED );
	}

	/**
	 * Remove our own filters after each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'now_pages_threadi_validate_webhook_token' );

		global $wp_rest_server;

		update_option( 'now_pages_threadi_provider_webhook_enabled', 0 );

		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Accept our test token from now on.
	 *
	 * @return void
	 */
	private function allow_token(): void {
		add_filter(
			'now_pages_threadi_validate_webhook_token',
			static fn ( bool $is_valid, string $token ): bool => self::TOKEN === $token,
			10,
			2
		);
	}

	/**
	 * Send a request to the endpoint.
	 *
	 * @param array<string,mixed> $params The body parameters.
	 * @param string|null         $token  The token to send, null for none.
	 *
	 * @return \WP_REST_Response
	 */
	private function request( array $params = array(), ?string $token = self::TOKEN ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE );

		if ( null !== $token ) {
			$request->set_header( 'X-Now-Pages-Token', $token );
		}

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * Return all stored entries.
	 *
	 * @return array<int,int>
	 */
	private function get_stored_entries(): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'np_threadi_entry',
				'post_status'    => array( 'any', 'trash' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return array_map( 'absint', $query->posts );
	}

	/**
	 * The route must be registered at all.
	 *
	 * @return void
	 */
	public function test_route_is_registered(): void {
		$this->assertArrayHasKey( self::ROUTE, rest_get_server()->get_routes() );
	}


	/**
	 * A disabled webhook must reject requests before token validation.
	 *
	 * @return void
	 */
	public function test_disabled_webhook_is_rejected(): void {
		update_option( 'now_pages_threadi_provider_webhook_enabled', 0 );
		$this->allow_token();

		$response = $this->request( array( 'text' => 'Darf nicht gespeichert werden.' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'now_pages_threadi_webhook_disabled', $response->get_data()['code'] );
		$this->assertCount( 0, $this->get_stored_entries() );
	}

	/**
	 * A request without a token must be rejected with 401.
	 *
	 * @return void
	 */
	public function test_request_without_token_is_rejected(): void {
		$response = $this->request( array( 'text' => 'Ein Eintrag.' ), null );

		$this->assertSame( 401, $response->get_status() );
		$this->assertCount( 0, $this->get_stored_entries() );
	}

	/**
	 * An empty token must be rejected with 401, not treated as valid.
	 *
	 * @return void
	 */
	public function test_empty_token_is_rejected(): void {
		$this->allow_token();

		$response = $this->request( array( 'text' => 'Ein Eintrag.' ), '' );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * A wrong token must be rejected with 403.
	 *
	 * @return void
	 */
	public function test_wrong_token_is_rejected(): void {
		$this->allow_token();

		$response = $this->request( array( 'text' => 'Ein Eintrag.' ), 'wrong-token' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertCount( 0, $this->get_stored_entries() );
	}

	/**
	 * Without a validation filter nothing must be accepted.
	 *
	 * The endpoint is public, so the default has to be "deny".
	 *
	 * @return void
	 */
	public function test_nothing_is_accepted_without_a_validation_filter(): void {
		$response = $this->request( array( 'text' => 'Ein Eintrag.' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * A missing text must be rejected with 400.
	 *
	 * @return void
	 */
	public function test_missing_text_is_rejected(): void {
		$this->allow_token();

		$response = $this->request();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'now_pages_threadi_empty_text', $response->get_data()['code'] );
	}

	/**
	 * A text that is empty after stripping markup must be rejected.
	 *
	 * @return void
	 */
	public function test_text_that_is_only_markup_is_rejected(): void {
		$this->allow_token();

		$response = $this->request( array( 'text' => '<p>   </p>' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertCount( 0, $this->get_stored_entries() );
	}

	/**
	 * An unparsable date must be rejected with 400.
	 *
	 * @return void
	 */
	public function test_invalid_date_is_rejected(): void {
		$this->allow_token();

		$response = $this->request(
			array(
				'text' => 'Ein Eintrag.',
				'date' => 'not-a-date',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertCount( 0, $this->get_stored_entries() );
	}

	/**
	 * A valid request must create an entry and answer with 201.
	 *
	 * @return void
	 */
	public function test_valid_request_creates_an_entry(): void {
		$this->allow_token();

		$response = $this->request( array( 'text' => 'Bin gerade unterwegs.' ) );

		$this->assertSame( 201, $response->get_status() );

		$entries = $this->get_stored_entries();

		$this->assertCount( 1, $entries );
		$this->assertSame( $entries[0], $response->get_data()['id'] );
		$this->assertSame( 'Bin gerade unterwegs.', get_post( $entries[0] )->post_content );
	}

	/**
	 * Markup in the text must be stripped before storing.
	 *
	 * @return void
	 */
	public function test_markup_is_stripped_from_the_text(): void {
		$this->allow_token();

		$this->request( array( 'text' => 'Ein <script>alert(1)</script>Eintrag.' ) );

		$entries = $this->get_stored_entries();

		$this->assertStringNotContainsString( '<script>', get_post( $entries[0] )->post_content );
	}

	/**
	 * A submitted external ID must be stored.
	 *
	 * @return void
	 */
	public function test_external_id_is_stored(): void {
		$this->allow_token();

		$this->request(
			array(
				'text'        => 'Ein Eintrag.',
				'external_id' => 'toot-42',
			)
		);

		$entries = $this->get_stored_entries();

		$this->assertSame( 'toot-42', get_post_meta( $entries[0], 'external_id', true ) );
	}

	/**
	 * Without an external ID one must be derived, so dedupe still works.
	 *
	 * @return void
	 */
	public function test_external_id_is_derived_when_missing(): void {
		$this->allow_token();

		$this->request(
			array(
				'text' => 'Ein Eintrag.',
				'date' => '2026-01-15T10:00:00+00:00',
			)
		);

		$entries = $this->get_stored_entries();

		$this->assertNotSame( '', get_post_meta( $entries[0], 'external_id', true ) );
	}

	/**
	 * The same external ID must not create a second entry.
	 *
	 * @return void
	 */
	public function test_same_external_id_is_not_stored_twice(): void {
		$this->allow_token();

		$params = array(
			'text'        => 'Ein Eintrag.',
			'external_id' => 'toot-42',
		);

		$this->request( $params );

		$response = $this->request( $params );

		// the endpoint confirms without creating anything, so the sender does not retry.
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['added'] );
		$this->assertSame( 'duplicate', $response->get_data()['reason'] );
		$this->assertCount( 1, $this->get_stored_entries() );
	}

	/**
	 * An identical request without an external ID must not duplicate either,
	 * because the derived ID is stable for the same text and date.
	 *
	 * @return void
	 */
	public function test_identical_request_without_external_id_is_not_duplicated(): void {
		$this->allow_token();

		$params = array(
			'text' => 'Ein Eintrag.',
			'date' => '2026-01-15T10:00:00+00:00',
		);

		$this->request( $params );
		$this->request( $params );

		$this->assertCount( 1, $this->get_stored_entries() );
	}

	/**
	 * A submitted URL must be stored, it is needed for linking the source.
	 *
	 * @return void
	 */
	public function test_url_is_stored(): void {
		$this->allow_token();

		$this->request(
			array(
				'text' => 'Ein Eintrag.',
				'url'  => 'https://example.com/note/1',
			)
		);

		$entries = $this->get_stored_entries();

		$this->assertSame( 'https://example.com/note/1', get_post_meta( $entries[0], 'source_url', true ) );
	}

	/**
	 * The submitted date must be used, not the current time.
	 *
	 * @return void
	 */
	public function test_submitted_date_is_used(): void {
		$this->allow_token();

		$this->request(
			array(
				'text' => 'Ein Eintrag.',
				'date' => '2026-01-15T10:00:00+00:00',
			)
		);

		$entries = $this->get_stored_entries();

		$this->assertStringStartsWith( '2026-01-15', get_post( $entries[0] )->post_date_gmt );
	}

	/**
	 * A submitted locale must survive unchanged.
	 *
	 * The digest compares this meta with an exact string match, so a
	 * lowercased "de_de" would drop the entry out of every digest.
	 *
	 * @return void
	 */
	public function test_submitted_locale_is_not_lowercased(): void {
		$this->allow_token();

		$this->request(
			array(
				'text'     => 'Ein Eintrag.',
				'language' => 'de_DE',
			)
		);

		$entries = $this->get_stored_entries();

		$this->assertSame( 'de_DE', get_post_meta( $entries[0], 'language', true ) );
	}

	/**
	 * A BCP-47 tag must be normalized into the WordPress locale format.
	 *
	 * @return void
	 */
	public function test_bcp47_language_tag_is_normalized(): void {
		$this->allow_token();

		$this->request(
			array(
				'text'     => 'Ein Eintrag.',
				'language' => 'pt-BR',
			)
		);

		$entries = $this->get_stored_entries();

		$this->assertSame( 'pt_BR', get_post_meta( $entries[0], 'language', true ) );
	}

	/**
	 * An entry pushed via webhook must not count as manually created.
	 *
	 * The webhook passes source_id 0, so only the external ID tells the
	 * two apart.
	 *
	 * @return void
	 */
	public function test_webhook_entry_is_not_manual(): void {
		$this->allow_token();

		$this->request( array( 'text' => 'Per Webhook gekommen.' ) );

		$entries = $this->get_stored_entries();

		$this->assertFalse( Entries::get_instance()->is_manual( get_post( $entries[0] ) ) );
	}

	/**
	 * Only POST must be accepted.
	 *
	 * @return void
	 */
	public function test_get_is_not_allowed(): void {
		$this->allow_token();

		$request = new WP_REST_Request( 'GET', self::ROUTE );

		$request->set_header( 'X-Now-Pages-Token', self::TOKEN );

		$this->assertSame( 404, rest_do_request( $request )->get_status() );
	}

	/**
	 * Authentication must be checked before the parameters.
	 *
	 * Otherwise an unauthenticated client can probe which fields the
	 * endpoint expects — and bypasses the rate limit while doing so.
	 *
	 * @return void
	 */
	public function test_authentication_is_checked_before_the_parameters(): void {
		$response = $this->request( array(), null );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * A date in the future must be rejected with 400.
	 *
	 * @return void
	 */
	public function test_future_date_is_rejected(): void {
		$this->allow_token();

		$response = $this->request(
			array(
				'text' => 'Ein Eintrag aus der Zukunft.',
				'date' => gmdate( 'c', time() + DAY_IN_SECONDS ),
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertCount( 0, $this->get_stored_entries() );
	}

	/**
	 * A source with its own token must accept only this token.
	 *
	 * @return void
	 */
	public function test_source_token_replaces_the_global_token(): void {
		$this->allow_token();

		$source_id = self::factory()->post->create(
			array(
				'post_type'   => 'np_threadi_source',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $source_id, 'provider', 'webhook' );
		update_post_meta( $source_id, 'webhook_slug', 'own' );
		update_post_meta( $source_id, 'webhook_token', \NowPagesThreadi\Plugin\Crypt::get_instance()->encrypt( self::SOURCE_TOKEN ) );

		// the global token is rejected for this source.
		$response = $this->request(
			array(
				'text'   => 'Ein Eintrag.',
				'source' => 'own',
			)
		);
		$this->assertSame( 403, $response->get_status() );

		// the own token of the source is accepted.
		$response = $this->request(
			array(
				'text'   => 'Ein Eintrag.',
				'source' => 'own',
			),
			self::SOURCE_TOKEN
		);
		$this->assertSame( 201, $response->get_status() );
	}
}
