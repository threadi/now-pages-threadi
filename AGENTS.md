# AGENTS.md

Instructions for AI coding agents working on the **Now Pages** WordPress plugin repository (`threadi/now-pages-threadi`).

This file is for **plugin development** in this repo. It is not end-user documentation.

## Project overview

Now Pages lets a site show what someone is currently doing: entries are collected from **sources** (RSS, ICS, GitHub, webhooks, WordPress, …), stored as CPT entries, and rendered on a **Now Page** as a **digest** (verbatim list or narrative summary), optionally grouped by **category**.

Text domain: `now-pages-threadi`. Namespace: `NowPagesThreadi\`. Main file: `now-pages-threadi.php`.

## Requirements

- PHP >= 8.2
- WordPress >= 7.0 (plugin header)
- Composer (Mozart rewrites selected dependencies into `app/Dependencies/`)
- Node/npm for the Digest block (`blocks/digest/`)

## Setup

```bash
composer install          # also runs mozart compose + dump-autoload
# optional JS block:
npm install
npm run build             # or npm start for watch
```

Activate the plugin in a local WordPress install after `composer install`.

Alternative: Ant via `build/` (see `readme.md`). `readme.md` also names `task prepare`, but no `Taskfile.yml` is part of this repository.

## Commands

```bash
composer test-install     # WP test suite (see script args in composer.json)
composer test             # PHPUnit
vendor/bin/phpstan analyse
vendor/bin/phpcs .
vendor/bin/phpcbf .
npm run build             # blocks
npm run make-pot          # i18n pot
```

Release: Ant in `build/` or the GitHub Action on tag (`task release -- x.y.z` needs a `Taskfile.yml`, which is not in this repository). Bump version in `readme.txt` and `now-pages-threadi.php` when releasing by hand.

## Layout

| Path | Role |
|------|------|
| `now-pages-threadi.php` | Bootstrap, constants |
| `app/Plugin/` | Init, settings, CPT registration helpers, schedules, setup |
| `app/NowPages/` | Entries, Entry DTO, Categories taxonomy, Frontend route, Content filters |
| `app/Sources/` | Source configuration, `Source_Builder` (resolve/check/create a source), `Advisor` (AI proposals for a wish) |
| `app/Providers/` | Import providers (RSS, ICS, GitHub, Webhook, …) |
| `app/Fediverse/` | `Fediverse` (what is shared with followers and when), `Updates` (the non-public post type the ActivityPub plugin federates) |
| `app/Digests/` | Digest_Builder, periods, formats (verbatim/narrative), summarizers |
| `app/PageBuilder/` | Elementor, Bricks, Gutenberg block bridge, shortcode, widgets, … |
| `app/Helper/` | Languages, Format, Plugin helpers |
| `app/Presets/` | Presets of the setup (services like Mastodon, built on a provider) |
| `app/Dependencies/` | Mozart-packaged libs (do not edit by hand; regenerate via Composer) |
| `blocks/digest/` | Block source + build output |
| `legacy-classes/` | Page builder modules which must live in the global namespace (Avia, Bold Builder, Breakdance, Divi 4, Live Composer, Themify) |
| `brizy/`, `divi5/` | JS sources of the Brizy and Divi 5 modules (own `package.json`) |
| `pagelayer/` | Plain JS which renders the Pagelayer widget in its editor (no build step) |
| `skills/now-pages-threadi/` | Skill for assistants helping site operators (not for development) |
| `templates/` | Front templates (e.g. now-pages-threadi.php) |
| `tests/Unit/` | PHPUnit tests |
| `css/` | Front styles (digest list, period, delay notice, …) |
| `docs/` | Human docs (if present); not required for agents to invent APIs from training data—prefer code |

## Domain model (keep this straight)

```
Source (CPT) ──parent──► Entry (CPT)
                            │
                            └── optional term: now_pages_threadi_category
