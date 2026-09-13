# Single-Codebase Freemium Migration — Design

**Date:** 2026-09-05
**Status:** Approved for planning
**Supersedes:** the thin-add-on architecture shipped as free v5.3.0 + Pro v6.0.0

## Summary

Collapse the two plugins — free `simple-events-calendar` (v5.3.0) and the private
Pro add-on `simple-events-calendar-pro` (v6.0.0) — into **one codebase** that
builds into two distributions of the **same** plugin:

- a **free build** published on WordPress.org, and
- a **premium build** delivered to paying customers by Freemius.

Premium code lives in one directory that Freemius's deployment processor strips
when it generates the free build. A site owner installs and manages **one**
plugin; entering a license swaps the free build for the premium build under the
same slug.

The work ends at "ready to submit to WordPress.org." Calendar/month view and
ticketing are separate projects with their own specs.

## Locked decisions

| Decision | Value | Rationale |
|---|---|---|
| Distribution model | One plugin, two builds (Freemius processor) | A single ZIP whose features unlock by license key is prohibited by the WordPress.org trialware rule. Two co-active plugins imposes a permanent "add a hook to free first" tax that the one-price-unlocks-everything business model gets nothing back for. |
| wp.org slug | `simply-events-calendar` | `simple-events-calendar` is permanently taken (closed 2021-06-02 for a security issue); `simple-event-calendar` and `simple-events` are likewise taken and closed. `simply-events-calendar` was verified free on 2026-09-07, carries **no tier marker**, and keeps `events calendar` — the phrase users actually search. A tier-marked slug such as `-lite` was rejected: wp.org slugs are permanent, so a paying customer would carry `lite` in their install path and text domain forever. |
| Plugin display name | `Simply Events Calendar` | Slug is derived from the name at submission, so the two are locked together at that moment. Free and premium builds carry the same name; the premium build appends `premium_suffix`. |
| Text domain | `simply-events-calendar` | WordPress.org requires the text domain to equal the slug. Identical in both builds — they share one source tree and one `languages/` directory, so a divergent domain would make every `__()` call miss. |
| Stripping mechanism | `@fs_premium_only /includes/pro/` + one `is__premium_only()` block | Smallest reviewable marker surface. |
| Canonical repo | Existing `simple-events-calendar`, made **private** | Already holds the free code in the correct state; the Pro repo deleted all of it in `dbb82c4`. |
| Version | `6.0.0` | Slug, text domain, and global constants all break. |

## Non-goals

- No new end-user features. Calendar/month view, ticketing, and RSVP are out.
- No data migration. Event post meta and the `simple_events_settings` option keep
  their current keys and formats.
- No automated test suite. The project has none; this work does not add one.
- No public GitHub mirror of the free build. Possible later; not built now.

## Architecture

### Repo topology

`Level-Up-Studios-LLC/simple-events-calendar` becomes the private canonical
monorepo and is the source of truth for both builds. Ported in from the Pro repo:

- `freemius/` — vendored SDK 2.13.1
- `includes/features/class-configurable-urls.php` → `includes/pro/features/`

`Level-Up-Studios-LLC/simple-events-calendar-pro` is archived after the port.
The `pro` git remote on the canonical repo is removed.

Because the repo goes private, `Plugin URI` must no longer point at
`github.com/Level-Up-Studios-LLC/simple-events-calendar` — it would 404 for
users. Repoint to the wordpress.org plugin page (after approval) or to
levelupstudios.com.

### Directory layout

```
simple-events-calendar/                     (private canonical repo)
├── simply-events-calendar.php              main file: header, constants,
│                                           Freemius bootstrap, @fs_premium_only
├── freemius/                               vendored SDK 2.13.1
├── includes/
│   ├── class-main.php                      gated require of the pro loader
│   ├── class-migrations.php
│   ├── …existing free classes, unchanged in structure…
│   ├── elementor/
│   └── pro/                                ← stripped from the free build
│       ├── class-pro-loader.php
│       └── features/
│           └── class-configurable-urls.php
├── assets/  src/  templates/  template-parts/  languages/
├── docs/  phpcs.xml  package.json  readme.txt
```

