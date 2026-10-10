<?php
/**
 * File to test the detection of duplicate sources.
 *
 * The method decides whether a source is created at all, so the case that
 * must not regress is the negative one: an empty identifying value cannot
 * identify anything, and a provider without an identity never reports a
 * duplicate.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use NowPagesThreadi\Providers\Providers;
use NowPagesThreadi\Providers\Webhook_Provider;
use NowPagesThreadi\Sources\Field_Base;
use NowPagesThreadi\Sources\Fields;
use NowPagesThreadi\Sources\Sources;
use NowPagesThreadi\Tests\NowPagesTestCase;
use NowPagesThreadi\Tests\Unit\Stubs\Stub_Combined_Provider;
use NowPagesThreadi\Tests\Unit\Stubs\Stub_Identified_Provider;
use NowPagesThreadi\Tests\Unit\Stubs\Stub_Provider;
use NowPagesThreadi\Tests\Unit\Stubs\Stub_Unique_Provider;

/**
 * Object to test Sources::get_duplicate().
 */
class DuplicateSourceTest extends NowPagesTestCase {
	/**
	 * Register the stub providers before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$providers_obj = Providers::get_instance();

		$providers_obj->register_provider( new Stub_Provider() );
		$providers_obj->register_provider( new Stub_Identified_Provider() );
		$providers_obj->register_provider( new Stub_Combined_Provider() );
		$providers_obj->register_provider( new Stub_Unique_Provider() );
	}

	/**
	 * Create a source with the given provider and configuration.
	 *
	 * @param string              $provider_slug The slug of the provider.
	 * @param array<string,mixed> $config        The configuration to store.
	 * @param string              $post_status   The status of the source.
	 *
	 * @return int
	 */
	private function create_source( string $provider_slug, array $config = array(), string $post_status = 'publish' ): int {
		$source_id = self::factory()->post->create(
			array(
				'post_type'   => 'np_threadi_source',
				'post_status' => $post_status,
				'post_title'  => 'Test source',
			)
		);

		update_post_meta( $source_id, 'provider', $provider_slug );

		foreach ( $config as $name => $value ) {
			update_post_meta( $source_id, $name, $value );
		}

		return $source_id;
	}

	/**
	 * Look for a duplicate of the given configuration.
	 *
	 * @param string              $provider_slug The slug of the provider.
	 * @param array<string,mixed> $config        The configuration to check.
	 * @param int                 $exclude       A source to ignore.
	 *
	 * @return int
	 */
	private function get_duplicate( string $provider_slug, array $config, int $exclude = 0 ): int {
		return Sources::get_instance()->get_duplicate(
			Providers::get_instance()->get_provider( $provider_slug ),
			$config,
			$exclude
		);
	}

	/**
	 * The same feed with other content filter rules is another source.
	 *
	 * That is how one feed is split: a profile feed which mixes forum
	 * replies and translations becomes two sources, each keeping its part.
	 *
	 * @return void
	 */
	public function test_other_filter_rules_make_it_another_source(): void {
		$source_id = $this->create_source(
			'stub_identified',
			array(
				'stub_account'         => 'tz',
				'content_filter_rules' => 'include:text:contains:/support/',
			)
		);

		$provider = Providers::get_instance()->get_provider( 'stub_identified' );
		$config   = array( 'stub_account' => 'tz' );

		// other rules: not a duplicate.
		$this->assertSame( 0, Sources::get_instance()->get_duplicate( $provider, $config, 0, 'include:text:contains:translate.wordpress.org' ) );

		// no own rules: not a duplicate of a filtered source either.
		$this->assertSame( 0, Sources::get_instance()->get_duplicate( $provider, $config ) );

		// the same rules: a duplicate.
		$this->assertSame( $source_id, Sources::get_instance()->get_duplicate( $provider, $config, 0, 'include:text:contains:/support/' ) );
	}

