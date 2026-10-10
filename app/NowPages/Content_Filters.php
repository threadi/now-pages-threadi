<?php
/**
 * This file contains the generic content filter for imported entries.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\NowPages;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Dependencies\easyTransientsForWordPress\Transients;
use NowPagesThreadi\Plugin\Log;
use NowPagesThreadi\Providers\Provider_Interface;
use NowPagesThreadi\Providers\Providers;

/**
 * Object, which decides whether an entry passes its configured field rules.
 */
class Content_Filters {
	/**
	 * The option holding the site-wide rules.
	 *
	 * @var string
	 */
	public const OPTION = 'now_pages_threadi_content_filter_rules';

	/**
	 * The post meta holding the rules of a single source.
	 *
	 * @var string
	 */
	public const SOURCE_META = 'content_filter_rules';

	/**
	 * The allowed operators.
	 *
	 * @var string[]
	 */
	public const OPERATORS = array( 'equals', 'not_equals', 'contains', 'not_contains', 'word', 'regex' );

	/**
	 * The fields every entry has, whatever its provider is.
	 *
	 * @var string[]
	 */
	public const FIELDS = array( 'text', 'url', 'source_slug' );

	/**
	 * Instance of actual object.
	 *
	 * @var ?Content_Filters
	 */
	private static ?Content_Filters $instance = null;

	/**
	 * Constructor, not used as this a Singleton object.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning of this object.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Return instance of this object as singleton.
	 *
	 * @return Content_Filters
	 */
	public static function get_instance(): Content_Filters {
		if ( is_null( self::$instance ) ) {
			self::$instance = new static();
		}

		return self::$instance;
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// use hooks.
		add_filter( 'now_pages_threadi_log_categories', array( $this, 'add_log_category' ) );
	}

	/**
	 * Add our own log category.
	 *
	 * @param array<string,string> $categories List of log categories.
	 *
	 * @return array<string,string>
	 */
	public function add_log_category( array $categories ): array {
		$categories['content_filter'] = __( 'Content filter', 'now-pages-threadi' );

		return $categories;
	}

	/**
	 * Check whether the given entry passes all configured rules.
	 *
	 * @param Entry $entry      The entry object.
	 * @param int   $sources_id The ID of the used source (0 = none).
	 *
	 * @return true|string True if the entry passes, otherwise the rule that blocked it (for logging).
	 */
	public function check( Entry $entry, int $sources_id ): true|string {
		$include_rules = array();
		$exclude_rules = array();

		foreach ( $this->get_rules( $sources_id ) as $rule ) {
			if ( 'exclude' === $rule['action'] ) {
				$exclude_rules[] = $rule;
			} else {
				$include_rules[ $rule['field'] ][] = $rule;
			}
		}

		// exclude rules: the entry is blocked if ANY of them matches.
		foreach ( $exclude_rules as $rule ) {
			if ( $this->rule_matches( $entry, $rule ) ) {
				return $this->describe_rule( $rule );
			}
		}

		// include rules: grouped by field - if a field has 1+ include rules,
		// the entry must match AT LEAST ONE of them, or it is blocked.
		foreach ( $include_rules as $field_rules ) {
			$matched_any = false;

			foreach ( $field_rules as $rule ) {
				if ( $this->rule_matches( $entry, $rule ) ) {
					$matched_any = true;
					break;
				}
			}

			if ( ! $matched_any ) {
				return $this->describe_rule( $field_rules[0] ) . ' ' . __( '(or a similar rule for the same field)', 'now-pages-threadi' );
			}
		}

		return true;
	}