`includes/pro/` is the **only** location premium code may occupy. Nothing
premium goes into a shared file.

### Main file rename

`simple-events-calendar.php` → `simply-events-calendar.php`, matching the
slug.

This changes `plugin_basename()`, so WordPress treats it as a different plugin:
activation hooks, `register_uninstall_hook`, and the active-plugins list all key
off the basename. Existing installs must deactivate the old plugin and install
the new one. **No data is lost** — events are posts, fields are post meta,
settings live in the `simple_events_settings` option, and
`delete_data_on_uninstall` defaults to `'no'`, so deleting the old plugin
preserves everything. The migration DB version in `Simple_Events_Migrations` is
unchanged, so no migration re-runs.

### Premium code stripping

Two markers, both verified against the bundled SDK's own README.

**1. Directory exclusion** — in the main file's header docblock:

```php
 * @fs_premium_only /includes/pro/
```

**2. Load gate** — in `Simple_Events_Calendar::load_components()`, after the
existing component wiring:

```php
if ( sec_fs()->is__premium_only() ) {
    $pro = SIMPLE_EVENTS_DIR . '/includes/pro/class-pro-loader.php';
    if ( file_exists( $pro ) ) {
        require_once $pro;
        Simple_Events_Pro_Loader::init();
    }
}
```

The condition is the exact documented form. It must **not** be written as a
compound condition (e.g. `function_exists( 'sec_fs' ) && …`) — the processor
pattern-matches this construct, and a compound condition risks the block
surviving into the free build.

The `file_exists()` guard is deliberate defence in depth: it makes the two
failure modes independent. If the directory is stripped but the block is not,
the free build no-ops instead of fataling on a missing `require_once`.

`Simple_Events_Pro_Loader::init()` instantiates each feature module only when
`sec_fs()->can_use_premium_code()` returns true, so an unlicensed premium build
behaves like the free build.

### Freemius configuration

Bootstrapped in the main file directly after the `ABSPATH` guard and the two
Freemius key constants, and **before** the plugin's own constants and the
`class-main.php` require, so the SDK initialises earlier than `plugins_loaded`.
Ordering within the main file is therefore:

1. Header docblock (including `@fs_premium_only`)
2. `ABSPATH` guard
3. `SIMPLE_EVENTS_FS_ID` / `SIMPLE_EVENTS_FS_PUBLIC_KEY` definitions
4. `sec_fs()` definition and invocation
5. `SIMPLE_EVENTS_DIR` and the remaining plugin constants
6. `require_once` of `includes/class-main.php` and bootstrap

```php
if ( ! function_exists( 'sec_fs' ) ) {
    function sec_fs() {
        global $sec_fs;

        if ( ! isset( $sec_fs ) ) {
            require_once __DIR__ . '/freemius/start.php';

            $sec_fs = fs_dynamic_init( array(
                'id'                  => SIMPLE_EVENTS_FS_ID,
                'slug'                => 'simply-events-calendar',
                'premium_slug'        => 'simply-events-calendar-premium',
                'type'                => 'plugin',
                'public_key'          => SIMPLE_EVENTS_FS_PUBLIC_KEY,
                'is_premium'          => true,
                'premium_suffix'      => '(Pro)',
                'has_premium_version' => true,
                'has_paid_plans'      => true,
                'has_addons'          => false,
                'is_org_compliant'    => true,
                'anonymous_mode'      => true,
                'menu'                => array(
                    'slug'       => 'edit.php?post_type=simple-events',
                    'account'    => true,
                    'pricing'    => false,
                    'contact'    => false,
                    'support'    => false,
                    'first-path' => 'edit.php?post_type=simple-events&page=simple-events-settings',
                ),
            ) );
        }

        return $sec_fs;
    }

    sec_fs();
    do_action( 'sec_fs_loaded' );
}
```

