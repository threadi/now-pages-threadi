<?php
/**
 * File to test the source the setup creates from a preset.
 *
 * The way from a chosen preset to the stored post meta has become long:
 * answers, recipe, duplicate check, post creation. This covers it end to
 * end without touching the React side.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Tests\Unit;

use ThreadiNowPages\Plugin\Setup;
use ThreadiNowPages\Presets\Presets;
use ThreadiNowPages\Tests\NowPagesTestCase;
use WP_Query;

/**
 * Object to test Setup::run_process().
 */
class SetupCreateSourceTest extends NowPagesTestCase {
	/**
	 * Start every test without answers and without sources.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		\easySetupForWordPress\Setup::get_instance()->set_user_values( true );
		\easySetupForWordPress\Setup::get_instance()->delete_field_values( Setup::get_instance()->get_setup_name() );

		delete_option( 'esfw_step' );
		delete_option( 'esfw_max_steps' );
		delete_option( 'esfw_step_label' );
	}

	/**
	 * Store the answers of a setup run.
	 *
	 * @param string               $preset_slug The chosen preset.
	 * @param array<string,string> $answers     The answers to its questions.
	 *
	 * @return void
	 */
	private function answer( string $preset_slug, array $answers = array() ): void {
		$setup_obj = \easySetupForWordPress\Setup::get_instance();

		$setup_obj->set_field_value( Setup::get_instance()->get_setup_name(), Setup::OPTION_PRESET, $preset_slug );

		foreach ( $answers as $name => $value ) {
			$setup_obj->set_field_value( Setup::get_instance()->get_setup_name(), Setup::FIELD_PREFIX . $name, $value );
		}
	}

	/**
	 * Run the setup process.
	 *
	 * @return void
	 */
	private function run_setup(): void {
		Setup::get_instance()->run_process( Setup::get_instance()->get_setup_name() );
	}

	/**
	 * Return all existing sources.
	 *
	 * @return array<int,int>
	 */
	private function get_sources(): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'threadi_np_source',
				'post_status'    => array( 'any', 'trash' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return array_map( 'absint', $query->posts );
	}

	/**
	 * A preset without questions must create a source.
	 *
	 * @return void
	 */
	public function test_preset_without_questions_creates_a_source(): void {
		$this->answer( 'own_website' );
		$this->run_setup();

		$this->assertCount( 1, $this->get_sources() );
	}

	/**
	 * The source must be titled after the service, not after the provider.
	 *
	 * That is the whole point of the presets.
	 *
	 * @return void
	 */
	public function test_the_source_is_titled_after_the_service(): void {
		$this->answer( 'mastodon', array( 'mastodon_instance' => 'mastodon.social', 'mastodon_handle' => 'tz' ) );
		$this->run_setup();

		$sources = $this->get_sources();

		$this->assertSame( 'Mastodon', get_post( $sources[0] )->post_title );
	}

	/**
	 * A routine is no service: its source is named after the sentence it repeats.
	 *
	 * @return void
	 */
	public function test_a_routine_is_titled_after_its_sentence(): void {
		$this->answer( 'routine', array( 'text' => 'Went to the gym' ) );
		$this->run_setup();

		$sources = $this->get_sources();

		$this->assertCount( 1, $sources );
		$this->assertSame( 'Went to the gym', get_the_title( $sources[0] ) );
		$this->assertSame( 'recurring', get_post_meta( $sources[0], 'provider', true ) );
		$this->assertSame( 'Went to the gym', get_post_meta( $sources[0], 'recurring_text', true ) );
		$this->assertSame( 'routine', get_post_meta( $sources[0], \ThreadiNowPages\Presets\Presets::SOURCE_META, true ) );
	}

	/**
	 * The source must carry the provider of its preset.
	 *
	 * @return void
	 */
	public function test_the_source_carries_the_provider(): void {
		$this->answer( 'mastodon', array( 'mastodon_instance' => 'mastodon.social', 'mastodon_handle' => 'tz' ) );
		$this->run_setup();

		$sources = $this->get_sources();

		$this->assertSame( 'rss', get_post_meta( $sources[0], 'provider', true ) );
	}

	/**
	 * The source must remember which preset created it.
	 *
	 * @return void
	 */
	public function test_the_source_remembers_its_preset(): void {
		$this->answer( 'mastodon', array( 'mastodon_instance' => 'mastodon.social', 'mastodon_handle' => 'tz' ) );
		$this->run_setup();

		$sources = $this->get_sources();

		$this->assertSame( 'mastodon', get_post_meta( $sources[0], Presets::SOURCE_META, true ) );
	}

	/**
	 * The derived configuration must end up as post meta.
	 *
	 * @return void
	 */
	public function test_the_derived_configuration_is_stored(): void {
		$this->answer( 'mastodon', array( 'mastodon_instance' => 'mastodon.social', 'mastodon_handle' => 'tz' ) );
		$this->run_setup();

		$sources = $this->get_sources();

		$this->assertSame( 'https://mastodon.social/@tz.rss', get_post_meta( $sources[0], 'feed_url', true ) );
	}

