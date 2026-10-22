=== WooCommerce Pet Gallery ===
Requires at least: 5.7
Tested up to: 6.6
Stable tag: 1.7.5
License: Proprietary
Author: Cosmick Media (websupport@cosmickmedia.com)

Adopted-pet image gallery add-on for the Petkey, Unified KM, and Pinogy sync plugins. Includes a modern, theme-agnostic UI and adaptive lead capture.

== Description ==

WooCommerce Pet Gallery turns adopted pets synced by `wc-petkey-sync`, `wc-unified-km`, or `wc-pinogy-sync` into a beautiful "Forever Family Album" experience. Renders an editorial hero, a polaroid-style card grid stamped with a Forever Adopted sticker, and a smart inquiry CTA that reuses the active theme’s pet-inquiry modal when available (petland, petland-pep), or any configured Gravity Forms / Contact Form 7 / WPForms / custom shortcode, and falls back gracefully to a contact-page link with `?pet={ref}`.

== Shortcodes ==

* `[pet-gallery]` — full hero + filter bar + grid. Accepts `hero`, `stats`, `columns`, `count`, `heading`, `intro`, and the legacy `pet-gender`, `container-class`, `form-class` attributes.
* `[pet-gallery-hero]` — hero only, for placing on a different page than the grid.

`[petkey-gallery]` and `[petkey-gallery-hero]` are still registered as aliases — pages using the old tags keep working without changes.

== Lead capture ==

Configure under Petkey → Gallery. The "Auto" mode tries, in order:

1. Theme `#petInquiry` modal (detected via the ACF option `gform_pet_inquiry_popup` used by Cosmick Petland themes).
2. Gravity Forms by ID (configured form ID).
3. A custom shortcode.
4. A contact page (configured slug, or auto-detected `/contact/`) with `?pet={ref}` for analytics.
5. No CTA, silently.

== Cross-theme compatibility ==

All frontend styles are scoped under `.wcpk-gallery` and use CSS custom properties so themes can re-skin via `--wcpk-primary` and friends. By default the plugin only overrides the post-type archive when the active theme has no `archive-pet-gallery.php` of its own — the one exception is the Layout setting: choosing **the plugin's own design** deliberately overrides a theme template, because otherwise the setting could not change the archive on a theme that ships one.

== Installation ==

Upload to `wp-content/plugins/wc-pet-gallery`. Requires one of the parent sync plugins to be active: Petkey Sync (`wc-petkey-sync`), Unified KM (`wc-unified-km`), or Pinogy Sync (`wc-pinogy-sync`). Activate via WP Admin → Plugins.

== Compatibility ==

| Parent plugin | Theme | Result |
|---|---|---|
| `wc-petkey-sync` only | petland / petland-pep / any | ✅ Full feature set including the petkey cron, "Removed → Gallery" promotion, force-move admin UI. |
| `wc-unified-km` only | petland / petland-pep / any | ✅ Frontend gallery, single template, shortcode, filter bar, breeds, hero, related grid, lead capture — plus, since 1.7.0, a native ingest (`includes/ukm-ingest.php`). It mirrors pets whose product status is a configured source status (default **Sold**) and which have a featured image, and it **never writes a product's `post_status`**. Mirroring is off by default: arm it on the Gallery tab, then run a backfill for the historical backlog. The petkey cron stays suppressed. When `wc-ukm-gallery` is also active this plugin stands down completely — no mirroring, no scan — and the admin metabox is deferred to it. |
| `wc-pinogy-sync` only | petland / petland-pep / any | ✅ Frontend gallery, single template, shortcodes. The pinogy bridge (`includes/pinogy-bridge.php`) wires WooCommerce order completion to the gallery: when an order is set to `processing` or `completed`, the pet product is moved to the `pet-gallery` CPT and the source product flips to `gallery_moved`. Refunds / cancellations / failures trash the gallery post and restore the product. The petkey cron is suppressed. |
| Multiple plugins active | any | ✅ Precedence is `petkey > ukm > pinogy`. The chosen mode is frozen in the `WCPK_GALLERY_PARENT_MODE` constant for the request. |
| Neither plugin active | any | Plugin sleeps silently. No PHP notices, no admin clutter. |

The plugin uses a logical schema adapter (`wcpk_gallery_meta_key_map`) that maps each display field to an ordered list of meta keys (petkey first, UKM fallback). A `get_post_metadata` filter makes theme code that reads keys like `breed_name` directly transparently get the UKM-stored `_breed` value back — so existing themes don't need to know which provider wrote the post.

== Smoke test (UKM-only site) ==

1. Verify the plugin is active in WP Admin → Plugins ("WooCommerce Pet Gallery").
2. Go to WP Admin → Unified KM → Gallery (the submenu auto-attaches there). Settings page renders + saves.
3. Visit `/pet-gallery/` (or any page containing `[pet-gallery]` / `[petkey-gallery]`). Cards show breed / gender / ref pulled from UKM keys (`_breed`, `gender`, `reference_number`).
4. Filter dropdown lists every distinct breed. Picking one AJAX-filters the grid.
5. Click a card → single page renders with the right data. CTA opens whatever inquiry mechanism the theme exposes.
6. `wp cron event list | grep petkey` shows no `wc_petkey_gallery_data` event (correct — petkey cron is suppressed on UKM-only sites).

