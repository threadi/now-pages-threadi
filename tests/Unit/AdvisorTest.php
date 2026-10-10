<?php
/**
 * File to test the advisor, which proposes sources for a wish.
 *
 * The AI model is replaced by a fixed answer here. What matters is what
 * happens to that answer: a model can return anything, and nothing of it
 * may reach a source unchecked.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use NowPagesThreadi\Sources\Advisor;
use NowPagesThreadi\Sources\Source_Builder;
use NowPagesThreadi\Tests\NowPagesTestCase;
use WP_Error;

/**
 * Object to test Advisor.
 */
class AdvisorTest extends NowPagesTestCase {
	/**
	 * The address of a feed which exists.
	 *
	 * @var string
	 */
	private const FEED_URL = 'https://films.now-pages-threadi-test.example.com/jane/rss/';

	/**
	 * The answer the model gives in this test.
	 *
	 * @var string|WP_Error
	 */
	private string|WP_Error $answer = '';

	/**
	 * How often the model has been asked.
	 *
	 * @var int
	 */
	private int $requests = 0;

	/**
	 * Act as an administrator with a connected AI service in every test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->answer   = '';
		$this->requests = 0;

		add_filter( 'now_pages_threadi_advisor_is_available', '__return_true' );
		add_filter( 'now_pages_threadi_advisor_pre_request', array( $this, 'get_answer' ) );
		add_filter( 'pre_http_request', array( $this, 'mock_feed' ), 5, 3 );
	}

	/**
	 * Return the answer of the model.
	 *
	 * @return string|WP_Error
	 */
	public function get_answer(): string|WP_Error {
		++$this->requests;

		return $this->answer;
	}

	/**
	 * Deliver a feed for the one address which exists, and nothing for every other one below its host.
	 *
	 * @param mixed  $preempt     The response so far.
	 * @param mixed  $parsed_args The arguments of the request.
	 * @param string $url         The requested address.
	 *
	 * @return mixed
	 */
	public function mock_feed( mixed $preempt, mixed $parsed_args, string $url ): mixed {
		if ( self::FEED_URL === $url ) {
			$feed = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Films</title><link>https://films.now-pages-threadi-test.example.com/jane/</link><description>Films</description>'
				. '<item><title>Watched a film</title><link>https://films.now-pages-threadi-test.example.com/jane/1</link><guid>film-1</guid><pubDate>' . gmdate( 'r', time() - DAY_IN_SECONDS ) . '</pubDate><description>Watched The Third Man</description></item>'
				. '<item><title>Watched another film</title><link>https://films.now-pages-threadi-test.example.com/jane/2</link><guid>film-2</guid><pubDate>' . gmdate( 'r', time() - ( 2 * DAY_IN_SECONDS ) ) . '</pubDate><description>Watched Paris, Texas</description></item>'
				. '</channel></rss>';

			return self::mock_http_response( 200, $feed, array( 'content-type' => 'application/rss+xml; charset=UTF-8' ) );
		}

		if ( str_contains( $url, 'films.now-pages-threadi-test.example.com' ) ) {
			return self::mock_http_response( 404, 'Not found' );
		}

		return $preempt;
	}

	/**
	 * Set the answer of the model to the given proposals.
	 *
	 * @param array<int,array<string,mixed>> $proposals The proposals.
	 * @param string                         $message   The message.
	 *
	 * @return void
	 */
	private function answer_with( array $proposals, string $message = 'Here is what I found.' ): void {
		$this->answer = (string) wp_json_encode(
			array(
				'message'   => $message,
				'proposals' => $proposals,
			)
		);
	}

	/**
	 * Return a proposal for a feed.
	 *
	 * @param string $url The address of the feed.
	 *
	 * @return array<string,mixed>
	 */
	private function get_feed_proposal( string $url ): array {
		return array(
			'title'     => 'Films',
			'kind'      => 'provider',
			'slug'      => 'rss',
			'reason'    => 'The films you logged.',
			'values'    => array(
				array(
					'name'  => 'feed_url',
					'value' => $url,
				),
			),
			'questions' => array(),
		);
	}

