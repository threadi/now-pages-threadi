<?php
/**
 * File to test the content a page builder starts a now page with.
 *
 * Fourteen almost identical method pairs are the classic place for a typo
 * nobody notices: a wrong widget name or a missing encoding step produces
 * a page that looks fine until somebody opens it in the builder.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use NowPagesThreadi\PageBuilder\Page_Builders;
use NowPagesThreadi\PageBuilder\Pagelayer\Digest as Pagelayer_Digest;
use NowPagesThreadi\PageBuilder\PageBuilder_Base;
use NowPagesThreadi\Tests\NowPagesTestCase;

/**
 * Object to test the page content of every page builder.
 */
class PageBuilderContentTest extends NowPagesTestCase {
	/**
	 * Return one page builder by its slug.
	 *
	 * @param string $slug The slug of the page builder.
	 *
	 * @return PageBuilder_Base
	 */
	private function get_page_builder( string $slug ): PageBuilder_Base {
		foreach ( Page_Builders::get_instance()->get_page_builders_as_objects() as $page_builder_obj ) {
			if ( $slug === $page_builder_obj->get_slug() ) {
				return $page_builder_obj;
			}
		}

		$this->fail( 'The page builder ' . $slug . ' is not registered.' );
	}

	/**
	 * Every page builder must be registered with a slug and a label.
	 *
	 * @return void
	 */
	public function test_every_page_builder_has_a_slug_and_a_label(): void {
		$page_builders = Page_Builders::get_instance()->get_page_builders_as_objects();

		$this->assertNotEmpty( $page_builders );

		foreach ( $page_builders as $page_builder_obj ) {
			$this->assertNotSame( '', $page_builder_obj->get_slug() );
			$this->assertNotSame( '', $page_builder_obj->get_label() );
		}
	}

	/**
	 * The slugs must be unique.
	 *
	 * Two page builders with the same slug would make one of them
	 * unreachable, and which one depends on the order of the list.
	 *
	 * @return void
	 */
	public function test_the_slugs_are_unique(): void {
		$slugs = array();

		foreach ( Page_Builders::get_instance()->get_page_builders_as_objects() as $page_builder_obj ) {
			$this->assertNotContains(
				$page_builder_obj->get_slug(),
				$slugs,
				'The slug ' . $page_builder_obj->get_slug() . ' is used twice.'
			);

			$slugs[] = $page_builder_obj->get_slug();
		}
	}

	/**
	 * Every page builder must deliver something to start a page with.
	 *
	 * Either content, or meta carrying its layout — a page builder that
	 * delivers neither would create an empty page.
	 *
	 * @return void
	 */
	public function test_every_page_builder_delivers_a_starting_point(): void {
		foreach ( Page_Builders::get_instance()->get_page_builders_as_objects() as $page_builder_obj ) {
			$has_content = '' !== trim( $page_builder_obj->get_page_content() );
			$has_meta    = ! empty( $page_builder_obj->get_page_meta() );

			$this->assertTrue(
				$has_content || $has_meta,
				'The page builder ' . $page_builder_obj->get_slug() . ' delivers neither content nor meta.'
			);
		}
	}

	/**
	 * Meta keys must never be empty.
	 *
	 * @return void
	 */
	public function test_meta_keys_are_never_empty(): void {
		foreach ( Page_Builders::get_instance()->get_page_builders_as_objects() as $page_builder_obj ) {
			foreach ( array_keys( $page_builder_obj->get_page_meta() ) as $meta_key ) {
				$this->assertNotSame(
					'',
					trim( (string) $meta_key ),
					'The page builder ' . $page_builder_obj->get_slug() . ' delivers an empty meta key.'
				);
			}
		}
	}

	/**
	 * The shortcode is the fallback and must stay usable everywhere.
	 *
	 * @return void
	 */
	public function test_the_shortcode_is_the_fallback(): void {
		$page_builder_obj = $this->get_page_builder( 'shortcode' );

		$this->assertStringContainsString( 'now_pages_threadi_digest', $page_builder_obj->get_page_content() );
		$this->assertFalse( $page_builder_obj->is_active() );
		$this->assertSame( 0, $page_builder_obj->get_priority() );
	}

