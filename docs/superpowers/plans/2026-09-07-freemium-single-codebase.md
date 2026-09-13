# Single-Codebase Freemium Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Merge the free plugin and the private Pro add-on into one codebase that builds into two distributions of the same WordPress plugin — a free build for WordPress.org and a Freemius-licensed premium build — and bring that codebase to WordPress.org submission standard.

**Architecture:** All premium code lives in `includes/pro/`, which the Freemius deployment processor strips when it generates the free build. Two markers control the strip: an `@fs_premium_only /includes/pro/` tag in the main file's header docblock, and one `sec_fs()->is__premium_only()` gate in `Simple_Events_Calendar::load_components()`. Everything else — post type, taxonomy, meta keys, option, class and function prefixes — is untouched, so no data migrates.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, Freemius WordPress SDK 2.13.1 (vendored), Sass, stylelint, phpcs + WordPress Coding Standards, WP-CLI (i18n only), Git Bash on Windows.

**Spec:** `docs/superpowers/specs/2026-09-05-freemium-single-codebase-design.md`

## Global Constraints

- **Slug and text domain:** `simply-events-calendar` — identical in both builds.
- **Plugin display name:** `Simply Events Calendar`. Premium build appends `premium_suffix` `(Pro)`.
- **Main file:** `simply-events-calendar.php`.
- **Version:** `6.0.0` everywhere — main file header, `SIMPLE_EVENTS_VERSION`, `package.json`, `readme.txt` `Stable tag`, `changelog.md`.
- **Never rename** (renaming any of these is a data migration, an explicit non-goal): post type `simple-events`, taxonomy `simple-events-cat`, option `simple_events_settings`, every `event_*` and `_sec_*` meta key, every `sec_recur_*` and `simple_events_*` filter/action name, function prefix `simple_events_`, class prefix `Simple_Events_`.
- **Constants:** `SIMPLE_EVENTS_DIR`, `SIMPLE_EVENTS_URL`, `SIMPLE_EVENTS_ASSETS`, `SIMPLE_EVENTS_VERSION`, `SIMPLE_EVENTS_PLUGIN_FILE`, `SIMPLE_EVENTS_NONCE_ACTION`, `SIMPLE_EVENTS_FS_ID`, `SIMPLE_EVENTS_FS_PUBLIC_KEY`. No `PLUGIN_*` constant may exist after Task 2.
- **The Freemius secret key is never committed, never written to any file in this repo, and never pasted into a commit message or a chat.** Only the product ID and public key ship in the plugin. Task 10 fails the build if `sk_` appears in a shipped file.
- **PHP style:** existing files use 4-space indent, `array()` long syntax, and no trailing `?>`. Match the surrounding file; do not reformat untouched lines.
- **Shell:** every command in this plan runs in **Git Bash on Windows** from the repo root, `G:/Web Projects/simple-events-calendar`.
- **No automated test suite exists and this work does not add one.** Where the skill's template calls for a failing unit test, this plan substitutes an explicit, runnable verification command whose expected output is stated. Run it before the change to confirm it fails, and after to confirm it passes. That is the test cycle for this project.

## Verification vocabulary

Three commands recur. They are the project's whole automated safety net:

```bash
# Syntax check every PHP file the task touched.
php -l <file>

# Full lint. Requires phpcs with WordPress Coding Standards installed.
phpcs

# CSS lint.
npm run lint:css
```

`php -l` is non-negotiable after every PHP edit in this plan. `phpcs` is run at the end of Tasks 1, 2, 7 and 12; it is slow and noisy on this codebase, so intermediate tasks skip it.

## File Structure

**Created:**

| File | Responsibility |
|---|---|
| `simply-events-calendar.php` | Plugin header (incl. `@fs_premium_only`), `ABSPATH` guard, Freemius keys, `sec_fs()`, plugin constants, bootstrap. Replaces `simple-events-calendar.php`. |
| `freemius/` | Vendored Freemius SDK 2.13.1, copied verbatim from the Pro repo. Never edited. |
| `includes/pro/class-pro-loader.php` | `Simple_Events_Pro_Loader::init()` — instantiates premium feature modules, gated on `can_use_premium_code()`. The only entry point into premium code. |
| `includes/pro/features/class-configurable-urls.php` | `Sec_Pro_Configurable_Urls` — configurable event URL base, ported from the Pro repo. |
| `bin/verify-free.sh` | Release gate. Inspects a built ZIP and fails if premium code, a secret key, or a wrong text domain is present. |

**Modified:** `includes/class-main.php`, `includes/class-pro-upsell.php`, and the 20 other PHP files carrying the text domain or `PLUGIN_*` constants; `phpcs.xml`; `package.json`; `readme.txt`; `README.md`; `changelog.md`; `languages/*`.

**Deleted:** `simple-events-calendar.php` (renamed), `simple-events-calendar.zip` (stale artifact), `dist/` (stale build output).

---

### Task 1: Text domain — literal, correct, and matching the slug

Two defects, one fix. 280 call sites pass the literal `'simple_events'`, which does not equal the slug WordPress.org requires. 105 more pass the `PLUGIN_TEXT_DOMAIN` **constant** — and WordPress's string extractor reads only literals, so **those 105 strings are absent from the `.pot` and are untranslatable today**. That is a live bug, not just a compliance issue.

385 call sites total. (The spec says 561/295/856; those numbers were wrong and Task 11 corrects them in the spec.)

Both replacements are mechanically safe. Verified: outside its own `define()`, the literal `'simple_events'` appears **only** as an i18n argument, and `PLUGIN_TEXT_DOMAIN` appears **only** as an i18n argument plus the `load_plugin_textdomain()` call.

**Files:**
- Modify: all 22 PHP files listed by the grep in Step 1
- Modify: `includes/class-main.php:237-243` (`load_textdomain()`)
- Modify: `simple-events-calendar.php:13` (`Text Domain:` header), `:37` (delete the `define`)
- Modify: `phpcs.xml:38-44` (`WordPress.WP.I18n` `text_domain` property)
- Rename: `languages/simple-events*.{pot,po,mo}` → `languages/simply-events-calendar*`

**Interfaces:**
- Consumes: nothing.
- Produces: the literal text domain `'simply-events-calendar'`, used by every later task that adds a user-facing string. `PLUGIN_TEXT_DOMAIN` no longer exists — Task 2 relies on that.

- [ ] **Step 1: Record the starting state**

```bash
grep -ro "'simple_events'" --include=*.php . | grep -v node_modules | grep -v "^./dist" | wc -l
grep -ro "PLUGIN_TEXT_DOMAIN" --include=*.php . | grep -v node_modules | grep -v "^./dist" | wc -l
```

Expected: `280` then `105`. If either differs, the codebase moved since this plan was written — stop and re-derive the counts before continuing.

- [ ] **Step 2: Confirm both tokens are only ever text domains**

```bash
grep -rn "'simple_events'" --include=*.php . | grep -v node_modules | grep -v "^./dist" \
  | grep -vE "(__|_e|_x|_n|_nx|_ex|esc_html__|esc_html_e|esc_attr__|esc_attr_e|esc_html_x|esc_attr_x|_n_noop)\("
grep -rn "PLUGIN_TEXT_DOMAIN" --include=*.php . | grep -v node_modules | grep -v "^./dist" \
  | grep -vE "(__|_e|_x|_n|_nx|_ex|esc_html__|esc_html_e|esc_attr__|esc_attr_e|esc_html_x|esc_attr_x)\("
```

Expected: the first prints only `./simple-events-calendar.php:37` (the `define`). The second prints only that same line plus `./includes/class-main.php:239`. Any other line is a non-i18n use — inspect it by hand before running Step 3, because the blanket replacement would corrupt it.

- [ ] **Step 3: Replace both forms with the new literal**

```bash
FILES=$(grep -rl "PLUGIN_TEXT_DOMAIN\|'simple_events'" --include=*.php . \
  | grep -v node_modules | grep -v "^./dist")

sed -i "s/'simple_events'/'simply-events-calendar'/g" $FILES
sed -i "s/PLUGIN_TEXT_DOMAIN/'simply-events-calendar'/g" $FILES
```

- [ ] **Step 4: Fix the three sites the blanket replacement got wrong**