```

- **Entry text** lives in `post_content`. Always read display text via `NowPagesThreadi\Helper\Format::get_entry_text()`.
- **Manual entries**: `post_parent = 0` and empty `external_id` meta (`Entries::is_manual()`).
- **Category**: taxonomy slug constant `Categories::TAXONOMY` = `now_pages_threadi_category` (not the rewrite slug `now-category`). Prefer the constant in queries.
- **Language**: post meta `language`; digests default to `Languages::get_current_language()` and filter with `meta_query` when set.
- **Adding entries**: normalize to `NowPagesThreadi\NowPages\Entry`, then `do_action( 'now_pages_threadi_entry_received', $entry, $source_id )` → `Entries::add_entry()`.

## Digest output (critical)

Rendering goes through `NowPagesThreadi\Digests\Digest_Builder::build( $args )` only—do not reimplement queries in page builders.

Important args / defaults (`Display_Settings`):

- `period`, `format` (`verbatim` / narrative), `group_by_category` (default true)
- **`delay_hours` (default 6)**: privacy anti-stalking. Effective `before` is never later than `now − delay_hours`. Fresh entries (including manual ones) **do not appear** until the delay passes.
- Frontend should explain the delay when `delay_hours > 0` (e.g. notice under the list / when empty). Filter: `now_pages_threadi_digest_delay_notice`.
- Query filter: `now_pages_threadi_digest_query_args`.
- Period note filter: `now_pages_threadi_digest_period_text`.

When changing digest behaviour, update Unit tests under `tests/Unit/Digest*.php` and keep page-builder widgets as thin wrappers around `Digest_Builder`.

## Categories

- Uncategorized entries are valid: no term assigned → label `__('Uncategorized', 'now-pages-threadi')` via `Categories::get_category_label()`.
- `assign_to_entry( $post_id, 0 )` is a no-op by design.
- Resolution order for new entries: explicit `Entry::$category_term_id` → source default meta → none.
- Tax queries must use `Categories::TAXONOMY` (`now_pages_threadi_category`).

## Creating sources (critical)

A source is created in one place: `NowPagesThreadi\Sources\Source_Builder`. The setup, the abilities and the advisor all use it.

- `resolve( $preset, $provider, $values )` turns untrusted input into a provider and a clean config. Only fields the provider declares survive, each cleaned by its field type. It also reports `missing`, `missing_secrets` and `duplicate_of`.
- A duplicate (`Sources::get_duplicate()`) is a source which reads the same (the identifying fields of its provider) **and** keeps the same (its own content filter rules, compared by `Content_Filters::get_signature()`). The same feed with other rules is a different source: that is how one feed is split into several. A source created by the setup or an ability has no own rules, so it is only a duplicate of another source without own rules.
- The entries of a source follow its category: when the default category of a source **changes** in `Sources::save_post()`, `Categories::assign_to_source_entries( $source_id, $term_id, $previous )` gives the new category to every entry of the source (also drafts and trash, whatever category an entry had). Set to none, only the category the source gave is taken back. Saving without a change touches nothing, so an entry moved by hand stays until the source's category changes. The user is told once how many entries were moved; the action `now_pages_threadi_source_category_changed` fires afterwards.
- Content filter rules are stored as entered. `Content_Filters::validate( $value, $fields )` names the lines which do not work (`incomplete`, `action`, `operator`, `field`, `regex`); `Content_Filters::report()` shows them once after saving a source or the site-wide rules, as a transient **without** dismissible time (gone after the next page load). The fields a rule can use are `Content_Filters::FIELDS` plus the provider's `get_filterable_fields()`, extendable via the filter `now_pages_threadi_content_filter_fields`. A new operator has to be added to `OPERATORS` **and** to `rule_matches()`.
- `check( $provider, $config )` runs the provider without a source: nothing is stored, nothing is logged, errors are returned. Status: `ok`, `empty`, `failed`, `unsafe`, `incomplete`, `not_checkable`.
- `create_checked( $resolved, $args )` publishes if the check holds, creates a draft if a secret is missing, and creates nothing if the check fails (unless `draft_on_failure`).

The advisor (`Sources\Advisor`) asks an AI model via `wp_ai_client_prompt()` for proposals. Rules that must stay true:

- The model only proposes. Its answer is untrusted: it goes through `Source_Builder::resolve()` and every complete proposal through `check()` before it is offered.
- Providers which only receive entries (`is_push()`, e.g. the webhook) are neither part of the catalog the model sees nor offered if it proposes one anyway: they cannot be checked and are useless without a tool that sends data. If nothing fits, the answer is "not possible", not a workaround.
- Secrets never pass the model: secret fields are stripped from its answer and never part of the prompt.
- Only the wish and the catalog (`Source_Builder::get_catalog( false )`) are sent. No entries, no existing sources, no user data.
- The model is asked in exactly one place: `Setup::prepare_advice()`, right before the setup fetches its fields. The steps of the setup are built on every request (also in the frontend) and must only read the cached advice.
- The cache key of an advice must not depend on anything that changes while WordPress loads (e.g. which providers are available at `init`).

Tests: `SourceBuilderTest`, `AbilitiesSourcesTest`, `AdvisorTest` (fixed model answer), `AdvisorRequestTest` (stub AI provider registered with the AI Client), `SetupAdvisorTest` (the way through the setup).

## Recurring entries and confirmation

`Providers\Recurring_Provider` (slug `recurring`) reads nothing: it repeats a sentence of the user on a schedule. It still is a provider, so each repetition is an ordinary entry of an ordinary source (category, author, delay, filters, Fediverse switch). The preset `routine` offers it in the setup with one question, the sentence.

- `fetch()` returns the **latest** due entry on every run. Whether it exists is decided by its external ID (`recurring-Y-m-d`) in `Entries::add_entry()`. So nothing is caught up after a pause, and a repetition which was deleted or discarded does not come back. Do not add state for this.
- `get_occurrence()` never returns a day in the future: an entry dated ahead would be stored as `future` by WordPress.
- The rhythm `period` follows the option `now_pages_threadi_frontend_period` and dates the entry to the first day of the period the now page shows. Periods shorter than a day (`hour`, `live`) get one entry per day.
- Fields with fixed choices use `options` (not `options_callback`). `Source_Builder::get_catalog()` lists those also for what leaves the website; choices from a callback can name users and stay on the website.
- A provider without a service behind it can name its sources itself with `get_source_title( array $config ): string` (optional, found by `method_exists`). `Source_Builder::get_default_title()` is the one place which decides the title of a new source.

`NowPages\Confirmation` handles entries which **wait**: a provider sets `Entry::$status` to `Confirmation::STATUS` (`pending`), any other value is stored as `publish`. Nothing on the now page, in the Fediverse or in a report reads pending entries. Rules that must stay true:

- Confirming keeps the date of the entry (it belongs to the day it was due on), discarding moves it to the trash, where `Entries::entry_exists()` still finds it.
- A new waiting entry of a source discards the older waiting entries of the same source (`discard_older()`), and is announced by the email `Plugin\Emails\ConfirmEntry`.
- The links of the email carry a token of which only the hash is stored (`confirm_token`). Opening a link changes **nothing**: it shows a page with a form, only the POST of its button decides. Mail programs and scanners open links ahead of the reader - never let a GET confirm.
- The addresses of the links must not be printed as text in the email: `Email_Base::send()` writes the body without its tags to the log.
- An email with `$default_enabled = true` is on until somebody switches it off, also if its setting was never saved (`Email_Base::is_enabled()`). Use that, not `get_option()`.

The advisor may propose `recurring` (rule 9 of its instruction): only if the user describes a routine of their own, never for a service and never as a way out when nothing can be read. A field with `'review' => true` holds the words of the user: a value the model fills there becomes a question of the kind `Advisor::QUESTION_SUGGESTION`, shown in the setup as an optional field with the proposal as placeholder. Empty means "use it", an answer replaces it. Such a question does not count as open (`has_open_questions()`), the proposal is still tried.

Tests: `RecurringProviderTest`, `ConfirmationTest`, and the routine tests in `AdvisorTest`, `AdvisorRequestTest`, `SetupAdvisorTest`, `AbilitiesSourcesTest`; in the browser `tests/Playwright/recurring.spec.ts`.

## Sharing in the Fediverse (critical)

`Fediverse\Fediverse` turns what the now page shows into **updates** (CPT `np_threadi_update`), either one summary per day/week or one per entry. This plugin does not speak ActivityPub itself: the ActivityPub plugin federates the updates because the post type gets the support `activitypub` while sharing is switched on. Rules that must stay true:

- An update is not a page of the website: the post type is not public, not queryable, not searchable, not in REST. Its permalink is the now page (`post_type_link`). Do not make it public to "fix" federation - the ActivityPub plugin resolves an object by `?p=ID` and only for requests which ask for ActivityPub.
- Nothing is shared that visitors cannot see on the now page. Entries are read through `Digest_Builder` (`build()` for a summary, `get_entries()` for single entries), never by an own query, so the privacy delay and every other rule which hides an entry hold here too. What has reached other servers cannot be recalled.
- A summary is only shared once its period is over **and** the delay has passed for its last second.
- Switching sharing on (or changing the mode) starts from that moment (`OPTION_SINCE`); it never sends what was collected before.
- Single entries are shared a few per run (`ENTRIES_PER_RUN`) and not for sources which are excluded (`Fediverse::META_SOURCE_EXCLUDED`; by default sources created from the Mastodon preset, their posts are in the Fediverse already).
- Removing an entry by hand trashes its update, which makes the ActivityPub plugin send a delete. The cleanup of old entries (`Cleanup`) does not.

Tests: `FediverseTest`.

## Coding conventions

- `declare(strict_types=1);` and ABSPATH guard on PHP files.
- WPCS / VIP-aware tooling; run `phpcbf`/`phpcs` before finishing.
- Prefer existing helpers (`Format`, `Languages`, singletons via `get_instance()`).
- Do not edit Mozart output under `app/Dependencies/`; change the real package or Mozart config.
- i18n: text domain `now-pages-threadi`; user-facing strings must be translatable.
- New provider: implement the provider interface pattern under `app/Providers/` and register via `Providers`.
- New page builder: follow `PageBuilder_Base` / existing integrations; output must call `Digest_Builder`.

## Tests

- PHPUnit with WP test suite (`composer test-install` then `composer test`).
- Prefer extending existing unit tests when touching Entries, Digest_Builder, providers, or filters.

### Playwright (`tests/Playwright/`)

End-to-end tests against WordPress Playground: `npx playwright test`. All tests share one website (`fixtures.ts`, worker-scoped), and Playwright starts a fresh one whenever a test fails. So:

- A test never relies on what another test left behind. It prepares what it needs through `helpers.ts` (`removeContent()`, `completeSetup()`, `addEntry()`, `setOptions()`, `php()`), not by clicking.
- `page` is always the administrator (Playground logs every browser in). Use the fixture `visitor` for what somebody who is not logged in sees.
- Only use sources which need no other server (`This website`): the tests must run without network access to third parties.
- Wait for what the user would wait for (a step being shown, a notice), never for a fixed time. The setup overwrites a choice made before it has loaded its values.

## Do not

- Delete anything without asking the user first: files, branches, entries, sources, categories, options, database tables or the test database (`install-wp-tests.sh` can drop it). Say what would be deleted and wait for the answer.
- Bypass `now_pages_threadi_entry_received` / `Entries::add_entry()` for storage.
- Create a source with `wp_insert_post()` directly instead of `Source_Builder`, or store a value from an AI model or an ability without `Source_Builder::resolve()`.
- Hardcode taxonomy string `now_category` instead of `now_pages_threadi_category` / `Categories::TAXONOMY`.
- Assume a just-saved entry is visible on the Now Page while `delay_hours > 0`.
- Make the post type of the Fediverse updates public, or create an update from entries which did not come through `Digest_Builder`.
- Commit secrets or local `build/build.properties` with credentials.
- Treat this repo’s root `AGENTS.md` or `skills/` as something end users need in the plugin ZIP. Both release builds (`build/build.xml`, `.github/workflows/build-zip.yml`) list the shipped files explicitly; there is no `.distignore`.

## Human docs

Product docs: https://plugins.thomaszwirner.de/en/now-pages-threadi/  
Contribution process: `CONTRIBUTING.md`. Setup/release: `readme.md`.