	/**
	 * Without a connected AI service there is no advice.
	 *
	 * @return void
	 */
	public function test_without_ai_service_there_is_no_advice(): void {
		remove_filter( 'now_pages_threadi_advisor_is_available', '__return_true' );

		$this->assertFalse( Advisor::get_instance()->is_available() );

		$advice = Advisor::get_instance()->get_advice( 'The films I watch' );

		$this->assertInstanceOf( WP_Error::class, $advice );
		$this->assertSame( 0, $this->requests );
	}

	/**
	 * The advisor can be switched off in the settings.
	 *
	 * @return void
	 */
	public function test_the_advisor_can_be_switched_off(): void {
		remove_filter( 'now_pages_threadi_advisor_is_available', '__return_true' );
		update_option( Advisor::OPTION_ENABLED, 0 );

		$this->assertFalse( Advisor::get_instance()->is_enabled() );
		$this->assertFalse( Advisor::get_instance()->is_available() );
		$this->assertSame( '', Advisor::get_instance()->get_unavailable_reason() );
	}

	/**
	 * Without a wish the model is not asked.
	 *
	 * @return void
	 */
	public function test_empty_wish_is_not_sent(): void {
		$advice = Advisor::get_instance()->get_advice( '   ' );

		$this->assertInstanceOf( WP_Error::class, $advice );
		$this->assertSame( 0, $this->requests );
	}

	/**
	 * A proposal which could be read must be offered with what was found.
	 *
	 * @return void
	 */
	public function test_readable_proposal_is_offered_with_samples(): void {
		$this->answer_with( array( $this->get_feed_proposal( self::FEED_URL ) ) );

		$advice = Advisor::get_instance()->get_advice( 'The films I watch' );

		$this->assertIsArray( $advice );
		$this->assertCount( 1, $advice['proposals'] );
		$this->assertSame( array(), $advice['rejected'] );

		$proposal = reset( $advice['proposals'] );

		$this->assertSame( 'Films', $proposal['title'] );
		$this->assertSame( 'rss', $proposal['provider'] );
		$this->assertSame( Source_Builder::CHECK_OK, $proposal['check']['status'] );
		$this->assertSame( 2, $proposal['check']['entries'] );
		$this->assertStringContainsString( 'The Third Man', $proposal['check']['samples'][0]['text'] );
	}

	/**
	 * An address the model made up must not be offered.
	 *
	 * This is the reason every proposal is tried: a model without access to
	 * the web returns addresses which merely look right.
	 *
	 * @return void
	 */
	public function test_invented_address_is_not_offered(): void {
		$this->answer_with( array( $this->get_feed_proposal( 'https://films.now-pages-threadi-test.example.com/invented/feed.xml' ) ) );

		$advice = Advisor::get_instance()->get_advice( 'The films I watch' );

		$this->assertIsArray( $advice );
		$this->assertSame( array(), $advice['proposals'] );
		$this->assertCount( 1, $advice['rejected'] );
		$this->assertSame( 'Films', $advice['rejected'][0]['title'] );
		$this->assertNotSame( '', $advice['rejected'][0]['message'] );
	}

	/**
	 * A preset or provider the model made up must not be offered.
	 *
	 * @return void
	 */
	public function test_invented_provider_is_not_offered(): void {
		$proposal         = $this->get_feed_proposal( self::FEED_URL );
		$proposal['slug'] = 'letterboxd-api';

		$this->answer_with( array( $proposal ) );

		$advice = Advisor::get_instance()->get_advice( 'The films I watch' );

		$this->assertIsArray( $advice );
		$this->assertSame( array(), $advice['proposals'] );
		$this->assertCount( 1, $advice['rejected'] );
	}