	/**
	 * Check whether a single rule matches the given entry.
	 *
	 * @param Entry                $entry The entry object.
	 * @param array<string,string> $rule The rule, see parse_rules().
	 *
	 * @return bool
	 */
	private function rule_matches( Entry $entry, array $rule ): bool {
		$haystack = mb_strtolower( $this->get_field_value( $entry, $rule['field'] ) );
		$needle   = mb_strtolower( $rule['value'] );

		switch ( $rule['operator'] ) {
			case 'equals':
				return $haystack === $needle;
			case 'not_equals':
				return $haystack !== $needle;
			case 'contains':
				return '' !== $needle && str_contains( $haystack, $needle );
			case 'not_contains':
				return '' === $needle || ! str_contains( $haystack, $needle );
			case 'word':
				// the value as a whole word: "art" matches "art" and "Art.", but not "party".
				return '' !== $needle && 1 === preg_match( '~(?<![\\p{L}\\p{N}_])' . preg_quote( $needle, '~' ) . '(?![\\p{L}\\p{N}_])~u', $haystack );
			case 'regex':
				$result = @preg_match( self::get_regex_pattern( $rule['value'] ), $this->get_field_value( $entry, $rule['field'] ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid patterns from user input must not throw a fatal error.

				// fail closed on an error: an exclude rule counts as matching, so the entry is
				// skipped, and an include rule as not matching, which skips the entry as well.
				if ( false === $result ) {
					$is_exclude = 'exclude' === $rule['action'];

					Log::get_instance()->add(
						sprintf( $this->get_regex_error_message( $is_exclude ), $rule['value'] ),
						'error',
						'content_filter'
					);

					return $is_exclude;
				}

				return 1 === $result;
			default:
				return false;
		}
	}

	/**
	 * Return the pattern for the value of a "regex" rule.
	 *
	 * Uses "~" as delimiter and escapes it in the rule, so rules containing "/" (e.g. URLs) work.
	 *
	 * @param string $value The value of the rule.
	 *
	 * @return string
	 */
	private static function get_regex_pattern( string $value ): string {
		return '~' . (string) preg_replace( '/(?<!\\\\)~/', '\\~', $value ) . '~ui';
	}

	/**
	 * Return the value of a given field on an entry.
	 *
	 * 'text', 'url' and 'source_slug' are always available; everything else
	 * comes from the provider-specific Entry::$meta bag (empty string if unset).
	 *
	 * 'url' is the address an entry links to. It often tells what an entry
	 * is about when its text does not: a feed which mixes several kinds of
	 * activity can be split by where each item leads.
	 *
	 * @param Entry  $entry The entry object.
	 * @param string $field The field name.
	 *
	 * @return string
	 */
	private function get_field_value( Entry $entry, string $field ): string {
		if ( 'text' === $field ) {
			return wp_strip_all_tags( $entry->text );
		}

		if ( 'source_slug' === $field ) {
			return $entry->source_slug;
		}

		if ( 'url' === $field ) {
			return (string) $entry->source_url;
		}

		return (string) ( $entry->meta[ $field ] ?? '' );
	}

	/**
	 * Return a human-readable description of a rule, for logging.
	 *
	 * @param array<string,string> $rule The rule, see parse_rules().
	 *
	 * @return string
	 */
	private function describe_rule( array $rule ): string {
		return sprintf(
		/* translators: %1$s: action (include/exclude), %2$s: field, %3$s: operator, %4$s: value. */
			__( '%1$s rule "%2$s %3$s %4$s"', 'now-pages-threadi' ),
			'exclude' === $rule['action'] ? __( 'Exclude', 'now-pages-threadi' ) : __( 'Include', 'now-pages-threadi' ),
			$rule['field'],
			$rule['operator'],
			$rule['value']
		);
	}

	/**
	 * Return all rules which apply to a given source.
	 *
	 * The list of a source extends the site-wide list, it does not replace it.
	 *
	 * @param int $sources_id The ID of the used source (0 = none).
	 *
	 * @return array<int,array{action:string,field:string,operator:string,value:string}>
	 */
	public function get_rules( int $sources_id ): array {
		$rules = self::parse_rules( (string) get_option( self::OPTION, '' ) );

		if ( $sources_id > 0 ) {
			$rules = array_merge(
				$rules,
				self::parse_rules( (string) get_post_meta( $sources_id, self::SOURCE_META, true ) )
			);
		}

		/**
		 * Filter the content filter rules applied to an entry before it is imported.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<int,array{action:string,field:string,operator:string,value:string}> $rules      The parsed rules.
		 * @param int                                                                        $sources_id The ID of the used source.
		 */
		return apply_filters( 'now_pages_threadi_content_filter_rules', $rules, $sources_id );
	}

	/**
	 * Return what a list of rules does, regardless of how it is written.
	 *
	 * Two values with the same signature filter the same way: the order of
	 * the lines, blank lines, comments, invalid lines and repeated rules do
	 * not matter. Used to tell two sources of the same feed apart.
	 *
	 * @param string $value The raw value, one rule per line.
	 *
	 * @return string An empty string if there is no valid rule.
	 */
	public static function get_signature( string $value ): string {
		$lines = array();

		foreach ( self::parse_rules( $value ) as $rule ) {
			$lines[] = implode( ':', $rule );
		}

		$lines = array_unique( $lines );
		sort( $lines );

		return implode( "\n", $lines );
	}

	/**
	 * Clean up the raw value of a list of rules before it is stored.
	 *
	 * Rules are no HTML: a regex like "(?<=a)b" or "(?<name>...)" looks like a
	 * tag, and "%20" like an encoded character, so sanitize_textarea_field()
	 * would change them. Only invalid UTF-8 and control characters other than
	 * tabs and line breaks are removed, and each line is trimmed.
	 *
	 * @param string $value The raw value, one rule per line, not slashed.
	 *
	 * @return string
	 */
	public static function sanitize_rules( string $value ): string {
		// remove invalid UTF-8, as the pattern below would not match on it.
		$value = wp_check_invalid_utf8( $value, true );

		// remove control characters except tabs and line breaks.
		$value = (string) preg_replace( '/[^\P{Cc}\t\n\r]/u', '', $value );

		// trim each line.
		$parts = preg_split( '/\r\n|\r|\n/', $value );
		$lines = array_map( 'trim', false !== $parts ? $parts : array() );

		return trim( implode( "\n", $lines ) );
	}

	/**
	 * Parse a textarea value into a list of rules.
	 *
	 * One rule per line, syntax: `action:field:operator:value`
	 * - action:   'include' or 'exclude'
	 * - field:    'text', 'url', 'source_slug', or a provider-specific meta key (see
	 *             Provider_Interface::get_filterable_fields()).
	 * - operator: one of self::OPERATORS.
	 * - value:    the value to compare against (for 'regex': a PCRE pattern body, without delimiters).
	 *
	 * Example: `include:author:equals:Jane Doe`
	 *
	 * @param string $value The raw value, one rule per line.
	 *
	 * @return array<int,array{action:string,field:string,operator:string,value:string}>
	 */
	public static function parse_rules( string $value ): array {
		$rules = array();

		foreach ( (array) preg_split( '/\r\n|\r|\n/', $value ) as $line ) {
			$rule = self::parse_line( (string) $line );

			// only keep what is a rule: everything else is ignored, validate() tells why.
			if ( is_array( $rule ) ) {
				$rules[] = $rule;
			}
		}

		return $rules;
	}

	/**
	 * Parse a single line.
	 *
	 * @param string $line The line.
	 *
	 * @return array{action:string,field:string,operator:string,value:string}|string|null The rule, the reason why this is none ("incomplete", "action", "operator"), or null for a blank line or a comment.
	 */
	private static function parse_line( string $line ): array|string|null {
		$line = trim( $line );

		if ( '' === $line || str_starts_with( $line, '#' ) ) {
			return null;
		}

		// split into at most 4 parts, so a regex value may itself contain colons.
		$parts = explode( ':', $line, 4 );

		if ( count( $parts ) < 4 ) {
			return 'incomplete';
		}

		list( $action, $field, $operator, $rule_value ) = $parts;

		$action   = strtolower( trim( $action ) );
		$field    = trim( $field );
		$operator = strtolower( trim( $operator ) );

		if ( ! in_array( $action, array( 'include', 'exclude' ), true ) ) {
			return 'action';
		}

		if ( '' === $field ) {
			return 'incomplete';
		}

		if ( ! in_array( $operator, self::OPERATORS, true ) ) {
			return 'operator';
		}

		return array(
			'action'   => $action,
			'field'    => $field,
			'operator' => $operator,
			'value'    => trim( $rule_value ),
		);
	}

	/**
	 * Return the lines which do not do what somebody would expect of them.
	 *
	 * A line which is no rule is ignored on import without a word, and a
	 * rule on a field no entry has never matches - an "include" rule on it
	 * keeps every entry out. Both look fine in the form, so this is what
	 * tells the user.
	 *
	 * @param string   $value  The raw value, one rule per line.
	 * @param string[] $fields The fields the entries have. Empty to not check the fields.
	 *
	 * @return array<int,array{line:string,reason:string}> Reason is one of "incomplete", "action", "operator", "field", "regex".
	 */
	public static function validate( string $value, array $fields = array() ): array {
		$problems = array();

		foreach ( (array) preg_split( '/\r\n|\r|\n/', $value ) as $line ) {
			$line = trim( (string) $line );
			$rule = self::parse_line( $line );

			// a blank line or a comment.
			if ( null === $rule ) {
				continue;
			}

			$reason = '';

			if ( is_string( $rule ) ) {
				$reason = $rule;
			} elseif ( ! empty( $fields ) && ! in_array( $rule['field'], $fields, true ) ) {
				$reason = 'field';
			} elseif ( 'regex' === $rule['operator'] && false === @preg_match( self::get_regex_pattern( $rule['value'] ), '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an invalid pattern is what we are looking for.
				$reason = 'regex';
			}

			if ( '' !== $reason ) {
				$problems[] = array(
					'line'   => $line,
					'reason' => $reason,
				);
			}
		}

		return $problems;
	}

	/**
	 * Return the fields the entries of a provider have.
	 *
	 * @param Provider_Interface|null $provider The provider, null for the fields of all providers.
	 *
	 * @return string[]
	 */
	public function get_available_fields( ?Provider_Interface $provider = null ): array {
		$fields    = self::FIELDS;
		$providers = $provider instanceof Provider_Interface ? array( $provider ) : Providers::get_instance()->get_providers();

		foreach ( $providers as $provider_obj ) {
			$fields = array_merge( $fields, array_map( 'strval', array_keys( $provider_obj->get_filterable_fields() ) ) );
		}

		$fields = array_values( array_unique( $fields ) );

		/**
		 * Filter the fields content filter rules may use.
		 *
		 * Add a field here if entries get it in another way than through
		 * Provider_Interface::get_filterable_fields().
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param string[]                $fields   The names of the fields.
		 * @param Provider_Interface|null $provider The provider, null for all providers.
		 */
		return (array) apply_filters( 'now_pages_threadi_content_filter_fields', $fields, $provider );
	}

	/**
	 * Tell the user once which of the given rules do not work.
	 *
	 * @param string                  $value    The raw value, one rule per line.
	 * @param Provider_Interface|null $provider The provider of the source the rules belong to, null for the site-wide rules.
	 *
	 * @return int How many lines were reported.
	 */
	public function report( string $value, ?Provider_Interface $provider = null ): int {
		$fields   = $this->get_available_fields( $provider );
		$problems = self::validate( $value, $fields );

		// bail if every line does what it says.
		if ( empty( $problems ) ) {
			return 0;
		}

		$items = '';

		foreach ( $problems as $problem ) {
			$items .= '<li><code>' . esc_html( $problem['line'] ) . '</code> - ' . esc_html( $this->get_problem_text( $problem['reason'], $problem['line'], $fields ) ) . '</li>';
		}

		$headline = $provider instanceof Provider_Interface
			? __( 'Some content filter rules of this source do not work as written:', 'now-pages-threadi' )
			: __( 'Some of the site-wide content filter rules do not work as written:', 'now-pages-threadi' );

		// shown once on the next page: no dismissible time, it is gone after that.
		$transient_obj = Transients::get_instance()->add();
		$transient_obj->set_name( 'now_pages_threadi_content_filter_rules' );
		$transient_obj->set_message( '<strong>' . esc_html( $headline ) . '</strong><ul class="ul-disc">' . $items . '</ul>' );
		$transient_obj->set_type( 'error' );
		$transient_obj->save();

		return count( $problems );
	}

	/**
	 * Check the site-wide rules when they are saved.
	 *
	 * @param mixed $value The value being saved.
	 *
	 * @return mixed The value, unchanged: the user decides what to do about the hint.
	 */
	public function report_site_rules( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			$this->report( $value );
		}

		return $value;
	}

	/**
	 * Return why a line does not work, for the user.
	 *
	 * @param string   $reason The reason, see validate().
	 * @param string   $line   The line.
	 * @param string[] $fields The fields which are available.
	 *
	 * @return string
	 */
	private function get_problem_text( string $reason, string $line, array $fields ): string {
		$parts = explode( ':', $line, 4 );

		switch ( $reason ) {
			case 'action':
				return __( 'is ignored: a rule has to start with "include" or "exclude".', 'now-pages-threadi' );
			case 'operator':
				/* translators: %1$s will be replaced by what was entered, %2$s by a list of operators. */
				return sprintf( __( 'is ignored: "%1$s" is not an operator. Possible are: %2$s.', 'now-pages-threadi' ), trim( $parts[2] ?? '' ), implode( ', ', self::OPERATORS ) );
			case 'field':
				/* translators: %1$s will be replaced by a field name, %2$s by a list of field names. */
				return sprintf( __( 'never matches: there is no field "%1$s". Available are: %2$s.', 'now-pages-threadi' ), trim( $parts[1] ?? '' ), implode( ', ', $fields ) );
			case 'regex':
				return __( 'never matches: the regular expression is not valid.', 'now-pages-threadi' );
			default:
				return __( 'is ignored: a rule has four parts, action:field:operator:value.', 'now-pages-threadi' );
		}
	}

	/**
	 * Return the log message for a regular expression which could not be applied.
	 *
	 * @param bool $is_exclude Whether the rule excludes entries.
	 *
	 * @return string The message, with %s for the expression.
	 */
	private function get_regex_error_message( bool $is_exclude ): string {
		// a valid expression which took too long, e.g. by catastrophic backtracking.
		if ( in_array( preg_last_error(), array( PREG_BACKTRACK_LIMIT_ERROR, PREG_RECURSION_LIMIT_ERROR, PREG_JIT_STACKLIMIT_ERROR ), true ) ) {
			/* translators: %s will be replaced by a regular expression. */
			return $is_exclude ? __( 'A regular expression in a content filter rule took too long and was stopped, the entry is skipped: %s', 'now-pages-threadi' ) : __( 'A regular expression in a content filter rule took too long and was stopped, rule ignored: %s', 'now-pages-threadi' );
		}

		/* translators: %s will be replaced by a regular expression. */
		return $is_exclude ? __( 'Invalid regular expression in a content filter rule, the entry is skipped: %s', 'now-pages-threadi' ) : __( 'Invalid regular expression in a content filter rule, rule ignored: %s', 'now-pages-threadi' );
	}
}
