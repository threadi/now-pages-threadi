---
name: now-pages-threadi
description: Help someone who runs WordPress with the Now Pages plugin configure it, add or fix entries and sources, and find out why an entry does not show on the now page. Not for developing the plugin codebase.
---

# Now Pages (site operators)

Guidance for AI assistants helping **someone who runs WordPress with the Now Pages plugin** (configuration, content, troubleshooting).
This is **not** for developing the plugin codebase.

Official docs: https://plugins.thomaszwirner.de/en/now-pages-threadi/

## What Now Pages does

Now Pages shows **what is going on right now** (or in a chosen period) on a public “now” view:

1. **Entries** — short text items (one action / status each).
2. **Sources** — optional feeds that import entries (RSS, calendar/ICS, GitHub, webhooks, WordPress, …). In the backend a new source is created in a short setup: choose a service, or choose **Get advice** and describe what should be shown (needs an AI service under Settings → Connectors).
3. **Categories** — optional thematic groups for entries.
4. **Digest** — the rendered list or narrative on the Now Page (and in blocks / page builders / shortcodes).

Entries can be **imported** from sources or **added by hand** (admin, abilities/AI tools, webhooks).

## Mental model

```
Sources (optional)  →  Entries  →  Digest on the Now Page
                          ↑
                    optional Category
```

- Without a category, an entry is still valid. It is shown under **Uncategorized** when grouping by category is on.
- Reading values in custom code is not required for normal use; the digest does the output.

## Privacy delay (most common “missing entry” issue)

Settings include a **delay in hours** (plugin default is often **6**).

- Entries newer than “now minus delay” are **intentionally hidden** on the Now Page (anti-stalking / privacy).
- A text saved **just now** will **not** appear until the delay has passed.
- This applies to **every** way of adding entries (block editor, classic editor, AI abilities, webhook, import).
- The frontend may show a short notice that recent entries are delayed when a delay is configured.

**If the user says “I added a text and it doesn’t show”:**

1. Check the delay setting (hours).
2. Wait until the entry is older than the delay, or temporarily set delay to `0` for testing.
3. Confirm the entry is **published**, not draft.
4. Confirm date/time of the entry falls into the selected **period** (day / week / …).

## Categories

- Assigning a category is **optional**.
- No category → still listed; grouped label is **Uncategorized** when “group by category” is enabled.
- Turning grouping off lists entries in time order without category headings.
- A category can define an **override text**: if set, the digest may show that text instead of individual entries for that category in the period.

## Sources vs manual entries

| Kind | Typical signs | Notes |
|------|----------------|-------|
| From a source | Has a parent source; often has an external ID | Subject to source schedule, filters, and source default category |
| Manual | No source parent; no external ID | Created in admin or via add-entry tools; still subject to delay and period |

Content filters on sources can skip imported items; manual entries without a source are not blocked by source-specific rules the same way.

**One feed, several sources:** a feed which mixes several kinds of activity (e.g. a WordPress.org profile: forum replies, translations, commits) can be added once per kind. Give each source its own rule under *Additional content filter rules*, e.g. `include:url:contains:/support/topic/` for one and `include:url:contains:translate.wordpress.org` for the other - `url` is the address an entry links to. Then each part can have its own category. Add such sources by hand and set the rule **before** publishing: a published source is imported at once, with everything the feed contains.

**The category of a source also applies to what it already imported:** changing *Default category for imported entries* on a source (or setting it for the first time) moves all entries of this source to that category, including entries which had been put into another category one by one. Setting it to none removes only the category the source gave. The categories themselves are managed under *Now Page Entries → Categories*; the source form links there.

**A rule which does not work is reported, not rejected:** rules are stored as entered. After saving a source (or the site-wide rules in the settings) a hint names every line which is ignored (not starting with `include`/`exclude`, unknown operator, fewer than four parts) or which never matches (a field the source does not deliver, an invalid regular expression). The hint is shown once. If a source imports everything although a rule was set, look for this hint first: a rule has the form `action:field:operator:value`, operators are `equals`, `not_equals`, `contains`, `not_contains`, `word`, `regex`.