	/**
	 * A source which only receives entries must not be offered.
	 *
	 * Asked for a service without any feed, a model proposed the webhook
	 * as a way out. The user got a source, but nothing that would ever
	 * send something to it.
	 *
	 * @return void
	 */
	public function test_a_source_which_only_receives_is_not_offered(): void {
		update_option( 'now_pages_threadi_provider_webhook_enabled', 1 );

		$this->answer_with(
			array(
				array(
					'title'     => 'gutefrage.net',
					'kind'      => 'provider',
					'slug'      => 'webhook',
					'reason'    => 'Your posts, pushed by an automation.',
					'values'    => array(
						array(
							'name'  => 'webhook_slug',
							'value' => 'gutefrage',
						),
					),
					'questions' => array(),
				),
				array(
					'title'     => 'Discord',
					'kind'      => 'preset',
					'slug'      => 'discord',
					'reason'    => 'Pushed by a bot.',
					'values'    => array(),
					'questions' => array(),
				),
			),
			'gutefrage.net offers no public feed.'
		);

		$advice = Advisor::get_instance()->get_advice( 'My posts on gutefrage.net' );

		delete_option( 'now_pages_threadi_provider_webhook_enabled' );

		$this->assertIsArray( $advice );
		$this->assertSame( array(), $advice['proposals'] );
		$this->assertCount( 2, $advice['rejected'] );
		$this->assertSame( 'gutefrage.net offers no public feed.', $advice['message'] );
	}

	/**
	 * Fields the provider does not know must be dropped, whatever the model sends.
	 *
	 * @return void
	 */
	public function test_unknown_fields_of_the_model_are_dropped(): void {
		$proposal             = $this->get_feed_proposal( self::FEED_URL );
		$proposal['values'][] = array(
			'name'  => 'post_status',
			'value' => 'private',
		);
		$proposal['values'][] = array(
			'name'  => 'provider',
			'value' => 'webhook',
		);

		$this->answer_with( array( $proposal ) );

		$advice = Advisor::get_instance()->get_advice( 'The films I watch' );

		$this->assertIsArray( $advice );

		$offered = reset( $advice['proposals'] );

		$this->assertSame( array( 'feed_url' ), array_keys( $offered['values'] ) );
	}

	/**
	 * A key must never come from the model.
	 *
	 * @return void
	 */
	public function test_a_key_of_the_model_is_dropped(): void {
		$this->answer_with(
			array(
				array(
					'title'     => 'Last.fm',
					'kind'      => 'preset',
					'slug'      => 'lastfm',
					'reason'    => 'The music you listen to.',
					'values'    => array(
						array(
							'name'  => 'lastfm_username',
							'value' => 'rj',
						),
						array(
							'name'  => 'lastfm_api_key',
							'value' => 'invented-key',
						),
					),
					'questions' => array(),
				),
			)
		);

		$advice = Advisor::get_instance()->get_advice( 'The music I listen to' );

		$this->assertIsArray( $advice );

		$offered = reset( $advice['proposals'] );

		$this->assertArrayNotHasKey( 'lastfm_api_key', $offered['values'] );
		$this->assertNotEmpty( $offered['secrets'] );
		$this->assertSame( Source_Builder::CHECK_INCOMPLETE, $offered['check']['status'] );
	}

