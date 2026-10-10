<?php
/**
 * This file contains the registry for all presets.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Presets;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Helper\Singleton_Interface;
use NowPagesThreadi\Helper\Singleton_Trait;
use NowPagesThreadi\Presets\Preset\Discord;
use NowPagesThreadi\Presets\Preset\GitHub;
use NowPagesThreadi\Presets\Preset\Goodreads;
use NowPagesThreadi\Presets\Preset\LastFm;
use NowPagesThreadi\Presets\Preset\Mastodon;
use NowPagesThreadi\Presets\Preset\Meetups;
use NowPagesThreadi\Presets\Preset\Own_Website;
use NowPagesThreadi\Presets\Preset\Routine;
use NowPagesThreadi\Presets\Preset\Steam;
use NowPagesThreadi\Presets\Preset\WordCamps;
use NowPagesThreadi\Presets\Preset\WordPress_Org;

/**
 * Object which holds all known presets.
 */
class Presets implements Singleton_Interface {
	use Singleton_Trait;

	/**
	 * The query parameter carrying the chosen preset.
	 *
	 * @var string
	 */
	public const REQUEST_PARAM = 'now_pages_threadi_preset';

	/**
	 * The post meta remembering which preset created a source.
	 *
	 * @var string
	 */
	public const SOURCE_META = 'preset';

	/**
	 * List of registered presets.
	 *
	 * @var array<string,Preset_Interface>
	 */
	private array $presets = array();

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// register our own, built-in presets.
		$this->register_preset( new Own_Website() );
		$this->register_preset( new Mastodon() );
		$this->register_preset( new WordPress_Org() );
		$this->register_preset( new WordCamps() );
		$this->register_preset( new Meetups() );
		$this->register_preset( new GitHub() );
		$this->register_preset( new Discord() );
		$this->register_preset( new LastFm() );
		$this->register_preset( new Steam() );
		$this->register_preset( new Goodreads() );
		$this->register_preset( new Routine() );

		$interface = $this;

		/**
		 * Fires once the built-in presets are registered.
		 *
		 * Use this to add your own services to the list shown when a new
		 * source is created.
		 *
		 * Example:
		 * add_action( 'now_pages_threadi_register_presets', function( $presets ) {
		 *     $presets->register_preset( new My_Preset() );
		 * } );
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param Presets $interface The presets object.
		 */
		do_action( 'now_pages_threadi_register_presets', $interface );
	}

	/**
	 * Register a single preset.
	 *
	 * @param Preset_Interface $preset The preset to register.
	 *
	 * @return void
	 */
	public function register_preset( Preset_Interface $preset ): void {
		$this->presets[ $preset->get_slug() ] = $preset;
	}

	/**
	 * Return all registered presets.
	 *
	 * @return array<string,Preset_Interface>
	 */
	public function get_presets(): array {
		return $this->presets;
	}

	/**
	 * Return all presets which can be offered on this website.
	 *
	 * @return array<string,Preset_Interface>
	 */
	public function get_available_presets(): array {
		$presets = array();

		foreach ( $this->presets as $slug => $preset ) {
			if ( ! $preset->is_available() ) {
				continue;
			}

			$presets[ $slug ] = $preset;
		}

		return $presets;
	}

	/**
	 * Return a single preset by its slug.
	 *
	 * @param string $slug The slug of the preset.
	 *
	 * @return Preset_Interface|null
	 */
	public function get_preset( string $slug ): ?Preset_Interface {
		if ( ! isset( $this->presets[ $slug ] ) ) {
			return null;
		}

		return $this->presets[ $slug ];
	}

	/**
	 * Return the groups presets can belong to.
	 *
	 * @return array<string,string>
	 */
	public function get_groups(): array {
		$groups = array(
			'own'      => __( 'Your own website', 'now-pages-threadi' ),
			'social'   => __( 'Social', 'now-pages-threadi' ),
			'code'     => __( 'Code', 'now-pages-threadi' ),
			'media'    => __( 'Music, film and games', 'now-pages-threadi' ),
			'calendar' => __( 'Calendar and events', 'now-pages-threadi' ),
			'other'    => __( 'Other', 'now-pages-threadi' ),
		);

		/**
		 * Filter the groups presets are sorted into.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<string,string> $groups Map of key => label.
		 */
		return apply_filters( 'now_pages_threadi_preset_groups', $groups );
	}
}
