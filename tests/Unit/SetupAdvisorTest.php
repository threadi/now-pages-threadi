<?php
/**
 * File to test the way through the setup with the advisor.
 *
 * Choose "Get advice", describe a wish, pick a proposal, answer what is
 * open, create the source. The React side is left out: the steps are read
 * the way the setup reads them, the answers are stored the way it stores them.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use NowPagesThreadi\Plugin\Setup;
use NowPagesThreadi\Sources\Advisor;
use NowPagesThreadi\Tests\NowPagesTestCase;
use ReflectionMethod;
use WP_Query;
use WP_REST_Request;

/**
 * Object to test the advisor steps of Setup.
 */
class SetupAdvisorTest extends NowPagesTestCase {
	/**
	 * The address of a feed which exists.
	 *
	 * @var string
	 */
	private const FEED_URL = 'https://films.now-pages-threadi-test.example.com/jane/rss/';

	/**
	 * The answer the model gives in this test.
	 *
	 * @var string
	 */
	private string $answer = '';

	/**
	 * How often the model has been asked.
	 *
	 * @var int
	 */
	private int $requests = 0;

	/**
	 * Start every test on the setup page, without answers, with a connected AI service.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// the advisor is only offered while the setup is shown.
		set_current_screen( 'dashboard' );
		$_GET['page'] = 'now_pages_threadi_settings-setup';

		\easySetupForWordPress\Setup::get_instance()->set_user_values( true );
		\easySetupForWordPress\Setup::get_instance()->delete_field_values( Setup::get_instance()->get_setup_name() );

		delete_option( 'esfw_step' );
		delete_option( 'esfw_max_steps' );
		delete_option( 'esfw_step_label' );

		$this->requests = 0;
		$this->answer   = (string) wp_json_encode(
			array(
				'message'   => 'This is the feed of your films.',
				'proposals' => array(
					array(
						'title'     => 'Films',
						'kind'      => 'provider',
						'slug'      => 'rss',
						'reason'    => 'The films you logged.',
						'values'    => array(
							array(
								'name'  => 'feed_url',
								'value' => 'https://films.now-pages-threadi-test.example.com/{username}/rss/',
							),
						),
						'questions' => array(
							array(
								'name'  => 'username',
								'label' => 'Your name there',
								'help'  => 'As in the address of your profile.',
							),
						),
					),
				),
			)
		);

		add_filter( 'now_pages_threadi_advisor_is_available', '__return_true' );
		add_filter( 'now_pages_threadi_advisor_pre_request', array( $this, 'get_answer' ) );
		add_filter( 'pre_http_request', array( $this, 'mock_feed' ), 5, 3 );
	}

	/**
	 * Leave the setup page.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		unset( $_GET['page'], $GLOBALS['current_screen'] );

		parent::tear_down();
	}

	/**
	 * Return the answer of the model.
	 *
	 * @return string
	 */
	public function get_answer(): string {
		++$this->requests;

		return $this->answer;
	}

	/**
	 * Deliver a feed for the one address which exists.
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
				. '</channel></rss>';

			return self::mock_http_response( 200, $feed, array( 'content-type' => 'application/rss+xml; charset=UTF-8' ) );
		}

		if ( str_contains( $url, 'films.now-pages-threadi-test.example.com' ) ) {
			return self::mock_http_response( 404, 'Not found' );
		}

		return $preempt;
	}

	/**
	 * Store a value the way the setup stores it.
	 *
	 * @param string $name  The name of the field.
	 * @param string $value The value.
	 *
	 * @return void
	 */
	private function store( string $name, string $value ): void {
		\easySetupForWordPress\Setup::get_instance()->set_field_value( Setup::get_instance()->get_setup_name(), $name, $value );
	}

	/**
	 * Return the steps as a fresh request would build them.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_steps(): array {
		$setup = Setup::get_instance();
		$setup->set_config();

		$method = new ReflectionMethod( $setup, 'get_setup' );

		return $method->invoke( $setup );
	}

	/**
	 * Return the step holding the given field.
	 *
	 * @param string $field_name The name of a field.
	 *
	 * @return array<string,mixed>
	 */
	private function get_step_with( string $field_name ): array {
		foreach ( $this->get_steps() as $step ) {
			if ( isset( $step[ $field_name ] ) ) {
				return $step;
			}
		}

		return array();
	}