Notes on specific keys:

- **`anonymous_mode => true`** satisfies the WordPress.org requirement that
  usage tracking be skippable. SDK `class-freemius.php:5287` shows
  `is_premium_only => true` force-disables anonymous mode — the Pro plugin
  currently sets that key to `true`, and it **must not** carry over.
- **`is_premium_only`** is omitted entirely (defaults false). A free version now
  exists on wp.org.
- **`pricing => false`, `contact => false`, `support => false`** because the
  plugin keeps its own Events → Upgrade to Pro page; leaving them on produces
  duplicate menu items.
- **`premium_suffix => '(Pro)'`** makes the premium build read as "Simply Events
  Calendar (Pro)" in the WordPress plugins list. The suffix lives in the premium
  build's own `Plugin Name:` header — not only in Freemius's UI: `set_name()`
  (`freemius/includes/class-freemius.php:10212`) strips a trailing suffix off the
  header value, and the updater strips it again before querying the wp.org update
  API (`freemius/includes/class-fs-plugin-updater.php:944`) so wp.org never tries
  to update a premium copy. Whether the deployment processor writes the suffix
  into the header or we maintain it in source is confirmed by the first dry-run
  deployment (see Release pipeline).

### Key handling

The product ID and public key ship inside the plugin by design and are not
secrets. They are defined in the main file with `wp-config.php` overrides:

```php
if ( ! defined( 'SIMPLE_EVENTS_FS_ID' ) ) {
    define( 'SIMPLE_EVENTS_FS_ID', '<product id>' );
}
if ( ! defined( 'SIMPLE_EVENTS_FS_PUBLIC_KEY' ) ) {
    define( 'SIMPLE_EVENTS_FS_PUBLIC_KEY', '<pk_…>' );
}
```

The **secret key** is never committed, never placed in any repo file, and never
passed through the `secret_key` config option in tracked code. It is set only in
`wp-config.php` on a local sandbox, only while testing, and removed afterward.
The release verification step (below) fails the build if `sk_` appears anywhere
in the ZIP.

### Upsell surface changes

`Simple_Events_Pro_Upsell` stays and keeps its banner, locked preview section,
and Upgrade page. Changes:

- `is_pro_active()` returns `sec_fs()->can_use_premium_code()`, still passed
  through the `simple_events_pro_active` filter so the value can be forced during
  development.
- `pro_url()` defaults to `sec_fs()->get_upgrade_url()` instead of a static URL,
  still filterable via `simple_events_pro_url`.

### Extension hooks

The five v5.3.0 hooks (`simple_events_event_slug`,
`simple_events_settings_after_sections`, `simple_events_sanitize_settings`,
`simple_events_setting_defaults`, `simple_events_pro_active`) are **retained
unchanged** as public API for third-party developers.

`Sec_Pro_Configurable_Urls` continues to use them rather than reaching into base
classes directly. It already works this way, it needs no modification beyond its
new file path and class prefix, and keeping it hook-based exercises the public
API on every release. Future premium features may call base classes directly now
that they run in-process — the hook-first rule no longer binds.

## WordPress.org compliance refactor

Independent of Freemius; the largest and most mechanical part of the work.

### 1. Global constants

`PLUGIN_DIR`, `PLUGIN_URL`, `PLUGIN_ASSETS`, `PLUGIN_VERSION` are unprefixed
globals with real collision potential and are a reliable review rejection.
Rename:

| Current | New |
|---|---|
| `PLUGIN_DIR` | `SIMPLE_EVENTS_DIR` |
| `PLUGIN_URL` | `SIMPLE_EVENTS_URL` |
| `PLUGIN_ASSETS` | `SIMPLE_EVENTS_ASSETS` |
| `PLUGIN_VERSION` | `SIMPLE_EVENTS_VERSION` |
| `PLUGIN_TEXT_DOMAIN` | *(deleted)* |

`SIMPLE_EVENTS_PLUGIN_FILE` and `SIMPLE_EVENTS_NONCE_ACTION` are already
correctly prefixed and unchanged.