	/**
	 * Every other page builder must outrank the shortcode.
	 *
	 * Otherwise, the fallback could win over a builder that is actually in
	 * use, and the page would be prepared for the wrong editor.
	 *
	 * @return void
	 */
	public function test_every_page_builder_outranks_the_shortcode(): void {
		foreach ( Page_Builders::get_instance()->get_page_builders_as_objects() as $page_builder_obj ) {
			if ( 'shortcode' === $page_builder_obj->get_slug() ) {
				continue;
			}

			$this->assertGreaterThan(
				0,
				$page_builder_obj->get_priority(),
				'The page builder ' . $page_builder_obj->get_slug() . ' does not outrank the shortcode.'
			);
		}
	}

	/**
	 * The block editor must start with our block.
	 *
	 * @return void
	 */
	public function test_gutenberg_starts_with_our_block(): void {
		$content = $this->get_page_builder( 'gutenberg' )->get_page_content();

		$this->assertStringContainsString( 'wp:now-pages-threadi/digest', $content );
	}

	/**
	 * Elementor keeps its layout in meta, encoded as JSON.
	 *
	 * @return void
	 */
	public function test_elementor_delivers_decodable_json(): void {
		$meta = $this->get_page_builder( 'elementor' )->get_page_meta();

		$this->assertArrayHasKey( '_elementor_data', $meta );

		$data = json_decode( (string) $meta['_elementor_data'], true );

		$this->assertIsArray( $data, 'The Elementor data is not valid JSON.' );
		$this->assertStringContainsString( 'now-pages-threadi-digest', (string) wp_json_encode( $data ) );
	}

	/**
	 * Bricks keeps its elements in meta, as an array.
	 *
	 * @return void
	 */
	public function test_bricks_delivers_one_element(): void {
		$meta = $this->get_page_builder( 'bricks' )->get_page_meta();

		$this->assertArrayHasKey( '_bricks_page_content_2', $meta );
		$this->assertIsArray( $meta['_bricks_page_content_2'] );
		$this->assertCount( 1, $meta['_bricks_page_content_2'] );

		$element = $meta['_bricks_page_content_2'][0];

		$this->assertSame( 'now-pages-threadi-digest', $element['name'] );
		$this->assertNotSame( '', $element['id'] );
	}

	/**
	 * Breakdance stores JSON inside JSON.
	 *
	 * A single missing encoding step would produce a tree breakdance
	 * cannot read, and the page would simply be empty.
	 *
	 * @return void
	 */
	public function test_breakdance_delivers_json_inside_json(): void {
		$meta = $this->get_page_builder( 'breakdance' )->get_page_meta();

		$this->assertArrayHasKey( '_breakdance_data', $meta );

		$outer = json_decode( (string) $meta['_breakdance_data'], true );

		$this->assertIsArray( $outer );
		$this->assertArrayHasKey( 'tree_json_string', $outer );

		$tree = json_decode( (string) $outer['tree_json_string'], true );

		$this->assertIsArray( $tree, 'The breakdance tree is not valid JSON.' );
		$this->assertArrayHasKey( 'root', $tree );
		$this->assertStringContainsString( 'Digest', (string) wp_json_encode( $tree ) );
	}

	/**
	 * Brizy stores base64 inside serialized meta.
	 *
	 * @return void
	 */
	public function test_brizy_delivers_decodable_editor_data(): void {
		$meta = $this->get_page_builder( 'brizy' )->get_page_meta();

		$this->assertArrayHasKey( 'brizy', $meta );
		$this->assertArrayHasKey( 'editor_data', $meta['brizy']['brizy-post'] );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- this is how brizy stores its data.
		$decoded = base64_decode( (string) $meta['brizy']['brizy-post']['editor_data'], true );

		$this->assertIsString( $decoded, 'The brizy editor data is not valid base64.' );

		$data = json_decode( (string) $decoded, true );

		$this->assertIsArray( $data, 'The brizy editor data is not valid JSON.' );
		$this->assertStringContainsString( 'Now.Pages.Digest', (string) wp_json_encode( $data ) );
	}

	/**
	 * Zion stores its elements as a JSON string.
	 *
	 * @return void
	 */
	public function test_zion_delivers_decodable_json(): void {
		$meta = $this->get_page_builder( 'zionbuilder' )->get_page_meta();

		$this->assertArrayHasKey( '_zionbuilder_page_elements', $meta );

		$elements = json_decode( (string) $meta['_zionbuilder_page_elements'], true );

		$this->assertIsArray( $elements, 'The zion elements are not valid JSON.' );
		$this->assertStringContainsString( 'now_pages_threadi_digest', (string) wp_json_encode( $elements ) );
	}