	/**
	 * Return the fields the setup fetches after a step has been saved.
	 *
	 * @return array<int|string,mixed>
	 */
	private function fetch_fields(): array {
		// a fresh request builds its steps from what is stored.
		Setup::get_instance()->set_config();

		$response = rest_do_request( new WP_REST_Request( 'GET', '/easy-setup-for-wordpress/v1/fields/' . Setup::get_instance()->get_setup_name() ) );

		$this->assertSame( 200, $response->get_status() );

		return (array) $response->get_data();
	}

	/**
	 * Return all existing sources.
	 *
	 * @return array<int,int>
	 */
	private function get_sources(): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'np_threadi_source',
				'post_status'    => array( 'any', 'trash' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return array_map( 'absint', $query->posts );
	}

	/**
	 * Return the values the cards of the preset step offer.
	 *
	 * @return array<int,string>
	 */
	private function get_offered_presets(): array {
		$step = $this->get_step_with( Setup::OPTION_PRESET );

		return wp_list_pluck( $step[ Setup::OPTION_PRESET ]['options'], 'value' );
	}

	/**
	 * With a connected AI service the advisor must be the first choice.
	 *
	 * @return void
	 */
	public function test_the_advisor_is_offered_first(): void {
		$offered = $this->get_offered_presets();

		$this->assertSame( Advisor::SLUG, $offered[0] );
		$this->assertContains( 'mastodon', $offered );
	}

	/**
	 * The steps must be built again after WordPress handed the API keys to the AI Client.
	 *
	 * That happens at init:20. Built only before, the advisor is never
	 * offered, although a connector is active - that is how it was missed.
	 *
	 * @return void
	 */
	public function test_the_steps_are_rebuilt_after_the_connectors_are_ready(): void {
		$rebuilt = has_action( 'init', array( Setup::get_instance(), 'refresh_config' ) );

		$this->assertIsInt( $rebuilt );

		// _wp_connectors_pass_default_keys_to_ai_client() is hooked to init with priority 20 in wp-includes/connectors.php.
		$this->assertGreaterThan( 20, $rebuilt );
	}

	/**
	 * A service which becomes available after the first build must be offered after the rebuild.
	 *
	 * @return void
	 */
	public function test_the_rebuild_offers_the_advisor(): void {
		// the first build, before any connector has its key.
		remove_filter( 'now_pages_threadi_advisor_is_available', '__return_true' );
		Setup::get_instance()->set_config();

		$method = new ReflectionMethod( Setup::get_instance(), 'get_setup' );

		$early = array();
		foreach ( $method->invoke( Setup::get_instance() ) as $step ) {
			if ( isset( $step[ Setup::OPTION_PRESET ] ) ) {
				$early = wp_list_pluck( $step[ Setup::OPTION_PRESET ]['options'], 'value' );
			}
		}

		$this->assertNotContains( Advisor::SLUG, $early );

		// the connector is ready now.
		add_filter( 'now_pages_threadi_advisor_is_available', '__return_true' );
		Setup::get_instance()->refresh_config();

		$late = array();
		foreach ( $method->invoke( Setup::get_instance() ) as $step ) {
			if ( isset( $step[ Setup::OPTION_PRESET ] ) ) {
				$late = wp_list_pluck( $step[ Setup::OPTION_PRESET ]['options'], 'value' );
			}
		}

		$this->assertContains( Advisor::SLUG, $late );
	}

	/**
	 * Without a connected AI service the advisor must not be offered, but named.
	 *
	 * @return void
	 */
	public function test_without_ai_service_the_advisor_is_only_named(): void {
		remove_filter( 'now_pages_threadi_advisor_is_available', '__return_true' );

		$step = $this->get_step_with( Setup::OPTION_PRESET );

		$this->assertNotContains( Advisor::SLUG, $this->get_offered_presets() );
		$this->assertArrayHasKey( 'help0', $step );
		$this->assertStringContainsString( 'options-connectors.php', $step['help0']['text'] );
	}