The `define()` line is now `define('simply-events-calendar', 'simply-events-calendar');` and `load_plugin_textdomain()` now takes a quoted literal where it read a constant. Delete the first; the second is already correct but must be re-read to confirm.

In `simple-events-calendar.php`, delete this line entirely:

```php
define('simply-events-calendar', 'simply-events-calendar');
```

In `simple-events-calendar.php`, change the header line:

```php
 * Text Domain: simple_events
```

to:

```php
 * Text Domain: simply-events-calendar
 * Domain Path: /languages
```

Confirm `includes/class-main.php` now reads:

```php
    public function load_textdomain() {
        load_plugin_textdomain(
            'simply-events-calendar',
            false,
            dirname(plugin_basename(SIMPLE_EVENTS_PLUGIN_FILE)) . '/languages/'
        );
    }
```

Leave it hooked on `init` (`includes/class-main.php:157`). That placement avoids WordPress 6.7's `_load_textdomain_just_in_time` notice, and the premium build needs the call because WordPress never ships it a language pack.

- [ ] **Step 5: Rename the language files**

Renaming preserves msgids, so the existing `es_ES` and `fr_FR` translations keep matching. Task 8 refreshes their contents.

```bash
git mv languages/simple-events.pot        languages/simply-events-calendar.pot
git mv languages/simple-events-es_ES.po   languages/simply-events-calendar-es_ES.po
git mv languages/simple-events-es_ES.mo   languages/simply-events-calendar-es_ES.mo
git mv languages/simple-events-fr_FR.po   languages/simply-events-calendar-fr_FR.po
git mv languages/simple-events-fr_FR.mo   languages/simply-events-calendar-fr_FR.mo
```

- [ ] **Step 6: Point phpcs at the new domain**

In `phpcs.xml`, change:

```xml
            <property name="text_domain" type="array">
                <element value="simple_events"/>
            </property>
```

to:

```xml
            <property name="text_domain" type="array">
                <element value="simply-events-calendar"/>
            </property>
```

This is what makes the rule enforce itself: any call site left on the old domain is now a lint failure rather than a silent miss.

- [ ] **Step 7: Verify**

```bash
grep -rn "PLUGIN_TEXT_DOMAIN\|'simple_events'" --include=*.php . | grep -v node_modules | grep -v "^./dist"
```

Expected: **no output**.

```bash
grep -ro "'simply-events-calendar'" --include=*.php . | grep -v node_modules | grep -v "^./dist" | wc -l
```

Expected: `385`.

```bash
for f in $(git diff --name-only -- '*.php'); do php -l "$f" || break; done
```

Expected: `No syntax errors detected` for every file.

```bash
phpcs
```

Expected: no `WordPress.WP.I18n.TextDomainMismatch` errors. Other pre-existing violations are out of scope for this task.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "fix(i18n): use a literal text domain matching the wp.org slug

Replaces 280 'simple_events' literals and 105 PLUGIN_TEXT_DOMAIN constant
references with the literal 'simply-events-calendar'.

The constant form was a real bug, not only a compliance problem: WordPress
extracts translatable strings by reading literals out of the source, so the
105 strings behind the constant never reached the .pot and could not be
translated in any locale.

Deletes the PLUGIN_TEXT_DOMAIN constant so the constant form cannot return,
renames the language catalogues to the new domain (msgids preserved, so the
existing es_ES and fr_FR translations still match), and points the phpcs
WordPress.WP.I18n text_domain property at the new domain so a regression
fails lint."
```

---

### Task 2: Prefix the global constants

`PLUGIN_DIR`, `PLUGIN_URL`, `PLUGIN_ASSETS`, and `PLUGIN_VERSION` are unprefixed globals in the shared PHP namespace. Any other plugin defining `PLUGIN_DIR` fatals the site, and WordPress.org review rejects them on sight.

**Files:**
- Modify: every PHP file matching the grep below (23 `PLUGIN_DIR`, 4 `PLUGIN_URL`, 10 `PLUGIN_ASSETS`, 12 `PLUGIN_VERSION` occurrences)
- Modify: `simple-events-calendar.php:36-42` (the `define()` block)
- Modify: `phpcs.xml:47-56` (`PrefixAllGlobals` `prefixes` property)

**Interfaces:**
- Consumes: Task 1 deleted `PLUGIN_TEXT_DOMAIN`, so no `PLUGIN_*` constant survives this task.
- Produces: `SIMPLE_EVENTS_DIR`, `SIMPLE_EVENTS_URL`, `SIMPLE_EVENTS_ASSETS`, `SIMPLE_EVENTS_VERSION`. Task 5's load gate uses `SIMPLE_EVENTS_DIR`; Task 4 defines the Freemius constants alongside these.

- [ ] **Step 1: Record the starting state**

```bash
for c in PLUGIN_DIR PLUGIN_URL PLUGIN_ASSETS PLUGIN_VERSION; do
  printf "%s " "$c"
  grep -rown "\b$c\b" --include=*.php . | grep -v node_modules | grep -v "^./dist" | wc -l
done
```

Expected: `PLUGIN_DIR 23`, `PLUGIN_URL 4`, `PLUGIN_ASSETS 10`, `PLUGIN_VERSION 12`.

- [ ] **Step 2: Rename, longest name first**

Order matters. `PLUGIN_URL` is a substring of nothing here, but `\b` word boundaries plus a longest-first order keep the substitutions independent regardless.

```bash
FILES=$(grep -rl "\bPLUGIN_\(DIR\|URL\|ASSETS\|VERSION\)\b" --include=*.php . \
  | grep -v node_modules | grep -v "^./dist")

sed -i 's/\bPLUGIN_VERSION\b/SIMPLE_EVENTS_VERSION/g' $FILES
sed -i 's/\bPLUGIN_ASSETS\b/SIMPLE_EVENTS_ASSETS/g'   $FILES
sed -i 's/\bPLUGIN_DIR\b/SIMPLE_EVENTS_DIR/g'         $FILES
sed -i 's/\bPLUGIN_URL\b/SIMPLE_EVENTS_URL/g'         $FILES
```

- [ ] **Step 3: Confirm the define block reads correctly**

`simple-events-calendar.php` should now hold exactly:

```php
// Define plugin constants
define('SIMPLE_EVENTS_DIR', __DIR__);
define('SIMPLE_EVENTS_URL', untrailingslashit(plugin_dir_url(__FILE__)));
define('SIMPLE_EVENTS_ASSETS', SIMPLE_EVENTS_URL . '/assets');
define('SIMPLE_EVENTS_VERSION', '5.3.0');
define('SIMPLE_EVENTS_PLUGIN_FILE', __FILE__);
define('SIMPLE_EVENTS_NONCE_ACTION', 'load_more_events_nonce');
```

The version string stays `5.3.0` here. Task 11 bumps it to `6.0.0` in one place along with every other version reference, so the bump is a single reviewable change.

- [ ] **Step 4: Make a reintroduced generic constant fail lint**

In `phpcs.xml`, delete this line from the `PrefixAllGlobals` `prefixes` array:

```xml
                <element value="PLUGIN_"/>
```

Leave `simple_events`, `Simple_Events`, and `SIMPLE_EVENTS_` in place — those are the internal prefixes the Global Constraints forbid renaming.

- [ ] **Step 5: Verify**

```bash
grep -rn "\bPLUGIN_[A-Z_]*\b" --include=*.php . | grep -v node_modules | grep -v "^./dist"
```

Expected: **no output**.

```bash
for f in $(git diff --name-only -- '*.php'); do php -l "$f" || break; done
phpcs
```

Expected: no syntax errors; no `PrefixAllGlobals` errors.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "refactor: prefix the plugin's global constants

PLUGIN_DIR, PLUGIN_URL, PLUGIN_ASSETS, and PLUGIN_VERSION sat unprefixed in
the global PHP namespace, where any other plugin defining the same name
fatals the site. WordPress.org review rejects them outright.

Renames all 49 occurrences to SIMPLE_EVENTS_*, matching the already-correct
SIMPLE_EVENTS_PLUGIN_FILE and SIMPLE_EVENTS_NONCE_ACTION, and drops PLUGIN_
from the phpcs PrefixAllGlobals prefixes array so a reintroduced generic
constant fails lint."
```

---

### Task 3: Rename the product and the main file

The plugin becomes **Simply Events Calendar**. WordPress.org derives the slug from the submitted plugin name, so the name and the slug are chosen together and frozen at submission.

