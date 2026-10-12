import React, { useEffect } from 'react';
import { ModuleStyles } from './module-styles';
import { ModuleScriptData } from './module-script-data';
import { moduleClassnames } from './module-classnames';
import { getAttrValue } from './attributes';
const { useFetch } = window?.divi?.rest;
const { ModuleContainer } = window?.divi?.module;

/**
 * Edit component for this module.
 *
 * This defines the preview on the edit screen.
 */
export const edit = ({
  attrs,
  elements,
  id,
  name,
}) => {
  const {
    fetch,
    response,
    isLoading,
  } = useFetch('');

  // the preview has to follow the settings, so every value the render
  // callback reads has to travel along. the defaults match the ones of
  // the render callback: an empty period or format lets the REST endpoint
  // use the defaults of the website.
  const query = new URLSearchParams({
    period:            getAttrValue(attrs, 'period', ''),
    format:            getAttrValue(attrs, 'format', ''),
    override_display:  getAttrValue(attrs, 'overrideDisplay', 'off'),
    show_time:         getAttrValue(attrs, 'showTime', 'off'),
    show_source:       getAttrValue(attrs, 'showSource', 'off'),
    link_source:       getAttrValue(attrs, 'linkSource', 'off'),
    show_period:       getAttrValue(attrs, 'showPeriod', 'off'),
    group_by_category: getAttrValue(attrs, 'groupByCategory', 'off'),
  }).toString();

  useEffect(() => {
    // Fetching data from REST API.
    fetch({
      method:    'GET',
      restRoute: '/threadi-now-pages-divi5/v1/module-data/digest?' + query,
    }).catch(error => {
      console.log(error);
    });
  }, [query]);

  return (
    <ModuleContainer
      attrs={attrs}
      elements={elements}
      id={id}
      name={name}
      scriptDataComponent={ModuleScriptData}
      stylesComponent={ModuleStyles}
      classnamesFunction={moduleClassnames}
    >
      {elements.styleComponents({
        attrName: 'module',
      })}
      <div>
        {isLoading && <div>Loading...</div>}
        {!isLoading && (
          <div
            className="threadi-now-pages-content"
            dangerouslySetInnerHTML={{ __html: response?.data ?? '' }}
          />
        )}
      </div>
    </ModuleContainer>
  );
}