	/**
	 * Switched off in the settings the advisor must not show up at all.
	 *
	 * @return void
	 */
	public function test_switched_off_the_advisor_is_not_mentioned(): void {
		remove_filter( 'now_pages_threadi_advisor_is_available', '__return_true' );
		update_option( Advisor::OPTION_ENABLED, 0 );

		$step = $this->get_step_with( Setup::OPTION_PRESET );

		$this->assertNotContains( Advisor::SLUG, $this->get_offered_presets() );
		$this->assertArrayNotHasKey( 'help0', $step );
	}

	/**
	 * Outside of the setup nothing must be asked about AI at all.
	 *
	 * The steps are built on every request, also in the frontend.
	 *
	 * @return void
	 */
	public function test_outside_of_the_setup_the_advisor_is_not_offered(): void {
		unset( $_GET['page'] );

		$this->assertNotContains( Advisor::SLUG, $this->get_offered_presets() );
	}

	/**
	 * A preset must keep its three steps.
	 *
	 * @return void
	 */
	public function test_a_preset_keeps_its_steps(): void {
		$before = count( $this->get_steps() );

		$this->store( Setup::OPTION_PRESET, 'mastodon' );

		$this->assertCount( $before, $this->get_steps() );
	}

	/**
	 * Choosing the advisor must replace the questions of a preset by its own steps.
	 *
	 * @return void
	 */
	public function test_choosing_the_advisor_adds_its_steps(): void {
		$before = count( $this->get_steps() );

		$this->store( Setup::OPTION_PRESET, Advisor::SLUG );

		$this->assertCount( $before + 2, $this->get_steps() );
		$this->assertNotEmpty( $this->get_step_with( Setup::OPTION_WISH ) );
		$this->assertNotEmpty( $this->get_step_with( Setup::OPTION_PROPOSAL ) );
	}

	/**
	 * Building the steps must never ask the model.
	 *
	 * @return void
	 */
	public function test_building_the_steps_does_not_ask_the_model(): void {
		$this->store( Setup::OPTION_PRESET, Advisor::SLUG );
		$this->store( Setup::OPTION_WISH, 'The films I watch' );

		$step = $this->get_step_with( Setup::OPTION_PROPOSAL );

		$this->assertSame( 0, $this->requests );
		$this->assertSame( array(), $step[ Setup::OPTION_PROPOSAL ]['options'] );
	}

	/**
	 * Fetching the fields after the wish was saved must deliver the proposals.
	 *
	 * @return void
	 */
	public function test_fetching_the_fields_delivers_the_proposals(): void {
		$this->store( Setup::OPTION_PRESET, Advisor::SLUG );
		$this->store( Setup::OPTION_WISH, 'The films I watch' );

		$steps = $this->fetch_fields();

		$this->assertSame( 1, $this->requests );

		$options = array();
		foreach ( $steps as $step ) {
			if ( isset( $step[ Setup::OPTION_PROPOSAL ] ) ) {
				$options = $step[ Setup::OPTION_PROPOSAL ]['options'];
				$this->assertStringContainsString( 'This is the feed of your films.', $step[ Setup::OPTION_PROPOSAL ]['help'] );
			}
		}

		$this->assertCount( 1, $options );
		$this->assertSame( 'Films', $options[0]['label'] );

		// fetching them again must not cost a second request.
		$this->fetch_fields();

		$this->assertSame( 1, $this->requests );
	}

	/**
	 * A user who may not run the setup must not be able to make the model work.
	 *
	 * @return void
	 */
	public function test_other_users_cannot_trigger_the_model(): void {
		$this->store( Setup::OPTION_PRESET, Advisor::SLUG );
		$this->store( Setup::OPTION_WISH, 'The films I watch' );

		$admin = get_current_user_id();
		$other = self::factory()->user->create( array( 'role' => 'editor' ) );

		// the editor has the same answers stored, however they got there.
		update_user_meta( $other, 'esfw_values_' . Setup::get_instance()->get_setup_name(), get_user_meta( $admin, 'esfw_values_' . Setup::get_instance()->get_setup_name(), true ) );
		wp_set_current_user( $other );

		Setup::get_instance()->set_config();
		rest_do_request( new WP_REST_Request( 'GET', '/easy-setup-for-wordpress/v1/fields/' . Setup::get_instance()->get_setup_name() ) );

		$this->assertSame( 0, $this->requests );
	}

