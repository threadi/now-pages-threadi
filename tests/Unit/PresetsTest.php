<?php
/**
 * File to test the presets.
 *
 * A preset is nothing but a recipe turning a few answers into a provider
 * configuration. That makes it cheap to test and easy to break — a
 * misplaced slash in an assembled URL is invisible until an import fails.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Tests\Unit;

use ThreadiNowPages\Presets\Preset_Interface;
use ThreadiNowPages\Presets\Presets;
use ThreadiNowPages\Providers\Provider_Interface;
use ThreadiNowPages\Providers\Providers;
use ThreadiNowPages\Tests\NowPagesTestCase;

/**
 * Object to test the presets.
 */
class PresetsTest extends NowPagesTestCase {
	/**
	 * Return the configuration a preset derives from the given answers.
	 *
	 * @param string               $slug  The slug of the preset.
	 * @param array<string,string> $setup The answers.
	 *
	 * @return array<string,mixed>
	 */
	private function get_config( string $slug, array $setup = array() ): array {
		$preset = Presets::get_instance()->get_preset( $slug );

		$this->assertInstanceOf( Preset_Interface::class, $preset, 'The preset ' . $slug . ' is not registered.' );

		return $preset->get_config( $setup );
	}

	/**
	 * Mastodon must assemble the feed URL of an account.
	 *
	 * @return void
	 */
	public function test_mastodon_builds_the_feed_url(): void {
		$config = $this->get_config(
			'mastodon',
			array(
				'mastodon_instance' => 'mastodon.social',
				'mastodon_handle'   => 'tz',
			)
		);

		$this->assertSame( 'https://mastodon.social/@tz.rss', $config['feed_url'] );
	}

	/**
	 * A pasted full address must not end up doubled.
	 *
	 * People copy the whole URL out of their browser, so the scheme has to
	 * be cut off before we add our own.
	 *
	 * @return void
	 */
	public function test_mastodon_strips_a_pasted_scheme(): void {
		$config = $this->get_config(
			'mastodon',
			array(
				'mastodon_instance' => 'https://mastodon.social/',
				'mastodon_handle'   => '@tz',
			)
		);

		$this->assertSame( 'https://mastodon.social/@tz.rss', $config['feed_url'] );
	}

	/**
	 * Without answers Mastodon must produce no configuration at all.
	 *
	 * Half a URL would create a source that fails on every single run.
	 *
	 * @return void
	 */
	public function test_mastodon_without_answers_produces_nothing(): void {
		$this->assertSame( array(), $this->get_config( 'mastodon' ) );
		$this->assertSame( array(), $this->get_config( 'mastodon', array( 'mastodon_instance' => 'mastodon.social' ) ) );
		$this->assertSame( array(), $this->get_config( 'mastodon', array( 'mastodon_handle' => 'tz' ) ) );
	}

	/**
	 * An instance with a path, a query or credentials must produce no configuration.
	 *
	 * @return void
	 */
	public function test_mastodon_refuses_an_instance_which_is_no_host(): void {
		foreach ( array( 'mastodon.social/evil', 'mastodon.social?x=1', 'user@mastodon.social', 'mastodon.social#x' ) as $instance ) {
			$this->assertSame(
				array(),
				$this->get_config(
					'mastodon',
					array(
						'mastodon_instance' => $instance,
						'mastodon_handle'   => 'tz',
					)
				),
				$instance
			);
		}
	}

	/**
	 * The WordPress.org profile must point at the feed of a profile.
	 *
	 * @return void
	 */
	public function test_wordpress_org_builds_the_profile_feed(): void {
		$config = $this->get_config( 'wordpress_org', array( 'wporg_username' => 'threadi' ) );

		$this->assertSame( 'https://profiles.wordpress.org/threadi/feed/', $config['feed_url'] );
	}

	/**
	 * Goodreads must fall back to the shelf people actually mean.
	 *
	 * @return void
	 */
	public function test_goodreads_defaults_to_the_current_shelf(): void {
		$config = $this->get_config( 'goodreads', array( 'goodreads_user_id' => '12345678' ) );

		$this->assertStringContainsString( 'currently-reading', $config['feed_url'] );
		$this->assertStringContainsString( '12345678', $config['feed_url'] );
	}

	/**
	 * A given shelf must win over the default.
	 *
	 * @return void
	 */
	public function test_goodreads_uses_the_given_shelf(): void {
		$config = $this->get_config(
			'goodreads',
			array(
				'goodreads_user_id' => '12345678',
				'goodreads_shelf'   => 'read',
			)
		);

		$this->assertStringContainsString( 'shelf=read', $config['feed_url'] );
	}