	/**
	 * Rules which only look different must not hide a duplicate.
	 *
	 * @return void
	 */
	public function test_the_same_rules_written_differently_are_a_duplicate(): void {
		$source_id = $this->create_source(
			'stub_identified',
			array(
				'stub_account'         => 'tz',
				'content_filter_rules' => "include:text:contains:forum\nexclude:text:contains:spam",
			)
		);

		$duplicate = Sources::get_instance()->get_duplicate(
			Providers::get_instance()->get_provider( 'stub_identified' ),
			array( 'stub_account' => 'tz' ),
			0,
			"# my rules\n\n  EXCLUDE:text:Contains:spam  \r\ninclude:text:contains:forum\nnot a rule\ninclude:text:contains:forum"
		);

		$this->assertSame( $source_id, $duplicate );
	}

	/**
	 * Among several sources of the same feed the one with the same rules must be found.
	 *
	 * @return void
	 */
	public function test_the_matching_one_of_several_is_found(): void {
		$this->create_source(
			'stub_identified',
			array(
				'stub_account'         => 'tz',
				'content_filter_rules' => 'include:text:contains:/support/',
			)
		);
		$unfiltered = $this->create_source( 'stub_identified', array( 'stub_account' => 'tz' ) );
		$translate  = $this->create_source(
			'stub_identified',
			array(
				'stub_account'         => 'tz',
				'content_filter_rules' => 'include:text:contains:translate',
			)
		);

		$provider = Providers::get_instance()->get_provider( 'stub_identified' );
		$config   = array( 'stub_account' => 'tz' );

		$this->assertSame( $unfiltered, Sources::get_instance()->get_duplicate( $provider, $config ) );
		$this->assertSame( $translate, Sources::get_instance()->get_duplicate( $provider, $config, 0, 'include:text:contains:translate' ) );

		// the source being edited is not its own duplicate.
		$this->assertSame( 0, Sources::get_instance()->get_duplicate( $provider, $config, $translate, 'include:text:contains:translate' ) );
	}

	/**
	 * A provider which may exist only once stays unique, whatever the rules are.
	 *
	 * @return void
	 */
	public function test_a_unique_provider_ignores_the_rules(): void {
		$source_id = $this->create_source( 'stub_unique', array( 'content_filter_rules' => 'include:text:contains:forum' ) );

		$this->assertSame( $source_id, Sources::get_instance()->get_duplicate( Providers::get_instance()->get_provider( 'stub_unique' ), array(), 0, 'include:text:contains:other' ) );
	}

	/**
	 * Two webhook sources with the same slug are a conflict, whatever the rules are.
	 *
	 * Incoming entries are routed by the slug only.
	 *
	 * @return void
	 */
	public function test_a_webhook_slug_ignores_the_rules(): void {
		$source_id = $this->create_source(
			'webhook',
			array(
				'webhook_slug'         => 'discord',
				'content_filter_rules' => 'include:text:contains:forum',
			)
		);

		$this->assertSame( $source_id, Sources::get_instance()->get_duplicate( new Webhook_Provider(), array( 'webhook_slug' => 'discord' ), 0, 'include:text:contains:other' ) );
	}

	/**
	 * The webhook slug must be stored the way a request is matched.
	 *
	 * @return void
	 */
	public function test_a_webhook_slug_is_stored_as_a_key(): void {
		$field_obj = Fields::get_instance()->get_field_by_slug( 'text' );

		$this->assertInstanceOf( Field_Base::class, $field_obj );

		$field_obj->set_settings( ( new Webhook_Provider() )->get_config_fields()['webhook_slug'] );

		$this->assertSame( 'mydiscord', $field_obj->sanitize( 'My Discord' ) );
	}

	/**
	 * Without any source there can be no duplicate.
	 *
	 * @return void
	 */
	public function test_no_source_means_no_duplicate(): void {
		$this->assertSame( 0, $this->get_duplicate( 'stub_identified', array( 'stub_account' => 'tz' ) ) );
	}