	/**
	 * The chosen proposal must ask what it left open.
	 *
	 * @return void
	 */
	public function test_the_chosen_proposal_asks_its_question(): void {
		$this->store( Setup::OPTION_PRESET, Advisor::SLUG );
		$this->store( Setup::OPTION_WISH, 'The films I watch' );
		$this->fetch_fields();

		$advice = Advisor::get_instance()->get_cached_advice( 'The films I watch' );

		$this->assertIsArray( $advice );

		$this->store( Setup::OPTION_PROPOSAL, (string) array_key_first( $advice['proposals'] ) );

		$step = $this->get_step_with( Setup::ANSWER_PREFIX . 'username' );

		$this->assertNotEmpty( $step );
		$this->assertSame( 'Your name there', $step[ Setup::ANSWER_PREFIX . 'username' ]['label'] );
		$this->assertTrue( $step[ Setup::ANSWER_PREFIX . 'username' ]['required'] );
	}

	/**
	 * The whole way must end with a published, imported source.
	 *
	 * @return void
	 */
	public function test_the_whole_way_creates_a_source(): void {
		$this->store( Setup::OPTION_PRESET, Advisor::SLUG );
		$this->store( Setup::OPTION_WISH, 'The films I watch' );
		$this->fetch_fields();

		$advice = Advisor::get_instance()->get_cached_advice( 'The films I watch' );

		$this->assertIsArray( $advice );

		$this->store( Setup::OPTION_PROPOSAL, (string) array_key_first( $advice['proposals'] ) );
		$this->store( Setup::ANSWER_PREFIX . 'username', 'jane' );

		Setup::get_instance()->run_process( Setup::get_instance()->get_setup_name() );

		$sources = $this->get_sources();

		$this->assertCount( 1, $sources );
		$this->assertSame( 'publish', get_post_status( $sources[0] ) );
		$this->assertSame( 'Films', get_the_title( $sources[0] ) );
		$this->assertSame( 'rss', get_post_meta( $sources[0], 'provider', true ) );
		$this->assertSame( self::FEED_URL, get_post_meta( $sources[0], 'feed_url', true ) );

		// the import of the setup must have read it.
		$entries = new WP_Query(
			array(
				'post_type'   => 'np_threadi_entry',
				'post_status' => 'any',
				'post_parent' => $sources[0],
				'fields'      => 'ids',
			)
		);

		$this->assertCount( 1, $entries->posts );
	}

	/**
	 * A wrong answer must leave a draft and say so.
	 *
	 * @return void
	 */
	public function test_a_wrong_answer_is_reported(): void {
		$this->store( Setup::OPTION_PRESET, Advisor::SLUG );
		$this->store( Setup::OPTION_WISH, 'The films I watch' );
		$this->fetch_fields();

		$advice = Advisor::get_instance()->get_cached_advice( 'The films I watch' );

		$this->assertIsArray( $advice );

		$this->store( Setup::OPTION_PROPOSAL, (string) array_key_first( $advice['proposals'] ) );
		$this->store( Setup::ANSWER_PREFIX . 'username', 'nobody' );

		Setup::get_instance()->run_process( Setup::get_instance()->get_setup_name() );
		Setup::get_instance()->show_process_end( Setup::get_instance()->get_setup_name() );

		$sources = $this->get_sources();

		$this->assertCount( 1, $sources );
		$this->assertSame( 'draft', get_post_status( $sources[0] ) );
		$this->assertStringContainsString( 'draft', (string) get_option( 'esfw_step_label' ) );
	}