Renaming the main file changes `plugin_basename()`, which is how WordPress identifies a plugin. Activation hooks, `register_uninstall_hook`, and the active-plugins list all key off it, so existing installs must deactivate the old plugin and install the new one. **No data moves** — see the changeover procedure documented in Task 11.

**Files:**
- Rename: `simple-events-calendar.php` → `simply-events-calendar.php`
- Modify: `README.md`, `package.json`, and the 20 PHP files carrying the product name in a docblock or a user-facing string
- Delete: `simple-events-calendar.zip`, `dist/`

**Interfaces:**
- Consumes: Tasks 1-2.
- Produces: `simply-events-calendar.php` as the plugin entry point. Task 4 inserts the Freemius bootstrap into it; Task 5 adds the `@fs_premium_only` tag to its header docblock.

- [ ] **Step 1: Rename the main file**

```bash
git mv simple-events-calendar.php simply-events-calendar.php
```

- [ ] **Step 2: Update the plugin header**

In `simply-events-calendar.php`, the header block becomes:

```php
/**
 * Plugin Name: Simply Events Calendar
 * Plugin URI: https://www.levelupstudios.com/simply-events-calendar/
 * Description: A simple, responsive events calendar for WordPress. Easily create one-time or recurring events and display them anywhere with shortcodes, Elementor widgets, and one-click Add to Calendar.
 * Version: 5.3.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Level Up Studios, LLC
 * Author URI: https://www.levelupstudios.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: simply-events-calendar
 * Domain Path: /languages
 *
 * @copyright Copyright (C) 2026 Level Up Studios, LLC
 *
 * Simply Events Calendar is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * Simply Events Calendar is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see https://www.gnu.org/licenses/.
 */
```

`Plugin URI` must move off GitHub: the canonical repo becomes private in Task 12, and a private repo URL 404s for every user. The wordpress.org plugin page does not exist until after approval, so levelupstudios.com is the value that is correct both before and after.

- [ ] **Step 3: Rename the product in PHP**

```bash
FILES=$(grep -rl "Simple Events Calendar" --include=*.php . | grep -v node_modules | grep -v "^./dist")
sed -i 's/Simple Events Calendar/Simply Events Calendar/g' $FILES
```

This covers 33 occurrences: file-header docblocks, four user-facing upsell strings in `includes/class-pro-upsell.php` (lines 183, 204, 237, 253), the CPT description at `includes/class-post-type.php:97`, the `.ics` `PRODID` at `includes/class-ics.php:156`, and two `error_log()` prefixes.

The `PRODID` change alters generated `.ics` output. That is correct and harmless — `PRODID` identifies the generating software, and calendar clients do not key off it.

- [ ] **Step 4: Rename the product in the JS-facing and packaging files**

In `package.json`, change the first two fields:

```json
  "name": "simply-events-calendar",
  "version": "5.3.0",
  "description": "A simple, responsive events calendar for WordPress. Easily create one-time or recurring events and display them anywhere with shortcodes, Elementor widgets, and one-click Add to Calendar.",
```

In `README.md`, replace every "Simple Events Calendar" with "Simply Events Calendar". The 4.x upgrade note and the feature list keep their wording otherwise.

`readme.txt` is rewritten wholesale in Task 9 — leave it alone here.

- [ ] **Step 5: Delete the stale build artifacts**

`dist/` and `simple-events-calendar.zip` are old v4-era output still carrying removed dependency text. Both are gitignored, so this only removes them from the working tree.

```bash
rm -rf dist simple-events-calendar.zip
```

- [ ] **Step 6: Verify**

```bash
grep -rn "Simple Events Calendar" --include=*.php --include=*.json . | grep -v node_modules
```

Expected: **no output**.

```bash
ls simply-events-calendar.php && php -l simply-events-calendar.php
for f in $(git diff --name-only -- '*.php'); do php -l "$f" || break; done
```

Expected: the file exists; no syntax errors.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "refactor: rename the product to Simply Events Calendar

WordPress.org derives a plugin's permanent slug from its submitted name, so
the name and slug are chosen together. simple-events-calendar is taken and
permanently closed; simply-events-calendar is free, carries no tier marker,
and keeps the phrase users search for.

Renames the main file to simply-events-calendar.php to match the slug. This
changes plugin_basename(), so WordPress treats this as a different plugin and
existing installs need a manual deactivate/reinstall - documented in Task 11.
No stored data is affected: the post type, taxonomy, meta keys, and settings
option all keep their names.

Repoints Plugin URI away from the GitHub repo, which goes private."
```

---

### Task 4: Vendor the Freemius SDK and bootstrap it

The SDK loads before `plugins_loaded` so its own hooks — license activation, account pages, the opt-in connect screen — register in time.

Ordering inside the main file is fixed and matters: `sec_fs()` reads `SIMPLE_EVENTS_FS_ID`, so the key constants must be defined before it; and `class-main.php` must load after `sec_fs()` exists, because Task 5's load gate calls it.

**Files:**
- Create: `freemius/` (copied verbatim from the Pro repo)
- Modify: `simply-events-calendar.php`

**Interfaces:**
- Consumes: `SIMPLE_EVENTS_DIR` from Task 2.
- Produces: the global function `sec_fs(): Freemius`, and the action `sec_fs_loaded`. Task 5 calls `sec_fs()->is__premium_only()`; Task 6 calls `sec_fs()->can_use_premium_code()`, `->get_upgrade_url()`.

- [ ] **Step 1: Copy the SDK**

```bash
cp -r "G:/Web Projects/simple-events-calendar-pro/freemius" ./freemius
ls freemius/start.php freemius/includes/class-freemius.php
```

Expected: both paths exist. The SDK is vendored code — never edit anything under `freemius/`.

- [ ] **Step 2: Add the key constants and the SDK bootstrap**

In `simply-events-calendar.php`, immediately after the `ABSPATH` guard and **before** the `// Define plugin constants` block, insert:

```php
/*
 * Freemius product credentials. The product ID and public key are not secrets
 * and ship inside the plugin by design. The SECRET key is never placed in this
 * repo — set it in wp-config.php on a sandbox while testing, then remove it.
 */
if (!defined('SIMPLE_EVENTS_FS_ID')) {
    define('SIMPLE_EVENTS_FS_ID', '<product id>');
}
if (!defined('SIMPLE_EVENTS_FS_PUBLIC_KEY')) {
    define('SIMPLE_EVENTS_FS_PUBLIC_KEY', '<pk_…>');
}

if (!function_exists('sec_fs')) {
    /**
     * Freemius SDK singleton accessor.
     *
     * @return Freemius
     */
    function sec_fs()
    {
        global $sec_fs;

        if (!isset($sec_fs)) {
            require_once __DIR__ . '/freemius/start.php';

            $sec_fs = fs_dynamic_init(array(
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
            ));
        }

        return $sec_fs;
    }

    sec_fs();
    do_action('sec_fs_loaded');
}
```

Replace `<product id>` and `<pk_…>` with the real values from the Freemius dashboard (product → Settings → Keys). Both are safe to commit.

Four config keys carry consequences worth knowing:

- **`anonymous_mode => true`** is what satisfies WordPress.org's requirement that usage tracking be skippable. The old Pro plugin set `is_premium_only => true`, and `freemius/includes/class-freemius.php:5287` shows that key force-disables anonymous mode. It **must not** carry over — omit `is_premium_only` entirely.
- **`is_org_compliant => true`** because a free version now lives on wp.org.
- **`pricing`, `contact`, `support` all `false`** because the plugin keeps its own Events → Upgrade to Pro page. Leaving them on produces duplicate menu items.
- **`premium_suffix => '(Pro)'`** makes the premium build read "Simply Events Calendar (Pro)" in the plugins list.

- [ ] **Step 3: Confirm the resulting file order**

Read `simply-events-calendar.php` top to bottom. It must run in exactly this order:

1. Header docblock
2. `ABSPATH` guard
3. `SIMPLE_EVENTS_FS_ID` / `SIMPLE_EVENTS_FS_PUBLIC_KEY`
4. `sec_fs()` definition, `sec_fs()` invocation, `do_action('sec_fs_loaded')`
5. `SIMPLE_EVENTS_DIR` and the remaining plugin constants
6. `require_once SIMPLE_EVENTS_DIR . '/includes/class-main.php';` and `simple_events_calendar();`

