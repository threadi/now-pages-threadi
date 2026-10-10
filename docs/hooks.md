# Hooks

- [Actions](#actions)
- [Filters](#filters)

## Actions

### `now_pages_threadi_setup_source_created`

*Fires after the setup created a source from a preset.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`absint($post_id)` |  |
`$preset` | `\NowPagesThreadi\Presets\Preset_Interface` | The used preset.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Setup.php](Plugin/Setup.php), [line 1322](Plugin/Setup.php#L1322-L1330)

### `now_pages_threadi_setup_completed`

*Run additional tasks if the setup is marked as completed.*


**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Setup.php](Plugin/Setup.php), [line 1387](Plugin/Setup.php#L1387-L1392)

### `now_pages_threadi_entry_added`

*Fires after a new entry has been stored.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`(int) $post_id` |  |
`$entry` | `\NowPagesThreadi\NowPages\Entry` | The entry object it was created from.
`$sources_id` | `int` | The ID of the source it came from (0 = none).

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/NowPages/Entries.php](NowPages/Entries.php), [line 203](NowPages/Entries.php#L203-L212)

### `now_pages_threadi_cleanup_done`

*Run additional tasks after old entries have been deleted.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$deleted` | `int` | How many entries were deleted.
`$days` | `int` | The age in days.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/NowPages/Cleanup.php](NowPages/Cleanup.php), [line 395](NowPages/Cleanup.php#L395-L403)

### `now_pages_threadi_entry_confirmed`

*Fires after the user confirmed an entry which was waiting.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$entry_id` | `int` | The ID of the entry.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/NowPages/Confirmation.php](NowPages/Confirmation.php), [line 156](NowPages/Confirmation.php#L156-L163)

### `now_pages_threadi_entry_discarded`

*Fires after an entry which was waiting has been discarded.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$entry_id` | `int` | The ID of the entry.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/NowPages/Confirmation.php](NowPages/Confirmation.php), [line 191](NowPages/Confirmation.php#L191-L198)

### `now_pages_threadi_category_assign_error`

*Fires when assigning a category to an entry failed.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$result` | `\WP_Error` | The error.
`$post_id` | `int` | Entry post ID.
`$term_id` | `int` | Requested term ID.

Source: [app/NowPages/Categories.php](NowPages/Categories.php), [line 460](NowPages/Categories.php#L460-L467)

### `now_pages_threadi_category_assign_error`

*This hook is documented in app/NowPages/Categories.php.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$result` |  |
`$entry_id` |  |
`$term_id` |  |

Source: [app/NowPages/Categories.php](NowPages/Categories.php), [line 558](NowPages/Categories.php#L558-L561)

### `now_pages_threadi_source_category_changed`

*Fires after the entries of a source followed the category of this source.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$source_id` | `int` | The source post ID.
`$term_id` | `int` | The category of the source now (0 = none).
`$previous` | `int` | The category of the source before (0 = none).
`$changed` | `int` | The number of entries which were changed.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/NowPages/Categories.php](NowPages/Categories.php), [line 570](NowPages/Categories.php#L570-L580)

### `now_pages_threadi_entry_received`

*This hook is documented in app/NowPages/Entries.php.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$entry` |  |
`$source_id` |  |

Source: [app/NowPages/Abilities.php](NowPages/Abilities.php), [line 1207](NowPages/Abilities.php#L1207-L1210)

### `now_pages_threadi_provider_error`

*This action is documented in app/Providers/RSS_Provider.php*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$this->get_slug()` |  |
`$error` |  |

Source: [app/Providers/HTTP_Provider_Base.php](Providers/HTTP_Provider_Base.php), [line 217](Providers/HTTP_Provider_Base.php#L217-L218)

### `now_pages_threadi_entry_received`

*Fires once a normalized entry has been received, from any provider (pull or push).*

The actual now_page_entries-post is created by whatever hooks into this
(keyword-filter, dedupe-check, storage), so both add paths converge here.

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$entry` | `\NowPagesThreadi\NowPages\Entry` | The normalized entry.
`$source_id` | `int` | The ID of the source.

Source: [app/Providers/Webhook_Controller.php](Providers/Webhook_Controller.php), [line 401](Providers/Webhook_Controller.php#L401-L410)

### `now_pages_threadi_register_providers`

*Fires once the built-in providers are registered.*

Use this to add further providers (e.g. custom Discord/LinkedIn
clients) from your own code, without touching this plugin.

Example:
add_action( 'now_pages_threadi_register_providers', function( $providers ) {
    $providers->register_provider( new My_Provider() );
} );

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$interface` | `\NowPagesThreadi\Providers\Providers` | The providers objects.

Source: [app/Providers/Providers.php](Providers/Providers.php), [line 90](Providers/Providers.php#L90-L103)

### `now_pages_threadi_provider_error`

*Fires when a provider fails to fetch data, for the central logging requirement.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$this->get_slug()` |  |
`$feed` |  |

Source: [app/Providers/RSS_Provider.php](Providers/RSS_Provider.php), [line 108](Providers/RSS_Provider.php#L108-L114)

### `now_pages_threadi_fediverse_update_created`

*Fires after an update for the Fediverse has been created.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`absint($post_id)` |  |
`$entry_id` | `int` | The ID of the entry it shares, 0 for a summary.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Fediverse/Fediverse.php](Fediverse/Fediverse.php), [line 748](Fediverse/Fediverse.php#L748-L756)

### `now_pages_threadi_advisor_source_created`

*Fires after a source has been created from a proposal of the advisor.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$created['source_id']` |  |
`$proposal` | `array<string,mixed>` | The proposal it was created from.
`$created['status']` |  |

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Sources/Advisor.php](Sources/Advisor.php), [line 630](Sources/Advisor.php#L630-L639)

### `now_pages_threadi_source_created`

*Fires after a source has been created by the setup, an ability or the advisor.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`absint($post_id)` |  |
`$provider_slug` | `string` | The slug of its provider.
`$args` | `array<string,mixed>` | The arguments it was created with.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Sources/Source_Builder.php](Sources/Source_Builder.php), [line 695](Sources/Source_Builder.php#L695-L704)

### `now_pages_threadi_provider_error`

*Return the resolved provider + configuration for every published source.*

Centralizes what Cli::request_sources() and Scheduler::run() both need,
so pull-based add stays consistent regardless of who triggers it -
and so the source_id always travels along in the exact same shape.

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$provider_slug` |  |
`new WP_Error('now_pages_threadi_unknown_provider', sprintf(
    /* translators: %1$s will be replaced by a provider slug, %2$s by the title of a source. */
    __('The provider %1$s configured on the source %2$s does not exist. It was probably provided by a plugin that is no longer active.', 'now-pages-threadi'),
    '<code>' . esc_html($provider_slug) . '</code>',
    '<a href="' . esc_url((string) get_edit_post_link($post_id, '')) . '">' . esc_html(get_the_title($post_id)) . '</a>'
), array('source_id' => $post_id, 'formatted' => true))` |  |

Source: [app/Sources/Sources.php](Sources/Sources.php), [line 751](Sources/Sources.php#L751-L808)

### `now_pages_threadi_provider_error`

*Run the import of all configured sources.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$source['provider']->get_slug()` |  |
`new WP_Error('now_pages_threadi_provider_exception', $e->getMessage(), array('source_id' => absint($source['source_id'])))` |  |

Source: [app/Sources/Sources.php](Sources/Sources.php), [line 1283](Sources/Sources.php#L1283-L1374)

### `now_pages_threadi_entry_received`

*Add a new entry for now pages.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$entry` | `\NowPagesThreadi\NowPages\Entry` | The entry object.
`$source['source_id']` |  |

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Sources/Sources.php](Sources/Sources.php), [line 1428](Sources/Sources.php#L1428-L1435)

### `now_pages_threadi_source_duplicate`

*Run additional tasks after a duplicate was detected.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$provider_obj` | `\NowPagesThreadi\Providers\Provider_Interface` | The provider.
`$duplicate` | `int` | Amount of duplicates.
`$post_id` | `int` | The post ID which is a duplicate.
`$identity` | `array` | The list of parameters to identify the duplicate.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Sources/Sources.php](Sources/Sources.php), [line 1746](Sources/Sources.php#L1746-L1755)

### `now_pages_threadi_register_presets`

*Fires once the built-in presets are registered.*

Use this to add your own services to the list shown when a new
source is created.

Example:
add_action( 'now_pages_threadi_register_presets', function( $presets ) {
    $presets->register_preset( new My_Preset() );
} );

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$interface` | `\NowPagesThreadi\Presets\Presets` | The presets object.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Presets/Presets.php](Presets/Presets.php), [line 77](Presets/Presets.php#L77-L92)

### `now_pages_threadi_summarizer_request`

*Turn the given entries into a narrative summary via an AI model.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`array('summarizer' => $this->get_slug(), 'source' => 'cache', 'success' => true, 'entries' => count($entries), 'chars_in' => 0, 'chars_out' => mb_strlen($cached), 'duration' => 0, 'language' => $language)` |  |

Source: [app/Digests/Summarizers/Ai_Summarizer.php](Digests/Summarizers/Ai_Summarizer.php), [line 99](Digests/Summarizers/Ai_Summarizer.php#L99-L144)

### `now_pages_threadi_summarizer_request`

*Fires after a summarizer request has finished.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`array('summarizer' => $this->get_slug(), 'source' => 'api', 'success' => !is_wp_error($result), 'entries' => $entry_count, 'chars_in' => mb_strlen($this->build_raw_text($entries)), 'chars_out' => is_wp_error($result) ? 0 : mb_strlen((string) $result), 'duration' => (int) round((microtime(true) - $started) * 1000), 'language' => $language)` |  |

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Digests/Summarizers/Ai_Summarizer.php](Digests/Summarizers/Ai_Summarizer.php), [line 195](Digests/Summarizers/Ai_Summarizer.php#L195-L223)

### `now_pages_threadi_register_summarizers`

*Fires once the built-in summarizers are registered.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$interface` | `\NowPagesThreadi\Digests\Summarizers` | The summarizers object.

Source: [app/Digests/Summarizers.php](Digests/Summarizers.php), [line 57](Digests/Summarizers.php#L57-L62)

### `now_pages_threadi_register_digest_formats`

*Fires once the built-in digest formats are registered.*

Use this to add further formats from your own code, without
touching this plugin:
add_action( 'now_pages_threadi_register_digest_formats', function ( $formats ) {
    $formats->register_format( new My_Format() );
} );

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$interface` | `\NowPagesThreadi\Digests\Digests` | The digests object.

Source: [app/Digests/Digests.php](Digests/Digests.php), [line 54](Digests/Digests.php#L54-L65)

### `now_pages_threadi_digest_error`

*Render the given entries as a narrative summary.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`new WP_Error('now_pages_threadi_summarizer_unavailable', __('The configured summarizer is not available.', 'now-pages-threadi'))` |  |
`$summarizer_slug` |  |
`''` |  |

Source: [app/Digests/Formats/Narrative_Format.php](Digests/Formats/Narrative_Format.php), [line 67](Digests/Formats/Narrative_Format.php#L67-L99)

### `now_pages_threadi_digest_error`

*Fires when a narrative summary could not be generated.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$text` | `\WP_Error` | The resulting error.
`$summarizer_slug` |  |
`$label` | `string` | Category label of the failed block (empty if ungrouped).

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Digests/Formats/Narrative_Format.php](Digests/Formats/Narrative_Format.php), [line 216](Digests/Formats/Narrative_Format.php#L216-L225)

## Filters

### `now_pages_threadi_report_digest_args`

*Filter the digest args used for the report email.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$args` | `array<string,mixed>` | Builder args.
`$start` | `string` | Start of the period.
`$end` | `string` | End of the period.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Emails/Report.php](Plugin/Emails/Report.php), [line 367](Plugin/Emails/Report.php#L367-L376)

### `now_pages_threadi_schedule_interval`

*Filter the interval to a single schedule.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$interval` | `string` | The interval.
`$instance` | `\NowPagesThreadi\Plugin\Schedules_Base` | The schedule-object.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Schedules_Base.php](Plugin/Schedules_Base.php), [line 85](Plugin/Schedules_Base.php#L85-L92)

### `now_pages_threadi_schedule_enabling`

*Filter whether to activate this schedule.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$false` | `bool` | True if this object should NOT be enabled.
`$instance` | `\NowPagesThreadi\Plugin\Schedules_Base` | Actual object.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Schedules_Base.php](Plugin/Schedules_Base.php), [line 251](Plugin/Schedules_Base.php#L251-L261)

### `now_pages_threadi_set_template_directory`

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$directory` |  |

Source: [app/Plugin/Templates.php](Plugin/Templates.php), [line 92](Plugin/Templates.php#L92-L92)

### `now_pages_threadi_set_template_directory`

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$directory` |  |

Source: [app/Plugin/Templates.php](Plugin/Templates.php), [line 131](Plugin/Templates.php#L131-L131)

### `now_pages_threadi_import_error_support_hint`

*Filter the support part of an email.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$support_part` | `string` | The text to use.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Email_Base.php](Plugin/Email_Base.php), [line 312](Plugin/Email_Base.php#L312-L318)

### `now_pages_threadi_email_headers`

*Filter the email header.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$headers` | `array<int,string>` | List of headers.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Email_Base.php](Plugin/Email_Base.php), [line 428](Plugin/Email_Base.php#L428-L434)

### `now_pages_threadi_intervals`

*Filter the list of possible intervals.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$list` | `array<int,class-string<\NowPagesThreadi\Plugin\Interval_Base>>` | List of our interval objects.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Intervals.php](Plugin/Intervals.php), [line 96](Plugin/Intervals.php#L96-L102)

### `now_pages_threadi_emails`

*Filter the list of possible email objects.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$trigger` | `array<int,string>` | List of Email trigger objects.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Emails.php](Plugin/Emails.php), [line 99](Plugin/Emails.php#L99-L105)

### `now_pages_threadi_setup_is_completed`

*Filter the setup complete marker.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$completed` | `bool` | True if setup has been completed.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Setup.php](Plugin/Setup.php), [line 220](Plugin/Setup.php#L220-L226)

### `now_pages_threadi_setup`

*Filter the configured setup for this plugin.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$setup` | `array<int,array<string,mixed>>` | The setup-configuration.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Setup.php](Plugin/Setup.php), [line 296](Plugin/Setup.php#L296-L303)

### `now_pages_threadi_setup_config`

*Filter the setup configuration.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$config` | `array<string,array<int,mixed>\|bool\|string>` | List of configuration for the setup.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Setup.php](Plugin/Setup.php), [line 405](Plugin/Setup.php#L405-L411)

### `now_pages_threadi_setup_process_completed_text`

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$completed_text` |  |
`$config_name` |  |

Source: [app/Plugin/Setup.php](Plugin/Setup.php), [line 1360](Plugin/Setup.php#L1360-L1360)

### `now_pages_threadi_log_table_filter`

*Filter the list before output.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$list` | `array<string,string>` | List of filter.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Log_Table.php](Plugin/Log_Table.php), [line 315](Plugin/Log_Table.php#L315-L321)

### `now_pages_threadi_status_list`

*Filter the list of possible states in the log table.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$list` |  |

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Log_Table.php](Plugin/Log_Table.php), [line 374](Plugin/Log_Table.php#L374-L379)

### `now_pages_threadi_objects_with_db_tables`

*Add additional objects for this plugin, which use custom tables.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$objects` | `array<int,string>` | List of objects.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Init.php](Plugin/Init.php), [line 205](Plugin/Init.php#L205-L211)

### `now_pages_threadi_log_without_debug`

*Filter whether a log entry should be written when debug mode is disabled.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$should_log` | `bool` | Whether the entry should be logged.
`$category` | `string` | The log entry category.
`$state` | `string` | The log entry state.
`$log` | `string` | The log message.
`$md5` | `string` | The unique marker.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Log.php](Plugin/Log.php), [line 173](Plugin/Log.php#L173-L184)

### `now_pages_threadi_log_with_debug`

*Filter whether a log entry should be written when debug mode is enabled.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$should_log` | `bool` | Whether the entry should be logged.
`$category` | `string` | The log entry category.
`$state` | `string` | The log entry state.
`$log` | `string` | The log message.
`$md5` | `string` | The unique marker.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Log.php](Plugin/Log.php), [line 200](Plugin/Log.php#L200-L211)

### `now_pages_threadi_log_categories`

*Filter the list of possible log categories.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$list` | `array<string,string>` | List of categories.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Log.php](Plugin/Log.php), [line 312](Plugin/Log.php#L312-L319)

### `now_pages_threadi_log_limit`

*Filter limit to prevent possible errors on big tables.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$limit` | `int` | The actual limit.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Log.php](Plugin/Log.php), [line 343](Plugin/Log.php#L343-L349)

### `now_pages_threadi_log_category`

*Filter the used category.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$category` | `string` | The category to use.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Log.php](Plugin/Log.php), [line 354](Plugin/Log.php#L354-L360)

### `now_pages_threadi_log_md5`

*Filter the used md5.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$md5` | `string` | The md5 to use.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Log.php](Plugin/Log.php), [line 370](Plugin/Log.php#L370-L376)

### `now_pages_threadi_log_errors`

*Filter for errors.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$errors` | `int` | Should be 1 to filter only for errors.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Log.php](Plugin/Log.php), [line 381](Plugin/Log.php#L381-L387)

### `now_pages_threadi_register_post_type`

*Filter the post-types.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$post_types` | `array<int,class-string<\NowPagesThreadi\Plugin\Post_Type_Base>>` | List of post-types.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Post_Types.php](Plugin/Post_Types.php), [line 66](Plugin/Post_Types.php#L66-L73)

### `now_pages_threadi_knowledge_center_entries`

*Filter the list of available knowledge center entries.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$entries` | `array<int,class-string<\NowPagesThreadi\Plugin\KnowledgeCenter_Base>>` | List of knowledge center entries.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/KnowledgeCenter.php](Plugin/KnowledgeCenter.php), [line 79](Plugin/KnowledgeCenter.php#L79-L85)

### `now_pages_threadi_show_help`

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$allowed` |  |
`$screen` |  |

Source: [app/Plugin/KnowledgeCenter.php](Plugin/KnowledgeCenter.php), [line 123](Plugin/KnowledgeCenter.php#L123-L123)

### `now_pages_threadi_help_tabs`

*Filter the list of help tabs with its contents.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$list` | `array<string,mixed>` | List of help tabs.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/KnowledgeCenter.php](Plugin/KnowledgeCenter.php), [line 152](Plugin/KnowledgeCenter.php#L152-L158)

### `now_pages_threadi_help_sidebar_content`

*Filter the sidebar content.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$sidebar_content` | `string` | The content.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/KnowledgeCenter.php](Plugin/KnowledgeCenter.php), [line 172](Plugin/KnowledgeCenter.php#L172-L178)

### `now_pages_threadi_hide_intro`

*Hide intro via hook.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$false` | `bool` | Return true to hide the intro.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0

Source: [app/Plugin/Intro.php](Plugin/Intro.php), [line 77](Plugin/Intro.php#L77-L85)

### `now_pages_threadi_dashboard_widgets`

*Filter the dashboard-widgets used by this plugin.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$dashboard_widgets` | `array<string,array<string,mixed>>` | List of widgets.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Admin/Dashboard.php](Plugin/Admin/Dashboard.php), [line 85](Plugin/Admin/Dashboard.php#L85-L91)

### `now_pages_threadi_export_filename`

*Filter the filename for CSV-download.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$filename` | `string` | The generated filename for CSV-download.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Admin/Admin.php](Plugin/Admin/Admin.php), [line 157](Plugin/Admin/Admin.php#L157-L164)

### `now_pages_threadi_schedule_our_events`

*Filter the list of our own events, e.g., to check if all, which are enabled in setting are active.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$our_events` | `array<string,array<string,mixed>>` | List of our own events in WP-cron.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Schedules.php](Plugin/Schedules.php), [line 152](Plugin/Schedules.php#L152-L159)

### `now_pages_threadi_disable_cron_check`

*Disable the additional cron check.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$false` | `bool` | True if the check should be disabled.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Schedules.php](Plugin/Schedules.php), [line 177](Plugin/Schedules.php#L177-L185)

### `now_pages_threadi_schedules`

*Add custom schedule-objects to use.*

They must be objects based on NowPagesThreadi\Plugin\Schedules_Base.

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$list_of_schedules` | `string[]` | List of additional schedules.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Schedules.php](Plugin/Schedules.php), [line 335](Plugin/Schedules.php#L335-L344)

### `now_pages_threadi_current_language`

*Filter the current front-end language (2-letter code).*

Example: add_filter( 'now_pages_threadi_current_language', function () {
    return substr( apply_filters( 'wpml_current_language', get_locale() ), 0, 5 );
} );

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`substr(get_locale(), 0, 5)` |  |

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Helper/Languages.php](Helper/Languages.php), [line 30](Helper/Languages.php#L30-L40)

### `now_pages_threadi_entry_text_pre_linkify`

*Link URLs in a given text.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$text` | `string` | The text.

Source: [app/Helper/Format.php](Helper/Format.php), [line 82](Helper/Format.php#L82-L94)

### `now_pages_threadi_entry_text_linkified`

*Link URLs in a given text.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$html` |  |
`$text` | `string` | The text.

Source: [app/Helper/Format.php](Helper/Format.php), [line 82](Helper/Format.php#L82-L96)

### `now_pages_threadi_file_version`

*Filter the used file version (for JS- and CSS-files, which get enqueued).*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$plugin_version` | `string` | The plugin-version.
`$filepath` | `string` | The absolute path to the requested file.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Helper/Plugin.php](Helper/Plugin.php), [line 60](Helper/Plugin.php#L60-L68)

### `now_pages_threadi_sitemap_entry`

*Filter a single now-page sitemap entry.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$entry` | `array<string,mixed>` | The sitemap entry (loc/lastmod/changefreq/priority only).
`null` |  |

Source: [app/NowPages/Sitemap.php](NowPages/Sitemap.php), [line 126](NowPages/Sitemap.php#L126-L132)

### `now_pages_threadi_sitemap_entry`

*This filter is documented above.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$entry` |  |
`$user` |  |

Source: [app/NowPages/Sitemap.php](NowPages/Sitemap.php), [line 150](NowPages/Sitemap.php#L150-L151)

### `now_pages_threadi_frontend_digest_args`

*This filter is documented in Frontend::prepare().*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$args` |  |
`null` |  |

Source: [app/NowPages/Sitemap.php](NowPages/Sitemap.php), [line 227](NowPages/Sitemap.php#L227-L228)

### `now_pages_threadi_entry_is_manual`

*Filter whether an entry counts as manually created.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$is_manual` | `bool` | Whether the entry was created by hand.
`$entry` | `\WP_Post` | The entry.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/NowPages/Entries.php](NowPages/Entries.php), [line 740](NowPages/Entries.php#L740-L748)

### `now_pages_threadi_cleanup_max_per_run`

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$max_per_run` |  |
`$days` |  |

Source: [app/NowPages/Cleanup.php](NowPages/Cleanup.php), [line 332](NowPages/Cleanup.php#L332-L332)

### `now_pages_threadi_page_builder_for_page`

*Filter the page builder used to prefill a new now page.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$active` | `\NowPagesThreadi\PageBuilder\PageBuilder_Base\|null` | The chosen page builder.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/NowPages/Now_Page.php](NowPages/Now_Page.php), [line 356](NowPages/Now_Page.php#L356-L363)

### `now_pages_threadi_content_filter_rules`

*Filter the content filter rules applied to an entry before it is imported.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$rules` | `array<int,array{action: string, field: string, operator: string, value: string}>` | The parsed rules.
`$sources_id` | `int` | The ID of the used source.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/NowPages/Content_Filters.php](NowPages/Content_Filters.php), [line 282](NowPages/Content_Filters.php#L282-L290)

### `now_pages_threadi_content_filter_fields`

*Filter the fields content filter rules may use.*

Add a field here if entries get it in another way than through
Provider_Interface::get_filterable_fields().

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$fields` | `string[]` | The names of the fields.
`$provider` | `\NowPagesThreadi\Providers\Provider_Interface\|null` | The provider, null for all providers.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/NowPages/Content_Filters.php](NowPages/Content_Filters.php), [line 458](NowPages/Content_Filters.php#L458-L469)

### `now_pages_threadi_category_taxonomy_args`

*Filter the arguments passed to register_taxonomy() for "now_pages_threadi_category".*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$args` | `array<string,mixed>` | Taxonomy args.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/NowPages/Categories.php](NowPages/Categories.php), [line 227](NowPages/Categories.php#L227-L234)

### `now_pages_threadi_category_object_types`

*Filter which post types the "now_pages_threadi_category" taxonomy is attached to.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$object_types` | `array<int,string>` | Post type slugs.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/NowPages/Categories.php](NowPages/Categories.php), [line 241](NowPages/Categories.php#L241-L248)

### `now_pages_threadi_narrative_category_templates`

*Filter category-slug → sentence template map (fallback when term meta is empty).*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`array()` |  |

Source: [app/NowPages/Categories.php](NowPages/Categories.php), [line 730](NowPages/Categories.php#L730-L735)

### `now_pages_threadi_is_public`

*Filter whether the now page is publicly available.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$is_public` | `bool` | True if the page may be shown.
`$for_author` | `bool` | True if the author route is meant.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/NowPages/Frontend.php](NowPages/Frontend.php), [line 465](NowPages/Frontend.php#L465-L473)

### `now_pages_threadi_frontend_digest_args`

*Filter the Digest_Builder args used for the /now frontend routes.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$args` | `array<string,mixed>` | Builder args.
`$this->user` |  |

Source: [app/NowPages/Frontend.php](NowPages/Frontend.php), [line 522](NowPages/Frontend.php#L522-L528)

### `now_pages_threadi_frontend_content`

*Filter the digest HTML body (not the full document).*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$this->content` |  |
`$this->user` |  |
`$this->args` |  |

Source: [app/NowPages/Frontend.php](NowPages/Frontend.php), [line 562](NowPages/Frontend.php#L562-L569)

### `now_pages_threadi_frontend_templates`

*Filter the list of template names looked up in the theme.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`array('now-pages-threadi/now-pages-threadi.php', 'now-pages-threadi.php', 'now.php')` |  |
`$this->user` |  |

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/NowPages/Frontend.php](NowPages/Frontend.php), [line 838](NowPages/Frontend.php#L838-L854)

### `now_pages_threadi_frontend_template`

*Filter the resolved template path.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$located` | `string` | Absolute path or empty string.
`$templates` | `array<int,string>` | Candidate file names.
`$this->user` |  |

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/NowPages/Frontend.php](NowPages/Frontend.php), [line 871](NowPages/Frontend.php#L871-L880)

### `now_pages_threadi_frontend_digest_args`

*Filter the Digest_Builder args used for the /now frontend routes.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$args` | `array<string,mixed>` | Builder args.
`$user` | `\WP_User\|null` | Author for /author/…/now, null for global /now.

Source: [app/NowPages/Frontend.php](NowPages/Frontend.php), [line 1306](NowPages/Frontend.php#L1306-L1312)

### `now_pages_threadi_frontend_digest_args`

*This hook is documented in app/NowPages/Frontend.php.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$args` |  |
`$user` |  |

Source: [app/NowPages/Abilities.php](NowPages/Abilities.php), [line 2229](NowPages/Abilities.php#L2229-L2232)

### `now_pages_threadi_provider_request_args`

*Filter the arguments of a provider request.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$args` | `array<string,mixed>` | The arguments for wp_remote_get().
`$url` | `string` | The URL being requested.
`$this->get_slug()` |  |

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Providers/HTTP_Provider_Base.php](Providers/HTTP_Provider_Base.php), [line 113](Providers/HTTP_Provider_Base.php#L113-L122)

### `now_pages_threadi_validate_webhook_token`

*Filters whether a given webhook token is valid.*

Not used for requests to a source which has its own token.

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$is_valid` | `bool` | Whether the token is valid. Default false.
`$token` | `string` | The submitted token.
`$request` | `\WP_REST_Request` | The full request, e.g. to read source-specific data from the body.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Providers/Webhook_Controller.php](Providers/Webhook_Controller.php), [line 209](Providers/Webhook_Controller.php#L209-L222)

### `now_pages_threadi_webhook_client_ip`

*Filter the client identifier used for rate limiting.*

Behind a reverse proxy or a CDN, REMOTE_ADDR is the proxy - return
the real client address from the appropriate header here.

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$ip` | `string` | The client address, empty if it could not be determined.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Providers/Webhook_Controller.php](Providers/Webhook_Controller.php), [line 493](Providers/Webhook_Controller.php#L493-L503)

### `now_pages_threadi_github_event_text`

*Filter the text describing a single GitHub event.*

Return an empty string to skip the event, or add your own text for
an event type this provider does not describe.

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$text` | `string` | The description, empty if unhandled.
`$type` | `string` | The event type, e.g. "PushEvent".
`$repo` | `string` | The repository, as "owner/name".
`$payload` | `array<string,mixed>` | The event payload.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Providers/GitHub_Provider.php](Providers/GitHub_Provider.php), [line 367](Providers/GitHub_Provider.php#L367-L380)

### `now_pages_threadi_fediverse_is_available`

*Filter whether something is there which federates our updates.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`defined('ACTIVITYPUB_PLUGIN_VERSION')` |  |

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Fediverse/Fediverse.php](Fediverse/Fediverse.php), [line 185](Fediverse/Fediverse.php#L185-L192)

### `now_pages_threadi_fediverse_digest_content`

*Filter the content of a summary before it is shared in the Fediverse.*

Return an empty string to not share this summary.

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$content` | `string` | The content, simple HTML.
`$start` | `\DateTimeImmutable` | The start of the period.
`$end` | `\DateTimeImmutable` | The end of the period.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Fediverse/Fediverse.php](Fediverse/Fediverse.php), [line 468](Fediverse/Fediverse.php#L468-L479)

### `now_pages_threadi_fediverse_entries_per_run`

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`self::ENTRIES_PER_RUN` |  |

Source: [app/Fediverse/Fediverse.php](Fediverse/Fediverse.php), [line 599](Fediverse/Fediverse.php#L599-L599)

### `now_pages_threadi_fediverse_share_entry`

*Filter whether a single entry is shared in the Fediverse.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$shared` | `bool` | Whether it is shared.
`$entry` | `\WP_Post` | The entry.
`$source_id` | `int` | The ID of its source, 0 for an entry written by hand.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Fediverse/Fediverse.php](Fediverse/Fediverse.php), [line 644](Fediverse/Fediverse.php#L644-L653)

### `now_pages_threadi_fediverse_entry_content`

*Filter the content of a single entry before it is shared in the Fediverse.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$content` | `string` | The content, simple HTML.
`$entry` | `\WP_Post` | The entry.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Fediverse/Fediverse.php](Fediverse/Fediverse.php), [line 692](Fediverse/Fediverse.php#L692-L700)

### `now_pages_threadi_avada_class`

*Filter the used CSS class for Avada widgets.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$class_name` | `string` | The CSS-classes.
`$instance` | `\Fusion_Element` | The widget-object.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/PageBuilder/Avada/Fusion_Elements/Digest.php](PageBuilder/Avada/Fusion_Elements/Digest.php), [line 277](PageBuilder/Avada/Fusion_Elements/Digest.php#L277-L285)

### `now_pages_threadi_avada_fusion_flow_elements`

*Filter the flow elements used by Avada to format output in frontend.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$flow_elements` | `array` | List of elements.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/PageBuilder/Avada/Fusion_Elements/Digest.php](PageBuilder/Avada/Fusion_Elements/Digest.php), [line 296](PageBuilder/Avada/Fusion_Elements/Digest.php#L296-L303)

### `now_pages_threadi_pagebuilder`

*Filter the possible page builders.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$list` | `array<int,class-string<\NowPagesThreadi\PageBuilder\PageBuilder_Base>>` | List of the handler.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/PageBuilder/Page_Builders.php](PageBuilder/Page_Builders.php), [line 68](PageBuilder/Page_Builders.php#L68-L75)

### `now_pages_threadi_source_fields`

*Filter the fields for providers on settings for sources.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$fields` | `array<int,class-string<\NowPagesThreadi\Sources\Field_Base>>` | List of field-types.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Sources/Fields.php](Sources/Fields.php), [line 77](Sources/Fields.php#L77-L84)

### `now_pages_threadi_advisor_is_available`

*Filter whether the advisor is offered.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$this->is_enabled() && $this->has_ai_client()` |  |

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Sources/Advisor.php](Sources/Advisor.php), [line 145](Sources/Advisor.php#L145-L152)

### `now_pages_threadi_advice`

*Filter the advice for a wish before it is cached and shown.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$advice` | `array<string,mixed>` | The message, the offered and the rejected proposals.
`$wish` | `string` | The wish of the user.
`$data` | `array<string,mixed>` | The decoded answer of the model.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Sources/Advisor.php](Sources/Advisor.php), [line 432](Sources/Advisor.php#L432-L441)

### `now_pages_threadi_advisor_instruction`

*Filter the instruction the model of the advisor works by.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`implode("\n", $lines)` |  |
`$with_search` | `bool` | Whether the model can search the web.
`$with_schema` | `bool` | Whether the form of the answer is enforced by a schema.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Sources/Advisor.php](Sources/Advisor.php), [line 1173](Sources/Advisor.php#L1173-L1182)

### `now_pages_threadi_advisor_pre_request`

*Filter the answer of the model before it is requested.*

Return a string (the JSON answer) or a WP_Error to skip the
request, e.g. to ask another service or in tests.

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`null` |  |
`$wish` | `string` | The wish of the user.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Sources/Advisor.php](Sources/Advisor.php), [line 1197](Sources/Advisor.php#L1197-L1208)

### `now_pages_threadi_advisor_use_web_search`

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`true` |  |

Source: [app/Sources/Advisor.php](Sources/Advisor.php), [line 1229](Sources/Advisor.php#L1229-L1229)

### `now_pages_threadi_availability_tests`

*Filter the tests run against a source.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$tests` | `array<string,array<string,mixed>>` | The tests with their configuration.
`$url` | `string` | The URL being checked.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Sources/Availability.php](Sources/Availability.php), [line 262](Sources/Availability.php#L262-L270)

### `now_pages_threadi_availability_error_texts`

*Filter the texts explaining the error codes of the analyzer.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$texts` | `array<string,string>` | The texts, keyed by error code.
`$error_code` | `string` | The code being explained.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Sources/Availability.php](Sources/Availability.php), [line 505](Sources/Availability.php#L505-L513)

### `now_pages_threadi_source_catalog`

*Filter what a source can be created from.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$catalog` | `array{presets: array<int,array<string,mixed>>, providers: array<int,array<string,mixed>>}` | The presets and providers.
`$with_options` | `bool` | Whether choices of select fields are listed.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Sources/Source_Builder.php](Sources/Source_Builder.php), [line 200](Sources/Source_Builder.php#L200-L208)

### `now_pages_threadi_import_time_budget`

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`self::IMPORT_TIME_BUDGET` |  |

Source: [app/Sources/Sources.php](Sources/Sources.php), [line 1236](Sources/Sources.php#L1236-L1236)

### `now_pages_threadi_preset_groups`

*Filter the groups presets are sorted into.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$groups` | `array<string,string>` | Map of key => label.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Presets/Presets.php](Presets/Presets.php), [line 164](Presets/Presets.php#L164-L171)

### `now_pages_threadi_periods`

*Filter the list of possible periods.*

Use this to add your own period to the ones a digest can cover.

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$list` | `array<int,class-string<\NowPagesThreadi\Digests\Period_Base>>` | List of our period objects.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Digests/Periods.php](Digests/Periods.php), [line 54](Digests/Periods.php#L54-L63)

### `now_pages_threadi_ai_temperature`

*Filter the temperature used for the narrative summary.*

Unset by default: several current models reject the parameter with a
400. Return a float to set it for a provider that supports it.

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`null` |  |
`$language` | `string` | The target language code.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Digests/Summarizers/Ai_Summarizer.php](Digests/Summarizers/Ai_Summarizer.php), [line 163](Digests/Summarizers/Ai_Summarizer.php#L163-L174)

### `now_pages_threadi_ai_cache_lifetime`

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`WEEK_IN_SECONDS` |  |

Source: [app/Digests/Summarizers/Ai_Summarizer.php](Digests/Summarizers/Ai_Summarizer.php), [line 244](Digests/Summarizers/Ai_Summarizer.php#L244-L244)

### `now_pages_threadi_narrative_sentence_template`

*Filter the sentence template used to turn one entry's text into a sentence.*

%%s is replaced with the entry's text.

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$template` | `string` | Resolved template (term meta → slug map → default).
`$entry` | `\WP_Post` | The entry being rendered.
`$slugs` | `array<int,string>` | Category slugs assigned to the entry.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Digests/Summarizers/Template_Summarizer.php](Digests/Summarizers/Template_Summarizer.php), [line 133](Digests/Summarizers/Template_Summarizer.php#L133-L144)

### `now_pages_threadi_narrative_connectors`

*Filter the pool of connector phrases used between sentences.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`array(__('I also', 'now-pages-threadi'), __('Besides that, I', 'now-pages-threadi'), __('On top of that, I', 'now-pages-threadi'))` |  |

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Digests/Summarizers/Template_Summarizer.php](Digests/Summarizers/Template_Summarizer.php), [line 164](Digests/Summarizers/Template_Summarizer.php#L164-L178)

### `now_pages_threadi_narrative_soften_start`

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`true` |  |
`$text` |  |

Source: [app/Digests/Summarizers/Template_Summarizer.php](Digests/Summarizers/Template_Summarizer.php), [line 203](Digests/Summarizers/Template_Summarizer.php#L203-L203)

### `now_pages_threadi_digest_allowed_html`

*Filter the HTML a rendered digest may contain.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$allowed` | `array<string,array<string,bool>>` | The allowed tags and attributes.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Digests/Digests.php](Digests/Digests.php), [line 157](Digests/Digests.php#L157-L164)

### `now_pages_threadi_digest_source_url`

*Filter the URL a source name links to.*

Return an empty string to render the name without a link.

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$url` | `string` | The URL, empty for no link.
`$entry` | `\WP_Post` | The entry.
`$settings` | `array<string,mixed>` | The resolved display settings.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Digests/Formats/Verbatim_Format.php](Digests/Formats/Verbatim_Format.php), [line 280](Digests/Formats/Verbatim_Format.php#L280-L291)

### `now_pages_threadi_digest_range`

*Resolve the digest args into a concrete after/before datetime range.*

Applies the configured delay (anti-stalking): the effective "before"
is never later than (now − delay_hours).

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`array($after, $before)` |  |
`$args` | `array<string,mixed>` | The resolved digest args, see build().

Source: [app/Digests/Digest_Builder.php](Digests/Digest_Builder.php), [line 254](Digests/Digest_Builder.php#L254-L294)

### `now_pages_threadi_digest_period_text`

*Filter the note about the period of a digest.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$text` | `string` | The text, already assembled.
`$args` | `array<string,mixed>` | The resolved args.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Digests/Digest_Builder.php](Digests/Digest_Builder.php), [line 329](Digests/Digest_Builder.php#L329-L337)

### `now_pages_threadi_digest_query_args`

*Filter the WP_Query arguments used to fetch entries for a digest.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$query_args` | `array<string,mixed>` | The query args, as passed to WP_Query.
`$args` | `array<string,mixed>` | The resolved digest args, see Digest_Builder::build().

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Digests/Digest_Builder.php](Digests/Digest_Builder.php), [line 461](Digests/Digest_Builder.php#L461-L469)

### `now_pages_threadi_digest_delay_notice`

*Filter the frontend delay notice. Empty string = hide.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$text` | `string` |
`$args` | `array<string,mixed>` |

Source: [app/Digests/Digest_Builder.php](Digests/Digest_Builder.php), [line 645](Digests/Digest_Builder.php#L645-L651)


<p align="center"><a href="https://github.com/pronamic/wp-documentor"><img src="https://cdn.jsdelivr.net/gh/pronamic/wp-documentor@main/logos/pronamic-wp-documentor.svgo-min.svg" alt="Pronamic WordPress Documentor" width="32" height="32"></a><br><em>Generated by <a href="https://github.com/pronamic/wp-documentor">Pronamic WordPress Documentor</a> <code>1.2.0</code></em><p>

