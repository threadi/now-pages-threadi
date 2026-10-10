((api, createElement, themifyBuilder) => {
  'use strict';

  // Periods, formats and labels come from PHP through a global printed by
  // TB_Digest_Module::print_builder_data(). Builder can only load this
  // module from a plain, static ".js" URL, so the data cannot travel with
  // the file itself — but it can sit in a global next to it. That keeps
  // the lists translated and in sync with whatever is registered through
  // the 'now_pages_threadi_periods' and the format filters.
  const data = window.nowPagesThreadiThemify || {};

  /**
   * Return a list handed over by PHP, or an empty map.
   *
   * @param name The name of the list.
   * @return {Object}
   */
  const getList = (name) => {
    if (!data[name]) {
      return {};
    }

    return data[name];
  };

  /**
   * Return a label handed over by PHP, or the given fallback.
   *
   * The fallback is only reached when the global is missing entirely —
   * an English word is better than an empty field label.
   *
   * @param name The name of the label.
   * @param fallback The text to use if PHP delivered none.
   * @return {string}
   */
  const getLabel = (name, fallback) => {
    if (!data.labels || !data.labels[name]) {
      return fallback;
    }

    return data.labels[name];
  };

  /**
   * Return the value a field should start with.
   *
   * These are the site-wide settings, so a fresh module shows what the
   * now page shows until somebody changes it here.
   *
   * @param name The name of the default.
   * @param fallback The value to use if PHP delivered none.
   * @return {string}
   */
  const getDefault = (name, fallback) => {
    if (!data.default || !data.default[name]) {
      return fallback;
    }

    return data.default[name];
  };

  // the "override_display" switch and the display settings it unlocks,
  // mirrored 1:1 from the Elementor widget so both stay in sync.
  const displayFields = [
    { id: 'show_time', label: 'showTime', fallback: 'Show times' },
    { id: 'show_source', label: 'showSource', fallback: 'Show data source' },
    { id: 'link_source', label: 'linkSource', fallback: 'Link the data source' },
    { id: 'show_period', label: 'showPeriod', fallback: 'Show period of entries' },
    { id: 'group_by_category', label: 'groupByCategory', fallback: 'Group by category' }
  ];

  // a single-option checkbox field is how the Builder represents an on/off
  // switch. Here "options" is a list of {value, label} objects — unlike a
  // select field, which takes a plain map of value => label.
  const boolField = (id, label) => ({
    id: id,
    type: 'checkbox',
    label: label,
    options: [
      { value: 'yes', label: getLabel('yes', 'Yes') }
    ]
  });

  api.ModuleDigest = class extends api.Module {
    constructor(fields) {
      super(fields);
    }

    static getOptions() {
      return [
        {
          id: 'period',
          type: 'select',
          label: getLabel('period', 'Period'),
          options: getList('periods')
        },
        {
          id: 'format',
          type: 'select',
          label: getLabel('format', 'Format'),
          options: getList('formats')
        },
        boolField('override_display', getLabel('overrideDisplay', 'Use own display settings')),
        ...displayFields.map((field) => boolField(field.id, getLabel(field.label, field.fallback)))
      ];
    }

    static default() {
      return {
        period: getDefault('period', 'live'),
        format: getDefault('format', 'verbatim')
      };
    }

    static builderSave(settings) {
      return super.builderSave(settings);
    }
  };
})(tb_app, tb_createElement, themifyBuilder);