== Automatic updates ==

This plugin ships with the YahnisElsts plugin-update-checker (v5) configured to read from the private GitHub repo `CosmickMedia/wc-pet-gallery`. To enable auto-updates on a client site, add the following constant to `wp-config.php` (above the "stop editing" line):

`define( 'COSMICK_AUTH_KEY', 'ghp_yourTokenHere' );`

The token is a GitHub personal access token (classic or fine-grained) with read access to the repo. Without the constant the plugin still works — it just disables update checks and shows a dismissible warning notice to admins.

= How a version is detected =

The updater uses the library's default order on the `main` branch: **latest GitHub release → highest version tag → branch HEAD**. All three stay enabled on purpose — a draft or failed release falls through to the tag, and a push with no tag falls through to the `Version:` header on the branch, so there is no state in which a site is silently told it is up to date when it is not.

Releasing is the project's normal flow: bump `Version:` in `wc-pet-gallery.php` **and** `PETGALLERYVERSION`, update the `Stable tag` here, commit to `main`, tag, and publish a GitHub release. Two things to get right, because both fail silently:

* **Push the tag yourself, and publish the release.** A draft release is skipped by the release strategy, and a draft does not create its tag either — so the tag fallback only carries the update if the tag was pushed independently. Otherwise detection falls through to branch HEAD and every site is quietly told it is current.
* **Point the tag at the commit that carries the new `Version:` header.** The version offered to a site is read off the file at the tagged ref, not off the tag name, so a `1.7.5` tag landing on a commit whose header still says `1.7.4` offers nothing and spends the version number.

= Release channels =

`wcpk_gallery_release_channel` is `stable` (default) or `beta`.

* **stable** — only non-pre-release versions. A version whose number carries a pre-release suffix (`1.7.0-beta.1`) or a channel word is rejected no matter which strategy found it, so a tagged beta cannot leak to the fleet through the tag or branch fallback.
* **beta** — also receives GitHub pre-releases. This is how a fix reaches one site ahead of everyone else, and it is what makes a rollback stick instead of silently re-updating on the next check.

Set it per site with the `wcpk_gallery_release_channel` option, or pin it in `wp-config.php` with `define( 'WCPK_GALLERY_RELEASE_CHANNEL', 'beta' );`.

= Overrides =

The updater is built at plugin-load time, before `plugins_loaded`, so a regular plugin or theme cannot hook its filters in time. Each override therefore also has a constant, which is what you want in `wp-config.php` anyway:

* `WCPK_GALLERY_UPDATE_REPO` / `wcpk_gallery_update_repo` — the source repo.
* `WCPK_GALLERY_RELEASE_CHANNEL` / option `wcpk_gallery_release_channel`.
* `WCPK_GALLERY_UPDATE_BRANCH_ONLY` / `wcpk_gallery_update_from_branch_only` — ignore releases and tags and track the branch HEAD, which is how 1.6.0 and earlier behaved.
* `wcpk_gallery_update_auth_token`, `wcpk_gallery_update_branch`.

The last successful check and the last updater error are recorded in the non-autoloaded options `wcpk_gallery_updater_last_check` and `wcpk_gallery_updater_last_error`.

== License ==

Licensed to Cosmick Media. This plugin is granted via an exclusive license provided by Cosmick Media. Reproduction, disclosure, reverse engineering, disassembly, and modifications are strictly prohibited without prior written permission. You may not rent, lease, or resell this software. Exceptions are solely granted by Cosmick Media. You may obtain a copy of the License at cosmickmedia.com or by contacting websupport@cosmickmedia.com. Any violation will result in legal action.

== Changelog ==

= 1.7.5 =
*Release Date - 20 October 2026*

**The first published release of the 1.7 line.** 1.6.0 was the last version ever tagged, so a site updating from it receives 1.6.1 through 1.7.5 at once — see every entry below. This release itself corrects the shipped documentation and the test harness; no runtime behaviour changes.