	/**
	 * The source must belong to the user who ran the setup.
	 *
	 * @return void
	 */
	public function test_the_source_belongs_to_the_current_user(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $user_id );

		$this->answer( 'own_website' );
		$this->run_setup();

		$sources = $this->get_sources();

		$this->assertSame( $user_id, absint( get_post_field( 'post_author', $sources[0] ) ) );
	}

	/**
	 * Every source must get a language, otherwise it stays invisible.
	 *
	 * @return void
	 */
	public function test_the_source_gets_a_language(): void {
		$this->answer( 'own_website' );
		$this->run_setup();

		$sources = $this->get_sources();

		$this->assertNotSame( '', get_post_meta( $sources[0], 'language', true ) );
	}

	/**
	 * Without a chosen preset nothing must be created.
	 *
	 * @return void
	 */
	public function test_without_a_preset_nothing_is_created(): void {
		$this->run_setup();

		$this->assertSame( array(), $this->get_sources() );
	}

	/**
	 * An unknown preset must not create anything either.
	 *
	 * @return void
	 */
	public function test_an_unknown_preset_creates_nothing(): void {
		$this->answer( 'does-not-exist' );
		$this->run_setup();

		$this->assertSame( array(), $this->get_sources() );
	}

	/**
	 * Running the setup twice with the same answers must create one source.
	 *
	 * This is the case that happens when somebody walks through the wizard
	 * again instead of editing the source they already have.
	 *
	 * @return void
	 */
	public function test_running_twice_creates_only_one_source(): void {
		$answers = array( 'mastodon_instance' => 'mastodon.social', 'mastodon_handle' => 'tz' );

		$this->answer( 'mastodon', $answers );
		$this->run_setup();

		$this->answer( 'mastodon', $answers );
		$this->run_setup();

		$this->assertCount( 1, $this->get_sources() );
	}

	/**
	 * A second account of the same service must be possible.
	 *
	 * @return void
	 */
	public function test_a_second_account_creates_a_second_source(): void {
		$this->answer( 'mastodon', array( 'mastodon_instance' => 'mastodon.social', 'mastodon_handle' => 'tz' ) );
		$this->run_setup();

		$this->answer( 'mastodon', array( 'mastodon_instance' => 'mastodon.social', 'mastodon_handle' => 'someone-else' ) );
		$this->run_setup();

		$this->assertCount( 2, $this->get_sources() );
	}

	/**
	 * A refused run must say so instead of claiming success.
	 *
	 * @return void
	 */
	public function test_a_refused_run_reports_the_reason(): void {
		$answers = array( 'mastodon_instance' => 'mastodon.social', 'mastodon_handle' => 'tz' );

		$this->answer( 'mastodon', $answers );
		$this->run_setup();

		$this->answer( 'mastodon', $answers );
		$this->run_setup();

		Setup::get_instance()->show_process_end( Setup::get_instance()->get_setup_name() );

		$this->assertStringContainsString( 'already exists', (string) get_option( 'esfw_step_label' ) );
	}

	/**
	 * A successful run must report success.
	 *
	 * @return void
	 */
	public function test_a_successful_run_reports_success(): void {
		$this->answer( 'own_website' );
		$this->run_setup();

		Setup::get_instance()->show_process_end( Setup::get_instance()->get_setup_name() );

		$this->assertStringNotContainsString( 'already exists', (string) get_option( 'esfw_step_label' ) );
	}

	/**
	 * The answers must be gone after the source was created.
	 *
	 * Otherwise the next run would start with the values of the last one.
	 *
	 * @return void
	 */
	public function test_the_answers_are_cleared_afterwards(): void {
		$this->answer( 'mastodon', array( 'mastodon_instance' => 'mastodon.social', 'mastodon_handle' => 'tz' ) );
		$this->run_setup();

		$value = \easySetupForWordPress\Setup::get_instance()->get_field_value(
			Setup::get_instance()->get_setup_name(),
			Setup::OPTION_PRESET,
			''
		);

		$this->assertSame( '', (string) $value );
	}

	/**
	 * A run for another setup must be ignored.
	 *
	 * @return void
	 */
	public function test_another_setup_is_ignored(): void {
		$this->answer( 'own_website' );

		Setup::get_instance()->run_process( 'some-other-plugin' );

		$this->assertSame( array(), $this->get_sources() );
	}

	/**
	 * The created source must be announced, so extensions can react.
	 *
	 * @return void
	 */
	public function test_the_created_source_is_announced(): void {
		$announced = 0;

		$handler = static function () use ( &$announced ): void {
			++$announced;
		};

		add_action( 'threadi_now_pages_setup_source_created', $handler );

		$this->answer( 'own_website' );
		$this->run_setup();

		remove_action( 'threadi_now_pages_setup_source_created', $handler );

		$this->assertSame( 1, $announced );
	}
}
