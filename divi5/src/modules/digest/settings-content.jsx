import React from 'react';

const {
  AdminLabelGroup,
  BackgroundGroup,
} = window?.divi?.module;

/**
 * Define the contents for "Content" tab in Divi.
 */
export const SettingsContent = ({ defaultSettingsAttrs }) => (
  <React.Fragment>
    <BackgroundGroup />
    <AdminLabelGroup
      defaultGroupAttr={defaultSettingsAttrs?.module?.meta?.adminLabel ?? {}}
    />
  </React.Fragment>
);