**Things no service knows about** (sports once a week, the weekly market): use a source with the provider *Recurring entry* - in the setup it is the card *Something I do regularly*. It repeats one sentence: once per period of the now page (default), every day, every week on chosen days, or every month. Each repetition is a normal entry; if the routine was skipped, delete that one entry and it does not come back.

**Entries which wait for a confirmation:** with *Ask me first* on such a source, each new entry is stored with the status `pending` and is not shown until the user confirms it - in *Now Page Entries* (row actions *Confirm* / *Discard*, the menu shows how many wait) or through the two links of the email *Confirm an entry* (Settings → Now Pages → Emails, on by default). The links open a page with a button; opening them alone decides nothing. An entry which is not confirmed is discarded when the next one is due. If a recurring entry "does not appear", check for a waiting entry first.

## Digest display options (conceptual)

Operators can usually control:

- **Period** — which time window is shown (e.g. day, week).
- **Format** — e.g. 1:1 list (verbatim) vs narrative/summary.
- **Group by category** — headings per category or a single timeline.
- **Show time / source / period label**
- **Delay (hours)** — privacy window
- **Language** — digests can be limited to the current site language of entries

Page builders (Elementor, Bricks, Gutenberg block, shortcode, …) should all show the **same** digest logic; prefer the plugin’s Now Page / Digest block/widget over reinventing lists.

## When helping the user

**Do**

- Explain delay before assuming a bug.
- Treat “no category” as allowed.
- Ask whether they mean the **admin list of entries** or the **public Now Page** (different screens).
- Suggest checking publish status, entry date, period, delay, and language.

**Don’t**

- Tell them every new entry must appear instantly on the public page while a delay is enabled.
- Claim entries without a category are invalid or hidden by design.
- Invent provider APIs or setting key names; prefer the plugin UI labels and official docs.
- Edit the PHP files of the plugin (`wp-content/plugins/now-pages-threadi/`) unless they explicitly want code-level changes (and then warn about updates overwriting edits).
- Delete, discard or trash anything (entries, sources, categories, Fediverse updates) without asking first. Name what would be removed and wait until the user agrees in this conversation.

## Quick troubleshooting checklist

1. Entry published?
2. Entry date inside the digest period?
3. Delay: is the entry newer than `delay_hours`?
4. Language filter: does entry language match the page language?
5. Category override: is a category replacing the list with a single override text?
6. Now Page disabled or wrong URL / page assignment in settings?
7. Caching (page cache, CDN): try a hard refresh or exclude the Now Page.

## AI / automation (if available on the site)

The plugin registers **WordPress abilities** (category `now-pages-threadi`), exposed via the MCP Adapter when it is installed.

Site operators control them under **Settings → Now Pages → AI & automation**: they can switch them off completely or allow **read only** (then `add`, `update-entry`, `delete`, `create-source` and `import` are missing). If an ability the user expects is missing, point them to that tab — its status table shows which abilities are active and whether an MCP Adapter was found.

| Ability | Use it to                                                                                                  |
|---------|------------------------------------------------------------------------------------------------------------|
| `now-pages-threadi/list-entries` | Find entries and their **IDs** (filters: status, after/before, source, category, language, author, search) |
| `now-pages-threadi/get-entry` | Read one entry                                                                                             |
| `now-pages-threadi/add` | Add an entry; returns `added`, `post_id`, `reason` and the entry incl. `visible_from`                      |
| `now-pages-threadi/update-entry` | Change text, date, category (`0` removes it), language or status (`draft` hides)                           |
| `now-pages-threadi/delete` | Move an entry in the trash (imported ones are not re-imported)                                             |
| `now-pages-threadi/list` | Get the rendered Now Page as visitors see it                                                               |
| `now-pages-threadi/diagnose-digest` | Explain the Now Page, and with `entry_id` why one entry is (not) shown                                     |
| `now-pages-threadi/get-periods`, `get-formats`, `get-categories`, `list-sources` | Look up valid values                                                                                       |
| `now-pages-threadi/list-providers` | See what a source can be created from: presets (services) and providers (RSS, ICS, …) with their fields    |
| `now-pages-threadi/check-source` | Try a source without storing anything: status, number of entries, the latest ones as samples               |
| `now-pages-threadi/create-source` | Create a source; it is tried first and only published if the service answers                               |
| `now-pages-threadi/import` | Run the import of all sources now                                                                          |

