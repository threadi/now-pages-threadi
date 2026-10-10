<?php
/**
 * File to test how the advisor asks the model.
 *
 * Here nothing is short-circuited: the request goes through the AI Client
 * of WordPress to a stub provider. This covers what AdvisorTest cannot -
 * which request is sent to a service, depending on what it is able to do.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use NowPagesThreadi\Sources\Advisor;
use NowPagesThreadi\Tests\NowPagesTestCase;
use NowPagesThreadi\Tests\Unit\Stubs\Stub_Ai_State;
use ReflectionProperty;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\NetworkException;
use WP_Error;

/**
 * Object to test the request of the Advisor.
 */
class AdvisorRequestTest extends NowPagesTestCase {
	/**
	 * Act as an administrator in every test, with the stub AI service connected.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'The AI Client is not available.' );
		}

		require_once __DIR__ . '/Stubs/Stub_Ai_Provider.php';

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		Stub_Ai_State::reset();
		Stub_Ai_State::connect();
		Stub_Ai_State::$answer = '{"message":"Nothing fits.","proposals":[]}';

		$this->forget_availability();
	}

	/**
	 * Disconnect the stub AI service, other tests must not see it.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		Stub_Ai_State::reset();
		$this->forget_availability();

		parent::tear_down();
	}

	/**
	 * Make the advisor ask again whether an AI service is connected.
	 *
	 * @return void
	 */
	private function forget_availability(): void {
		$property = new ReflectionProperty( Advisor::class, 'has_ai_client' );
		$property->setValue( Advisor::get_instance(), null );
	}

	/**
	 * A connected service which can write text makes the advisor available.
	 *
	 * @return void
	 */
	public function test_a_connected_service_makes_it_available(): void {
		$this->assertTrue( Advisor::get_instance()->is_available() );
		$this->assertSame( '', Advisor::get_instance()->get_unavailable_reason() );
	}

	/**
	 * Without a connected service it is not available, and says how to change that.
	 *
	 * @return void
	 */
	public function test_without_a_connected_service_it_is_not_available(): void {
		Stub_Ai_State::$connected = false;
		$this->forget_availability();

		$this->assertFalse( Advisor::get_instance()->is_available() );
		$this->assertStringContainsString( 'options-connectors.php', Advisor::get_instance()->get_unavailable_reason() );
	}

	/**
	 * A "no" from early in the request must not stick.
	 *
	 * WordPress hands the API keys of the connectors to the AI Client at
	 * init:20. Asked before that, no service is connected - asked again
	 * afterwards, in the same request, there is one.
	 *
	 * @return void
	 */
	public function test_an_early_no_is_not_remembered(): void {
		Stub_Ai_State::$connected = false;
		$this->forget_availability();

		$this->assertFalse( Advisor::get_instance()->is_available() );

		Stub_Ai_State::$connected = true;

		$this->assertTrue( Advisor::get_instance()->is_available() );
	}

	/**
	 * The answer of the model must arrive as advice.
	 *
	 * @return void
	 */
	public function test_the_answer_of_the_model_arrives(): void {
		$advice = Advisor::get_instance()->get_advice( 'My runs' );

		$this->assertIsArray( $advice, is_wp_error( $advice ) ? $advice->get_error_message() : '' );
		$this->assertSame( 'Nothing fits.', $advice['message'] );
		$this->assertCount( 1, Stub_Ai_State::$calls );
	}

	/**
	 * A service which can do both is asked with web search and schema.
	 *
	 * @return void
	 */
	public function test_a_capable_service_gets_search_and_schema(): void {
		Advisor::get_instance()->get_advice( 'My runs' );

		$this->assertCount( 1, Stub_Ai_State::$calls );
		$this->assertTrue( Stub_Ai_State::$calls[0]['search'] );
		$this->assertTrue( Stub_Ai_State::$calls[0]['schema'] );
		$this->assertSame( 'application/json', Stub_Ai_State::$calls[0]['mime'] );
	}

	/**
	 * A service without web search is asked without it, in one request.
	 *
	 * @return void
	 */
	public function test_a_service_without_search_is_asked_without_it(): void {
		Stub_Ai_State::$supports_search = false;

		$advice = Advisor::get_instance()->get_advice( 'My runs' );

		$this->assertIsArray( $advice );
		$this->assertCount( 1, Stub_Ai_State::$calls );
		$this->assertFalse( Stub_Ai_State::$calls[0]['search'] );
		$this->assertTrue( Stub_Ai_State::$calls[0]['schema'] );
	}

	/**
	 * A service which can only write text gets the form of the answer described.
	 *
	 * @return void
	 */
	public function test_a_plain_service_gets_the_form_described(): void {
		Stub_Ai_State::$supports_search = false;
		Stub_Ai_State::$supports_schema = false;

		$advice = Advisor::get_instance()->get_advice( 'My runs' );

		$this->assertIsArray( $advice );
		$this->assertCount( 1, Stub_Ai_State::$calls );
		$this->assertFalse( Stub_Ai_State::$calls[0]['schema'] );
		$this->assertStringContainsString( 'single JSON object', Stub_Ai_State::$calls[0]['instruction'] );
	}

	/**
	 * The web search can be switched off by a filter.
	 *
	 * @return void
	 */
	public function test_the_web_search_can_be_switched_off(): void {
		add_filter( 'now_pages_threadi_advisor_use_web_search', '__return_false' );

		Advisor::get_instance()->get_advice( 'My runs' );

		$this->assertCount( 1, Stub_Ai_State::$calls );
		$this->assertFalse( Stub_Ai_State::$calls[0]['search'] );
	}