	/**
	 * The same identifying value must be found.
	 *
	 * @return void
	 */
	public function test_same_identifying_value_is_found(): void {
		$source_id = $this->create_source( 'stub_identified', array( 'stub_account' => 'tz' ) );

		$this->assertSame( $source_id, $this->get_duplicate( 'stub_identified', array( 'stub_account' => 'tz' ) ) );
	}

	/**
	 * A different identifying value must not be found.
	 *
	 * @return void
	 */
	public function test_different_identifying_value_is_not_found(): void {
		$this->create_source( 'stub_identified', array( 'stub_account' => 'tz' ) );

		$this->assertSame( 0, $this->get_duplicate( 'stub_identified', array( 'stub_account' => 'someone-else' ) ) );
	}

	/**
	 * A value that only differs in a non-identifying field is a duplicate.
	 *
	 * @return void
	 */
	public function test_other_fields_do_not_make_it_unique(): void {
		$source_id = $this->create_source(
			'stub_identified',
			array(
				'stub_account' => 'tz',
				'stub_limit'   => 5,
			)
		);

		$duplicate = $this->get_duplicate(
			'stub_identified',
			array(
				'stub_account' => 'tz',
				'stub_limit'   => 20,
			)
		);

		$this->assertSame( $source_id, $duplicate );
	}

	/**
	 * The same value on another provider must not be found.
	 *
	 * @return void
	 */
	public function test_same_value_on_another_provider_is_not_found(): void {
		$this->create_source( 'stub_identified', array( 'stub_account' => 'tz' ) );

		$this->assertSame( 0, $this->get_duplicate( 'stub_combined', array( 'stub_account' => 'tz' ) ) );
	}

	/**
	 * A provider without identifying fields must never report a duplicate.
	 *
	 * Two sources of it can be perfectly intentional, so the check has to
	 * stay out of the way.
	 *
	 * @return void
	 */
	public function test_provider_without_identity_never_reports_a_duplicate(): void {
		$this->create_source( 'stub', array( 'stub_url' => 'https://example.com/feed' ) );

		$this->assertSame( 0, $this->get_duplicate( 'stub', array( 'stub_url' => 'https://example.com/feed' ) ) );
	}

	/**
	 * An empty identifying value cannot identify anything.
	 *
	 * Without this guard every unconfigured source would be a duplicate of
	 * every other unconfigured source.
	 *
	 * @return void
	 */
	public function test_empty_identifying_value_reports_no_duplicate(): void {
		$this->create_source( 'stub_identified', array( 'stub_account' => '' ) );

		$this->assertSame( 0, $this->get_duplicate( 'stub_identified', array( 'stub_account' => '' ) ) );
	}

	/**
	 * A missing identifying value must behave like an empty one.
	 *
	 * @return void
	 */
	public function test_missing_identifying_value_reports_no_duplicate(): void {
		$this->create_source( 'stub_identified', array( 'stub_account' => 'tz' ) );

		$this->assertSame( 0, $this->get_duplicate( 'stub_identified', array() ) );
	}

	/**
	 * Both fields of a combined identity have to match.
	 *
	 * @return void
	 */
	public function test_combined_identity_needs_both_fields(): void {
		$source_id = $this->create_source(
			'stub_combined',
			array(
				'stub_calendar' => 'https://example.com/calendar.ics',
				'stub_filter'   => 'Europe',
			)
		);

		// both values match.
		$this->assertSame(
			$source_id,
			$this->get_duplicate(
				'stub_combined',
				array(
					'stub_calendar' => 'https://example.com/calendar.ics',
					'stub_filter'   => 'Europe',
				)
			)
		);

		// the same calendar with another filter is a different source.
		$this->assertSame(
			0,
			$this->get_duplicate(
				'stub_combined',
				array(
					'stub_calendar' => 'https://example.com/calendar.ics',
					'stub_filter'   => 'Leipzig',
				)
			)
		);
	}