* FIXED: **The readme told UKM operators the opposite of what the plugin now does.** The Compatibility table still carried the sentence 1.7.0's own changelog names as the documentation half of that bug — "Petkey cron is suppressed (UKM has its own via the `wc-ukm-gallery` plugin)" — so one section claimed there was no ingest while another described the native one shipped in 1.7.0. This matters more than an ordinary typo: plugin-update-checker renders readme.txt in the plugin-details popup, so it is what an operator reads while deciding whether to update. The row now describes the real behaviour, including that mirroring is off until armed and that the plugin stands down entirely beside `wc-ukm-gallery`.
* FIXED: "Cross-theme compatibility" claimed the plugin only overrides the post-type archive when the theme has none of its own. The Layout preset writes `archive_override = always`, which deliberately overrides a theme template — without it, "use the plugin's design" could not change the archive on a theme that ships one. Documented as the exception it is.
* FIXED: The 1.7.0 settings entry undercounted the Advanced tab — 15 fields, two of them provider-gated, so the tab renders 15 on Petkey, 14 on Pinogy and 13 on UKM.
* ADDED: Three user-visible 1.7.0 changes that shipped undocumented — the sidebar widget and the single-pet "related pets" grid now honour "Pet types to show", and a UKM backfill suppresses UKM's own pet change-log rows rather than writing one bogus "Pet Update" per pet.
* FIXED: The release instructions now say to push the tag yourself and to point it at the commit carrying the new `Version:` header. A draft release creates no tag, so the documented "the tag will still carry the update" fallback was only true if the tag had been pushed separately; and because the offered version is read off the file at the tagged ref rather than off the tag name, a tag landing on the wrong commit offers nothing and spends the version number.
* FIXED: **`tests/run.sh` reported "clean" and exited 0 over a failing test.** Three of the four tests were piped through `tail -1`, and a POSIX pipeline exits with the status of its last command — so the status came from `tail`, was always 0, and `set -e` sailed past the failure. The lint stage had the same hole in a different shape: its status came from `grep`, `|| true` discarded even that, and `echo "   clean"` ran unconditionally, so a parse error printed itself on screen and the run still passed. Both verified failing before the fix and exiting 1 after it. `set -o pipefail` is not POSIX, and this script is documented as `sh tests/run.sh`, so the fix captures each test's output instead — full output on failure, last line on success. The lint stage no longer skips `update/`: those vendored files install on client sites, where a parse error is a fatal.

= 1.7.4 =
*Release Date - 20 October 2026*

* FIXED: **The UKM backfill could not drain itself, and the twice-daily scan was never scheduled.** The ingest file was required only when the detected provider mode was `ukm`. Action Scheduler resolves a callback with `has_action()` inside whatever request happens to drain the queue — `wp-cron.php`, or its admin-ajax loopback — and on a live UKM site the mode did not resolve to `ukm` in those contexts, so every queued batch failed outright with *"Scheduled action for wcpk_gallery_ukm_backfill_batch will not be executed as no callbacks are registered."* Action Scheduler does not retry a failed action, so the self-enqueued chain died silently after the first inline batch and a 3,800-pet backfill had to be driven by hand. The same gap meant `register_schedule()` never ran outside the admin, so `wcpk_gallery_ukm_scan` was never created and the recurring watchdog did not exist.
* FIXED: Registration is now unconditional and only *behaviour* is gated. That was always the intended design — `enabled()` is re-checked at the top of `run_backfill_batch()`, `run_scan()`, `on_transition()`, `mirror_one_async()` and every admin entry point, and still returns false unless the site really is UKM with mirroring on. A registered callback that declines to act costs nothing; a missing one loses the job with no retry. `enabled()` also tolerates `wcpk_gallery_ukm_gallery_active()` not being loaded yet, so it cannot fatal inside a queue runner.
* ADDED: Six assertions in `tests/test-ukm-constraint.php` — one that the require is not mode-gated, four that each entry point still re-checks `enabled()` (the thing that makes unconditional registration safe), and one for the helper guard. Verified to fail when the mode gate is put back.

= 1.7.3 =
*Release Date - 20 October 2026*

* FIXED: **`/pet-gallery/page/2/` rendered page 1.** WordPress paginates the archive itself, generates those URLs, links them and advertises them in the title tag — "Page 2 of 800" on one live site, "Page 2 of 155" on another. The server-side render read only the plugin's own `pg_page` parameter and ignored WordPress's `paged`, so every one of those URLs served the first 36 pets again under a different address. Visitors without JavaScript could never leave page one, and search engines were offered hundreds of duplicate URLs per site. The archive now honours `paged` (still letting `pg_page` win, and still gated to the archive so a `[pet-gallery]` shortcode on an ordinary paginated page is unaffected), and the no-JS pager builds its links from the clean archive URL instead of appending a second page number to the first. Confirmed on all three providers.
* ADDED: `tests/test-pagination.php`, wired into `tests/run.sh`, verified to fail when the fallback is removed.

= 1.7.2 =
*Release Date - 20 October 2026*

**Four guards found by a pre-flight review of the UKM backfill, run against a real site before the first backfill was fired. All four only bite on a site that `wc-ukm-gallery` populated and then left behind — which is the common case.**