	/**
	 * Let the model answer with a routine, and walk the setup up to the chosen proposal.
	 *
	 * @return array<string,mixed> The proposal.
	 */
	private function choose_a_routine(): array {
		$this->answer = (string) wp_json_encode(
			array(
				'message'   => 'A sentence about your run, repeated every week.',
				'proposals' => array(
					array(
						'title'     => 'Running',
						'kind'      => 'provider',
						'slug'      => 'recurring',
						'reason'    => 'A sentence about your weekly run.',
						'values'    => array(
							array(
								'name'  => 'recurring_text',
								'value' => 'Went running',
							),
							array(
								'name'  => 'recurring_rhythm',
								'value' => 'weekly',
							),
						),
						'questions' => array(),
					),
				),
			)
		);

		$this->store( Setup::OPTION_PRESET, Advisor::SLUG );
		$this->store( Setup::OPTION_WISH, 'I go running once a week' );
		$this->fetch_fields();

		$advice = Advisor::get_instance()->get_cached_advice( 'I go running once a week' );

		$this->assertIsArray( $advice );

		$proposal = reset( $advice['proposals'] );

		$this->assertIsArray( $proposal );

		$this->store( Setup::OPTION_PROPOSAL, (string) $proposal['id'] );

		return $proposal;
	}

	/**
	 * A routine shows its sentence in a field which may stay empty.
	 *
	 * @return void
	 */
	public function test_a_routine_shows_its_sentence_as_an_optional_field(): void {
		$proposal = $this->choose_a_routine();
		$field    = Setup::ANSWER_PREFIX . 'recurring_text_' . $proposal['id'];
		$step     = $this->get_step_with( $field );

		$this->assertNotEmpty( $step );
		$this->assertFalse( $step[ $field ]['required'] );
		$this->assertSame( 'Went running', $step[ $field ]['placeholder'] );
		$this->assertSame( 'Text of the entry', $step[ $field ]['label'] );

		// nothing is open, so the step does not announce a later try.
		$this->assertStringContainsString( 'needs no further details', $step['help']['text'] );
		$this->assertStringContainsString( 'Went running', $step['samples']['text'] );
	}

	/**
	 * A routine is created with the sentence of the model if the user leaves it alone.
	 *
	 * @return void
	 */
	public function test_a_routine_is_created_with_the_proposed_sentence(): void {
		$this->choose_a_routine();

		Setup::get_instance()->run_process( Setup::get_instance()->get_setup_name() );

		$sources = $this->get_sources();

		$this->assertCount( 1, $sources );
		$this->assertSame( 'publish', get_post_status( $sources[0] ) );
		$this->assertSame( 'Went running', get_post_meta( $sources[0], 'recurring_text', true ) );
		$this->assertSame( 'weekly', get_post_meta( $sources[0], 'recurring_rhythm', true ) );

		// the import of the setup created its first entry.
		$entries = new WP_Query(
			array(
				'post_type'   => 'np_threadi_entry',
				'post_status' => 'any',
				'post_parent' => $sources[0],
			)
		);

		$this->assertCount( 1, $entries->posts );
		$this->assertSame( 'Went running', $entries->posts[0]->post_content );
	}

	/**
	 * A routine is created with the words of the user, and those words are forgotten afterwards.
	 *
	 * @return void
	 */
	public function test_a_routine_is_created_with_the_words_of_the_user(): void {
		$proposal = $this->choose_a_routine();
		$field    = Setup::ANSWER_PREFIX . 'recurring_text_' . $proposal['id'];

		$this->store( $field, 'Ten kilometres along the river' );

		Setup::get_instance()->run_process( Setup::get_instance()->get_setup_name() );

		$sources = $this->get_sources();

		$this->assertCount( 1, $sources );
		$this->assertSame( 'Ten kilometres along the river', get_post_meta( $sources[0], 'recurring_text', true ) );

		// left in place, the answer would be used without a word the next time this proposal comes up.
		$this->assertSame( '', \easySetupForWordPress\Setup::get_instance()->get_field_value( Setup::get_instance()->get_setup_name(), $field, '' ) );
	}

	/**
	 * Without a chosen proposal nothing must be created, and the user must be told.
	 *
	 * @return void
	 */
	public function test_without_a_proposal_nothing_is_created(): void {
		$this->store( Setup::OPTION_PRESET, Advisor::SLUG );
		$this->store( Setup::OPTION_WISH, 'The films I watch' );

		Setup::get_instance()->run_process( Setup::get_instance()->get_setup_name() );
		Setup::get_instance()->show_process_end( Setup::get_instance()->get_setup_name() );

		$this->assertSame( array(), $this->get_sources() );
		$this->assertStringContainsString( 'no source was created', (string) get_option( 'esfw_step_label' ) );
	}
}