	/**
	 * A unique provider reports a duplicate regardless of its configuration.
	 *
	 * @return void
	 */
	public function test_unique_provider_reports_a_duplicate(): void {
		$source_id = $this->create_source( 'stub_unique' );

		$this->assertSame( $source_id, $this->get_duplicate( 'stub_unique', array() ) );
	}

	/**
	 * Without an existing source a unique provider reports nothing.
	 *
	 * @return void
	 */
	public function test_unique_provider_without_a_source_reports_nothing(): void {
		$this->assertSame( 0, $this->get_duplicate( 'stub_unique', array() ) );
	}

	/**
	 * The source being edited must not be its own duplicate.
	 *
	 * Otherwise every save of an existing source would warn about itself.
	 *
	 * @return void
	 */
	public function test_the_edited_source_is_not_its_own_duplicate(): void {
		$source_id = $this->create_source( 'stub_identified', array( 'stub_account' => 'tz' ) );

		$this->assertSame( 0, $this->get_duplicate( 'stub_identified', array( 'stub_account' => 'tz' ), $source_id ) );
	}

	/**
	 * A second source is still found when the first one is excluded.
	 *
	 * @return void
	 */
	public function test_another_source_is_found_when_excluding_one(): void {
		$first  = $this->create_source( 'stub_identified', array( 'stub_account' => 'tz' ) );
		$second = $this->create_source( 'stub_identified', array( 'stub_account' => 'tz' ) );

		$this->assertSame( $second, $this->get_duplicate( 'stub_identified', array( 'stub_account' => 'tz' ), $first ) );
	}

	/**
	 * A draft counts as a duplicate too.
	 *
	 * It would start importing as soon as it is published, so warning only
	 * about published ones would come too late.
	 *
	 * @return void
	 */
	public function test_a_draft_counts_as_duplicate(): void {
		$source_id = $this->create_source( 'stub_identified', array( 'stub_account' => 'tz' ), 'draft' );

		$this->assertSame( $source_id, $this->get_duplicate( 'stub_identified', array( 'stub_account' => 'tz' ) ) );
	}

	/**
	 * A trashed source must not count as a duplicate.
	 *
	 * @return void
	 */
	public function test_a_trashed_source_is_not_a_duplicate(): void {
		$source_id = $this->create_source( 'stub_identified', array( 'stub_account' => 'tz' ) );

		wp_trash_post( $source_id );

		$this->assertSame( 0, $this->get_duplicate( 'stub_identified', array( 'stub_account' => 'tz' ) ) );
	}

	/**
	 * Every built-in provider must declare how it identifies itself.
	 *
	 * A provider without an identity is a deliberate decision, not an
	 * oversight — this test makes sure that decision stays visible.
	 *
	 * @return void
	 */
	public function test_built_in_providers_declare_their_identity(): void {
		$without_identity = array(
			// reading this website twice with different settings is fine.
			'wordpress_site',
		);

		foreach ( Providers::get_instance()->get_providers() as $provider ) {
			// skip the stubs of this test run.
			if ( str_starts_with( $provider->get_slug(), 'stub' ) ) {
				continue;
			}

			if ( in_array( $provider->get_slug(), $without_identity, true ) ) {
				continue;
			}

			$this->assertNotEmpty(
				$provider->get_identifying_fields(),
				'The provider ' . $provider->get_slug() . ' declares no identifying fields, so duplicates of it cannot be detected.'
			);
		}
	}

	/**
	 * Every identifying field must exist in the configuration of its provider.
	 *
	 * A typo would silently disable the check for that provider.
	 *
	 * @return void
	 */
	public function test_identifying_fields_exist_in_the_configuration(): void {
		foreach ( Providers::get_instance()->get_providers() as $provider ) {
			$known = array_keys( $provider->get_config_fields() );

			foreach ( $provider->get_identifying_fields() as $field ) {
				$this->assertContains(
					$field,
					$known,
					'The provider ' . $provider->get_slug() . ' identifies itself by ' . $field . ', which is not one of its configuration fields.'
				);
			}
		}
	}
}
