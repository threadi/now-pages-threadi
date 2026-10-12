# Hooks

- [Actions](#actions)
- [Filters](#filters)

## Actions

### `threadi_now_pages_register_providers`

*Fires once the built-in providers are registered.*

Use this to add further providers (e.g. custom Discord/LinkedIn
clients) from your own code, without touching this plugin.

Example:
add_action( 'threadi_now_pages_register_providers', function( $providers ) {
    $providers->register_provider( new My_Provider() );
} );

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$interface` | `\ThreadiNowPages\Providers\Providers` | The providers objects.

Source: [app/Providers/Providers.php](Providers/Providers.php), [line 90](Providers/Providers.php#L90-L103)

### `threadi_now_pages_entry_received`

*Fires once a normalized entry has been received, from any provider (pull or push).*

The actual now_page_entries-post is created by whatever hooks into this
(keyword-filter, dedupe-check, storage), so both add paths converge here.

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$entry` | `\ThreadiNowPages\NowPages\Entry` | The normalized entry.
`$source_id` | `int` | The ID of the source.

Source: [app/Providers/Webhook_Controller.php](Providers/Webhook_Controller.php), [line 457](Providers/Webhook_Controller.php#L457-L466)

### `threadi_now_pages_provider_error`

*Fires when a provider fails to fetch data, for the central logging requirement.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$this->get_slug()` |  | 
`$this->redact_error($feed)` |  | 

Source: [app/Providers/RSS_Provider.php](Providers/RSS_Provider.php), [line 120](Providers/RSS_Provider.php#L120-L126)

### `threadi_now_pages_provider_error`

*This action is documented in app/Providers/RSS_Provider.php*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$this->get_slug()` |  | 
`$error` |  | 

Source: [app/Providers/HTTP_Provider_Base.php](Providers/HTTP_Provider_Base.php), [line 217](Providers/HTTP_Provider_Base.php#L217-L218)

### `threadi_now_pages_entry_received`

*This hook is documented in app/NowPages/Entries.php.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$entry` |  | 
`$source_id` |  | 

Source: [app/NowPages/Abilities.php](NowPages/Abilities.php), [line 1212](NowPages/Abilities.php#L1212-L1215)

### `threadi_now_pages_category_assign_error`

*Fires when assigning a category to an entry failed.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$result` | `\WP_Error` | The error.
`$post_id` | `int` | Entry post ID.
`$term_id` | `int` | Requested term ID.

Source: [app/NowPages/Categories.php](NowPages/Categories.php), [line 503](NowPages/Categories.php#L503-L510)

### `threadi_now_pages_category_assign_error`

*This hook is documented in app/NowPages/Categories.php.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$result` |  | 
`$entry_id` |  | 
`$term_id` |  | 

Source: [app/NowPages/Categories.php](NowPages/Categories.php), [line 602](NowPages/Categories.php#L602-L605)

### `threadi_now_pages_source_category_changed`

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

Source: [app/NowPages/Categories.php](NowPages/Categories.php), [line 614](NowPages/Categories.php#L614-L624)

### `threadi_now_pages_entry_added`

*Fires after a new entry has been stored.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`(int) $post_id` |  | 
`$entry` | `\ThreadiNowPages\NowPages\Entry` | The entry object it was created from.
`$sources_id` | `int` | The ID of the source it came from (0 = none).

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/NowPages/Entries.php](NowPages/Entries.php), [line 246](NowPages/Entries.php#L246-L255)

### `threadi_now_pages_entry_confirmed`

*Fires after the user confirmed an entry which was waiting.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$entry_id` | `int` | The ID of the entry.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/NowPages/Confirmation.php](NowPages/Confirmation.php), [line 171](NowPages/Confirmation.php#L171-L178)

### `threadi_now_pages_entry_discarded`

*Fires after an entry which was waiting has been discarded.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$entry_id` | `int` | The ID of the entry.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/NowPages/Confirmation.php](NowPages/Confirmation.php), [line 207](NowPages/Confirmation.php#L207-L214)

### `threadi_now_pages_cleanup_done`

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

Source: [app/NowPages/Cleanup.php](NowPages/Cleanup.php), [line 425](NowPages/Cleanup.php#L425-L433)

### `threadi_now_pages_provider_error`

*Return the resolved provider + configuration for every published source.*

Centralizes what Cli::request_sources() and Scheduler::run() both need,
so pull-based add stays consistent regardless of who triggers it -
and so the source_id always travels along in the exact same shape.

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$provider_slug` |  | 
`new WP_Error('threadi_now_pages_unknown_provider', sprintf(
    /* translators: %1$s will be replaced by a provider slug, %2$s by the title of a source. */
    __('The provider %1$s configured on the source %2$s does not exist. It was probably provided by a plugin that is no longer active.', 'threadi-now-pages'),
    '<code>' . esc_html($provider_slug) . '</code>',
    '<a href="' . esc_url((string) get_edit_post_link($post_id, '')) . '">' . esc_html(get_the_title($post_id)) . '</a>'
), array('source_id' => $post_id, 'formatted' => true))` |  | 

Source: [app/Sources/Sources.php](Sources/Sources.php), [line 820](Sources/Sources.php#L820-L885)

### `threadi_now_pages_provider_error`

*Run the import of all configured sources.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$source['provider']->get_slug()` |  | 
`new WP_Error('threadi_now_pages_provider_exception', $e->getMessage(), array('source_id' => absint($source['source_id'])))` |  | 

Source: [app/Sources/Sources.php](Sources/Sources.php), [line 1372](Sources/Sources.php#L1372-L1463)

### `threadi_now_pages_entry_received`

*Add a new entry for now pages.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$entry` | `\ThreadiNowPages\NowPages\Entry` | The entry object.
`$source['source_id']` |  | 

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Sources/Sources.php](Sources/Sources.php), [line 1526](Sources/Sources.php#L1526-L1533)

### `threadi_now_pages_provider_error`

*Report a secret of a source which cannot be decrypted.*

The function get_source_config() returns such a secret as an empty value, so the
provider would run without it and silently import nothing.

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$source['provider']->get_slug()` |  | 
`new WP_Error('threadi_now_pages_secret_not_decryptable', sprintf(
    /* translators: %1$s will be replaced by a field label, %2$s by the title of a source. */
    __('The value of the field %1$s on the source %2$s could not be decrypted, so the source has not been imported. Probably the security keys of this website have changed. Please enter the value again.', 'threadi-now-pages'),
    '<code>' . esc_html((string) ($field['label'] ?? $name)) . '</code>',
    '<a href="' . esc_url((string) get_edit_post_link(absint($source['source_id']), '')) . '">' . esc_html($source['title']) . '</a>'
), array('source_id' => absint($source['source_id']), 'formatted' => true))` |  | 

Source: [app/Sources/Sources.php](Sources/Sources.php), [line 1550](Sources/Sources.php#L1550-L1603)

### `threadi_now_pages_source_duplicate`

*Run additional tasks after a duplicate was detected.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$provider_obj` | `\ThreadiNowPages\Providers\Provider_Interface` | The provider.
`$duplicate` | `int` | Amount of duplicates.
`$post_id` | `int` | The post ID which is a duplicate.
`$identity` | `array` | The list of parameters to identify the duplicate.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Sources/Sources.php](Sources/Sources.php), [line 1919](Sources/Sources.php#L1919-L1928)

### `threadi_now_pages_source_created`

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

Source: [app/Sources/Source_Builder.php](Sources/Source_Builder.php), [line 697](Sources/Source_Builder.php#L697-L706)

### `threadi_now_pages_advisor_source_created`

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

Source: [app/Sources/Advisor.php](Sources/Advisor.php), [line 635](Sources/Advisor.php#L635-L644)

### `threadi_now_pages_fediverse_update_created`

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

Source: [app/Fediverse/Fediverse.php](Fediverse/Fediverse.php), [line 788](Fediverse/Fediverse.php#L788-L796)

### `threadi_now_pages_setup_source_created`

*Fires after the setup created a source from a preset.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`absint($post_id)` |  | 
`$preset` | `\ThreadiNowPages\Presets\Preset_Interface` | The used preset.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Setup.php](Plugin/Setup.php), [line 1330](Plugin/Setup.php#L1330-L1338)

### `threadi_now_pages_setup_completed`

*Run additional tasks if the setup is marked as completed.*


**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Setup.php](Plugin/Setup.php), [line 1395](Plugin/Setup.php#L1395-L1400)

### `threadi_now_pages_digest_error`

*Render the given entries as a narrative summary.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`new WP_Error('threadi_now_pages_summarizer_unavailable', __('The configured summarizer is not available.', 'threadi-now-pages'))` |  | 
`$summarizer_slug` |  | 
`''` |  | 

Source: [app/Digests/Formats/Narrative_Format.php](Digests/Formats/Narrative_Format.php), [line 67](Digests/Formats/Narrative_Format.php#L67-L99)

### `threadi_now_pages_digest_error`

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

Source: [app/Digests/Formats/Narrative_Format.php](Digests/Formats/Narrative_Format.php), [line 221](Digests/Formats/Narrative_Format.php#L221-L230)

### `threadi_now_pages_register_summarizers`

*Fires once the built-in summarizers are registered.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$interface` | `\ThreadiNowPages\Digests\Summarizers` | The summarizers object.

Source: [app/Digests/Summarizers.php](Digests/Summarizers.php), [line 57](Digests/Summarizers.php#L57-L62)

### `threadi_now_pages_register_digest_formats`

*Fires once the built-in digest formats are registered.*

Use this to add further formats from your own code, without
touching this plugin:
add_action( 'threadi_now_pages_register_digest_formats', function ( $formats ) {
    $formats->register_format( new My_Format() );
} );

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$interface` | `\ThreadiNowPages\Digests\Digests` | The digests object.

Source: [app/Digests/Digests.php](Digests/Digests.php), [line 54](Digests/Digests.php#L54-L65)

### `threadi_now_pages_summarizer_request`

*Turn the given entries into a narrative summary via an AI model.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`array('summarizer' => $this->get_slug(), 'source' => 'cache', 'success' => true, 'entries' => count($entries), 'chars_in' => 0, 'chars_out' => mb_strlen($cached), 'duration' => 0, 'language' => $language)` |  | 

Source: [app/Digests/Summarizers/Ai_Summarizer.php](Digests/Summarizers/Ai_Summarizer.php), [line 100](Digests/Summarizers/Ai_Summarizer.php#L100-L145)

### `threadi_now_pages_summarizer_request`

*Fires after a summarizer request has finished.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`array('summarizer' => $this->get_slug(), 'source' => 'api', 'success' => !is_wp_error($result), 'entries' => $entry_count, 'chars_in' => mb_strlen($this->build_raw_text($entries)), 'chars_out' => is_wp_error($result) ? 0 : mb_strlen((string) $result), 'duration' => (int) round((microtime(true) - $started) * 1000), 'language' => $language)` |  | 

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Digests/Summarizers/Ai_Summarizer.php](Digests/Summarizers/Ai_Summarizer.php), [line 201](Digests/Summarizers/Ai_Summarizer.php#L201-L229)

### `threadi_now_pages_register_presets`

*Fires once the built-in presets are registered.*

Use this to add your own services to the list shown when a new
source is created.

Example:
add_action( 'threadi_now_pages_register_presets', function( $presets ) {
    $presets->register_preset( new My_Preset() );
} );

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$interface` | `\ThreadiNowPages\Presets\Presets` | The presets object.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Presets/Presets.php](Presets/Presets.php), [line 77](Presets/Presets.php#L77-L92)

## Filters

### `threadi_now_pages_validate_webhook_token`

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

Source: [app/Providers/Webhook_Controller.php](Providers/Webhook_Controller.php), [line 232](Providers/Webhook_Controller.php#L232-L245)

### `threadi_now_pages_webhook_max_text_length`

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`self::MAX_TEXT_LENGTH` |  | 

Source: [app/Providers/Webhook_Controller.php](Providers/Webhook_Controller.php), [line 349](Providers/Webhook_Controller.php#L349-L349)

### `threadi_now_pages_webhook_client_ip`

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

Source: [app/Providers/Webhook_Controller.php](Providers/Webhook_Controller.php), [line 606](Providers/Webhook_Controller.php#L606-L616)

### `threadi_now_pages_github_event_text`

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

Source: [app/Providers/GitHub_Provider.php](Providers/GitHub_Provider.php), [line 379](Providers/GitHub_Provider.php#L379-L392)

### `threadi_now_pages_provider_request_args`

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

### `threadi_now_pages_current_language`

*Filter the current front-end language (2-letter code).*

Example: add_filter( 'threadi_now_pages_current_language', function () {
    return substr( apply_filters( 'wpml_current_language', get_locale() ), 0, 5 );
} );

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`self::normalize_locale(get_locale())` |  | 

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Helper/Languages.php](Helper/Languages.php), [line 30](Helper/Languages.php#L30-L40)

### `threadi_now_pages_file_version`

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

### `threadi_now_pages_is_settings_request`

*Filter whether the settings of this plugin are built in the actual request.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$result` | `bool` | True if the settings are built.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Helper/Plugin.php](Helper/Plugin.php), [line 288](Helper/Plugin.php#L288-L294)

### `threadi_now_pages_entry_text_pre_linkify`

*Link URLs in a given text.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$text` | `string` | The text.

Source: [app/Helper/Format.php](Helper/Format.php), [line 82](Helper/Format.php#L82-L94)

### `threadi_now_pages_entry_text_linkified`

*Link URLs in a given text.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$html` |  | 
`$text` | `string` | The text.

Source: [app/Helper/Format.php](Helper/Format.php), [line 82](Helper/Format.php#L82-L96)

### `threadi_now_pages_avada_class`

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

### `threadi_now_pages_avada_fusion_flow_elements`

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

### `threadi_now_pages_pagebuilder`

*Filter the possible page builders.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$list` | `array<int,class-string<\ThreadiNowPages\PageBuilder\PageBuilder_Base>>` | List of the handler.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/PageBuilder/Page_Builders.php](PageBuilder/Page_Builders.php), [line 69](PageBuilder/Page_Builders.php#L69-L76)

### `threadi_now_pages_frontend_digest_args`

*This hook is documented in app/NowPages/Frontend.php.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$args` |  | 
`$user` |  | 

Source: [app/NowPages/Abilities.php](NowPages/Abilities.php), [line 2268](NowPages/Abilities.php#L2268-L2271)

### `threadi_now_pages_category_taxonomy_args`

*Filter the arguments passed to register_taxonomy() for "threadi_now_pages_category".*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$args` | `array<string,mixed>` | Taxonomy args.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/NowPages/Categories.php](NowPages/Categories.php), [line 265](NowPages/Categories.php#L265-L272)

### `threadi_now_pages_category_object_types`

*Filter which post types the "threadi_now_pages_category" taxonomy is attached to.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$object_types` | `array<int,string>` | Post type slugs.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/NowPages/Categories.php](NowPages/Categories.php), [line 279](NowPages/Categories.php#L279-L286)

### `threadi_now_pages_narrative_category_templates`

*Filter category-slug → sentence template map (fallback when term meta is empty).*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`array()` |  | 

Source: [app/NowPages/Categories.php](NowPages/Categories.php), [line 774](NowPages/Categories.php#L774-L779)

### `threadi_now_pages_is_public`

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

Source: [app/NowPages/Frontend.php](NowPages/Frontend.php), [line 473](NowPages/Frontend.php#L473-L481)

### `threadi_now_pages_frontend_digest_args`

*Filter the Digest_Builder args used for the /now frontend routes.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$args` | `array<string,mixed>` | Builder args.
`$this->user` |  | 

Source: [app/NowPages/Frontend.php](NowPages/Frontend.php), [line 530](NowPages/Frontend.php#L530-L536)

### `threadi_now_pages_frontend_content`

*Filter the digest HTML body (not the full document).*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$this->content` |  | 
`$this->user` |  | 
`$this->args` |  | 

Source: [app/NowPages/Frontend.php](NowPages/Frontend.php), [line 570](NowPages/Frontend.php#L570-L577)

### `threadi_now_pages_frontend_templates`

*Filter the list of template names looked up in the theme.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`array('threadi-now-pages/threadi-now-pages.php', 'threadi-now-pages.php', 'now.php')` |  | 
`$this->user` |  | 

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/NowPages/Frontend.php](NowPages/Frontend.php), [line 837](NowPages/Frontend.php#L837-L853)

### `threadi_now_pages_frontend_template`

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

Source: [app/NowPages/Frontend.php](NowPages/Frontend.php), [line 870](NowPages/Frontend.php#L870-L879)

### `threadi_now_pages_frontend_digest_args`

*Filter the Digest_Builder args used for the /now frontend routes.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$args` | `array<string,mixed>` | Builder args.
`$user` | `\WP_User\|null` | Author for /author/…/now, null for global /now.

Source: [app/NowPages/Frontend.php](NowPages/Frontend.php), [line 1305](NowPages/Frontend.php#L1305-L1311)

### `threadi_now_pages_entry_is_manual`

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

Source: [app/NowPages/Entries.php](NowPages/Entries.php), [line 857](NowPages/Entries.php#L857-L865)

### `threadi_now_pages_sitemap_entry`

*Filter a single now-page sitemap entry.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$entry` | `array<string,mixed>` | The sitemap entry (loc/lastmod/changefreq/priority only).
`null` |  | 

Source: [app/NowPages/Sitemap.php](NowPages/Sitemap.php), [line 126](NowPages/Sitemap.php#L126-L132)

### `threadi_now_pages_sitemap_entry`

*This filter is documented above.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$entry` |  | 
`$user` |  | 

Source: [app/NowPages/Sitemap.php](NowPages/Sitemap.php), [line 150](NowPages/Sitemap.php#L150-L151)

### `threadi_now_pages_frontend_digest_args`

*This filter is documented in Frontend::prepare().*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$args` |  | 
`null` |  | 

Source: [app/NowPages/Sitemap.php](NowPages/Sitemap.php), [line 227](NowPages/Sitemap.php#L227-L228)

### `threadi_now_pages_cleanup_max_per_run`

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$max_per_run` |  | 
`$days` |  | 

Source: [app/NowPages/Cleanup.php](NowPages/Cleanup.php), [line 348](NowPages/Cleanup.php#L348-L348)

### `threadi_now_pages_page_builder_for_page`

*Filter the page builder used to prefill a new now page.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$active` | `\ThreadiNowPages\PageBuilder\PageBuilder_Base\|null` | The chosen page builder.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/NowPages/Now_Page.php](NowPages/Now_Page.php), [line 362](NowPages/Now_Page.php#L362-L369)

### `threadi_now_pages_content_filter_rules`

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

### `threadi_now_pages_content_filter_fields`

*Filter the fields content filter rules may use.*

Add a field here if entries get it in another way than through
Provider_Interface::get_filterable_fields().

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$fields` | `string[]` | The names of the fields.
`$provider` | `\ThreadiNowPages\Providers\Provider_Interface\|null` | The provider, null for all providers.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/NowPages/Content_Filters.php](NowPages/Content_Filters.php), [line 484](NowPages/Content_Filters.php#L484-L495)

### `threadi_now_pages_source_fields`

*Filter the fields for providers on settings for sources.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$fields` | `array<int,class-string<\ThreadiNowPages\Sources\Field_Base>>` | List of field-types.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Sources/Fields.php](Sources/Fields.php), [line 77](Sources/Fields.php#L77-L84)

### `threadi_now_pages_import_time_budget`

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`self::IMPORT_TIME_BUDGET` |  | 

Source: [app/Sources/Sources.php](Sources/Sources.php), [line 1321](Sources/Sources.php#L1321-L1321)

### `threadi_now_pages_availability_tests`

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

Source: [app/Sources/Availability.php](Sources/Availability.php), [line 272](Sources/Availability.php#L272-L280)

### `threadi_now_pages_availability_error_texts`

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

Source: [app/Sources/Availability.php](Sources/Availability.php), [line 515](Sources/Availability.php#L515-L523)

### `threadi_now_pages_source_catalog`

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

### `threadi_now_pages_pre_address_problem`

*Filter what is wrong with the address of a source, before its host is looked up.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`null` |  | 
`$url` | `string` | The address.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Sources/Source_Builder.php](Sources/Source_Builder.php), [line 816](Sources/Source_Builder.php#L816-L824)

### `threadi_now_pages_advisor_is_available`

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

### `threadi_now_pages_advice`

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

Source: [app/Sources/Advisor.php](Sources/Advisor.php), [line 437](Sources/Advisor.php#L437-L446)

### `threadi_now_pages_advisor_instruction`

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

Source: [app/Sources/Advisor.php](Sources/Advisor.php), [line 1178](Sources/Advisor.php#L1178-L1187)

### `threadi_now_pages_advisor_pre_request`

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

Source: [app/Sources/Advisor.php](Sources/Advisor.php), [line 1202](Sources/Advisor.php#L1202-L1213)

### `threadi_now_pages_advisor_use_web_search`

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`true` |  | 

Source: [app/Sources/Advisor.php](Sources/Advisor.php), [line 1234](Sources/Advisor.php#L1234-L1234)

### `threadi_now_pages_fediverse_is_available`

*Filter whether something is there which federates our updates.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`defined('ACTIVITYPUB_PLUGIN_VERSION')` |  | 

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Fediverse/Fediverse.php](Fediverse/Fediverse.php), [line 186](Fediverse/Fediverse.php#L186-L193)

### `threadi_now_pages_fediverse_digest_content`

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

Source: [app/Fediverse/Fediverse.php](Fediverse/Fediverse.php), [line 508](Fediverse/Fediverse.php#L508-L519)

### `threadi_now_pages_fediverse_entries_per_run`

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`self::ENTRIES_PER_RUN` |  | 

Source: [app/Fediverse/Fediverse.php](Fediverse/Fediverse.php), [line 639](Fediverse/Fediverse.php#L639-L639)

### `threadi_now_pages_fediverse_share_entry`

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

Source: [app/Fediverse/Fediverse.php](Fediverse/Fediverse.php), [line 684](Fediverse/Fediverse.php#L684-L693)

### `threadi_now_pages_fediverse_entry_content`

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

Source: [app/Fediverse/Fediverse.php](Fediverse/Fediverse.php), [line 732](Fediverse/Fediverse.php#L732-L740)

### `threadi_now_pages_import_error_support_hint`

*Filter the support part of an email.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$support_part` | `string` | The text to use.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Email_Base.php](Plugin/Email_Base.php), [line 313](Plugin/Email_Base.php#L313-L319)

### `threadi_now_pages_email_headers`

*Filter the email header.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$headers` | `array<int,string>` | List of headers.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Email_Base.php](Plugin/Email_Base.php), [line 441](Plugin/Email_Base.php#L441-L447)

### `threadi_now_pages_register_post_type`

*Filter the post-types.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$post_types` | `array<int,class-string<\ThreadiNowPages\Plugin\Post_Type_Base>>` | List of post-types.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Post_Types.php](Plugin/Post_Types.php), [line 66](Plugin/Post_Types.php#L66-L73)

### `threadi_now_pages_set_template_directory`

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$directory` |  | 

Source: [app/Plugin/Templates.php](Plugin/Templates.php), [line 88](Plugin/Templates.php#L88-L88)

### `threadi_now_pages_set_template_directory`

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$directory` |  | 

Source: [app/Plugin/Templates.php](Plugin/Templates.php), [line 127](Plugin/Templates.php#L127-L127)

### `threadi_now_pages_setup_is_completed`

*Filter the setup complete marker.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$completed` | `bool` | True if setup has been completed.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Setup.php](Plugin/Setup.php), [line 225](Plugin/Setup.php#L225-L231)

### `threadi_now_pages_setup`

*Filter the configured setup for this plugin.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$setup` | `array<int,array<string,mixed>>` | The setup-configuration.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Setup.php](Plugin/Setup.php), [line 301](Plugin/Setup.php#L301-L308)

### `threadi_now_pages_setup_config`

*Filter the setup configuration.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$config` | `array<string,array<int,mixed>\|bool\|string>` | List of configuration for the setup.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Setup.php](Plugin/Setup.php), [line 410](Plugin/Setup.php#L410-L416)

### `threadi_now_pages_setup_process_completed_text`

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$completed_text` |  | 
`$config_name` |  | 

Source: [app/Plugin/Setup.php](Plugin/Setup.php), [line 1368](Plugin/Setup.php#L1368-L1368)

### `threadi_now_pages_schedule_our_events`

*Filter the list of our own events, e.g., to check if all, which are enabled in setting are active.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$our_events` | `array<string,array<string,mixed>>` | List of our own events in WP-cron.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Schedules.php](Plugin/Schedules.php), [line 157](Plugin/Schedules.php#L157-L164)

### `threadi_now_pages_disable_cron_check`

*Disable the additional cron check.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$false` | `bool` | True if the check should be disabled.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Schedules.php](Plugin/Schedules.php), [line 182](Plugin/Schedules.php#L182-L190)

### `threadi_now_pages_schedules`

*Add custom schedule-objects to use.*

They must be objects based on ThreadiNowPages\Plugin\Schedules_Base.

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$list_of_schedules` | `string[]` | List of additional schedules.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Schedules.php](Plugin/Schedules.php), [line 349](Plugin/Schedules.php#L349-L358)

### `threadi_now_pages_objects_with_db_tables`

*Add additional objects for this plugin, which use custom tables.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$objects` | `array<int,string>` | List of objects.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Init.php](Plugin/Init.php), [line 232](Plugin/Init.php#L232-L238)

### `threadi_now_pages_emails`

*Filter the list of possible email objects.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$trigger` | `array<int,string>` | List of Email trigger objects.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Emails.php](Plugin/Emails.php), [line 104](Plugin/Emails.php#L104-L110)

### `threadi_now_pages_log_without_debug`

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

Source: [app/Plugin/Log.php](Plugin/Log.php), [line 183](Plugin/Log.php#L183-L194)

### `threadi_now_pages_log_with_debug`

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

Source: [app/Plugin/Log.php](Plugin/Log.php), [line 210](Plugin/Log.php#L210-L221)

### `threadi_now_pages_log_categories`

*Filter the list of possible log categories.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$list` | `array<string,string>` | List of categories.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Log.php](Plugin/Log.php), [line 322](Plugin/Log.php#L322-L329)

### `threadi_now_pages_log_limit`

*Filter limit to prevent possible errors on big tables.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$limit` | `int` | The actual limit.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Log.php](Plugin/Log.php), [line 353](Plugin/Log.php#L353-L359)

### `threadi_now_pages_log_category`

*Filter the used category.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$category` | `string` | The category to use.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Log.php](Plugin/Log.php), [line 364](Plugin/Log.php#L364-L370)

### `threadi_now_pages_log_md5`

*Filter the used md5.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$md5` | `string` | The md5 to use.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Log.php](Plugin/Log.php), [line 380](Plugin/Log.php#L380-L386)

### `threadi_now_pages_log_errors`

*Filter for errors.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$errors` | `int` | Should be 1 to filter only for errors.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Log.php](Plugin/Log.php), [line 391](Plugin/Log.php#L391-L397)

### `threadi_now_pages_intervals`

*Filter the list of possible intervals.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$list` | `array<int,class-string<\ThreadiNowPages\Plugin\Interval_Base>>` | List of our interval objects.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Intervals.php](Plugin/Intervals.php), [line 96](Plugin/Intervals.php#L96-L102)

### `threadi_now_pages_report_digest_args`

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

Source: [app/Plugin/Emails/Report.php](Plugin/Emails/Report.php), [line 373](Plugin/Emails/Report.php#L373-L382)

### `threadi_now_pages_hide_intro`

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

### `threadi_now_pages_schedule_interval`

*Filter the interval to a single schedule.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$interval` | `string` | The interval.
`$instance` | `\ThreadiNowPages\Plugin\Schedules_Base` | The schedule-object.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Schedules_Base.php](Plugin/Schedules_Base.php), [line 92](Plugin/Schedules_Base.php#L92-L99)

### `threadi_now_pages_schedule_enabling`

*Filter whether to activate this schedule.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$false` | `bool` | True if this object should NOT be enabled.
`$instance` | `\ThreadiNowPages\Plugin\Schedules_Base` | Actual object.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Schedules_Base.php](Plugin/Schedules_Base.php), [line 311](Plugin/Schedules_Base.php#L311-L321)

### `threadi_now_pages_knowledge_center_entries`

*Filter the list of available knowledge center entries.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$entries` | `array<int,class-string<\ThreadiNowPages\Plugin\KnowledgeCenter_Base>>` | List of knowledge center entries.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/KnowledgeCenter.php](Plugin/KnowledgeCenter.php), [line 79](Plugin/KnowledgeCenter.php#L79-L85)

### `threadi_now_pages_show_help`

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$allowed` |  | 
`$screen` |  | 

Source: [app/Plugin/KnowledgeCenter.php](Plugin/KnowledgeCenter.php), [line 123](Plugin/KnowledgeCenter.php#L123-L123)

### `threadi_now_pages_help_tabs`

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

### `threadi_now_pages_help_sidebar_content`

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

### `threadi_now_pages_log_table_filter`

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

### `threadi_now_pages_status_list`

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

### `threadi_now_pages_export_filename`

*Filter the filename for CSV-download.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$filename` | `string` | The generated filename for CSV-download.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Plugin/Admin/Admin.php](Plugin/Admin/Admin.php), [line 159](Plugin/Admin/Admin.php#L159-L166)

### `threadi_now_pages_dashboard_widgets`

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

### `threadi_now_pages_digest_source_url`

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

Source: [app/Digests/Formats/Verbatim_Format.php](Digests/Formats/Verbatim_Format.php), [line 302](Digests/Formats/Verbatim_Format.php#L302-L313)

### `threadi_now_pages_digest_range`

*Resolve the digest args into a concrete after/before datetime range.*

Applies the configured delay (anti-stalking): the effective "before"
is never later than (now − delay_hours).

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`array($after, $before)` |  | 
`$args` | `array<string,mixed>` | The resolved digest args, see build().

Source: [app/Digests/Digest_Builder.php](Digests/Digest_Builder.php), [line 313](Digests/Digest_Builder.php#L313-L353)

### `threadi_now_pages_digest_period_text`

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

Source: [app/Digests/Digest_Builder.php](Digests/Digest_Builder.php), [line 388](Digests/Digest_Builder.php#L388-L396)

### `threadi_now_pages_digest_query_args`

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

Source: [app/Digests/Digest_Builder.php](Digests/Digest_Builder.php), [line 521](Digests/Digest_Builder.php#L521-L529)

### `threadi_now_pages_digest_delay_notice`

*Filter the frontend delay notice. Empty string = hide.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$text` | `string` | 
`$args` | `array<string,mixed>` | 

Source: [app/Digests/Digest_Builder.php](Digests/Digest_Builder.php), [line 705](Digests/Digest_Builder.php#L705-L711)

### `threadi_now_pages_digest_allowed_html`

*Filter the HTML a rendered digest may contain.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$allowed` | `array<string,array<string,bool>>` | The allowed tags and attributes.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Digests/Digests.php](Digests/Digests.php), [line 164](Digests/Digests.php#L164-L171)

### `threadi_now_pages_periods`

*Filter the list of possible periods.*

Use this to add your own period to the ones a digest can cover.

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$list` | `array<int,class-string<\ThreadiNowPages\Digests\Period_Base>>` | List of our period objects.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Digests/Periods.php](Digests/Periods.php), [line 54](Digests/Periods.php#L54-L63)

### `threadi_now_pages_narrative_sentence_template`

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

### `threadi_now_pages_narrative_connectors`

*Filter the pool of connector phrases used between sentences.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`array(__('I also', 'threadi-now-pages'), __('Besides that, I', 'threadi-now-pages'), __('On top of that, I', 'threadi-now-pages'))` |  | 

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [app/Digests/Summarizers/Template_Summarizer.php](Digests/Summarizers/Template_Summarizer.php), [line 164](Digests/Summarizers/Template_Summarizer.php#L164-L178)

### `threadi_now_pages_narrative_soften_start`

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`true` |  | 
`$text` |  | 

Source: [app/Digests/Summarizers/Template_Summarizer.php](Digests/Summarizers/Template_Summarizer.php), [line 203](Digests/Summarizers/Template_Summarizer.php#L203-L203)

### `threadi_now_pages_ai_temperature`

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

Source: [app/Digests/Summarizers/Ai_Summarizer.php](Digests/Summarizers/Ai_Summarizer.php), [line 159](Digests/Summarizers/Ai_Summarizer.php#L159-L170)

### `threadi_now_pages_ai_cache_lifetime`

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`WEEK_IN_SECONDS` |  | 

Source: [app/Digests/Summarizers/Ai_Summarizer.php](Digests/Summarizers/Ai_Summarizer.php), [line 257](Digests/Summarizers/Ai_Summarizer.php#L257-L257)

### `threadi_now_pages_ai_max_length`

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`1500` |  | 

Source: [app/Digests/Summarizers/Ai_Summarizer.php](Digests/Summarizers/Ai_Summarizer.php), [line 514](Digests/Summarizers/Ai_Summarizer.php#L514-L514)

### `threadi_now_pages_ai_max_requests_per_hour`

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`30` |  | 

Source: [app/Digests/Summarizers/Ai_Summarizer.php](Digests/Summarizers/Ai_Summarizer.php), [line 608](Digests/Summarizers/Ai_Summarizer.php#L608-L608)

### `threadi_now_pages_preset_groups`

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


<p align="center"><a href="https://github.com/pronamic/wp-documentor"><img src="https://cdn.jsdelivr.net/gh/pronamic/wp-documentor@main/logos/pronamic-wp-documentor.svgo-min.svg" alt="Pronamic WordPress Documentor" width="32" height="32"></a><br><em>Generated by <a href="https://github.com/pronamic/wp-documentor">Pronamic WordPress Documentor</a> <code>1.2.0</code></em><p>