Then remove `PLUGIN_` from the `prefixes` array of the
`WordPress.NamingConventions.PrefixAllGlobals` rule in `phpcs.xml`, so a
reintroduced generic constant fails lint.

### 2. Text domain

Two distinct problems, one fix.

- 280 call sites pass the literal `'simple_events'`, which does not match the
  slug — a hard WordPress.org requirement, and translate.wordpress.org keys on
  it.
- 105 call sites pass the `PLUGIN_TEXT_DOMAIN` **constant**. WordPress i18n
  tooling extracts only literal strings, so **those 105 strings are currently
  untranslatable** and absent from the generated `.pot`. This is a pre-existing
  bug, not merely a compliance issue.

All 385 sites take the literal `'simply-events-calendar'`. The
`PLUGIN_TEXT_DOMAIN` constant is deleted rather than renamed, so the constant
form cannot reappear.

Header gains `Text Domain: simply-events-calendar` and
`Domain Path: /languages`.

`Simple_Events_Calendar::load_textdomain()` stays hooked on `init` (not
`plugins_loaded`), which avoids WordPress 6.7's `_load_textdomain_just_in_time`
notice. It is redundant for the wp.org build but required for the premium build,
which WordPress does not auto-load translations for.

Update the `WordPress.WP.I18n` `text_domain` property in `phpcs.xml` to the new
domain so lint enforces it.

### 3. Language files

Rename to the new domain, preserving msgids so existing translations survive:

```
languages/simple-events.pot        → simply-events-calendar.pot
languages/simple-events-es_ES.po   → simply-events-calendar-es_ES.po
languages/simple-events-es_ES.mo   → simply-events-calendar-es_ES.mo
languages/simple-events-fr_FR.po   → simply-events-calendar-fr_FR.po
languages/simple-events-fr_FR.mo   → simply-events-calendar-fr_FR.mo
```

See the Internationalisation section for the full treatment.

### 4. readme.txt

- `=== Simply Events Calendar ===`
- `Stable tag: 6.0.0`
- `Tested up to:` the current stable WordPress release at submission time
- Add an **External services** section disclosing that the plugin communicates
  with Freemius (freemius.com) for licensing, updates, and opt-in usage
  analytics; what is transmitted; when; and links to Freemius's terms and privacy
  policy. WordPress.org requires this disclosure for any third-party service.
- Update the description and screenshots to match the new name.

### 5. Product rename

The product is renamed from **Simple Events Calendar** to **Simply Events
Calendar**. The slug is derived from the submitted plugin name, so the name is
fixed at the moment of submission and the two cannot be chosen independently
afterwards.

User-facing surfaces to update: the `Plugin Name:` header, `readme.txt`,
`README.md`, `changelog.md`, the `name` and `description` fields in
`package.json`, and any admin string that spells the product out (settings page
title, Documentation page, upsell banner and Upgrade page copy). The Freemius
dashboard product name must match as well, or the license email and account
screens will name a different product than the one installed.

What does **not** change: the `simple-events` post type, the `simple-events-cat`
taxonomy, the `simple_events_settings` option, every `event_*` and `_sec_*` meta
key, the `sec_*` filter names, the `simple_events_*` function prefix, and the
`Simple_Events_*` class prefix. These are internal identifiers; renaming them
would be a data migration, which is an explicit non-goal. The `phpcs.xml`
`prefixes` array therefore keeps `simple_events`, `Simple_Events`, and
`SIMPLE_EVENTS_`.

The mismatch between the internal `simple_events` prefix and the public
`simply-events-calendar` name is deliberate and permanent. It costs nothing at
runtime and avoids touching stored data.

## Internationalisation

Translation is in scope. The two builds are served by different translation
systems, and the design must satisfy both from one source tree.

### Delivery model