If the constants block sits above `sec_fs()`, move `sec_fs()` up. If `class-main.php` loads before `sec_fs()` is defined, Task 5's gate fatals on an undefined function.

- [ ] **Step 4: Verify**

```bash
php -l simply-events-calendar.php
grep -n "sk_" simply-events-calendar.php
```

Expected: no syntax errors, and **no output** from the second command. A match means a secret key was pasted in — remove it before committing and rotate the key in the Freemius dashboard.

Then, on a local WordPress install with the plugin active: load wp-admin and confirm no PHP notices, and that an "Opt In"/"Skip" connect screen appears rather than being forced.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(freemius): vendor SDK 2.13.1 and bootstrap licensing

Adds the Freemius SDK and a sec_fs() singleton to the main file, ordered so
the SDK initialises before plugins_loaded and before class-main.php - the
premium load gate calls sec_fs(), so the function must already exist.

anonymous_mode is on and is_premium_only is omitted: WordPress.org requires
usage tracking to be skippable, and class-freemius.php:5287 shows
is_premium_only force-disables anonymous mode. The Pro add-on set that key;
it must not carry over.

Only the product ID and public key are committed. Both are non-secret and
ship in the plugin by design; the secret key stays out of the repo."
```

---

### Task 5: Premium directory, loader, and the two strip markers

This is the task the whole architecture rests on. Everything premium goes in `includes/pro/`, and exactly two markers tell the Freemius processor to strip it from the free build.

**Files:**
- Create: `includes/pro/class-pro-loader.php`
- Create: `includes/pro/features/class-configurable-urls.php`
- Modify: `simply-events-calendar.php` (header docblock)
- Modify: `includes/class-main.php` (`load_components()`)

**Interfaces:**
- Consumes: `sec_fs()` from Task 4; `SIMPLE_EVENTS_DIR` from Task 2; the five v5.3.0 extension hooks, verified present at `includes/class-post-type.php:119`, `includes/class-settings.php:146`, `includes/class-settings.php:366`, `includes/functions.php:179`.
- Produces: `Simple_Events_Pro_Loader::init(): void`, and the class `Sec_Pro_Configurable_Urls` with `register(): void`.

- [ ] **Step 1: Add the directory-exclusion marker**

In `simply-events-calendar.php`, inside the header docblock, add this line immediately after `Domain Path`:

```php
 * @fs_premium_only /includes/pro/
```

That tag is the whole instruction to the deployment processor: it removes the named path when generating the free build.

- [ ] **Step 2: Create the premium loader**

Create `includes/pro/class-pro-loader.php`:

```php
<?php

/**
 * Premium feature loader.
 *
 * This file and everything beside it under includes/pro/ is stripped from the
 * free build by the Freemius deployment processor (see the @fs_premium_only
 * tag in the main plugin file). Nothing here may be referenced from free code
 * except through the guarded gate in Simple_Events_Calendar::load_components().
 *
 * @package Simply_Events_Calendar
 * @since 6.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple_Events_Pro_Loader class
 */
class Simple_Events_Pro_Loader {

    /**
     * Whether init() has already run.
     *
     * @var bool
     */
    private static $initialized = false;

    /**
     * Instantiate and register every premium feature module.
     *
     * Gated on can_use_premium_code(): an unlicensed premium build takes this
     * path but registers nothing, so it behaves exactly like the free build.
     */
    public static function init() {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;

        if (!function_exists('sec_fs') || !sec_fs()->can_use_premium_code()) {
            return;
        }

        require_once SIMPLE_EVENTS_DIR . '/includes/pro/features/class-configurable-urls.php';

        $configurable_urls = new Sec_Pro_Configurable_Urls();
        $configurable_urls->register();
    }
}
```

The `$initialized` guard mirrors the one already in `Simple_Events_Calendar::init()` and stops a double `register()` from double-hooking the filters.

- [ ] **Step 3: Port the configurable-URLs feature**

```bash
mkdir -p includes/pro/features
cp "G:/Web Projects/simple-events-calendar-pro/includes/features/class-configurable-urls.php" \
   includes/pro/features/class-configurable-urls.php
```

Then edit the copied file:

1. Change the `@package` tag from `Simple_Events_Calendar_Pro` to `Simply_Events_Calendar`.
2. Replace all four `'simple_events'` text-domain arguments with `'simply-events-calendar'` (lines rendering the Permalinks section: the `Permalinks` heading, `Event URL base` label, the `aria-label`, and the two `description` strings).
3. In the class docblock, change "Implemented entirely through the free plugin's v5.3.0 extension hooks" to "Implemented entirely through the plugin's public extension hooks" — there is no separate free plugin any more.

Everything else stays. The class keeps using the public hooks rather than reaching into base classes: it already works that way, and every release then exercises the public API that third-party developers depend on.

```bash
sed -i "s/'simple_events'/'simply-events-calendar'/g" includes/pro/features/class-configurable-urls.php
grep -c "simply-events-calendar" includes/pro/features/class-configurable-urls.php
```

Expected: `5` or more.

- [ ] **Step 4: Add the load gate**

In `includes/class-main.php`, at the end of `load_components()` — after the `Simple_Events_Elementor::init();` line — add:

```php
        // Premium features. includes/pro/ is stripped from the free build, so
        // this block is a no-op there even if the processor leaves it behind.
        if (sec_fs()->is__premium_only()) {
            $pro = SIMPLE_EVENTS_DIR . '/includes/pro/class-pro-loader.php';
            if (file_exists($pro)) {
                require_once $pro;
                Simple_Events_Pro_Loader::init();
            }
        }
```

Two things about this block are load-bearing:

- **The condition must be exactly `sec_fs()->is__premium_only()`** and nothing else. The processor pattern-matches this construct. Writing it as a compound condition — `if (function_exists('sec_fs') && sec_fs()->is__premium_only())` — risks the processor failing to recognise it and leaving the block in the free build.
- **The `file_exists()` guard is deliberate defence in depth.** It makes the two failure modes independent: if the directory is stripped but the gate is not, the free build no-ops instead of fataling on a missing `require_once`.

- [ ] **Step 5: Verify**

```bash
php -l includes/class-main.php
php -l includes/pro/class-pro-loader.php
php -l includes/pro/features/class-configurable-urls.php
grep -n "is__premium_only" includes/class-main.php
grep -n "@fs_premium_only" simply-events-calendar.php
```

Expected: no syntax errors; exactly one `is__premium_only` line in plugin code; exactly one `@fs_premium_only` line.

On a local WordPress install with the premium build and a sandbox license active: Events → Settings shows a **Permalinks** section with an **Event URL base** field. Change the base, save, and confirm single-event URLs move to the new base after the `init:11` flush. Deactivate the license and confirm the field disappears and the base returns to `/events/`.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(pro): add includes/pro/ with the premium load gate

Establishes the single location premium code may occupy, and the two markers
that strip it from the free build: an @fs_premium_only tag in the main file's
header docblock, and one is__premium_only() gate in load_components().

The gate is written as the exact documented single-condition form. The
processor pattern-matches that construct, so a compound condition risks the
block surviving into the free build. The file_exists() guard beside it makes
the two failure modes independent - a stripped directory with a surviving
gate no-ops instead of fataling.

Ports Sec_Pro_Configurable_Urls from the Pro repo. It keeps using the public
extension hooks rather than calling base classes directly, so every release
exercises the API third-party developers depend on."
```

---

### Task 6: Point the upsell surfaces at Freemius

`Simple_Events_Pro_Upsell` currently reads a filter that nothing sets and links to a static marketing URL. Both now come from the SDK — while staying filterable, so a developer can force either during development.

**Files:**
- Modify: `includes/class-pro-upsell.php:54-56` (`is_pro_active()`), `:63-65` (`pro_url()`)

**Interfaces:**
- Consumes: `sec_fs()->can_use_premium_code()`, `sec_fs()->get_upgrade_url()` from Task 4.
- Produces: unchanged public signatures — `Simple_Events_Pro_Upsell::is_pro_active(): bool` and `::pro_url(): string`. Every existing caller (`banner()`, `locked_section()`, `enqueue()`, the Upgrade submenu registration) keeps working untouched.