* FIXED: **A product could be trashed, violating the one rule the UKM path must never break.** The second duplicate-guard read `ukm_gallery_id` out of pet meta and adopted whatever post that id named, with no `post_type` check — unlike the two guards after it, which pin the type in SQL. A stale pointer (a cloned or re-imported store has plenty) could therefore name a **product**; the plugin would stamp it with its own back-pointer and the de-mirror would later `wp_trash_post()` it — a product `post_status` write. The guard now requires a live `pet-gallery` post and lets anything else fall through to the recovery path.
* FIXED: As defence in depth, de-mirroring now refuses to trash a post that is not a `pet-gallery` post, and clears the stale pointers instead. This was the only place in the plugin where an id read out of stored meta — rather than one returned by a type-constrained lookup or by the plugin's own insert — reached a post-row write.
* FIXED: **Duplicate cards.** `wc-ukm-gallery` derived its slug exactly as this plugin does (`reference type breed`), so a legacy card whose pet-side pointer had been lost was invisible to all four duplicate guards and WordPress would quietly append `-2` and publish a **second card for the same animal**. A slug lookup now runs before the insert: it adopts an unclaimed card, and records a `slug_conflict` for the operator rather than publishing a twin. The slug catches what no id-based pointer can, including a re-imported product whose ID has changed.
* FIXED: **A full-site cache purge and an `opcache_reset()` per sale.** The batch paths suppressed the theme's `transition_post_status` purge; the two single-pet async paths did not. That purge constructs a fresh `Kinsta\Cache_Purge`, and Kinsta's own once-per-request guard is an *instance* property — so a new object per call defeated it. Since UKM's bulk tools flip hundreds of pets in one request, this could reset the opcode cache hundreds of times, forcing every other request on the site to recompile WordPress from source. Both callbacks now use the same suppressor the batches do.
* ADDED: Ten assertions in `tests/test-ukm-constraint.php` covering all four guards, each verified to fail when its guard is removed.

= 1.7.1 =
*Release Date - 20 October 2026*

* FIXED: On a site with "Discourage search engines" switched on (Settings → Reading — typically a staging or dev install), gallery pages emitted the self-contradicting directive `noindex, nofollow, follow`. The filter set `follow` without clearing the `nofollow` something else had already set. It now clears both opposites, and defers entirely to a site-wide discouragement rather than weakening it. Found on a live dev site during verification; production sites were unaffected, because nothing else sets `nofollow` there.

= 1.7.0 =
*Release Date - 19 October 2026*

**Unified KM sites can finally fill the gallery.**

* FIXED: On a Unified KM site the plugin registered no ingest at all. The cron hook is only wired in petkey mode and is actively cleared otherwise, and this readme said the UKM path was "suppressed (UKM has its own via the `wc-ukm-gallery` plugin)" — which is only true on sites that have that plugin installed. Where it is absent, nothing had ever created a gallery post, and the front end correctly reported "No pets found for your search."
* ADDED: A native UKM ingest (`includes/ukm-ingest.php`), loaded only in UKM mode. It mirrors pets whose product status is a configured source status (default **Sold**) and which have a featured image. **It never changes a product's post_status** — UKM pets stay in `sold_puppy`, which the warranty, certificate, my-account and bulk tools all key off. `tests/test-ukm-constraint.php` enforces that at the source level.
* ADDED: Mirroring is **off by default** and gated three ways: UKM mode, `wc-ukm-gallery` not active, and the switch on. Arming it stamps a watermark so the recurring scan only looks at pets sold from that point — the historical backlog is the backfill's job, and the backfill only runs when someone presses the button.
* ADDED: Backfill controls with a configurable window (default 12 months), a "try 25 first" option, live progress, a stop button, and a cron-independent "run the next batch now" for hosts that disable WP-Cron.
* ADDED: Total stand-down when `wc-ukm-gallery` is active. The two selection sets differ — that plugin is order-driven, this one is product-status driven — so they must not both run. We also write `ukm_gallery_id`, which is what makes that plugin link to our posts rather than duplicate them if it is ever activated.
* CHANGED: The source statuses are now a setting, built from the statuses that actually exist on the site. The cron previously scanned the `unavailable` status, labelled "Removed" — on UKM a catch-all that also covers returned, deceased and transferred pets, not an adoption signal.

**The grid was empty for three more reasons, all fixed.**

* FIXED: Saving the settings page with no "Pet types" box ticked stored a CODE => LABEL map, while the reader lowercased the option's *values* and compared them to `animal_type`. Nothing matched, so the entire grid went blank — and the checkboxes then drew unticked, so the next save repeated it. There is now one canonical `_wcpk_type_code` per post and a reader that tolerates every historical shape of the option. Migrated automatically, with the old value snapshotted to `petkey_gallery_types_pre170`.
* FIXED: Small animals and reptiles were silently dropped on UKM sites. `_pet_type` holds term names like "Rabbit" and "Guinea Pig", and the old allow-list had no bucket that could match them.
* FIXED: The pet-type filter ran *after* the SQL LIMIT, so pages came back short and "Load more" could never terminate correctly. It is a meta_query clause now, and fails open for any post that has not been stamped yet.
* CHANGED: The sidebar widget and the single-pet "related pets" grid now honour the "Pet types to show" setting too, in SQL. Both previously ignored it, so a site that had narrowed its pet types still saw every type in those two places; expect their contents to change on update.
* ADDED: During a UKM backfill the plugin detaches UKM's own `ukm_buffer_pet_meta_change` listener around its `ukm_gallery_id` write. Without that, a 3,800-pet backfill writes one bogus "Pet Update" row per pet into the staff-facing change log.
* FIXED: The "All Pets" view excluded every pet carrying any gallery category, so categorising a pet removed it from the default view. New option `petkey_gallery_cat_all_behavior` restores the old behaviour if a site was relying on it.
* FIXED: Paging used `paged` together with an over-fetched `posts_per_page`, which made WordPress compute the offset from the inflated page size and skip exactly one pet at every page boundary. Ordering now carries an ID tiebreaker, without which thousands of posts sharing a backfill timestamp would duplicate on one page and vanish from another.

