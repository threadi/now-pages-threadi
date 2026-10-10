import React from 'react';

const {
  CssGroup,
  IdClassesGroup,
  PositionSettingsGroup,
  ScrollSettingsGroup,
  TransitionGroup,
  VisibilitySettingsGroup,
} = window?.divi?.module;

/**
 * Advanced Settings panel for the Dynamic Module.
 */
export const SettingsAdvanced = () => (
  <React.Fragment>
    <IdClassesGroup />
    <CssGroup
      mainSelector=".now-pages-threadi-digest" // This is the main selector for the module.
    />
    <VisibilitySettingsGroup />
    <TransitionGroup />
    <PositionSettingsGroup />
    <ScrollSettingsGroup />
  </React.Fragment>
);
