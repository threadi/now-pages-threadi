<?php
/**
 * This file contains the main initialization for fields.
 *
 * Definition:
 * Fields are used to manage settings on providers which are assigned to sources.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Sources;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Helper\Instance_Registry;
use NowPagesThreadi\Helper\Singleton_Interface;
use NowPagesThreadi\Helper\Singleton_Trait;
use NowPagesThreadi\Sources\Fields\Number;
use NowPagesThreadi\Sources\Fields\Text;
use NowPagesThreadi\Sources\Fields\Url;

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
			'\NowPagesThreadi\Sources\Fields\Checkbox',
			'\NowPagesThreadi\Sources\Fields\MultiSelect',
			'\NowPagesThreadi\Sources\Fields\Number',
			'\NowPagesThreadi\Sources\Fields\Password',
			'\NowPagesThreadi\Sources\Fields\Select',
			'\NowPagesThreadi\Sources\Fields\Text',
			'\NowPagesThreadi\Sources\Fields\Url',
		);
		/**
		 * Filter the fields for providers on settings for sources.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<int,class-string<Field_Base>> $fields List of field-types.
		 */
		return apply_filters( 'now_pages_threadi_source_fields', $fields );
	}
}