	/**
	 * The texts of the model must arrive as plain text.
	 *
	 * @return void
	 */
	public function test_texts_of_the_model_are_plain_text(): void {
		$proposal           = $this->get_feed_proposal( self::FEED_URL );
		$proposal['title']  = 'Films<script>alert(1)</script>';
		$proposal['reason'] = '<img src=x onerror=alert(1)>Your films.';

		$this->answer_with( array( $proposal ), '<b>Found</b> <a href="https://example.com">one</a>.' );

		$advice = Advisor::get_instance()->get_advice( 'The films I watch' );

		$this->assertIsArray( $advice );
		$this->assertStringNotContainsString( '<', (string) wp_json_encode( $advice, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * A source the model names in its message must become a link.
	 *
	 * A model which searched the web cites what it found as [label](address).
	 *
	 * @return void
	 */
	public function test_a_source_in_the_message_becomes_a_link(): void {
		$this->answer_with( array(), 'The former feed has been removed. ([gutefrage.net](https://www.gutefrage.net/frage/hat-gutefrage-rss-feeds?utm_source=openai))' );

		$advice = Advisor::get_instance()->get_advice( 'My posts on gutefrage.net' );

		$this->assertIsArray( $advice );

		$html = Advisor::get_instance()->format_message( $advice['message'] );

		$this->assertStringContainsString( '<a href="https://www.gutefrage.net/frage/hat-gutefrage-rss-feeds"', $html );
		$this->assertStringContainsString( '>gutefrage.net<', $html );
		$this->assertStringContainsString( 'target="_blank"', $html );
		$this->assertStringContainsString( 'rel="noopener noreferrer nofollow"', $html );
		$this->assertStringNotContainsString( 'utm_source', $html );
		$this->assertStringNotContainsString( '](', $html );
		$this->assertStringStartsWith( 'The former feed has been removed. (', $html );
	}

	/**
	 * An address with encoded characters must survive as it is.
	 *
	 * @return void
	 */
	public function test_an_encoded_address_is_kept(): void {
		$this->answer_with( array(), 'See [the help](https://example.org/hilfe/rss%20feed?a=1&b=2).' );

		$advice = Advisor::get_instance()->get_advice( 'My posts' );

		$this->assertIsArray( $advice );
		$this->assertStringContainsString( 'href="https://example.org/hilfe/rss%20feed?a=1&#038;b=2"', Advisor::get_instance()->format_message( $advice['message'] ) );
	}

	/**
	 * Nothing but a link to a website may come out of the message.
	 *
	 * @return void
	 */
	public function test_the_message_cannot_inject_markup(): void {
		$html = Advisor::get_instance()->format_message( '<script>alert(1)</script> [click](javascript:alert(1)) [x" onmouseover="alert(1)](https://example.org/"onclick="alert(1)) <b>bold</b>' );

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringNotContainsString( 'href="javascript', $html );
		$this->assertStringNotContainsString( '" onmouseover="', $html );
		$this->assertStringNotContainsString( '"onclick="', $html );
		$this->assertSame( 0, preg_match( '/<a (?!href="https:\/\/example\.org\/" target="_blank" rel="noopener noreferrer nofollow">)/', $html ) );
	}

	/**
	 * A text which is shown as plain text must not carry an address in brackets.
	 *
	 * @return void
	 */
	public function test_links_are_removed_from_short_texts(): void {
		$proposal           = $this->get_feed_proposal( self::FEED_URL );
		$proposal['reason'] = 'The films you logged ([Letterboxd](https://letterboxd.com/?utm_source=openai)).';

		$this->answer_with( array( $proposal ) );

		$advice = Advisor::get_instance()->get_advice( 'The films I watch' );

		$this->assertIsArray( $advice );

		$offered = reset( $advice['proposals'] );

		$this->assertSame( 'The films you logged (Letterboxd).', $offered['reason'] );
	}

	/**
	 * A value which depends on the user must become a question instead of a guess.
	 *
	 * @return void
	 */
	public function test_placeholder_becomes_a_question(): void {
		$proposal              = $this->get_feed_proposal( 'https://films.now-pages-threadi-test.example.com/{username}/rss/' );
		$proposal['questions'] = array(
			array(
				'name'  => 'username',
				'label' => 'Your name there',
				'help'  => 'As in the address of your profile.',
			),
		);

		$this->answer_with( array( $proposal ) );

		$advice = Advisor::get_instance()->get_advice( 'The films I watch' );

		$this->assertIsArray( $advice );

		$offered = reset( $advice['proposals'] );

		$this->assertSame( array( 'username' ), array_keys( $offered['questions'] ) );
		$this->assertSame( 'Your name there', $offered['questions']['username']['label'] );

		// nothing was requested yet, there was nothing complete to request.
		$this->assertNull( $offered['check'] );
	}

	/**
	 * The answer to a question must complete the proposal, and the result must be tried.
	 *
	 * @return void
	 */
	public function test_answer_completes_the_proposal(): void {
		$this->answer_with( array( $this->get_feed_proposal( 'https://films.now-pages-threadi-test.example.com/{username}/rss/' ) ) );

		$advice = Advisor::get_instance()->get_advice( 'The films I watch' );

		$this->assertIsArray( $advice );

		$created = Advisor::get_instance()->create_source( reset( $advice['proposals'] ), array( 'username' => '@jane' ) );

		$this->assertIsArray( $created );
		$this->assertSame( 'publish', $created['status'] );
		$this->assertSame( self::FEED_URL, get_post_meta( $created['source_id'], 'feed_url', true ) );
		$this->assertSame( 'Films', get_the_title( $created['source_id'] ) );
	}

	/**
	 * A wrong answer must leave a draft to correct, not nothing and not a broken source.
	 *
	 * @return void
	 */
	public function test_wrong_answer_leaves_a_draft(): void {
		$this->answer_with( array( $this->get_feed_proposal( 'https://films.now-pages-threadi-test.example.com/{username}/rss/' ) ) );

		$advice = Advisor::get_instance()->get_advice( 'The films I watch' );

		$this->assertIsArray( $advice );

		$created = Advisor::get_instance()->create_source( reset( $advice['proposals'] ), array( 'username' => 'nobody' ) );

		$this->assertIsArray( $created );
		$this->assertSame( 'draft', $created['status'] );
		$this->assertSame( 'draft', get_post_status( $created['source_id'] ) );
		$this->assertSame( Source_Builder::CHECK_FAILED, $created['check']['status'] );
	}

	/**
	 * An answer must not be able to change where the address points to.
	 *
	 * @return void
	 */
	public function test_answer_cannot_change_the_host(): void {
		$this->answer_with( array( $this->get_feed_proposal( 'https://films.now-pages-threadi-test.example.com/{username}/rss/' ) ) );

		$advice = Advisor::get_instance()->get_advice( 'The films I watch' );

		$this->assertIsArray( $advice );

		$resolved = Advisor::get_instance()->resolve_proposal( reset( $advice['proposals'] ), array( 'username' => '../../evil.example.org/x?y=' ) );

		$this->assertIsArray( $resolved );
		$this->assertSame( 'films.now-pages-threadi-test.example.com', wp_parse_url( $resolved['config']['feed_url'], PHP_URL_HOST ) );
		$this->assertStringNotContainsString( '..', $resolved['config']['feed_url'] );
	}

	/**
	 * A question without an answer must stop the creation.
	 *
	 * @return void
	 */
	public function test_missing_answer_creates_nothing(): void {
		$this->answer_with( array( $this->get_feed_proposal( 'https://films.now-pages-threadi-test.example.com/{username}/rss/' ) ) );

		$advice = Advisor::get_instance()->get_advice( 'The films I watch' );

		$this->assertIsArray( $advice );

		$created = Advisor::get_instance()->create_source( reset( $advice['proposals'] ), array() );

		$this->assertInstanceOf( WP_Error::class, $created );
	}

	/**
	 * Return a proposal for something the user does regularly.
	 *
	 * @param array<string,string> $values Further values, keyed by field name.
	 *
	 * @return array<string,mixed>
	 */
	private function get_routine_proposal( array $values = array() ): array {
		$pairs = array(
			array(
				'name'  => 'recurring_text',
				'value' => 'Went running',
			),
		);

		foreach ( $values as $name => $value ) {
			$pairs[] = array(
				'name'  => $name,
				'value' => $value,
			);
		}

		return array(
			'title'     => 'Running',
			'kind'      => 'provider',
			'slug'      => 'recurring',
			'reason'    => 'A sentence about your weekly run.',
			'values'    => $pairs,
			'questions' => array(),
		);
	}

	/**
	 * A routine is offered with its sentence as a suggestion: these are the words of the user.
	 *
	 * @return void
	 */
	public function test_a_routine_is_offered_with_its_sentence_as_a_suggestion(): void {
		$this->answer_with( array( $this->get_routine_proposal( array( 'recurring_rhythm' => 'weekly' ) ) ) );

		$advice = Advisor::get_instance()->get_advice( 'I go running once a week' );

		$this->assertIsArray( $advice );
		$this->assertCount( 1, $advice['proposals'] );

		$offered = reset( $advice['proposals'] );

		$this->assertSame( 'recurring', $offered['provider'] );
		$this->assertSame( array( 'recurring_text' ), array_keys( $offered['questions'] ) );
		$this->assertSame( Advisor::QUESTION_SUGGESTION, $offered['questions']['recurring_text']['kind'] );
		$this->assertSame( 'Went running', $offered['questions']['recurring_text']['value'] );
		$this->assertSame( 'Went running', $offered['questions']['recurring_text']['placeholder'] );
		$this->assertFalse( Advisor::get_instance()->has_open_questions( $offered ) );

		// it works as it is, so it has been tried: nothing is read, the entry which would be created is shown.
		$this->assertIsArray( $offered['check'] );
		$this->assertSame( Source_Builder::CHECK_OK, $offered['check']['status'] );
		$this->assertSame( 'Went running', $offered['check']['samples'][0]['text'] );
	}

	/**
	 * Without an answer the suggestion is used.
	 *
	 * @return void
	 */
	public function test_a_suggestion_is_used_as_it_is(): void {
		$this->answer_with( array( $this->get_routine_proposal( array( 'recurring_rhythm' => 'weekly', 'recurring_weekdays' => 'tue, thu' ) ) ) );

		$advice = Advisor::get_instance()->get_advice( 'I go running on tuesdays and thursdays' );

		$this->assertIsArray( $advice );

		$created = Advisor::get_instance()->create_source( reset( $advice['proposals'] ), array() );

		$this->assertIsArray( $created );
		$this->assertSame( 'publish', $created['status'] );
		$this->assertSame( 'recurring', get_post_meta( $created['source_id'], 'provider', true ) );
		$this->assertSame( 'Went running', get_post_meta( $created['source_id'], 'recurring_text', true ) );
		$this->assertSame( 'weekly', get_post_meta( $created['source_id'], 'recurring_rhythm', true ) );
		$this->assertSame( array( 'tue', 'thu' ), get_post_meta( $created['source_id'], 'recurring_weekdays', true ) );
		$this->assertSame( 'Running', get_the_title( $created['source_id'] ) );
	}

	/**
	 * The user can put the sentence in their own words before the source is created.
	 *
	 * @return void
	 */
	public function test_a_suggestion_can_be_replaced(): void {
		$this->answer_with( array( $this->get_routine_proposal() ) );

		$advice = Advisor::get_instance()->get_advice( 'I go running once a week' );

		$this->assertIsArray( $advice );

		$created = Advisor::get_instance()->create_source( reset( $advice['proposals'] ), array( 'recurring_text' => ' Ten kilometres along the river ' ) );

		$this->assertIsArray( $created );
		$this->assertSame( 'Ten kilometres along the river', get_post_meta( $created['source_id'], 'recurring_text', true ) );
	}

	/**
	 * A routine without a sentence asks for it, like every other missing detail.
	 *
	 * @return void
	 */
	public function test_a_routine_without_a_sentence_asks_for_it(): void {
		$proposal           = $this->get_routine_proposal();
		$proposal['values'] = array();

		$this->answer_with( array( $proposal ) );

		$advice = Advisor::get_instance()->get_advice( 'Something every week' );

		$this->assertIsArray( $advice );

		$offered = reset( $advice['proposals'] );

		$this->assertSame( 'field', $offered['questions']['recurring_text']['kind'] );
		$this->assertTrue( Advisor::get_instance()->has_open_questions( $offered ) );
		$this->assertInstanceOf( WP_Error::class, Advisor::get_instance()->create_source( $offered, array() ) );
	}

	/**
	 * The sentence of the model is plain text, whatever it tries.
	 *
	 * @return void
	 */
	public function test_the_sentence_of_a_routine_is_plain_text(): void {
		$proposal                       = $this->get_routine_proposal();
		$proposal['values'][0]['value'] = 'Went <script>alert(1)</script>running';

		$this->answer_with( array( $proposal ) );

		$advice = Advisor::get_instance()->get_advice( 'I go running once a week' );

		$this->assertIsArray( $advice );

		$created = Advisor::get_instance()->create_source( reset( $advice['proposals'] ), array() );

		$this->assertIsArray( $created );
		$this->assertStringNotContainsString( '<', (string) get_post_meta( $created['source_id'], 'recurring_text', true ) );
	}

	/**
	 * A routine which exists already is not offered again.
	 *
	 * @return void
	 */
	public function test_an_existing_routine_is_not_offered(): void {
		$this->answer_with( array( $this->get_routine_proposal() ) );

		$advice = Advisor::get_instance()->get_advice( 'I go running once a week' );

		$this->assertIsArray( $advice );
		$this->assertIsArray( Advisor::get_instance()->create_source( reset( $advice['proposals'] ), array() ) );

		$this->answer_with( array( $this->get_routine_proposal() ) );

		$advice = Advisor::get_instance()->get_advice( 'I go running every single week' );

		$this->assertIsArray( $advice );
		$this->assertCount( 0, $advice['proposals'] );
		$this->assertCount( 1, $advice['rejected'] );
	}

	/**
	 * The preset for a routine works for the model as well, with the same suggestion.
	 *
	 * @return void
	 */
	public function test_the_routine_preset_offers_the_same_suggestion(): void {
		$this->answer_with(
			array(
				array(
					'title'     => 'Market',
					'kind'      => 'preset',
					'slug'      => 'routine',
					'reason'    => 'A sentence about the weekly market.',
					'values'    => array(
						array(
							'name'  => 'text',
							'value' => 'Went to the market',
						),
					),
					'questions' => array(),
				),
			)
		);

		$advice = Advisor::get_instance()->get_advice( 'I go to the market every saturday' );

		$this->assertIsArray( $advice );

		$offered = reset( $advice['proposals'] );

		$this->assertSame( Advisor::QUESTION_SUGGESTION, $offered['questions']['text']['kind'] );

		$created = Advisor::get_instance()->create_source( $offered, array( 'text' => 'Bought vegetables at the market' ) );

		$this->assertIsArray( $created );
		$this->assertSame( 'Bought vegetables at the market', get_post_meta( $created['source_id'], 'recurring_text', true ) );
	}

	/**
	 * An address is not a matter of wording: it stays a plain value.
	 *
	 * @return void
	 */
	public function test_other_values_are_no_suggestions(): void {
		$this->answer_with( array( $this->get_feed_proposal( self::FEED_URL ) ) );

		$advice = Advisor::get_instance()->get_advice( 'The films I watch' );

		$this->assertIsArray( $advice );
		$this->assertSame( array(), reset( $advice['proposals'] )['questions'] );
	}

	/**
	 * A preset must be usable by the model, with its own questions for what is missing.
	 *
	 * @return void
	 */
	public function test_preset_asks_its_own_questions(): void {
		$this->answer_with(
			array(
				array(
					'title'     => 'Mastodon',
					'kind'      => 'preset',
					'slug'      => 'mastodon',
					'reason'    => 'Your posts.',
					'values'    => array(
						array(
							'name'  => 'mastodon_instance',
							'value' => 'mastodon.social',
						),
					),
					'questions' => array(),
				),
			)
		);

		$advice = Advisor::get_instance()->get_advice( 'My posts on mastodon.social' );

		$this->assertIsArray( $advice );

		$offered = reset( $advice['proposals'] );

		$this->assertSame( 'mastodon', $offered['preset'] );
		$this->assertSame( array( 'mastodon_handle' ), array_keys( $offered['questions'] ) );
		$this->assertSame( 'field', $offered['questions']['mastodon_handle']['kind'] );
	}

	/**
	 * A source which exists already must not be offered again.
	 *
	 * @return void
	 */
	public function test_existing_source_is_not_offered(): void {
		$resolved = Source_Builder::get_instance()->resolve( '', 'rss', array( 'feed_url' => self::FEED_URL ) );

		$this->assertIsArray( $resolved );
		$this->assertIsInt( Source_Builder::get_instance()->create( $resolved ) );

		$this->answer_with( array( $this->get_feed_proposal( self::FEED_URL ) ) );

		$advice = Advisor::get_instance()->get_advice( 'The films I watch' );

		$this->assertIsArray( $advice );
		$this->assertSame( array(), $advice['proposals'] );
		$this->assertCount( 1, $advice['rejected'] );
	}

	/**
	 * The same wish must be answered from the cache, a model costs money.
	 *
	 * @return void
	 */
	public function test_the_same_wish_is_asked_once(): void {
		$this->answer_with( array( $this->get_feed_proposal( self::FEED_URL ) ) );

		Advisor::get_instance()->get_advice( 'The films I watch' );
		Advisor::get_instance()->get_advice( '  The films   I watch ' );

		$this->assertSame( 1, $this->requests );
		$this->assertIsArray( Advisor::get_instance()->get_cached_advice( 'The films I watch' ) );
	}

	/**
	 * The advice must be found again, whatever happens to the catalog in between.
	 *
	 * The setup reads the advice while WordPress is still loading, when not
	 * every provider knows yet whether it is available. A cache key built
	 * from the catalog pointed to nothing at that moment.
	 *
	 * @return void
	 */
	public function test_the_advice_is_found_with_another_catalog(): void {
		$this->answer_with( array( $this->get_feed_proposal( self::FEED_URL ) ) );

		Advisor::get_instance()->get_advice( 'The films I watch' );

		update_option( 'now_pages_threadi_provider_github_enabled', 0 );

		$cached = Advisor::get_instance()->get_cached_advice( 'The films I watch' );

		delete_option( 'now_pages_threadi_provider_github_enabled' );

		$this->assertIsArray( $cached );
	}

	/**
	 * A provider which was switched off after the advice was given must not be used anymore.
	 *
	 * @return void
	 */
	public function test_a_provider_switched_off_afterwards_is_not_used(): void {
		$this->answer_with( array( $this->get_feed_proposal( self::FEED_URL ) ) );

		$advice = Advisor::get_instance()->get_advice( 'The films I watch' );

		$this->assertIsArray( $advice );

		update_option( 'now_pages_threadi_provider_rss_enabled', 0 );

		$created = Advisor::get_instance()->create_source( reset( $advice['proposals'] ) );

		delete_option( 'now_pages_threadi_provider_rss_enabled' );

		$this->assertInstanceOf( WP_Error::class, $created );
		$this->assertSame( 'now_pages_threadi_provider_disabled', $created->get_error_code() );
	}

	/**
	 * The advice of one user must not be shown to another one.
	 *
	 * @return void
	 */
	public function test_advice_is_kept_per_user(): void {
		$this->answer_with( array( $this->get_feed_proposal( self::FEED_URL ) ) );

		Advisor::get_instance()->get_advice( 'The films I watch' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertNull( Advisor::get_instance()->get_cached_advice( 'The films I watch' ) );
	}

	/**
	 * An answer wrapped in text or a code block must still be read.
	 *
	 * @return void
	 */
	public function test_wrapped_answer_is_read(): void {
		$this->answer_with( array( $this->get_feed_proposal( self::FEED_URL ) ) );
		$this->answer = "Here you go:\n```json\n" . $this->answer . "\n```";

		$advice = Advisor::get_instance()->get_advice( 'The films I watch' );

		$this->assertIsArray( $advice );
		$this->assertCount( 1, $advice['proposals'] );
	}

	/**
	 * An answer which is not what we asked for must be an error, and must not be cached.
	 *
	 * @return void
	 */
	public function test_unreadable_answer_is_an_error(): void {
		$this->answer = 'I am sorry, I cannot help with that.';

		$advice = Advisor::get_instance()->get_advice( 'The films I watch' );

		$this->assertInstanceOf( WP_Error::class, $advice );
		$this->assertSame( 'now_pages_threadi_advisor_unreadable', $advice->get_error_code() );
		$this->assertNull( Advisor::get_instance()->get_cached_advice( 'The films I watch' ) );
	}

	/**
	 * A failing request must be passed on with its reason.
	 *
	 * @return void
	 */
	public function test_failed_request_is_passed_on(): void {
		$this->answer = new WP_Error( 'prompt_network_error', 'The service did not answer.' );

		$advice = Advisor::get_instance()->get_advice( 'The films I watch' );

		$this->assertInstanceOf( WP_Error::class, $advice );
		$this->assertSame( 'The service did not answer.', $advice->get_error_message() );
	}

	/**
	 * No more proposals than asked for must be tried, each one costs a request.
	 *
	 * @return void
	 */
	public function test_proposals_are_limited(): void {
		$proposals = array();

		foreach ( array( 'a', 'b', 'c', 'd', 'e' ) as $name ) {
			$proposals[] = $this->get_feed_proposal( 'https://films.now-pages-threadi-test.example.com/' . $name . '/rss/' );
		}

		$this->answer_with( $proposals );

		$advice = Advisor::get_instance()->get_advice( 'The films I watch' );

		$this->assertIsArray( $advice );
		$this->assertCount( 3, $advice['rejected'] );
	}
}