- [ ] **Step 1: Rewire `is_pro_active()`**

Replace `includes/class-pro-upsell.php:54-56`:

```php
    public static function is_pro_active() {
        return (bool) apply_filters('simple_events_pro_active', false);
    }
```

with:

```php
    public static function is_pro_active() {
        $active = function_exists('sec_fs') && sec_fs()->can_use_premium_code();

        return (bool) apply_filters('simple_events_pro_active', $active);
    }
```

The `function_exists()` check here is fine and is **not** the pattern Task 5 forbids — that restriction applies only to the `is__premium_only()` gate the processor parses.

- [ ] **Step 2: Rewire `pro_url()`**

Replace `includes/class-pro-upsell.php:63-65`:

```php
    public static function pro_url() {
        return apply_filters('simple_events_pro_url', 'https://levelupstudios.com/simple-events-calendar-pro/');
    }
```

with:

```php
    public static function pro_url() {
        $url = function_exists('sec_fs')
            ? sec_fs()->get_upgrade_url()
            : 'https://www.levelupstudios.com/simply-events-calendar/';

        return apply_filters('simple_events_pro_url', $url);
    }
```

- [ ] **Step 3: Verify**

```bash
php -l includes/class-pro-upsell.php
```

On a local install running the **free** build with no license: the CTA banner appears on Events → Settings and Events → Documentation, the "Available in Pro" locked section renders at the bottom of Settings, the Events → Upgrade to Pro menu item is present, and its button points at a Freemius checkout URL.

On the **premium** build with a sandbox license active: all four surfaces are gone.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "feat(upsell): drive the Pro surfaces from the Freemius license

is_pro_active() now reads can_use_premium_code() and pro_url() returns the
SDK's checkout URL. Both stay wrapped in their existing filters so the values
can still be forced during development.

Public signatures are unchanged, so the banner, locked preview section,
Upgrade page, and asset-enqueue gate all keep working as they are."
```

---

### Task 7: String correctness pass

Making strings *extractable* (Task 1) is not the same as making them *translatable*. This pass fixes what a translator would otherwise receive as unusable.

Current state: 8 translator comments across the codebase, 0 uses of `_n()`, 4 uses of `_x()`, and 17 strings containing placeholders.

**Files:**
- Modify: `includes/class-admin-columns.php:122,154`, `includes/class-meta-box.php:113-118`, `includes/class-recurrence.php:491,542,1196`, `includes/class-shortcode.php:273,357`, `includes/functions.php:883`, `template-parts/content-event-card.php:102,104,126,132,167`

**Interfaces:**
- Consumes: the literal text domain from Task 1.
- Produces: the final English source strings. Task 8 extracts the `.pot` from them, so this task must land first or the template is stale on arrival.

- [ ] **Step 1: Add a translator comment above every placeholder string**

`WordPress.WP.I18n` fails a string containing a placeholder with no `translators:` comment. The comment goes immediately above the call, and it must say what each placeholder holds — a translator sees the string, never the code.

Example, at `includes/class-admin-columns.php:122`:

```php
                /* translators: %d is the occurrence's position in the recurring series, e.g. 3 */
                esc_html__('Occurrence #%d', 'simply-events-calendar'),
```

At `includes/class-admin-columns.php:154`:

```php
            /* translators: 1: recurrence frequency, e.g. "Weekly"; 2: number of additional occurrences */
            esc_html__('%1$s series (+%2$d)', 'simply-events-calendar'),
```

At `template-parts/content-event-card.php:102`:

```php
                <?php /* translators: %s is the event title */ ?>
                aria-label="<?php printf(__('View event: %s', 'simply-events-calendar'), $title); ?>">
```

Apply the same treatment to each remaining placeholder string:
`includes/class-meta-box.php:113,114,116,118`; `includes/class-recurrence.php:491,542,1196`; `includes/class-shortcode.php:273,357`; `includes/functions.php:883`; `template-parts/content-event-card.php:104,126,132,167`.

- [ ] **Step 2: Convert the server-side plurals to `_n()`**

Three strings hedge with "(s)" instead of pluralising. Many languages have more than two plural forms, and `_n()` is how a translator reaches them.

At `includes/class-recurrence.php:1196`, the count variable is `$created_this_pass`. Replace:

```php
                    /* translators: %d is the number of occurrences created in the foreground pass */
                    __('Created %d occurrence(s) so far; the remaining occurrences will be generated in the background within a few minutes.', 'simply-events-calendar'),
                    $created_this_pass
```

with:

```php
                    /* translators: %d is the number of occurrences created in the foreground pass */
                    _n(
                        'Created %d occurrence so far; the remaining occurrences will be generated in the background within a few minutes.',
                        'Created %d occurrences so far; the remaining occurrences will be generated in the background within a few minutes.',
                        $created_this_pass,
                        'simply-events-calendar'
                    ),
                    $created_this_pass
```

The count is passed twice on purpose: `_n()` uses it to choose the form, and `sprintf()` uses it to fill `%d`.

At `includes/class-recurrence.php:491` the string interpolates two independent counts (`$deleted` and `$detached`), so one `_n()` cannot serve both. Split it into two pluralised halves joined by a wrapper. Replace:

```php
            sprintf(
                /* translators: 1: future unmodified occurrences deleted, 2: past / modified / trashed occurrences kept as standalone events */
                __('Recurrence disabled. %1$d future unmodified occurrence(s) deleted; %2$d occurrence(s) (past, edited, or trashed) kept as standalone events.', 'simply-events-calendar'),
                $deleted,
                $detached
            ),
```

with:

```php
            sprintf(
                /* translators: 1: sentence about deleted occurrences, 2: sentence about kept occurrences */
                __('Recurrence disabled. %1$s %2$s', 'simply-events-calendar'),
                sprintf(
                    /* translators: %d is the number of future unmodified occurrences deleted */
                    _n(
                        '%d future unmodified occurrence deleted.',
                        '%d future unmodified occurrences deleted.',
                        $deleted,
                        'simply-events-calendar'
                    ),
                    $deleted
                ),
                sprintf(
                    /* translators: %d is the number of past, edited, or trashed occurrences kept as standalone events */
                    _n(
                        '%d occurrence (past, edited, or trashed) kept as a standalone event.',
                        '%d occurrences (past, edited, or trashed) kept as standalone events.',
                        $detached,
                        'simply-events-calendar'
                    ),
                    $detached
                )
            ),
```

The split is what makes this translatable in a language with three or more plural forms; a single string with two `(s)` hedges cannot be rendered correctly in one.

- [ ] **Step 3: Leave the JS plural pair alone, and document why**

`includes/class-meta-box.php:113-114` defines `countOne` / `countMany` and hands both to JavaScript through `wp_localize_script`. The count is not known in PHP, so `_n()` cannot apply — JS picks between them at runtime. This is a two-form approximation that will read wrong in languages with three or more plural forms, and fixing it properly means moving the summary to `wp.i18n` with a JSON language pack, which is out of scope here.

Add a comment above the pair so the next reader does not "fix" it into a broken `_n()`:

```php
            // Plural pair for the JS-rendered recurrence summary. The count is
            // only known client-side, so _n() cannot apply; the JS picks one.
            // Two-form approximation - languages with more forms read wrong here.
```

- [ ] **Step 4: Add context to ambiguous short strings**

Single words translate differently by role. Wherever a bare `__('Date', …)`, `__('Time', …)`, `__('Location', …)`, or `__('Order', …)` is used as a column heading or a form label, convert it to `_x()` with the role as context:

```php
_x('Date', 'admin list table column heading', 'simply-events-calendar')
```

Find them with:

```bash
grep -rnE "(__|esc_html__|esc_attr__)\('(Date|Time|Location|Order|Categories|Title|Image|Content|Excerpt|Button)'" \
  --include=*.php . | grep -v node_modules
```

- [ ] **Step 5: Verify**

```bash
for f in $(git diff --name-only -- '*.php'); do php -l "$f" || break; done
phpcs --sniffs=WordPress.WP.I18n
```

Expected: no syntax errors, and no `MissingTranslatorsComment` or `MissingSingularPlaceholder` errors.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "fix(i18n): make the strings translatable, not merely extractable

Task 1 got every string into the .pot. This makes them usable once there.

Adds translator comments to all 17 placeholder strings - a translator sees
the string and never the code, so an unexplained %1\$s is a guess. Converts
the server-side '(s)' hedges to _n(), which is the only form that reaches the
three-plural-form languages. Adds _x() context to bare single-word labels
whose translation depends on their role.

Leaves the JS-localized plural pair in the meta box as two strings, with a
comment explaining why: the count is only known client-side, so _n() cannot
apply there."
```