The three abilities for sources need an administrator (`manage_options`).

Typical flows:

- **“My entry doesn’t show”** → `list-entries` (find it) → `diagnose-digest` with `entry_id`. Explain the returned `reasons` (`hidden_by_delay`, `not_published`, `before_period`, `after_period`, `other_language`, `other_category`, `other_author`, `replaced_by_override`, `filtered`) in plain language.
- **Add with category** → `get-categories` → `add` with `category_term_id`.
- **“I would like to show X”** → `list-providers` → pick a preset if one matches the service, otherwise a provider (`rss` for a public feed, `ics` for a calendar) → `check-source` with the values → show the user the returned `samples` and ask whether this is the right source → `create-source` → `import`.
- **Fix or remove an entry** → `list-entries` → `update-entry` or `delete`. Prefer `update-entry` with `status: draft` if the user only wants to hide it. Ask before every `delete`.
- **“I do X regularly”** (no service involved) → `create-source` with provider `recurring`: `recurring_text` (one short sentence, in the user's words - show it to them before creating), optionally `recurring_rhythm` (`period`, `daily`, `weekly`, `monthly`), `recurring_weekdays` (`mon` … `sun`, with `weekly`) and `recurring_confirm` (ask before each entry is shown). Do not use it to stand in for a service which cannot be read.
- **“What is waiting for me?” / “Confirm it”** → `list-entries` with `status: pending` → `update-entry` with `status: publish` confirms (the entry keeps its date), `delete` discards. Only confirm what the user confirmed in this conversation.

Rules for sources:

- Never guess an account name, an ID or a feed address. Ask the user, or look the address up if you can, and verify it with `check-source` before creating anything. `failed` means the address is wrong or not public - do not create the source then.
- `empty` is not an error: some sources only report something now and then (a calendar without a running appointment, Steam before the second run).
- Do not ask the user for API keys. If a provider needs one (fields marked `secret`), create the source without it: it becomes a **draft**, `edit_url` is where the user enters the key and publishes it. A draft is not imported.
- `reason: duplicate` names the existing source in `duplicate_of` - use that one instead of creating a second.

Always respect the **delay**: when `add` returns `delay_passed: false`, tell the user from when (`visible_from`) the entry will appear.
Errors come back as `WP_Error` with a message that names the problem (unknown category, invalid date, …) — relay it instead of retrying blindly.

## Sharing in the Fediverse

With the plugin **ActivityPub** installed, the user can share the Now Page with followers on Mastodon and similar services under **Settings → Now Pages → Fediverse**: nothing (default), a **summary** per day or week, or **single entries**.

- Shared posts are listed under **Now Page Entries → Fediverse Updates**. They are not pages of the website; their link leads to the Now Page. Replies, likes and boosts arrive as comments on them.
- “Why was nothing shared?” → check in this order: ActivityPub active, a mode chosen, the Now Page public, the **privacy delay** (an entry is shared when visitors can see it; a summary once its day or week is over plus the delay), and that sharing only covers what happened **after** it was switched on. Sharing runs once per hour.
- With single entries at most a few are shared per hour, and sources can be excluded on their edit screen (“Share in the Fediverse”). Sources created from the Mastodon preset are excluded by default.
- Recommend the summary for sources with many entries (music, commits). Single entries flood followers.
- Trashing a shared post or its entry asks other servers to delete it. Tell the user this cannot be guaranteed.

## Boundaries

- You help with **using and configuring** Now Pages on a site.
- Core WordPress, themes, and other plugins are in scope only where they affect the Now Page (permalinks, builders, cache, multilingual plugins).
- Security: never suggest disabling delay permanently on a public “what I’m doing right now” page without explaining the privacy trade-off.