**Failures are visible now.**

* ADDED: Page one is rendered server-side through `templates/card.php` — until now an orphaned file that `buildCardHtml()` in the script had quietly replaced — plus a `<noscript>` filter form and pager. The grid used to ship empty with no fallback, so any failure of the AJAX request was indistinguishable from "no pets found", and crawlers saw an empty page.
* FIXED: The endpoint returned HTTP 200 with `success:false` for *both* "no pets matched" and "something broke". Zero results is now a success with `empty:true`; a missing post type is 500, a bad page 400, a render failure 500.
* FIXED: The script called its response handler regardless of HTTP status, so a 500 or a 403 body fell into the JSON parse catch and returned without rendering anything. Status is branched on, there is a timeout, and errors render a real message with a retry button — capped at three consecutive failures so a dead endpoint cannot become a request loop via the auto-load observer.
* FIXED: Two `[pet-gallery]` shortcodes on one page shared state and document-wide element lookups, so one grid's filter change drove the other.
* FIXED: `.wcpk-empty` was referenced by the script but had no CSS rule at all.

**Settings.**

* CHANGED: One page of 31 fields becomes four tabs — Status (no settings), Gallery (9), Inquiries (6) and Advanced (15, two of them provider-specific, in collapsed sections). Every one of the 31 original option keys is still written or derived.
* CHANGED: Five template/theming switches (`archive_override`, `single_override`, `single_show_breed_info`, `single_show_related`, `force_clean_layout`) become one **Layout** choice, preceded by a readout naming the active theme and which design is actually rendering, with a preview link. They interacted invisibly and the only way to learn their effect was to save and go look.
* ADDED: A Status tab that reports the detected sync source, pets in the gallery, how many qualify, how many are not yet mirrored, how many were skipped for having no photo, the schedule, and the update state — plus a button that fetches the public gallery page and counts the cards. Configuration checks can all pass while the page is blank; that one cannot.
* FIXED: Saving moved out of the render callback, so there is a real POST-redirect-GET and validation failures are reported instead of discarded. A conditional field is skipped on save as well as on render — without that, setting Form source to "Theme" and saving wiped `petkey_gallery_form_id`.
* CHANGED: The Petkey promotion tools are gated to Petkey and Pinogy. On UKM every row read "No API status returned" above buttons that did nothing, and it is the only UI that can reach the code path which flips a product to `gallery_moved`.
* CHANGED: `petkey_gallery_enquiry` is demoted to Advanced but never destroyed — seven theme files read it. It mirrors `form_id` only while the two agree.
* CHANGED: The 14-day cooling-off was a literal `14` in three places, one of them inside the message that reports on it. It is `petkey_gallery_wait_days` now.

**Other.**