---

### Task 8: i18n tooling and refreshed catalogues

The project has no i18n build step, the `.pot` is stale, and the `es_ES`/`fr_FR` catalogues predate v5.1.0. They are also missing every string that was behind `PLUGIN_TEXT_DOMAIN`.

The premium build gets **no language packs** — WordPress fetches packs only for plugins it hosts, and the premium build updates through Freemius. So `languages/` ships in both builds and `.mo` compilation has to run in the release pipeline. In the free build the bundled files are a harmless fallback: `load_plugin_textdomain()` checks `WP_LANG_DIR/plugins/` first, so a downloaded pack always wins.

**Files:**
- Modify: `package.json` (scripts)
- Modify: `languages/simply-events-calendar.pot`, `languages/simply-events-calendar-es_ES.po`, `languages/simply-events-calendar-fr_FR.po`
- Regenerate: the two `.mo` files

**Interfaces:**
- Consumes: final English strings from Task 7.
- Produces: `npm run i18n:pot` and `npm run i18n:mo`. Task 10's release pipeline calls both.

- [ ] **Step 1: Confirm WP-CLI is available**

```bash
wp --info
```

Expected: WP-CLI version output. If the command is missing, install WP-CLI before continuing — it is a development dependency for this task and Task 10, and ships in neither build.

- [ ] **Step 2: Add the two npm scripts**

In `package.json`, add to `"scripts"`, after `"lint"`:

```json
    "i18n:pot": "wp i18n make-pot . languages/simply-events-calendar.pot --slug=simply-events-calendar --exclude=node_modules,dist,src,docs,freemius",
    "i18n:mo": "wp i18n make-mo languages languages",
```

`freemius` is excluded because the SDK carries its own text domain and its own bundled translations — extracting its strings into our template would hand translators hundreds of strings that never render under our domain.

- [ ] **Step 3: Regenerate the template**

```bash
npm run i18n:pot
grep -c "^msgid " languages/simply-events-calendar.pot
```

Expected: a string count materially higher than the previous template's, because the 105 previously-unextractable strings now appear. Record both numbers — the changelog entry in Task 11 cites the improvement.

- [ ] **Step 4: Merge the new strings into each catalogue**

```bash
msgmerge --update --backup=none languages/simply-events-calendar-es_ES.po languages/simply-events-calendar.pot
msgmerge --update --backup=none languages/simply-events-calendar-fr_FR.po languages/simply-events-calendar.pot
```

`msgmerge` ships with gettext. If it is unavailable, Poedit performs the same merge through its "Update from POT file" command.

- [ ] **Step 5: Translate the gaps**

Every newly added msgid arrives untranslated. Fill them in both catalogues.

Machine translation is acceptable for the first pass **provided every machine-translated entry is marked fuzzy** (`#, fuzzy`) and read by a human before the flag is cleared. Admin strings carry destructive verbs — "delete", "uninstall", "remove", "force-delete" — and a bad translation there has real consequences for a site owner. Review those first:

```bash
grep -n "delete\|uninstall\|remove\|permanently" languages/simply-events-calendar.pot
```

- [ ] **Step 6: Compile**

```bash
npm run i18n:mo
ls -la languages/
```

Expected: `simply-events-calendar-es_ES.mo` and `simply-events-calendar-fr_FR.mo` both newer than their `.po` sources.

- [ ] **Step 7: Verify in a running site**

Switch the local WordPress install to `es_ES`, then `fr_FR` (Settings → General → Site Language). In each:

- Admin strings resolve: the Events → Settings page, the Event Details meta box, the admin list-table columns.
- Front-end strings resolve: an event card, the single-event details card, the empty state.
- Spot-check at least three strings that were previously behind `PLUGIN_TEXT_DOMAIN` — they were untranslatable before this work, so they are the proof it landed. `includes/class-post-type.php:97` and `includes/class-recurrence.php:542` are convenient ones.
- Every placeholder renders with its value substituted, in both locales and in English. A mismatched `sprintf()` arity surfaces here and nowhere else.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat(i18n): add make-pot/make-mo scripts and refresh the catalogues

The premium build never receives WordPress.org language packs - WordPress
fetches those only for plugins it hosts, and premium updates come from
Freemius. So languages/ ships in both builds and .mo compilation has to be
part of the release pipeline, not a manual step.

Regenerates the template, which now carries the strings that were previously
unreachable behind the text-domain constant, merges them into the es_ES and
fr_FR catalogues, and compiles both. Excludes freemius/ from extraction: the
SDK has its own domain and its own translations."
```

---

### Task 9: readme.txt for WordPress.org

The current `readme.txt` names the wrong product, carries a stale stable tag, and discloses no external service. The last of those is a hard review blocker: any plugin that talks to a third-party service must say so, in detail, before it will be approved.

**Files:**
- Modify: `readme.txt`

**Interfaces:**
- Consumes: the product name and slug from Task 3.
- Produces: the readme WordPress.org reviews. Task 11 adds the 6.0.0 changelog section and the upgrade notice to it.

- [ ] **Step 1: Update the header block**

```
=== Simply Events Calendar ===
Contributors: levelupstudios
Tags: events, calendar, event calendar, recurring events, elementor
Requires at least: 6.0
Tested up to: <current stable WordPress release at submission time>
Requires PHP: 7.4
Stable tag: 6.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
```

`Tags` is capped at five by WordPress.org; extras are ignored silently.

- [ ] **Step 2: Add the External services section**

Place it after the Description and before Installation. This exact disclosure — what service, what data, when, and links to its terms and privacy policy — is what the requirement asks for:

```
== External services ==

This plugin connects to Freemius, a third-party licensing and update service,
to manage software licenses, deliver plugin updates, and — only if you opt in —
collect anonymous usage data.

**When it connects, and what it sends:**

* **On activation:** the plugin shows an opt-in screen. If you choose "Skip",
  no data is sent and the plugin works normally. Nothing is transmitted before
  you make that choice.
* **If you opt in:** your site URL, WordPress and PHP versions, the plugin
  version, active theme and plugin names, and the email address of the
  activating administrator are sent to Freemius. This is used for update
  delivery and anonymous usage statistics.
* **If you purchase a license:** your license key and site URL are sent to
  Freemius to activate and validate that license, and again periodically to
  confirm it remains valid.
* **On deactivation:** if you opt to share a reason, that reason is sent.

Service provider: Freemius, Inc. — https://freemius.com/
Terms of service: https://freemius.com/terms/
Privacy policy: https://freemius.com/privacy/
```

- [ ] **Step 3: Rewrite the description, FAQ, and screenshots**

- Replace every occurrence of the old product name with "Simply Events Calendar".
- Rewrite the FAQ for someone who has never used this plugin. The wp.org listing is a new listing with no upgrade history, so questions framed around migrating from an older version do not belong there. Keep the questions about shortcodes, Elementor, recurring events, and theme overrides.
- Update the screenshot captions to match the current UI.

- [ ] **Step 4: Verify**

```bash
grep -n "Stable tag\|=== Simply Events Calendar ===\|== External services ==" readme.txt
grep -rn "Simple Events Calendar" readme.txt
```

Expected: the three header/section lines are present; the old name returns **no output** outside the historical changelog sections.

Paste the file into the WordPress.org readme validator at `https://wordpress.org/plugins/developers/readme-validator/` and resolve every warning it reports.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "docs(readme): rewrite readme.txt for the WordPress.org listing

Adds the External services section disclosing the Freemius connection - what
is sent, when, and under which user action - with links to its terms and
privacy policy. That disclosure is a hard review requirement for any plugin
contacting a third-party service, and its absence is a rejection.

