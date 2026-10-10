import React from 'react';

const {
    CssStyle,
    StyleContainer,
    TextStyle,
} = window?.divi?.module;

/**
 * Module style component for this module.
 */
export const ModuleStyles = ({
                                 attrs,
                                 elements,
                                 settings,
                                 orderClass,
                                 mode,
                                 state,
                                 noStyleTag
                             }) => (
        <StyleContainer mode={mode} state={state} noStyleTag={noStyleTag}>
            {/* Element: Module */}
            {elements.style({
                attrName:   'module',
                styleProps: {
                    disabledOn: {
                        disabledModuleVisibility: settings?.disabledModuleVisibility,
                    },
                },
            })}
            <CssStyle
                    selector={orderClass}
                    attr={attrs.css}
            />
            <TextStyle
                    selector={`${orderClass} .now-pages-threadi-content`}
                    attr={attrs?.module?.advanced?.text}
                    propertySelectors={{
                        textShadow: {
                            desktop: {
                                value: {
                                    'text-shadow': `${orderClass} .now-pages-threadi-content`,
                                },
                            },
                        },
                    }}
            />
        </StyleContainer>
);
