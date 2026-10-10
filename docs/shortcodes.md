# Shortcodes

"Now Pages" entries can also be displayed using a shortcode:

`[now_pages_threadi_digest]`

The output can be further customized using shortcode parameters. If none are specified, the settings defined in the plugin are used. ## Parameters

### period

- Specifies the period to be considered
- Possible values:
  - day
  - hour
  - live
  - month
  - 2month
  - 6month
  - week

Example: `[now_pages_threadi_digest period="day"]`

### format

- Specifies the format to be used
- Possible values:
  - verbatim
  - narrative

Example: `[now_pages_threadi_digest format="verbatim"]`

### show_time

- Determines whether the entry's timestamp should be displayed
- Possible values:
  - true
  - false

Example: `[now_pages_threadi_digest show_time="true"]`

### delay_hours

- Defines the display delay duration
- Any numerical value of 0 or greater is valid
- Specified in hours
- Can only raise the configured delay (global setting or user setting), never lower it

Example: `[now_pages_threadi_digest delay_hours="24"]`

### group_by_category

- Determines whether entries should be grouped by category
- Possible values:
  - true
  - false

Example: `[now_pages_threadi_digest group_by_category="true"]`

### show_source

- Determines whether the entry source should be displayed
- Possible values:
  - true
  - false

Example: `[now_pages_threadi_digest show_source="true"]`

### link_source

- Determines whether the entry source should be linked
- Only applicable if `show_source` is also set to `true`
- Possible values:
  - true
  - false

Example: `[now_pages_threadi_digest link_source="true"]`

### category

- Specifies the category for which entries should be displayed
- Possible values correspond to the `term_id`s defined in the project categories

Example: `[now_pages_threadi_digest category="42"]`