Renames the product throughout, sets the 6.0.0 stable tag, and rewrites the
FAQ for a first-time reader: this is a new listing with no upgrade history,
so questions framed around migrating from an older version do not belong in
it."
```

---

### Task 10: Build pipeline and the free-build gate

Step 5 of this pipeline is the reason the whole thing exists: a premium-code leak becomes a failed command instead of something a human has to notice.

**Files:**
- Create: `bin/verify-free.sh`
- Modify: `package.json` (`dist`, `zip`, and a new `verify:free` script)

**Interfaces:**
- Consumes: `npm run i18n:mo` from Task 8.
- Produces: `npm run verify:free <path-to-zip>`.

- [ ] **Step 1: Exclude `docs/` from the distribution**

In `package.json`, the `dist` script's `robocopy` `/XD` list currently reads `node_modules src .git dist`. Add `docs` and `bin`:

```json
    "dist": "rm -rf dist && mkdir dist && robocopy . dist /E /XD node_modules src .git dist docs bin /XF .gitignore package.json package-lock.json .npmrc phpcs.xml *.md /NFL /NDL /NJH /NJS || echo 'Distribution created'",
```

`freemius/` is **not** excluded — the SDK must ship in both builds.

Note that `/XF *.md` already excludes `AGENTS.md`, `CLAUDE.md`, `README.md`, and `changelog.md`, while `readme.txt` correctly ships.

- [ ] **Step 2: Write the verification script**

Create `bin/verify-free.sh`:

```bash
#!/usr/bin/env bash
#
# Verifies a generated FREE build before it is pushed to WordPress.org SVN.
# Runs against the shipped artifact, not the source tree.
#
# Usage: npm run verify:free -- path/to/free-build.zip

set -u

ZIP="${1:-}"

if [ -z "$ZIP" ] || [ ! -f "$ZIP" ]; then
    echo "verify:free: usage: npm run verify:free -- <path-to-zip>" >&2
    exit 2
fi

FAILED=0

fail() {
    echo "verify:free: FAIL - $1" >&2
    FAILED=1
}

# Every file in the archive except the vendored SDK. The SDK legitimately
# defines is__premium_only() and its siblings in class-freemius-abstract.php,
# so scanning it would fail every build. The exclusion is scoped to that one
# directory; all plugin code is still covered.
FILES=$(unzip -Z1 "$ZIP" | grep -v '/freemius/' | grep -v '\.mo$' | grep -v '/$')

# 1. No premium directory.
if unzip -Z1 "$ZIP" | grep -q 'includes/pro/'; then
    fail "includes/pro/ is present in the free build"
fi

# 2. No premium gate left in plugin code.
for f in $FILES; do
    if unzip -p "$ZIP" "$f" 2>/dev/null | grep -q 'is__premium_only'; then
        fail "is__premium_only found outside freemius/: $f"
    fi
done

# 3. No secret key anywhere in plugin code.
for f in $FILES; do
    if unzip -p "$ZIP" "$f" 2>/dev/null | grep -q 'sk_'; then
        fail "possible Freemius secret key (sk_) found in: $f"
    fi
done

# 4. Correct text domain in the main file header.
MAIN=$(unzip -Z1 "$ZIP" | grep -E 'simply-events-calendar\.php$' | head -1)
if [ -z "$MAIN" ]; then
    fail "main file simply-events-calendar.php not found in the archive"
else
    DOMAIN=$(unzip -p "$ZIP" "$MAIN" | grep -m1 '^ \* Text Domain:' | sed 's/.*Text Domain:[[:space:]]*//' | tr -d '\r')
    if [ "$DOMAIN" != "simply-events-calendar" ]; then
        fail "Text Domain header is '$DOMAIN', expected 'simply-events-calendar'"
    fi
fi

# 5. A compiled .mo beside every .po.
for po in $(unzip -Z1 "$ZIP" | grep '\.po$'); do
    mo="${po%.po}.mo"
    if ! unzip -Z1 "$ZIP" | grep -qx "$mo"; then
        fail "no compiled .mo for $po"
    fi
done

if [ "$FAILED" -eq 0 ]; then
    echo "verify:free: PASS - $ZIP"
fi

exit "$FAILED"
```

- [ ] **Step 3: Register the npm script**

In `package.json`, add:

```json
    "verify:free": "bash bin/verify-free.sh",
```

- [ ] **Step 4: Prove the script fails when it should**

A gate that has never failed is not known to work. Build a **premium** ZIP — which legitimately contains `includes/pro/` — and point the verifier at it:

```bash
npm run build
npm run i18n:pot && npm run i18n:mo
npm run zip
npm run verify:free -- simply-events-calendar.zip
echo "exit: $?"
```

Expected: it FAILS with `includes/pro/ is present in the free build`, and `exit: 1`. If it passes, the script is not inspecting the archive correctly — fix it before trusting it.

- [ ] **Step 5: Verify the pass path**

The real pass run happens in Task 12 against the free ZIP that Freemius generates. For now, confirm the script's other four checks work by unzipping the premium build, deleting `includes/pro/`, rezipping, and re-running. It should report `PASS`.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "build: add the verify:free release gate

Inspects a generated free build for the four ways premium code or credentials
could leak: a surviving includes/pro/, an is__premium_only gate left in plugin
code, an sk_ secret key, or a wrong text domain. Also asserts a compiled .mo
beside every .po, since the premium build gets no language packs.

Scans exclude freemius/ deliberately, not incidentally: the SDK defines
is__premium_only() and its siblings itself, so a repo-wide scan would fail
every build. Plugin code stays fully covered.

Runs against the shipped artifact rather than the source tree, so a leak is a
failed command rather than something a human has to notice. Excludes docs/ and
bin/ from the distribution."
```

---

### Task 11: Version bump, changelog, and the changeover procedure

Existing sites — including client sites on 5.3.0 — need a manual changeover, because the folder and main file both change and `plugin_basename()` changes with them. The procedure has to be published, not left as tribal knowledge.

**Files:**
- Modify: `simply-events-calendar.php` (header `Version`, `SIMPLE_EVENTS_VERSION`)
- Modify: `package.json` (`version`)
- Modify: `changelog.md`, `readme.txt`
- Modify: `docs/superpowers/specs/2026-09-05-freemium-single-codebase-design.md` (correct the call-site counts)

**Interfaces:**
- Consumes: everything above.
- Produces: the 6.0.0 release, ready for a dry-run deployment.

- [ ] **Step 1: Bump the version in all four places**

```bash
sed -i 's/ \* Version: 5\.3\.0/ * Version: 6.0.0/' simply-events-calendar.php
sed -i "s/define('SIMPLE_EVENTS_VERSION', '5\.3\.0')/define('SIMPLE_EVENTS_VERSION', '6.0.0')/" simply-events-calendar.php
sed -i 's/"version": "5\.3\.0"/"version": "6.0.0"/' package.json
grep -n "6\.0\.0" simply-events-calendar.php package.json readme.txt
```

Expected: the header, the constant, `package.json`, and the `Stable tag` set in Task 9 all read `6.0.0`.

`Simple_Events_Shortcode` mixes the version into its transient cache keys, so every cached listing self-invalidates on upgrade. Nothing extra to do.

- [ ] **Step 2: Write the changelog entry**

Add to the top of `changelog.md`:

```markdown
## [v6.0.0] (2026-09-07)

### Changed

* **The plugin is now Simply Events Calendar.** One codebase now produces both
  the free build and the licensed Pro build of the same plugin, so there is one
  plugin to install and manage rather than two. Entering a license upgrades the
  plugin in place.
* **The plugin folder and main file are renamed** to `simply-events-calendar`.
  WordPress identifies a plugin by that path, so this release requires a manual
  changeover — see below. **No event data is affected.**
* Global constants `PLUGIN_DIR`, `PLUGIN_URL`, `PLUGIN_ASSETS`, and
  `PLUGIN_VERSION` are now `SIMPLE_EVENTS_*`. Anything reading them directly
  needs updating; they were unprefixed globals that could collide with any other
  plugin.

### Fixed

* **105 admin and front-end strings were untranslatable and now are not.** They
  passed the text domain as a PHP constant, and WordPress extracts translatable
  strings by reading literals out of the source, so those strings never reached
  the translation template in any locale. All 385 call sites now use a literal
  domain matching the plugin slug.
* Placeholder strings carry translator comments, "(s)" plural hedges are now
  real plural forms, and ambiguous single-word labels carry context — so
  translators can produce correct output rather than guesses.

### Added

* Licensing, updates, and optional usage analytics through Freemius. The opt-in
  is skippable; choosing "Skip" transmits nothing. See the External services
  section of readme.txt for exactly what is sent and when.

### Migration / compatibility

**Your data is safe.** Events are posts, their fields are post meta, categories
are terms, and settings are a single option. None of those names change in this
release, and deactivating a plugin never deletes them.

Because the plugin folder changed, WordPress treats this as a different plugin,
so the changeover is manual:

1. Go to **Events → Settings → Data** and confirm "Delete data on uninstall" is
   set to **No**. (No is the default.)
2. Back up your database anyway.
3. **Deactivate** the old plugin. Do not delete it yet — running both plugins at
   once fatals on duplicate class and post-type declarations.
4. Install and activate Simply Events Calendar.
5. Confirm your events, dates, categories, recurring series, and settings are
   all present.
6. Visit **Settings → Permalinks** once to flush rewrite rules.
7. Delete the old plugin.

For a zero-risk step 7, delete the old plugin's folder over SFTP or your host's
file manager instead of through the WordPress admin. Removing files directly
never fires the uninstall hook, which makes the delete-on-uninstall setting
irrelevant either way.
```

