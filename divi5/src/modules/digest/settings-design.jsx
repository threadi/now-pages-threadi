import React from 'react';

const {
  AnimationGroup,
  BorderGroup,
  BoxShadowGroup,
  FiltersGroup,
  SizingGroup,
  SpacingGroup,
  TextGroup,
  TransformGroup,
} = window?.divi?.module;


/**
 * Design Settings panel for the Dynamic Module.
 */
export const SettingsDesign = ({ defaultSettingsAttrs }) => (
  <React.Fragment>
    <TextGroup defaultGroupAttr={defaultSettingsAttrs?.module?.advanced?.text} />
    <SizingGroup />
    <SpacingGroup />
    <BorderGroup />
    <BoxShadowGroup />
    <FiltersGroup />
    <TransformGroup />
    <AnimationGroup />
  </React.Fragment>
);