| Build | Source of translations |
|---|---|
| Free (wp.org) | Language packs generated by translate.wordpress.org and downloaded into `WP_LANG_DIR/plugins/`. |
| Premium (Freemius) | **Bundled `.mo` files only.** WordPress fetches language packs solely for plugins it hosts; the premium build updates through Freemius, so it is never included in a language-pack request. |

Both builds therefore ship the `languages/` directory. In the free build this is
a harmless fallback — `load_plugin_textdomain()` checks `WP_LANG_DIR/plugins/`
before the plugin's own directory, so a downloaded pack always wins over a
bundled file. Because WordPress.org requires the text domain to equal the slug,
the pack filename and the bundled filename are the same
(`simply-events-calendar-{locale}.mo`), and a site that ran the free build
before upgrading keeps working either way.

### String correctness pass

Making strings *extractable* (the literal text domain fix) is not the same as
making them *translatable*. Before the `.pot` is regenerated, every user-facing
string is audited for:

- **Translator comments** on every string containing a placeholder —
  `/* translators: %s is the event date */` immediately above the call.
  `WordPress.WP.I18n` fails the build without them.
- **`_n()` for plurals** rather than a conditional between two singular strings.
  The recurrence summary ("10 occurrences") and admin counts are the known
  offenders.
- **`_x()` with context** for short ambiguous strings — "Date", "Time",
  "Location", "Order" all translate differently depending on role.
- **No concatenation or variables inside `__()`.** Placeholders and
  `sprintf()` only.
- **No markup inside translatable strings** where a placeholder will do, so
  translators cannot break the HTML.

`phpcs` with `WordPress.WP.I18n` and the `text_domain` property set to
`simply-events-calendar` catches most of these mechanically.

### Tooling

The project currently has no i18n build step; the existing `.pot` is stale and
missing the 105 constant-domain strings. Two npm scripts wrap WP-CLI:

```
"i18n:pot": generate languages/simply-events-calendar.pot via `wp i18n make-pot`
"i18n:mo":  compile every languages/*.po to .mo via `wp i18n make-mo`
```

`i18n:pot` runs after any string change; `i18n:mo` runs as part of the release
pipeline, before the ZIP is built, so the premium build never ships a `.po`
without its compiled `.mo`. WP-CLI becomes a documented development dependency.

### Locale strategy

- **Maintained in-repo:** `es_ES` and `fr_FR`. Both are stale — they predate
  v5.1.0 and miss every string added since, plus all 105 newly extractable ones.
  They are brought current as part of this work: regenerate the `.pot`, merge
  into each `.po`, translate the gaps, and review. Machine translation is
  acceptable for the first pass provided every machine-translated entry is
  marked fuzzy and read by a human before the fuzzy flag is cleared — admin
  strings carry destructive verbs ("delete", "uninstall") where a bad
  translation has real consequences.
- **Everything else:** community, via translate.wordpress.org. No further
  locales are hand-maintained.

After the plugin is approved, the GlotPress project appears at
`translate.wordpress.org/projects/wp-plugins/simply-events-calendar`.
Post-approval actions, outside the code work but part of the plan:

1. Import the maintained `es_ES` and `fr_FR` catalogues to seed the project.
2. Request Project Translation Editor rights so contributed strings can be
   approved rather than sitting as pending suggestions indefinitely.
3. Add a short "Translations welcome" note with the GlotPress link to
   `readme.txt`.

`readme.txt` itself is translatable through the same system at no extra cost,
which is how the plugin's directory listing reaches non-English users.

The Freemius SDK carries its own text domain and its own bundled translations.
It needs no work here.

## Release pipeline

1. `npm run build` — compile production CSS from SCSS.
1b. `npm run i18n:pot && npm run i18n:mo` — refresh the template and compile
   every catalogue, so the premium build never ships a `.po` without its `.mo`.
2. `npm run zip` — full premium ZIP, `includes/pro/` included. The existing
   `dist`/`zip` scripts already exclude `node_modules`, `src`, `.git`, `dist`,
   dotfiles, `package*.json`, `phpcs.xml`, and `*.md`; they additionally must
   exclude `docs/`.