- [ ] **Step 3: Mirror the procedure into readme.txt**

Add a matching `= 6.0.0 =` entry under `== Changelog ==`, and an upgrade notice:

```
== Upgrade Notice ==

= 6.0.0 =
The plugin is renamed to Simply Events Calendar and its folder changes, so
WordPress sees it as a new plugin: deactivate the old one (do not delete it
yet), install this one, verify your events, then visit Settings → Permalinks.
Your events, categories, and settings are preserved — none of the data they
live in is renamed. Back up your database first.
```

- [ ] **Step 4: Correct the spec's call-site counts**

The spec's Text domain section claims 561 literal sites, 295 constant sites, and 856 total. The real counts, measured in this repo, are **280**, **105**, and **385**. Fix all three numbers in `docs/superpowers/specs/2026-09-05-freemium-single-codebase-design.md`, plus the "856-site text-domain edit" row in the Risks table.

Leave the spec's reasoning intact — the argument does not depend on the magnitude, and a spec that quietly disagrees with the code it produced is worse than one that is corrected in place.

- [ ] **Step 5: Verify**

```bash
grep -rn "5\.3\.0" simply-events-calendar.php package.json readme.txt
grep -rn "856\|561\|295" docs/superpowers/specs/2026-09-05-freemium-single-codebase-design.md
```

Expected: **no output** from either.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "chore: release 6.0.0

Bumps the version in the header, the SIMPLE_EVENTS_VERSION constant,
package.json, and the readme stable tag.

Documents the changeover in both the changelog and the readme upgrade notice.
The folder rename means WordPress sees a different plugin, so the deactivate/
install/verify/flush sequence has to be published rather than left as tribal
knowledge - and it has to say plainly that no event data is affected, because
that is the question every site owner will actually have.

Corrects the spec's text-domain call-site counts to the measured values:
280 literal + 105 constant = 385, not 561/295/856."
```

---

### Task 12: Dry-run deployment, full QA, and repo housekeeping

The first deployment is a dry run precisely because the processor's exact behaviour is documented but unverified here. Nothing reaches WordPress.org SVN until the generated free build has been downloaded and inspected.

**Files:**
- Modify: none in the plugin. This task is verification plus repository administration.

**Interfaces:**
- Consumes: everything above.
- Produces: a verified free build ready to submit, and an archived Pro repo.

- [ ] **Step 1: Lint everything, once, at the end**

```bash
npm run lint:css
phpcs
for f in $(git ls-files '*.php' | grep -v '^freemius/'); do php -l "$f" || break; done
```

Expected: CSS clean; no phpcs errors in plugin code; no PHP syntax errors. `freemius/` is excluded — vendored code is not ours to lint.

- [ ] **Step 2: Build and upload the premium ZIP**

```bash
npm run build
npm run i18n:pot && npm run i18n:mo
npm run zip
```

Upload `simply-events-calendar.zip` in the Freemius Developer Dashboard → Deployment. The processor emits both a premium and a free build.

- [ ] **Step 3: Gate the generated free build**

Download the generated **free** ZIP, then:

```bash
npm run verify:free -- path/to/generated-free-build.zip
echo "exit: $?"
```

Expected: `verify:free: PASS` and `exit: 0`. Any failure stops the release — do not push to SVN, fix the cause, and rebuild.

Then open the free ZIP by hand and confirm three things the script cannot judge: `includes/pro/` is absent, the `Plugin Name:` header reads `Simply Events Calendar` **without** the `(Pro)` suffix, and `languages/` is present with both `.mo` files.

- [ ] **Step 4: QA the free build**

Install the free ZIP on a clean local WordPress site.

- [ ] Activation produces no PHP notices or warnings.
- [ ] The Freemius opt-in screen appears and offers a **Skip** option; choosing Skip leaves the plugin fully working.
- [ ] Create a one-time event; date, time, location, and featured image all save and display.
- [ ] Create a weekly recurring event with by-day selections; occurrences generate on the correct dates.
- [ ] `[sec_events]`, `[sec_event id="…"]`, and the `[sec_event_*]` element shortcodes all render.
- [ ] Post-type archive, taxonomy archive, and single-event pages render.
- [ ] Load-more / infinite scroll continues the correct query, including inside a category archive.
- [ ] Add to Calendar downloads a valid `.ics` that imports into a calendar client.
- [ ] Elementor Events Grid, Single Event, and per-element widgets render.
- [ ] The upsell banner, the locked "Available in Pro" section, and the Events → Upgrade to Pro page all appear.

- [ ] **Step 5: QA the premium build**

Install the premium ZIP on a second clean site and activate a license in Freemius **sandbox** mode. Set the secret key in that site's `wp-config.php` only, and remove it when finished.

- [ ] `sec_fs()->can_use_premium_code()` returns true.
- [ ] Events → Settings shows the **Permalinks** section with the Event URL base field.
- [ ] Changing the base rewrites single-event permalinks after the one-time `init:11` flush.
- [ ] Deactivating the license reverts the base to `/events/` and hides the field.
- [ ] Every upsell surface is hidden.
- [ ] The plugins list shows "Simply Events Calendar (Pro)".

- [ ] **Step 6: QA the upgrade path from 5.3.0**

On a third site running a **populated** 5.3.0 install — real events, categories, a recurring series, customised settings — walk the published changeover procedure exactly as written in Task 11. Then confirm every event, every category assignment, every occurrence in the series, and every setting survived.

This is the step that protects existing client sites. Do not skip it, and do not substitute a fresh install for a populated one.

- [ ] **Step 7: Repository housekeeping**

```bash
git remote -v
git remote remove pro
```

Then, in the GitHub web UI:

- Archive `Level-Up-Studios-LLC/simple-events-calendar-pro`.
- Make `Level-Up-Studios-LLC/simple-events-calendar` **private**.

The `Plugin URI` already moved off GitHub in Task 3, so making the repo private breaks no user-facing link.

- [ ] **Step 8: Commit and open the PR**

```bash
git add -A
git commit -m "chore: verified 6.0.0 free and premium builds

Dry-run deployment through the Freemius processor. The generated free build
passes verify:free and was inspected by hand: no includes/pro/, correct
Plugin Name without the (Pro) suffix, languages/ present with both compiled
catalogues.

QA covered the free build, the premium build under a sandbox license, and the
upgrade path from a populated 5.3.0 install following the published changeover
procedure."
git push -u origin feat/single-codebase-freemium
```

Open a PR against `main` summarising the twelve tasks. WordPress.org submission itself is a manual step outside this plan: it needs the verified free ZIP from Step 3 and the readme from Task 9.

---

## What this plan does not cover

- **The WordPress.org submission and review.** The plan ends at "ready to submit." Review latency is outside anyone's control, which is why no feature release is queued behind it.
- **Post-approval translation setup.** Once approved, the GlotPress project appears at `translate.wordpress.org/projects/wp-plugins/simply-events-calendar`. Then: import the maintained `es_ES` and `fr_FR` catalogues to seed it, request Project Translation Editor rights so contributed strings can be approved rather than sitting as pending suggestions, and add a "Translations welcome" note with the GlotPress link to `readme.txt`.
- **Calendar / month view** and **ticketing / RSVPs.** Separate specs, separate plans.
