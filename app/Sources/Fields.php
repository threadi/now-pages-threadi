<?php
/**
 * This file contains the main initialization for fields.
 *
 * Definition:
 * Fields are used to manage settings on providers which are assigned to sources.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Sources;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Helper\Instance_Registry;
use ThreadiNowPages\Helper\Singleton_Interface;
use ThreadiNowPages\Helper\Singleton_Trait;
use ThreadiNowPages\Sources\Fields\Number;
use ThreadiNowPages\Sources\Fields\Text;
use ThreadiNowPages\Sources\Fields\Url;

/**
 * Object to initialize the fields.
 */
class Fields implements Singleton_Interface {
	use Singleton_Trait;

	/**
	 * Constructor, not used as this a Singleton object.
	 */
	private function __construct() {}

	/**
	 * Return a field by slug.
	 *
	 * @param string $slug The given slug of the field.
	 *
	 * @return Field_Base|false
	 */
	public function get_field_by_slug( string $slug ): Field_Base|false {
		// get all instances of post types.
		$instances = Instance_Registry::get_instance()->get_new_instances_of( $this->get_fields(), Field_Base::class );

		// initialize them.
		foreach ( $instances as $field ) {
			// bail if slug does not match.
			if ( $field->get_slug() !== $slug ) {
				continue;
			}

			// return the field object.
			return $field;
		}

		// return false as no field could be found.
		return false;
	}

	/**
	 * Return the list of fields.
	 *
	 * @return array<int,class-string<Field_Base>>
	 */
	private function get_fields(): array {
		$fields = array(
			'\ThreadiNowPages\Sources\Fields\Checkbox',
			'\ThreadiNowPages\Sources\Fields\MultiSelect',
			'\ThreadiNowPages\Sources\Fields\Number',
			'\ThreadiNowPages\Sources\Fields\Password',
			'\ThreadiNowPages\Sources\Fields\Select',
			'\ThreadiNowPages\Sources\Fields\Text',
			'\ThreadiNowPages\Sources\Fields\Url',
		);
		/**
		 * Filter the fields for providers on settings for sources.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<int,class-string<Field_Base>> $fields List of field-types.
		 */
		return apply_filters( 'threadi_now_pages_source_fields', $fields );
	}
}
