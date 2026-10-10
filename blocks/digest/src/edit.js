/**
 * Add individual dependencies.
 */
import {
  useBlockProps,
  InspectorControls
} from '@wordpress/block-editor';
import {
  PanelBody,
  SelectControl,
  ToggleControl
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

/**
 * Return a list handed over by PHP.
 *
 * Period, format and the defaults of the display settings stay defined in
 * exactly one place — see Gutenberg::register_block().
 *
 * @param name The name of the list.
 * @return {Array|Object} The list, empty if PHP delivered none.
 */
const getList = ( name ) => {
  if ( typeof nowPagesThreadiDigestBlock === 'undefined' ) {
    return [];
  }

  if ( ! nowPagesThreadiDigestBlock[ name ] ) {
    return [];
  }

  return nowPagesThreadiDigestBlock[ name ];
};

/**
 * Return a list of options, starting with the default of the website.
 *
 * An empty value lets the block follow the settings of the website.
 *
 * @param name The name of the list.
 * @return {Array} The options.
 */
const getOptions = ( name ) => {
  return [
    { label: __( '- Use the default of the website -', 'now-pages-threadi' ), value: '' },
    ...getList( name )
  ];
};

/**
 * The edit function describes the structure of your block in the context of the
 * editor. This represents what the editor will render when the block is used.
 *
 * @see https://developer.wordpress.org/block-editor/developers/block-api/block-edit-save/#edit
 *
 * @param object
 * @return {WPElement} Element to render.
 */
export default function Edit( object ) {
  /**
   * The narrative format ignores the per-entry settings, so say that
   * instead of letting people wonder why times do not show up.
   *
   * @type {string}
   */
  let format_help = __( 'Lists every entry on its own line.', 'now-pages-threadi' );

  if ( 'narrative' === object.attributes.format ) {
    format_help = __( 'Turns the entries into a continuous text. Times and sources are not shown in this format.', 'now-pages-threadi' );
  }

  /**
   * Switching the override on starts from what the site is configured to
   * do, so nothing changes visually until the user actually changes one.
   */
  const onOverrideChange = ( value ) => {
    if ( ! value ) {
      object.setAttributes( { override_display: false } );

      return;
    }

    const defaults = getList( 'display_defaults' );

    object.setAttributes( {
      override_display:  true,
      show_time:         !! defaults.show_time,
      show_source:       !! defaults.show_source,
      link_source:       !! defaults.link_source,
      show_period:       !! defaults.show_period,
      group_by_category: !! defaults.group_by_category
    } );
  };

  /**
   * Collect return for the edit-function
   */
  return (
    <>
      <InspectorControls>
        <PanelBody title={ __( 'Settings', 'now-pages-threadi' ) }>
          <SelectControl
            label={ __( 'Period', 'now-pages-threadi' ) }
            help={ __( 'Which period the shown entries are from.', 'now-pages-threadi' ) }
            value={ object.attributes.period }
            options={ getOptions( 'periods' ) }
            onChange={ ( value ) => object.setAttributes( { period: value } ) }
            __next40pxDefaultSize
            __nextHasNoMarginBottom
          />
          <SelectControl
            label={ __( 'Format', 'now-pages-threadi' ) }
            help={ format_help }
            value={ object.attributes.format }
            options={ getOptions( 'formats' ) }
            onChange={ ( value ) => object.setAttributes( { format: value } ) }
            __next40pxDefaultSize
            __nextHasNoMarginBottom
          />
        </PanelBody>
        <PanelBody title={ __( 'Display', 'now-pages-threadi' ) } initialOpen={ false }>
          <ToggleControl
            label={ __( 'Use own display settings', 'now-pages-threadi' ) }
            help={ __( 'Without this the block follows the settings of your website, and follows them when you change them later.', 'now-pages-threadi' ) }
            checked={ !! object.attributes.override_display }
            onChange={ onOverrideChange }
            __nextHasNoMarginBottom
          />
          { object.attributes.override_display && (
            <>
              <ToggleControl
                label={ __( 'Show times', 'now-pages-threadi' ) }
                checked={ !! object.attributes.show_time }
                onChange={ ( value ) => object.setAttributes( { show_time: value } ) }
                __nextHasNoMarginBottom
              />
              <ToggleControl
                label={ __( 'Show data source', 'now-pages-threadi' ) }
                checked={ !! object.attributes.show_source }
                onChange={ ( value ) => object.setAttributes( { show_source: value } ) }
                __nextHasNoMarginBottom
              />
              { object.attributes.show_source && (
                <ToggleControl
                  label={ __( 'Link the data source', 'now-pages-threadi' ) }
                  help={ __( 'Links each source to the original entry, if the source delivered a URL.', 'now-pages-threadi' ) }
                  checked={ !! object.attributes.link_source }
                  onChange={ ( value ) => object.setAttributes( { link_source: value } ) }
                  __nextHasNoMarginBottom
                />
              ) }
              <ToggleControl
                label={ __( 'Show period of entries', 'now-pages-threadi' ) }
                checked={ !! object.attributes.show_period }
                onChange={ ( value ) => object.setAttributes( { show_period: value } ) }
                __nextHasNoMarginBottom
              />
              <ToggleControl
                label={ __( 'Group by category', 'now-pages-threadi' ) }
                checked={ !! object.attributes.group_by_category }
                onChange={ ( value ) => object.setAttributes( { group_by_category: value } ) }
                __nextHasNoMarginBottom
              />
            </>
          ) }
        </PanelBody>
      </InspectorControls>
      <div { ...useBlockProps() }>
        <ServerSideRender
          block="now-pages-threadi/digest"
          attributes={ object.attributes }
          httpMethod="POST"
        />
      </div>
    </>
  );
}