	/**
	 * The WordCamp preset must carry the filter into the provider.
	 *
	 * Without it every WordCamp worldwide would end up on the now page.
	 *
	 * @return void
	 */
	public function test_wordcamp_keeps_the_filter(): void {
		$config = $this->get_config( 'wordcamps', array( 'wordcamp_filter' => 'Europe' ) );

		$this->assertSame( 'Europe', $config['ics_include'] );
		$this->assertStringContainsString( 'central.wordcamp.org', $config['ics_url'] );
	}

	/**
	 * The WordCamp filter must keep its accents, the event titles have them.
	 *
	 * @return void
	 */
	public function test_wordcamp_filter_keeps_accents(): void {
		$config = $this->get_config( 'wordcamps', array( 'wordcamp_filter' => 'Montréal' ) );

		$this->assertSame( 'Montréal', $config['ics_include'] );
	}

	/**
	 * Without a filter the WordCamp preset must produce nothing.
	 *
	 * @return void
	 */
	public function test_wordcamp_without_filter_produces_nothing(): void {
		$this->assertSame( array(), $this->get_config( 'wordcamps' ) );
	}

	/**
	 * The Meetup preset must build the calendar address of a group, however the group was named.
	 *
	 * The name alone, the address of the group with or without a language in
	 * front, or the address of one of its events: all of them mean the same group.
	 *
	 * @return void
	 */
	public function test_meetup_builds_the_calendar_of_the_group(): void {
		$expected = 'https://www.meetup.com/leipzig-wordpress-meetup/events/ical/';

		$answers = array(
			'leipzig-wordpress-meetup',
			'  leipzig-wordpress-meetup ',
			'https://www.meetup.com/leipzig-wordpress-meetup/',
			'https://www.meetup.com/de-DE/leipzig-wordpress-meetup/',
			'https://www.meetup.com/leipzig-wordpress-meetup/events/315286844/',
			'www.meetup.com/de-DE/leipzig-wordpress-meetup?eventOrigin=home',
		);

		foreach ( $answers as $answer ) {
			$config = $this->get_config( 'meetups', array( 'meetup_group' => $answer ) );

			$this->assertSame( $expected, $config['ics_url'], 'Answer: ' . $answer );
		}

		// every event of the group is meant, so nothing is filtered.
		$this->assertArrayNotHasKey( 'ics_include', $config );
		$this->assertSame( 7, $config['ics_lookahead_days'] );
	}

	/**
	 * An answer must not be able to change where the address points to.
	 *
	 * @return void
	 */
	public function test_meetup_keeps_the_address_on_meetup(): void {
		foreach ( array( '../../evil.example.org/x', 'group/../../other', 'group?x=1#y', 'grüppe mit leerzeichen' ) as $answer ) {
			$config = $this->get_config( 'meetups', array( 'meetup_group' => $answer ) );

			$this->assertSame( 1, preg_match( '~^https://www\.meetup\.com/[A-Za-z0-9_-]+/events/ical/$~', $config['ics_url'] ), 'Answer: ' . $answer );
		}
	}

	/**
	 * Without a group the Meetup preset must produce nothing.
	 *
	 * @return void
	 */
	public function test_meetup_without_group_produces_nothing(): void {
		$this->assertSame( array(), $this->get_config( 'meetups' ) );
		$this->assertSame( array(), $this->get_config( 'meetups', array( 'meetup_group' => ' / ' ) ) );
	}

	/**
	 * The GitHub preset must pass the account through.
	 *
	 * @return void
	 */
	public function test_github_passes_the_account_through(): void {
		$config = $this->get_config( 'github', array( 'github_username' => 'octocat' ) );

		$this->assertSame( 'octocat', $config['github_username'] );
	}

	/**
	 * Last.fm must summarize by default.
	 *
	 * One entry per track would fill a whole now page with song titles.
	 *
	 * @return void
	 */
	public function test_lastfm_summarizes_by_default(): void {
		$config = $this->get_config( 'lastfm', array( 'lastfm_username' => 'rj' ) );

		$this->assertSame( 'rj', $config['lastfm_username'] );
		$this->assertNotEmpty( $config['lastfm_summarize'] );
	}

	/**
	 * The Steam preset must pass the account ID through.
	 *
	 * @return void
	 */
	public function test_steam_passes_the_account_through(): void {
		$config = $this->get_config( 'steam', array( 'steam_id' => '76561197960287930' ) );

		$this->assertSame( '76561197960287930', $config['steam_id'] );
	}

	/**
	 * The own website must be preconfigured for the current user.
	 *
	 * @return void
	 */
	public function test_own_website_uses_the_current_user(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $user_id );