	/**
	 * If the request with web search is refused, it is repeated without.
	 *
	 * Not every service which claims both accepts both in one request.
	 *
	 * @return void
	 */
	public function test_a_refused_search_is_repeated_without(): void {
		Stub_Ai_State::$fail_on_search = new ClientException( 'Tools cannot be combined with a schema.', 400 );

		$advice = Advisor::get_instance()->get_advice( 'My runs' );

		$this->assertIsArray( $advice, is_wp_error( $advice ) ? $advice->get_error_message() : '' );
		$this->assertCount( 2, Stub_Ai_State::$calls );
		$this->assertTrue( Stub_Ai_State::$calls[0]['search'] );
		$this->assertFalse( Stub_Ai_State::$calls[1]['search'] );
	}

	/**
	 * If the service refuses us for good, it is not asked again.
	 *
	 * @return void
	 */
	public function test_a_rejected_key_is_not_retried(): void {
		Stub_Ai_State::$fail_on_search = new ClientException( 'Invalid API key.', 401 );

		$advice = Advisor::get_instance()->get_advice( 'My runs' );

		$this->assertInstanceOf( WP_Error::class, $advice );
		$this->assertSame( 'Invalid API key.', $advice->get_error_message() );
		$this->assertCount( 1, Stub_Ai_State::$calls );
	}

	/**
	 * If the request with web search gets no answer in time, it is repeated without.
	 *
	 * Searching the web is what takes long. Without it the same service usually answers in time.
	 *
	 * @return void
	 */
	public function test_a_search_which_times_out_is_repeated_without(): void {
		Stub_Ai_State::$fail_on_search = new NetworkException( 'Connection timed out.' );

		$advice = Advisor::get_instance()->get_advice( 'My runs' );

		$this->assertIsArray( $advice, is_wp_error( $advice ) ? $advice->get_error_message() : '' );
		$this->assertCount( 2, Stub_Ai_State::$calls );
		$this->assertFalse( Stub_Ai_State::$calls[1]['search'] );
	}

	/**
	 * The wish is the prompt, the catalog is part of the instruction.
	 *
	 * @return void
	 */
	public function test_the_model_gets_the_wish_and_the_catalog(): void {
		Advisor::get_instance()->get_advice( 'My runs on a service' );

		$call = Stub_Ai_State::$calls[0];

		$this->assertSame( 'My runs on a service', $call['prompt'] );
		$this->assertStringContainsString( '"slug":"rss"', $call['instruction'] );
		$this->assertStringContainsString( '"slug":"mastodon"', $call['instruction'] );
		$this->assertStringContainsString( get_user_locale(), $call['instruction'] );
	}

	/**
	 * The model is told what the provider for routines is for, and what it is not for.
	 *
	 * @return void
	 */
	public function test_the_model_is_told_when_to_propose_a_routine(): void {
		Advisor::get_instance()->get_advice( 'I go running once a week' );

		$instruction = Stub_Ai_State::$calls[0]['instruction'];

		$this->assertStringContainsString( '"slug":"recurring"', $instruction );
		$this->assertStringContainsString( 'The provider "recurring" reads nothing', $instruction );
		$this->assertStringContainsString( 'never as a way out when rule 7 applies', $instruction );

		// the choices of its rhythm are written down in the code, so the model may know them.
		$this->assertStringContainsString( '"weekly":', $instruction );
		$this->assertStringContainsString( '"thu":', $instruction );
	}

	/**
	 * A website which switched the provider for routines off does not mention it.
	 *
	 * @return void
	 */
	public function test_the_rule_for_routines_follows_the_provider(): void {
		update_option( 'now_pages_threadi_provider_recurring_enabled', 0 );

		Advisor::get_instance()->get_advice( 'I go running twice a week' );

		delete_option( 'now_pages_threadi_provider_recurring_enabled' );

		$instruction = Stub_Ai_State::$calls[0]['instruction'];

		$this->assertStringNotContainsString( 'recurring', $instruction );
	}

	/**
	 * The model must not even learn about sources which only receive entries.
	 *
	 * @return void
	 */
	public function test_the_model_is_not_told_about_push_sources(): void {
		update_option( 'now_pages_threadi_provider_webhook_enabled', 1 );

		Advisor::get_instance()->get_advice( 'My posts on a service without a feed' );

		delete_option( 'now_pages_threadi_provider_webhook_enabled' );

		$instruction = Stub_Ai_State::$calls[0]['instruction'];

		$this->assertStringNotContainsString( '"slug":"webhook"', $instruction );
		$this->assertStringNotContainsString( '"slug":"discord"', $instruction );
		$this->assertStringContainsString( '"slug":"rss"', $instruction );
	}

	/**
	 * Nothing about this website beyond its source types must be sent.
	 *
	 * @return void
	 */
	public function test_nothing_else_about_the_website_is_sent(): void {
		$user = wp_get_current_user();

		// an existing source with a key and an address.
		$source_id = self::factory()->post->create(
			array(
				'post_type'   => 'np_threadi_source',
				'post_status' => 'publish',
				'post_title'  => 'My secret calendar',
				'meta_input'  => array(
					'provider' => 'ics',
					'ics_url'  => 'https://calendar.example.com/private-token-123456/basic.ics',
				),
			)
		);

		$this->assertGreaterThan( 0, $source_id );

		Advisor::get_instance()->get_advice( 'My runs' );

		$sent = Stub_Ai_State::$calls[0]['instruction'] . Stub_Ai_State::$calls[0]['prompt'];

		$this->assertStringNotContainsString( 'private-token-123456', $sent );
		$this->assertStringNotContainsString( 'My secret calendar', $sent );
		$this->assertStringNotContainsString( $user->user_login, $sent );
		$this->assertStringNotContainsString( $user->user_email, $sent );
		$this->assertStringNotContainsString( $user->display_name, $sent );
		$this->assertStringNotContainsString( home_url(), $sent );
	}
}