3. Upload the ZIP in the Freemius Developer Dashboard → Deployment. The processor
   emits both a premium and a free build.
4. Download the generated **free** ZIP.
5. `npm run verify:free <path-to-zip>` — new script. Fails non-zero if any of:
   - `includes/pro/` exists in the archive
   - `is__premium_only` appears in any file **outside `freemius/`**
   - `sk_` appears in any file **outside `freemius/`**
   - the `Text Domain:` header does not equal `simply-events-calendar`

   The `freemius/` exclusion is required, not incidental: the SDK legitimately
   defines `is__premium_only()`, `is_paying__premium_only()`, and siblings in
   `includes/class-freemius-abstract.php`, and scanning it would make the check
   fail on every build. The exclusion is scoped to that one vendored directory so
   plugin code is still covered.
6. Push the verified free build to the WordPress.org SVN `trunk`, then tag.

Step 5 exists so that a premium-code leak is a failed command rather than
something a human has to notice. It runs against the artifact that actually
ships, not against the source tree.

The `dist`/`zip` scripts remain Git-Bash-on-Windows only (`robocopy` plus a
Python one-liner). `verify:free` follows the same constraint; this is not the
project to port the tooling.

## Versioning

`6.0.0` for the merged product. Updated in: the main file header, the
`SIMPLE_EVENTS_VERSION` constant, `package.json`, `readme.txt` `Stable tag`, and
`changelog.md`.

`Simple_Events_Shortcode` embeds the version in its transient cache keys, so
caches self-invalidate on upgrade with no extra work.

`changelog.md` gets a `6.0.0` entry covering: the single-codebase merge, the
rename and slug change, the text-domain fix (calling out the 105 newly
translatable strings), the constant renames, Freemius licensing, and the
manual reinstall required of existing installs.

## Impact on existing installs

Existing sites — including client sites already running 5.3.0 — require a manual
changeover because the plugin folder and main file name both change, which
changes `plugin_basename()` and makes WordPress treat this as a different
plugin.

**No data moves.** Every piece of it lives in the database under names this
version registers identically:

| Data | Location | Changing? |
|---|---|---|
| Events | `wp_posts`, post type `simple-events` | No |
| Date, time, location, recurrence rules | `wp_postmeta` (`event_date`, `event_start_time`, `event_repeat_*`, `_sec_series_*`) | No |
| Categories | taxonomy `simple-events-cat` | No |
| Settings | option `simple_events_settings` | No |

Deactivating only unregisters the post type. The rows remain in `wp_posts` and
become visible again as soon as the new plugin registers `simple-events`.

The single destructive path is the `delete_data_on_uninstall` setting. Verified
in `Simple_Events_Calendar::uninstall()` (`includes/class-main.php:280`): it
reads `simple_events_settings['delete_data_on_uninstall']` and returns
immediately unless the value is exactly `'yes'`, in which case it force-deletes
every event, every term, the settings option, and the plugin's transients. The
default is `'no'`.

Documented changeover procedure:

1. Events → Settings → Data — confirm delete-on-uninstall is **No**.
2. Take a database backup regardless.
3. **Deactivate** the old plugin; do not delete it yet. Both plugins active
   simultaneously fatals on duplicate class and post-type declarations.
4. Install and activate `simply-events-calendar` (or the premium build).
5. Confirm events, dates, categories, recurring series, and settings are all
   present.
6. Visit Settings → Permalinks once to flush rewrite rules.
7. Delete the old plugin.

For a zero-risk step 7, delete the old plugin's directory over SFTP or the
host's file manager rather than through the WordPress admin. Removing files
directly never fires `register_uninstall_hook`, making the setting irrelevant.

This procedure is published in the `6.0.0` changelog entry and the readme
upgrade notice, not left as tribal knowledge.

## Testing

Manual, since the project has no automated suite. Executed against a local
WordPress install.