* ADDED: `petkey_gallery_seo_mode`. Individual pet pages are `noindex,follow` and out of the Yoast sitemap by default, while `/pet-gallery/` stays indexable; a second option also keeps the photos out of image search. Filtered and paginated archive views get a canonical to the plain archive.
* FIXED: `wc-ukm-gallery` registers the same post type on the same hook with a thinner args set, and whichever ran second won — dropping `editor` support and our meta box. Reconciled additively now.
* FIXED: Provider detection no longer caches an answer computed before `plugins_loaded`. Plugins load alphabetically, so a `class_exists()` check at our own include time answers false on a site where `wc-ukm-gallery` is active.
* FIXED: The upgrade routine ran on `init` on every request until the version bumped, and the bump was its last statement — so any fatal inside it retried forever and never got there. It now has a fast path, a single-flight lock, per-step completion flags, and hands the 5,000-row slug sweep to cron.
* FIXED: Column CSS for the gallery list table is no longer echoed into the `<head>` of every admin page.
* ADDED: `tests/` — a load smoke test that boots the plugin and renders every tab, a pet-type classifier suite, and the UKM constraint check. `sh tests/run.sh` runs them. Excluded from the release zip.
* CHANGED: `Requires at least` is now 5.7. The noindex handling uses the `wp_robots` filter, which does not exist before 5.7 — claiming 5.5 would have meant the SEO setting silently did nothing on older installs.
* FIXED: Release-channel gating moved from the metadata filter to the update filter, so a site on the stable channel keeps its "View details" popup and changelog when the newest tag happens to be a pre-release.
* FIXED: The boot gate matches a parent sync plugin by its main file's basename rather than its full path, so an install in a suffixed directory (`wc-unified-km-main/`) is still detected. When no parent is found the plugin now says so instead of silently doing nothing at all.
* FIXED: Deactivation clears every schedule the plugin owns, including the Action Scheduler jobs added in this release.
* FIXED: Pet pages are excluded from WordPress's own sitemap as well as Yoast's, and the plugin no longer prints a second canonical tag on sites running another SEO plugin.
* FIXED: Every check for a parent sync plugin now goes through one basename test. The boot gate matching on basename while the cron scheduler still matched on full path was worse than either alone: on a Petkey site installed in a suffixed directory the gate elected petkey and registered the hook, while the scheduler decided petkey was absent and cleared the schedule on every request, so the hourly sweep could never stay scheduled.
* FIXED: The mirroring retry counter is cleared on every path that ends in "this pet has a card", not only on the one that creates one. It previously accumulated across unsold/re-sold cycles, so on the sixth such cycle a healthy pet tripped the retry limit, was marked permanently skipped, and could no longer be mirrored or de-mirrored.
* FIXED: A backfill can always be stopped. Switching mirroring off mid-run left the state at "running" with both buttons returning 403, and switching off now cancels a run in flight.
* FIXED: Rich text and shortcode settings were unslashed twice, silently eating literal backslashes — `[form path="c:\x"]` saved as `c:x`.
* FIXED: "Pets per page" accepted values above the ceiling the gallery actually enforces.
* FIXED: The plugin now registers the `gallery_moved` and `unavailable` product statuses itself when the parent sync plugin does not. It writes `gallery_moved` onto source products but never registered it, and `wc-pinogy-sync` registers no statuses at all — so on a Pinogy site that status did not exist. An unregistered status is not cosmetic: a WP_Query asking only for unregistered statuses emits no status clause and silently widens to every product including trash, the product vanishes from the admin product list with no filter link to find it, and the edit screen has no matching option so saving rewrites the status. Registered at priority 101 and only when absent, so wc-petkey-sync's and Unified KM's own definitions always win. Query lists are additionally stripped of unregistered statuses, and a sweep whose list resolves to nothing now runs nothing instead of everything.
* FIXED: The plugin stands down with an explanation when the predecessor `wc-petkey-gallery` is also active. It carries the identical `Plugin Name`, owns the same post type and can promote the same products, so a site that installed this one alongside the old one instead of replacing it ran two indistinguishable copies — shown as two identically named rows in the plugins list.
* DOCS: Corrected the boot-gate comment. The documented `petkey > ukm > pinogy` precedence can only ever resolve a petkey/ukm or ukm/pinogy overlap: wc-petkey-sync and wc-pinogy-sync declare 19 identical unguarded top-level functions, so WordPress fatals with "Cannot redeclare" before the gate is reached.

= 1.6.1 =
*Release Date - 19 October 2026*

* FIXED: The update checker pointed at `starleycarley/wc-pet-gallery` instead of `CosmickMedia/wc-pet-gallery`. Because that repo was transferred, GitHub 301-redirects the API, so the *version check* still succeeded and sites were correctly told an update was available — but the zipball download had its `Authorization` header stripped on the redirect (the redirect target no longer matched the configured prefix) and 404'd against the private repo. Updates have therefore been detectable but not installable since 1.5.4.
* CHANGED: Update detection now uses the library's default order — latest release, then highest tag, then branch HEAD — instead of forcing branch HEAD only. Keeping all three removes any state in which a site is silently told it is current. Set `WCPK_GALLERY_UPDATE_BRANCH_ONLY` to restore the old behaviour.
* ADDED: Release channels. `wcpk_gallery_release_channel` (`stable` default, or `beta`) decides whether a site receives GitHub pre-releases. Channel gating is applied to the resolved version rather than per strategy, so a tagged beta cannot reach a stable site through the tag or branch fallback.
* ADDED: `Update URI`, `Requires at least` and `Requires PHP` plugin headers.
* ADDED: The last successful update check and the last updater error are recorded (`wcpk_gallery_updater_last_check` / `wcpk_gallery_updater_last_error`, both non-autoloaded) so a broken updater is visible instead of silent.
* ADDED: `assets/icon-128x128.png` and `assets/icon-256x256.png` — the plugin-details popup renders an icon for the first time. The library looks for a directory named `assets`; the existing `asset/` directory (singular) is the front-end asset path and is unchanged.
* SECURITY: Removed `cookies.txt` — a committed curl cookie jar containing a session cookie for kennelmanagement.org — from the repository, and added a `.gitignore`. The affected portal account should be logged out and its password changed. This never exposed the Petkey API key, which is a separate credential.
* FIXED: `.gitattributes` now excludes `.idea/`, `docs/`, `cookies.txt` and `*.md` from `git archive`, so development files stop shipping inside the update zip installed on client sites. Note that `export-ignore` has no directory semantics — a trailing slash silently matches nothing — so the patterns use globstars.

= 1.6.0 =
*Release Date - 28 May 2026*