		$config = $this->get_config( 'own_website' );

		$this->assertSame( (string) $user_id, (string) $config['wp_author'] );
		$this->assertContains( 'post', $config['wp_post_types'] );
	}

	/**
	 * Discord must create a webhook source with a routable slug.
	 *
	 * @return void
	 */
	public function test_discord_sets_a_webhook_slug(): void {
		$config = $this->get_config( 'discord' );

		$this->assertNotEmpty( $config['webhook_slug'] );
	}

	/**
	 * Every preset must point at a provider that exists.
	 *
	 * A typo in the slug would create sources nobody can run.
	 *
	 * @return void
	 */
	public function test_every_preset_points_at_an_existing_provider(): void {
		$presets = Presets::get_instance()->get_presets();

		$this->assertNotEmpty( $presets );

		foreach ( $presets as $preset ) {
			$this->assertInstanceOf(
				Provider_Interface::class,
				Providers::get_instance()->get_provider( $preset->get_provider_slug() ),
				'The preset ' . $preset->get_slug() . ' uses the unknown provider ' . $preset->get_provider_slug() . '.'
			);
		}
	}

	/**
	 * Every preset must belong to a known group.
	 *
	 * An unknown group would make the preset disappear from a grouped view.
	 *
	 * @return void
	 */
	public function test_every_preset_belongs_to_a_known_group(): void {
		$groups = array_keys( Presets::get_instance()->get_groups() );

		foreach ( Presets::get_instance()->get_presets() as $preset ) {
			$this->assertContains(
				$preset->get_group(),
				$groups,
				'The preset ' . $preset->get_slug() . ' belongs to the unknown group ' . $preset->get_group() . '.'
			);
		}
	}

	/**
	 * Every preset must have a label and a description.
	 *
	 * Both are shown on its card, an empty one looks broken.
	 *
	 * @return void
	 */
	public function test_every_preset_has_a_label_and_a_description(): void {
		foreach ( Presets::get_instance()->get_presets() as $preset ) {
			$this->assertNotSame( '', $preset->get_label(), 'The preset ' . $preset->get_slug() . ' has no label.' );
			$this->assertNotSame( '', $preset->get_description(), 'The preset ' . $preset->get_slug() . ' has no description.' );
		}
	}

	/**
	 * Setup field names must be unique across all presets.
	 *
	 * The setup stores them under one shared prefix, so two presets asking
	 * for the same field name would overwrite each other.
	 *
	 * @return void
	 */
	public function test_setup_field_names_are_unique_across_presets(): void {
		$seen = array();

		foreach ( Presets::get_instance()->get_presets() as $preset ) {
			foreach ( array_keys( $preset->get_setup_fields() ) as $name ) {
				$this->assertArrayNotHasKey(
					$name,
					$seen,
					'The field ' . $name . ' is used by ' . $preset->get_slug() . ' and by ' . ( $seen[ $name ] ?? '' ) . '.'
				);

				$seen[ $name ] = $preset->get_slug();
			}
		}
	}

	/**
	 * A preset must never ask for a secret in its setup fields.
	 *
	 * The answers travel through the setup and would end up in places a
	 * secret does not belong.
	 *
	 * @return void
	 */
	public function test_no_preset_asks_for_a_secret(): void {
		foreach ( Presets::get_instance()->get_presets() as $preset ) {
			foreach ( $preset->get_setup_fields() as $name => $field ) {
				$type = '';

				if ( ! empty( $field['type'] ) ) {
					$type = $field['type'];
				}

				$this->assertNotSame(
					'password',
					$type,
					'The preset ' . $preset->get_slug() . ' asks for the secret ' . $name . ' during setup.'
				);
			}
		}
	}

	/**
	 * A preset must only produce configuration its provider knows.
	 *
	 * A value under an unknown name would be stored and never read.
	 *
	 * @return void
	 */
	public function test_presets_only_produce_known_configuration(): void {
		foreach ( Presets::get_instance()->get_presets() as $preset ) {
			$provider = Providers::get_instance()->get_provider( $preset->get_provider_slug() );

			$this->assertInstanceOf( Provider_Interface::class, $provider );

			$known = array_keys( $provider->get_config_fields() );

			// answer every question with something usable.
			$setup = array();

			foreach ( array_keys( $preset->get_setup_fields() ) as $name ) {
				$setup[ $name ] = '1';
			}

			foreach ( array_keys( $preset->get_config( $setup ) ) as $name ) {
				$this->assertContains(
					$name,
					$known,
					'The preset ' . $preset->get_slug() . ' produces ' . $name . ', which its provider does not know.'
				);
			}
		}
	}
}