	/**
	 * Beaver stores a tree of objects keyed by their node ID.
	 *
	 * @return void
	 */
	public function test_beaver_keys_its_nodes_by_their_id(): void {
		$meta = $this->get_page_builder( 'beaver' )->get_page_meta();

		$this->assertArrayHasKey( '_fl_builder_data', $meta );
		$this->assertArrayHasKey( '_fl_builder_draft', $meta );

		foreach ( $meta['_fl_builder_data'] as $node_id => $node ) {
			$this->assertSame(
				$node_id,
				$node->node,
				'A beaver node is stored under a key that is not its own ID.'
			);
		}
	}

	/**
	 * Pagelayer stores a row, a column and our widget as blocks.
	 *
	 * Its blocks must be parseable by WordPress, otherwise Pagelayer would
	 * show an empty page, and its meta marks the page as one of Pagelayer.
	 *
	 * @return void
	 */
	public function test_pagelayer_delivers_parseable_blocks(): void {
		$page_builder_obj = $this->get_page_builder( 'pagelayer' );

		$this->assertArrayHasKey( 'pagelayer-data', $page_builder_obj->get_page_meta() );

		$blocks = parse_blocks( $page_builder_obj->get_page_content() );
		$blocks = array_values( array_filter( $blocks, static fn( array $block ): bool => ! empty( $block['blockName'] ) ) );

		$this->assertCount( 1, $blocks );
		$this->assertSame( 'pagelayer/pl_row', $blocks[0]['blockName'] );
		$this->assertSame( 'pagelayer/pl_col', $blocks[0]['innerBlocks'][0]['blockName'] );

		$widget = $blocks[0]['innerBlocks'][0]['innerBlocks'][0];

		$this->assertSame( 'pagelayer/now_pages_threadi_pagelayer_digest', $widget['blockName'] );
		$this->assertNotEmpty( $widget['attrs']['period'] );
		$this->assertNotEmpty( $widget['attrs']['pagelayer-id'] );

		// two calls must not deliver the same IDs.
		$this->assertNotSame( $page_builder_obj->get_page_content(), $page_builder_obj->get_page_content() );
	}

	/**
	 * The Pagelayer widget only fills its own elements.
	 *
	 * Its filter runs for every element Pagelayer renders.
	 *
	 * @return void
	 */
	public function test_pagelayer_widget_fills_only_its_own_elements(): void {
		$digest_obj = Pagelayer_Digest::get_instance();

		// not our tag: the element stays as it is.
		$el = array(
			'tag'  => 'pl_text',
			'atts' => array(),
		);
		$this->assertSame( $el, $digest_obj->prepare( $el ) );

		// our tag: the placeholder of the template is filled.
		$el = $digest_obj->prepare(
			array(
				'tag'  => Pagelayer_Digest::TAG,
				'atts' => array(),
				'tmp'  => array(),
			)
		);
		$this->assertArrayHasKey( 'digest', $el['tmp'] );
		$this->assertIsString( $el['tmp']['digest'] );
	}

	/**
	 * The shortcode builders must wrap our shortcode in their own markup.
	 *
	 * @return void
	 */
	public function test_shortcode_builders_wrap_our_shortcode(): void {
		$expected = array(
			'divi4'    => 'et_pb_section',
			'wpbakery' => 'vc_row',
			'avada'    => 'fusion_builder_container',
		);

		foreach ( $expected as $slug => $wrapper ) {
			$content = $this->get_page_builder( $slug )->get_page_content();

			$this->assertStringContainsString( $wrapper, $content, 'The ' . $slug . ' content misses its wrapper.' );
		}
	}

	/**
	 * Two calls must not deliver the same generated IDs.
	 *
	 * Beaver, bricks, brizy and zion generate IDs. Repeating them would
	 * collide as soon as two pages exist.
	 *
	 * @return void
	 */
	public function test_generated_ids_differ_between_calls(): void {
		foreach ( array( 'beaver', 'bricks', 'brizy', 'zionbuilder' ) as $slug ) {
			$page_builder_obj = $this->get_page_builder( $slug );

			$first  = wp_json_encode( $page_builder_obj->get_page_meta() );
			$second = wp_json_encode( $page_builder_obj->get_page_meta() );

			$this->assertNotSame(
				$first,
				$second,
				'The page builder ' . $slug . ' generates the same IDs on every call.'
			);
		}
	}
}
