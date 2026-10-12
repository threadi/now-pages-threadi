<?php
/**
 * This file contains an object, which represents a single entry for now pages.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\NowPages;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;

/**
 * Object, which represents a single entry for now pages.
 */
class Entry {
	/**
	 * The text.
	 *
	 * @var string
	 */
	public string $text;

	/**
	 * The date,
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $date;

	/**
	 * The source (aka provider slug).
	 *
	 * @var string
	 */
	public string $source_slug;

	/**
	 * The external ID.
	 *
	 * @var string
	 */
	public string $external_id;

	/**
	 * The external URL.
	 *
	 * @var string|null
	 */
	public ?string $source_url = null;

	/**
	 * The category term ID.
	 *
	 * @var int
	 */
	public int $category_term_id = 0;

	/**
	 * The language (2-letter code, e.g. 'de', 'en'). Null = not explicitly set by the source.
	 *
	 * @var string|null
	 */
	public ?string $language = null;

	/**
	 * Additional, provider-specific attributes usable for content filtering.
	 *
	 * @var array<string,string>
	 */
	public array $meta = array();

	/**
	 * The status the entry is stored with.
	 *
	 * "publish" by default. "pending" lets it wait until the user confirms
	 * it, see Confirmation - until then it is not shown anywhere.
	 *
	 * @var string
	 */
	public string $status = 'publish';

	/**
	 * Initialize this object.
	 */
	public function __construct() {}
}