* ADDED: `[pet-gallery]` and `[pet-gallery-hero]` shortcodes accept a new `suppress-page-hero` attribute that injects an inline style rule hiding the active theme's `.page-hero` block (petland's default page banner). Default is `auto`: on `[pet-gallery]` it suppresses only when the plugin's own hero is enabled (the common case — prevents two stacked page titles); on `[pet-gallery-hero]` it always suppresses. Pass `="no"` to keep the theme banner visible, `="yes"` to force suppression even when the plugin hero is off.
* CHANGED: Card photos (`.wcpk-card__photo`) no longer force a 3:4 aspect ratio. Images now size at their natural height so portrait, square, and landscape originals all render correctly without cropping.
* ADDED: Compatibility with Pinogy Sync (`wc-pinogy-sync`) as a third parent option alongside Petkey Sync and Unified KM. Boot gate now accepts any of the three; exactly one is expected to be active per site. Precedence on overlap: `petkey > ukm > pinogy`. The chosen mode is frozen in the `WCPK_GALLERY_PARENT_MODE` constant for the request.
* ADDED: Pinogy bridge (`includes/pinogy-bridge.php`) hooks the WooCommerce order lifecycle at priority 20 so pinogy's status flip (default priority 10) runs first: `processing` / `completed` moves the pet product to the `pet-gallery` CPT, sets `pk_pet_status_changed`, and flips the source product to `gallery_moved`. `cancelled` / `refunded` / `failed` trashes the gallery post and restores the source product (only if its status is still `gallery_moved`).
* ADDED: `wcpk_gallery_pending_product_statuses` filter exposes the WC product-status array the cron scans (default `['unavailable']`). The pinogy bridge filters this to add `'pending'` as a safety-net status.
* CHANGED: `update_datadetails_meta` now branches on parent mode. In UKM / pinogy mode it skips the Petkey API call and reads the cached `pet_Datadetail->Status` from product meta — the parent plugin keeps that fresh via its own hourly sync.
* CHANGED: `wc_petkey_syncpet_statuschange` re-list cleanup is now parent-mode aware. Petkey mode keeps the `get_publishables_statuses()` guard; pinogy mode uses the pinogy `['available', 'coming_soon']` set as the re-list signal.
* CHANGED: `wcpk_gallery_detect_provider` now also returns `'pinogy'`. The `wcpk_gallery_provider` filter receives a fourth argument (`$has_pinogy`).
* CHANGED: Settings submenu auto-attach now also recognizes `pinogy-setting` ("Pinogy Settings"). Existing petkey/UKM behavior unchanged.
* CHANGED: Petland theme partial `partials/content-archive-pet-gallery.php` shortcode check now accepts both `pet-gallery` and the legacy `petkey-gallery` tag.

= 1.5.4 =
*Release Date - 20 May 2026*

* FIXED: Settings submenu auto-attaches to whichever parent menu is registered — `wc-petkey-setting` ("Pet Settings") on petkey-sync sites, `wc-ukm-setting` ("Unified KM") on UKM sites. Previously the page was orphaned on UKM-only sites because it hard-coded the petkey parent. Falls back to a top-level "Pet Gallery" menu if neither parent is registered.
* HARDENED: `get_pet_status()` now guards `new Petkey_Functions()` with a `class_exists` check (matching the existing function_exists pattern at the caller). Belt-and-braces — only ever called from a path that's already guarded, but defensive against direct callers.
* ADDED: Compatibility matrix + UKM-only smoke-test checklist in readme.txt.

= 1.5.0 =
*Release Date - 20 May 2026*

* FIXED: Cron now accepts a configurable list of Petkey API statuses (default: `adopted, sold`) instead of only the exact string `adopted`. Petland POS systems typically report `Sold` for sold puppies, so the old single-status check blocked the gallery from ever populating on those sites. Comma-separated admin field + `wcpk_gallery_gallery_accept_statuses` filter for sister code.
* ADDED: Settings → Petkey → Gallery → "Cron — Sold pets → Gallery" section with three new controls: accepted-API-statuses text field, "Bypass 14-day cooling-off" checkbox (for first-run backlog cleanup), and a "Run cron now" button that immediately processes the queue and reports a per-status summary.
* ADDED: Diagnostic table on the settings page listing up to 100 most-recent products in the "Removed" status, with each one's last known API status, days since transition, and a verdict ("Will move next run", "Waiting Xd more", "Skipped: status not in accept list", "No pet_Datadetail meta"). Each row has a "Move now" button for one-off promotion to the gallery.
* CHANGED: The hard 14-day cooling-off remains the default (preserves the "pet returned to inventory" safety) but can now be bypassed via the new toggle.

= 1.4.0 =
*Release Date - 20 May 2026*

* ADDED: Compatibility with Unified KM. The plugin's boot gate now accepts either `wc-petkey-sync` OR `wc-unified-km` as the active parent (was petkey-only).
* ADDED: Schema adapter — all read paths now resolve fields through a logical key map so petkey-style meta (`breed_name`, `animal_type`, `reference_id`, `coloring`, `petid`, `location`) and UKM-style meta (`_breed`, `_pet_type`, `reference_number`, `coloration`, `_petid`, `location_slug`) both feed the same templates and AJAX response. Petkey keys are tried first; UKM keys are the fallback. Extend via the `wcpk_gallery_meta_key_map` filter.
* ADDED: Breed filter dropdown, unique-breeds stat, related-pets query, and Yoast SEO filters all union both schemas — sites with mixed-origin gallery posts get a single coherent UI.
* CHANGED: When the standalone `wc-ukm-gallery` plugin is also active, this plugin defers admin CPT/metabox/column registration to it so the edit screen doesn't render duplicate UI.
* CHANGED: The petkey cron hook (`wc_petkey_gallery_data`) now only registers when wc-petkey-sync is the active parent. On UKM sites the cron is fully suppressed (UKM has its own sync mechanism). Existing schedules are cleared on upgrade.
* ADDED: `wcpk_gallery_provider` filter exposes the detected provider for sister code that needs to branch. Returns `'petkey'`, `'ukm'`, or `'none'`.