**Free build**
- Fresh activation with no PHP notices or warnings.
- Create a one-time event; verify date, time, location, and featured image.
- Create a recurring event (weekly with by-day selections); verify occurrences
  generate on the correct dates.
- `[sec_events]`, `[sec_event id="…"]`, and element shortcodes render.
- Post-type archive, taxonomy archive, and single event pages render.
- Load-more/infinite scroll continues the correct query.
- Add to Calendar downloads a valid `.ics`.
- Elementor Events Grid, Single Event, and per-element widgets render.
- `includes/pro/` is absent from the shipped archive.
- Upsell banner, locked preview, and Upgrade page all appear.

**Premium build**
- Activate a license in Freemius sandbox mode; `can_use_premium_code()` true.
- Events → Settings → Permalinks shows the Event URL base field.
- Changing the base rewrites permalinks after the one-time flush at `init:11`.
- Deactivating the premium build reverts the base to `/events/`.
- All upsell surfaces are hidden.

**Cross-cutting**
- Upgrade from a populated 5.3.0 install per the steps above; all data intact.
- `npm run lint:css` and `phpcs` both clean.
- `npm run verify:free` passes on the generated free ZIP.

**Internationalisation**
- Switch the site to `es_ES`, then `fr_FR`. Admin and front-end strings resolve
  in both, confirming the `.mo` rename and recompile worked.
- Spot-check strings that were previously behind `PLUGIN_TEXT_DOMAIN` — they
  must now translate, having been untranslatable before.
- Every placeholder string renders with its values substituted, in both locales
  and in English (a mismatched `sprintf()` arity surfaces here).
- `wp i18n make-pot` produces no warnings; the generated `.pot` string count is
  materially higher than the 5.3.0 template.
- The premium ZIP contains a compiled `.mo` for every `.po` in `languages/`.

## Risks

| Risk | Mitigation |
|---|---|
| Premium code leaks into the free build | Two markers only, both in files a reviewer reads; `verify:free` gates the artifact; `file_exists()` guard makes the two failure modes independent. |
| WordPress.org review rejects the submission | The known blockers — generic constants, mismatched text domain, missing service disclosure — are all fixed here. Review latency remains outside our control, which is why this spec stops at "ready to submit" rather than bundling a feature release behind the queue. |
| 385-site text-domain edit introduces a typo | Scripted replacement, then `phpcs` with the `WordPress.WP.I18n` `text_domain` property set to the new domain, which flags any site that does not match. |
| Existing client sites broken by the rename | Documented reinstall procedure; data survives because it lives in posts, post meta, and options that the uninstall routine does not touch by default. |
| Freemius processor behaviour differs from documentation | The first deployment is a dry run: upload, download the generated free build, and inspect it before any SVN push. |
| Premium build ships with no translations | Premium is outside the language-pack system by design, so `languages/` is bundled in both builds and `i18n:mo` runs in the release pipeline before the ZIP is cut. Release verification confirms a `.mo` exists for every `.po`. |
| Machine-translated strings mislead users on destructive actions | Every machine-translated entry stays marked fuzzy until a human reads it; admin strings using "delete", "uninstall", and "remove" are reviewed first. |

## Prerequisites

- Freemius product ID and public key are available (confirmed 2026-09-05).
- The secret key is required only for sandbox testing and must be supplied via
  `wp-config.php`, never committed.
- WordPress.org submission requires the free build to exist; submission itself is
  a manual step outside this spec's implementation work.
- **WP-CLI** becomes a development dependency, for `wp i18n make-pot` and
  `wp i18n make-mo`. It is not needed at runtime and ships in neither build.
- Project Translation Editor rights on translate.wordpress.org can only be
  requested after the plugin is approved and its GlotPress project exists.

## Follow-on work (separate specs)

1. Calendar / month view — the first substantial premium feature.
2. Ticketing and RSVPs — a large sub-product; custom orders table, Stripe or
   WooCommerce checkout.
3. Additional locales beyond `es_ES` and `fr_FR`, as community contributions
   arrive through translate.wordpress.org.