= 1.3.0 =
*Release Date - 20 May 2026*

* CHANGED: Replaced the legacy cosmickupdates.com pull-updater with the YahnisElsts plugin-update-checker (v5.6) configured for GitHub. Update source is now the private `CosmickMedia/wc-petkey-gallery` repo (since renamed to `CosmickMedia/wc-pet-gallery`). Version tags and main-branch commits both trigger client updates.
* ADDED: GitHub auth via a `COSMICK_AUTH_KEY` constant in wp-config.php (PAT with repo read access). Filter `wcpk_gallery_update_auth_token` overrides the source. Filter `wcpk_gallery_update_branch` overrides the default `main`.
* ADDED: Graceful fallback when the auth constant is missing — the plugin still runs and shows a dismissible admin notice to administrators instead of fataling.
* REMOVED: All references to the old cosmickupdates.com JSON feed.

= 1.2.0 =
*Release Date - 20 May 2026*

* SECURITY: Replaced hand-built INSERT-INTO-postmeta SQL in the cron sync with safe wp_insert_post / update_post_meta calls. Pet names containing apostrophes (or other special chars) no longer break the cron.
* SECURITY: Replaced the remaining $wpdb interpolations in the AJAX handler and status-change hook with $wpdb->prepare()-equivalent get_posts() lookups.
* SECURITY: Fixed XSS in the admin meta box — every value is now esc_attr()'d on output. Save handler now sanitizes input and uses the correct edit_post capability.
* SECURITY: AJAX handler now optionally verifies a `wcpk_gallery` nonce when present.
* SECURITY: wc_petkey_syncpet_statuschange now guards against get_publishables_statuses() being undefined.
* PERFORMANCE: get_breeds() now runs as a single cached SQL query instead of 15,000+ get_post_meta calls on a 7.5k-pet site. Auto-invalidates on save/delete.
* PERFORMANCE: AJAX endpoint now warms the postmeta cache for the whole page in one go (was 13 meta queries per pet × 36 pets = ~500 queries per request).
* PERFORMANCE: Slug-correction sweep moved out of the AJAX hot loop into a one-time migration that runs once on upgrade.
* PERFORMANCE: Yoast SEO title/description filters now bail early on non-pet-gallery pages (was running 5+ get_post_meta calls per page).
* PERFORMANCE: Cron's "last updated on" option no longer thrashes the autoloaded options cache (autoload=no, only written when something changed).
* PERFORMANCE: AJAX endpoint now uses wp_send_json with proper Content-Type headers.
* CHANGED: AJAX response shape is now `{success: bool, data: array|string}`. JS handler reads the new shape and falls back to the legacy "nothing" sentinel for any consumer still on the old format.
* REMOVED: Empty share-buttons setting (`petkey_gallery_share`) that no code consumed.
* REMOVED: Dead `set_version()`, `plugin_dir()`, `plugin_url()` methods.
* REMOVED: Stale `asset/js/gallery.min.js` file that was never enqueued.
* FIXED: Widget no longer fatals when wc-petkey-sync isn't loaded; filters/sanitization tightened.
* FIXED: `current_time('timestamp')` calls swapped for `time()` (deprecated since WP 5.3).

= 1.1.0 =
*Release Date - 20 May 2026*

* CHANGED: Plugin author updated to Cosmick Media.
* ADDED: Modern editorial UI (hero, polaroid cluster, stats strip, card grid, Forever Adopted sticker).
* ADDED: Lead-capture form resolver — auto-detects the active theme's pet inquiry modal, falls through to Gravity Forms / CF7 / WPForms / custom shortcode, finally to a contact-page link with `?pet={ref}`.
* ADDED: Extended settings panel (hero copy, stats, brand color, columns, CTA labels, archive override toggle).
* ADDED: Optional archive template that activates only when the theme has no `archive-pet-gallery.php`.
* ADDED: New CSS custom-property design tokens scoped under `.wcpk-gallery`.
* ADDED: New `[petkey-gallery-hero]` shortcode for placing the hero independently.
* FIXED: Re-enabled `settings.php` (was previously commented out, leading to undefined `$petkey_gallery_settings` notices).

= 1.0.3 =
*Release Date - 20 Apr 2023*

* FIXES : Pet status switch monitoring.
* ADDED: Added actions to the gallery post creation.
* ADDED: A time delay of 14 days added before move the pet to gallery.

= 1.0.1/2 =
*Release Date - 16 Apr 2022*

* TWEAK: Slug structure change.

= 1.0.0 =
*Release Date - 15 March 2017*

* Initial Release.
