# wc-pet-gallery — Pinogy parent support, shortcode audit, and QC pass

**Date:** 2026-05-28
**Status:** Design — awaiting implementation plan
**Owner:** Carley Kuehner

## Context

`wc-pet-gallery` currently boot-gates on either `wc-petkey-sync` or
`wc-unified-km` being active (see `wc-pet-gallery.php:179-182`). A new
deployment target — petland sites running `wc-pinogy-sync` — needs the same
adopted-pet gallery experience. Operationally these three sync plugins are
mutually exclusive: any given site runs exactly one of them at a time.

The petland theme already references the `pet-gallery` CPT extensively
(templates, partials, a `pre_get_posts` filter), so the plugin must register
the CPT in all three parent modes — otherwise the theme's pet-gallery URLs
404 and its `is_singular('pet-gallery')` guards silently fail.

In parallel, the user has asked for:
1. A shortcode-naming audit to confirm `pet-gallery` is the canonical tag and
   `petkey-gallery` is only kept for backwards-compatibility.
2. A lint / QC pass across the whole plugin.

This spec covers all three asks as a single coordinated change.

## Goals

- Support **all three** parent sync plugins (petkey, UKM, pinogy), with
  exactly one active per site. No regression for existing petkey/UKM sites.
- Provide a working end-to-end adopted-pet gallery on pinogy sites: WC order
  completion → `pet-gallery` post created with image and meta → renders in
  the petland theme.
- Confirm shortcode tags are generalized (`pet-gallery*` canonical,
  `petkey-gallery*` aliased) and document in readme.
- Lint and QC the entire plugin so the multi-parent codebase ships in good
  shape: no PHP syntax errors, no missing `function_exists`/`class_exists`
  guards on petkey-only APIs, no unescaped output, no inline SQL with string
  interpolation.

## Non-goals

- A new sync-adapter abstraction (e.g. `WCPK_Gallery_Source_Adapter`
  interface). Out of scope; would be a larger refactor.
- New user-facing settings. The plugin auto-detects which parent is active.
- Changes to UKM or petkey behavior. Those paths stay untouched.
- Test suite scaffolding. The plugin has no existing tests; this spec keeps
  QC manual.

## Architecture

### Parent-plugin model

Exactly one parent is active per site. A constant
`WCPK_GALLERY_PARENT_MODE` is set once at bootstrap to one of `'petkey'`,
`'ukm'`, or `'pinogy'`. Precedence (if multiple are inadvertently active):
`petkey > ukm > pinogy`. Existing precedence preserved to avoid regressing
sites currently running both petkey + something else as a transitional
state.

| Parent plugin | Source-of-truth for pet data | Trigger for gallery move |
|---|---|---|
| `wc-petkey-sync` | `pet_Datadetail` on WC product (Petkey API) | Cron, status `unavailable`, 14-day cooling-off, Petkey API status in accept list |
| `wc-unified-km` | `pet_Datadetail` on WC product; UKM also writes `_breed` etc. | UKM-owned mechanism (no change to current behavior) |
| `wc-pinogy-sync` (NEW) | `pet_Datadetail` on WC product (Pinogy API) | `woocommerce_order_status_processing` / `_completed`, no cooling-off |

### Why the bridge is small

`wc-pet-gallery/functions.php:362-457` (`create_gallery_post_from_product`)
already does the heavy lifting: it reads `pet_Datadetail` and produces a
fully-populated `pet-gallery` post with image. Pinogy stores
`pet_Datadetail` with the identical shape (see
`wc-pinogy-sync/inc/wc-pet-sync-functions-pinogy.php:187`), so the bridge
only needs to (a) detect pinogy mode, (b) fire the move at the right
moment, and (c) skip the petkey-API-refresh step in
`update_datadetails_meta`.

### Data flow (pinogy mode, happy path)

```
Customer places order containing a pet product (SKU = Pinogy PetId)
  │
  ▼
WC fires woocommerce_order_status_processing (and later _completed)
  │
  ├─► wc-pinogy-sync existing handler (WP default priority = 10;
  │   see wc-pinogy-sync/wc-pinogy-sync.php:102-105 — no explicit
  │   priority is passed to add_action, so it falls through to 10):
  │      product → post_status = 'pending'
  │
  └─► NEW wc-pet-gallery/includes/pinogy-bridge.php handler (PRIORITY 20):
       For each order line item:
         • $product = wc_get_product($product_id)
         • Skip if no 'pet_Datadetail' meta (not a pinogy pet)
         • Skip if a pet-gallery post already exists with
           meta petkey_gallery_ID = $product->get_sku()  (idempotent)
         • update_post_meta($product_id, 'pk_pet_status_changed', time())
         • $petkey_gallery_functions->add_to_gallery_for_product($product_id)
              └► create_gallery_post_from_product(): creates pet-gallery
                 post with title, image, meta; flips source product to
                 post_status = 'gallery_moved'
```

**Hook priority is intentional.** The bridge runs at priority 20 so
pinogy's priority-10 handler has already flipped the product to `pending`
before the bridge fires. The bridge then overwrites status to
`gallery_moved`. Both `processing` and `completed` order statuses are
hooked; the second fire is a no-op because of the idempotency check.

### Data flow (rollback on cancel / refund / failure)

```
WC fires woocommerce_order_status_cancelled | _refunded | _failed
  │
  ├─► wc-pinogy-sync existing handler:
  │      product → post_status = 'publish' (returns to inventory)
  │
  └─► NEW bridge handler:
       For each order line item:
         • Find pet-gallery post with meta petkey_gallery_ID = SKU
         • If found → wp_trash_post($pet_gallery_id)
         • Defensive: only flip source product to 'publish' if its
           CURRENT post_status === 'gallery_moved'. If pinogy already
           reverted, or admin manually changed, leave it alone.
```

### Re-list cleanup (pet returned to inventory)

Pinogy fires `do_action('wc_petkey_syncpet_statuschange', $product_id, $previous_post_status, $list_status)` at `wc-pet-sync-functions-pinogy.php:62` when a previously non-published product comes back to a publishable status. The plugin already listens at `functions.php:23` with a 3-arg signature matching pinogy's call exactly, and handles the cleanup in `wc_petkey_syncpet_statuschange()` at `functions.php:842`. The lookup uses `meta_key = 'actual_product'` / `meta_value = $pet_post_id` (`functions.php:861-862`), which works because the bridge's `create_gallery_post_from_product` already sets `'actual_product' => (int) $product->get_id()` at `functions.php:432`.

**However**, the existing handler short-circuits when `get_publishables_statuses()` (a wc-petkey-sync-only function) is missing — `functions.php:844-846`. On pinogy, that means re-list cleanup silently fails today.

**Fix:** broaden `wc_petkey_syncpet_statuschange()` to be parent-mode aware. In petkey mode, current behavior. In pinogy mode, treat any status in the `['available', 'coming_soon']` set (the pinogy `$allowed_statuses` at `wc-pet-sync-functions-pinogy.php:37`) as a re-list signal and run the cleanup branch. In UKM mode, no change. The handler's unconditional `delete_post_meta($pet_post_id, 'moved_to_gallery')` at `functions.php:853` runs before the cleanup branch; on pinogy products that never had that meta set, the call is a harmless no-op.

## Code changes

All changes are in `/Users/carleykuehner/www/wc-pet-gallery/` unless
otherwise noted.

| # | File | Change |
|---|---|---|
| 1 | `wc-pet-gallery.php:179-199` | Boot gate's OR-chain adds `wc-pinogy-sync/wc-pinogy-sync.php`. Set `WCPK_GALLERY_PARENT_MODE` constant (`'petkey'`, `'ukm'`, `'pinogy'`) based on detection with petkey > ukm > pinogy precedence. **The petkey-only cron registration at lines 197-199 (`add_action('wc_petkey_gallery_data', ...)`) must move inside a `WCPK_GALLERY_PARENT_MODE === 'petkey'` branch.** |
| 2 | `wc-pet-gallery.php` after boot gate (~line 196-199) | If `WCPK_GALLERY_PARENT_MODE === 'pinogy'`, `require PETGALLERY_PLUGIN_DIR . 'includes/pinogy-bridge.php'` (guarded by `file_exists` for defense-in-depth, matching the existing pattern at `wc-pet-gallery.php:87`). |
| 3 | `wc-pet-gallery.php:201-217` (activation/deactivation) | Activation gates the `wc_petkey_gallery_data` cron-schedule call on `WCPK_GALLERY_PARENT_MODE === 'petkey'` only. Pinogy mode doesn't schedule the petkey cron at all. UKM mode unchanged. |
| 4 | `functions.php:463-518` (`update_datadetails_meta`) | Branch on parent mode: petkey mode → current behavior. UKM/pinogy mode → skip Petkey API call, read existing `pet_Datadetail->Status` from product meta, return it. Defensive fallback for the cron safety-net path. |
| 5 | `functions.php` cron queries at lines **149**, **229**, **311** | `'status' => 'unavailable'` becomes `'status' => $statuses` where `$statuses = apply_filters('wcpk_gallery_pending_product_statuses', ['unavailable'])`. Pinogy bridge filters this to add `'pending'`. |
| 6 | `functions.php:842-875` (`wc_petkey_syncpet_statuschange`) | Add parent-mode branch. Petkey mode: keep `get_publishables_statuses()` guard. Pinogy mode: treat `$pet_petkey_status` value in `['available','coming_soon']` as the re-list signal. UKM mode: unchanged. The cleanup branch (delete pet-gallery post + `delete_post_meta moved_to_gallery`) is shared. |
| 7 | `includes/template-loader.php:47-77` (`wcpk_gallery_detect_provider`) | Add `'pinogy'` detection. Return values become `'petkey' \| 'ukm' \| 'pinogy' \| 'none'`. Precedence preserved (petkey > ukm > pinogy). Currently dead code for downstream consumers, but update for forward consistency. |
| 8 | `settings.php:61` | Add `'pinogy-setting'` to the `$candidates` array. Pinogy registers this exact slug at `/Users/carleykuehner/www/wc-pinogy-sync/settings.php:50` via `add_menu_page(..., 'pinogy-setting', ...)`. The page already falls back to a top-level menu when none of the candidates exist, so this is a UX improvement rather than a blocker. |
| 9 | NEW `includes/pinogy-bridge.php` | Hook registrations at priority **20**: `woocommerce_order_status_processing`, `_completed` → move-to-gallery loop (skip line items lacking `pet_Datadetail`; idempotent via existing dup check at `functions.php:384-396`). `_cancelled`, `_refunded`, `_failed` → trash matching pet-gallery post; only revert source product to `publish` if its current `post_status === 'gallery_moved'`. Filters `wcpk_gallery_pending_product_statuses` to add `'pending'`. Guards every wc-pet-gallery internal call with `isset($petkey_gallery_functions)` / `function_exists()`. |
| 10 | `readme.txt:37` | Update "Requires either Petkey Sync or Unified KM" to list all three parents. Add a short pinogy-mode behavior section. |
| 11 | `readme.txt:16-19` | Already correct (canonical `pet-gallery`/`pet-gallery-hero`, legacy aliases). Re-verify after other changes. |
| 12 | `/Users/carleykuehner/www/petland/partials/content-archive-pet-gallery.php:34` | Theme change. `has_shortcode($content, 'petkey-gallery')` → `has_shortcode($content, 'pet-gallery') \|\| has_shortcode($content, 'petkey-gallery')`. |

### Files NOT changing (intentional)

- `post-type.php` — CPT and taxonomy registration is parent-agnostic. UKM-metabox handoff already class-detects (`UKMGalleryFunctions`); pinogy doesn't register on the `pet-gallery` CPT so no conflict.
- `shortcodes.php` — already registers `pet-gallery`, `pet-gallery-hero`, `petkey-gallery`, `petkey-gallery-hero`. No new tags, no removals.
- `widget.php` — no functional change.
- `petland/page-pet-gallery.php`, `petland/single-pet-gallery.php`, etc. — render the CPT via the standard WP loop and theme partials. Fine as-is once the plugin registers the CPT in pinogy mode.

## Shortcode audit

Already in target state — confirmed by grep across the plugin:

| Tag | Status |
|---|---|
| `[pet-gallery]` | Canonical. Registered in `shortcodes.php:28`. Documented in `readme.txt:16`. |
| `[pet-gallery-hero]` | Canonical. Registered in `shortcodes.php:29`. Documented in `readme.txt:17`. |
| `[petkey-gallery]` | Legacy alias. Same callback. `shortcodes.php:30`. |
| `[petkey-gallery-hero]` | Legacy alias. Same callback. `shortcodes.php:31`. |

Internal checker `post_has_gallery_shortcode()` (`functions.php:65-74`)
already tests all four tags. No further plugin change needed — verification
only. Theme change above covers the only legacy-tag reference outside the
plugin.

## Lint / QC plan

### Lint (automated)

`php -l` against every `.php` file under
`/Users/carleykuehner/www/wc-pet-gallery/` (57 files). Run twice: once
after each batch of edits, once at the very end.

### Static QC (manual code review)

Pass over every file the change touches, plus their immediate callers /
callees. Checklist:

- [ ] Every direct call to `wc_petkey_get_api()`, `Petkey_Functions`,
      `get_pet_data()`, `get_publishables_statuses()` or other
      petkey-specific symbols is wrapped in `function_exists` /
      `class_exists`. No fatal on non-petkey sites.
- [ ] All AJAX/admin endpoints have nonce + `current_user_can` checks
      before any state mutation. Don't regress existing handlers.
- [ ] No string interpolation in SQL. New queries use `$wpdb->prepare`
      or `$wpdb->update`-style param arrays.
- [ ] User-facing strings escaped with `esc_html`, `esc_attr`, `esc_url`.
- [ ] New code uses the established prefix conventions: `wcpk_gallery_`
      for functions and filters, `WCPK_GALLERY_` for constants.
- [ ] Pinogy bridge guards every wc-pet-gallery internal call with
      `function_exists`/`isset($petkey_gallery_functions)` so load-order
      surprises don't fatal.
- [ ] No new options or meta keys introduced beyond those documented
      here.
- [ ] No leftover `var_dump`/`error_log`/`debug_error_message` calls in
      hot paths.

### Smoke test (manual, per parent mode)

Three staging passes — one per parent mode. Each pass runs the
"Verification" section below.

## Verification

### Pinogy mode

1. Active plugins: `wc-pinogy-sync`, `wc-pet-gallery`. Inactive: petkey, UKM.
2. `wp-admin → Plugins`: both active, no fatal errors, no "missing dependency" admin notice.
3. `wp-admin → Puppy Gallery` menu visible → CPT registered.
4. `/pet-gallery/` archive loads (empty initially).
5. Add a page with `[pet-gallery]` and another with `[petkey-gallery]` — both render an empty grid (proves canonical + legacy aliases both wire to the same callback).
6. Trigger pinogy sync → WC products created (status `publish`).
7. Place a test order for one pet product. Mark order `processing`.
   - WC product status becomes `gallery_moved`.
   - Under `Puppy Gallery` admin menu, a new post exists with the correct title, featured image, and meta (`petid`, `name`, `breed_name`, `gender`, `coloring`, `birth_date`, `reference_id`, `petkey_gallery_ID`, `actual_product`).
   - `/pet-gallery/` archive shows the new card.
   - `/pet-gallery/<slug>/` single page renders via `petland/single-pet-gallery.php`.
8. Refund the order (transition to `_cancelled`, `_refunded`, or `_failed`).
   - Pet-gallery post moves to Trash.
   - WC product flips back to `publish` (only because its status was `gallery_moved`).
9. Re-list scenario: pinogy re-imports a pet whose SKU matches a previously-existing pet-gallery post (e.g., admin manually re-publishes a returned pet).
   - Existing pet-gallery post is deleted via `wc_petkey_syncpet_statuschange` handler.
   - Pet returns to inventory.
10. "Run cron now" admin button:
    - Examined > 0 if any pinogy product is in `pending` status (safety-net path).
    - Moved/skipped counts non-zero only for products that should actually move.
11. Settings page is accessible under `Pinogy Settings → Gallery`.

### Petkey mode (regression)

12. Active plugins: `wc-petkey-sync`, `wc-pet-gallery`. Inactive: UKM, pinogy.
13. Existing petkey cron (`wc_petkey_gallery_data`) is scheduled.
14. Existing gallery flow works unchanged (manual: trigger a status change on an `unavailable` product whose `pk_pet_status_changed` is >14 days old; cron moves it).
15. Both `[pet-gallery]` and `[petkey-gallery]` shortcodes still render the gallery.
16. Settings page accessible under `Pet Settings → Gallery`.

### UKM mode (regression)

17. Active plugins: `wc-unified-km`, `wc-pet-gallery`. Inactive: petkey, pinogy.
18. UKM-metabox handoff still works (`UKMGalleryFunctions` class detected; petkey's save-meta is detached per `post-type.php:13-19`).
19. `[pet-gallery]` archive shows the existing UKM-sourced pet-gallery posts.
20. No fatal errors on admin-edit screens for `pet-gallery` posts.
21. Settings page accessible under `Unified KM → Gallery`.

### Lint pass

22. `find /Users/carleykuehner/www/wc-pet-gallery -name "*.php" -print0 | xargs -0 -n1 php -l` returns "No syntax errors detected" for every file.

## Edge cases

| Case | Behavior |
|---|---|
| Order cancelled before bridge fires | Pinogy reverts product to `publish`. Bridge no-ops because it only fires on `processing`/`completed`. ✓ |
| Order `_cancelled`, `_refunded`, or `_failed` after gallery move | Bridge trashes pet-gallery post + flips product back to `publish` (only if current status === `gallery_moved`). ✓ |
| Pinogy deletes product after gallery move | pet-gallery post survives; `actual_product` becomes a dead ref (tolerated; only used as back-reference). ✓ |
| Pet re-listed (pinogy resync, sale fell through) | Pinogy fires `wc_petkey_syncpet_statuschange` → updated handler (change #6) trashes matching pet-gallery post. ✓ |
| Bridge fires twice (e.g. `processing` then `completed`) | `create_gallery_post_from_product` early-returns at `functions.php:384-396` on existing `petkey_gallery_ID`. Idempotent. ✓ |
| Bridge rollback fires twice (e.g. `cancelled` then `refunded`) | Second pass finds no matching pet-gallery post → no-op. Product status check (`=== 'gallery_moved'`) prevents clobbering a status set by other code between fires. ✓ |
| Mixed cart (pet + accessories) | Bridge skips line items without `pet_Datadetail` meta. ✓ |
| Multiple line items, same SKU | Pinogy enforces `set_sold_individually(true)` at `wc-pet-sync-functions-pinogy.php:202`, so this should be impossible. Bridge iterates `get_items()` defensively anyway. ✓ |
| Product missing featured image | `create_gallery_post_from_product` returns `false` at `functions.php:398-401`. Pinogy uploads images synchronously during sync (`wc-pet-sync-functions-pinogy.php:296`), so by the time an order can be placed the image exists. If it doesn't, the move silently skips and the safety-net cron retries on the next tick once the image catches up. Acceptable. ✓ |
| Site transitions petkey → pinogy (admin swaps plugins) | Boot gate detects new mode on next request. Existing pet-gallery posts unaffected. Old `pk_pet_status_changed` meta on legacy products tolerated. |
| Multiple parents accidentally active | Precedence (`petkey > ukm > pinogy`) elects one. Logged via `WCPK_GALLERY_PARENT_MODE`. No bridge file loaded unless pinogy is the elected mode. ✓ |
| Petkey site reads `[pet-gallery]` shortcode (canonical tag) | Already works — `shortcodes.php:28`. No regression. ✓ |
| Pinogy site has a stale page using `[petkey-gallery]` | Legacy alias still registered → renders the gallery normally. ✓ |

## Open questions resolved during design

- **Refunded orders:** trash the pet-gallery post. Applies to `_cancelled`,
  `_refunded`, and `_failed`.
- **Plugin-mode display row in settings:** skipped (noise).
- **Pinogy-mode handling for `petland/page-pet-gallery.php`:** none needed,
  template just renders shortcodes / partials.

## Implementation order

The bridge file is created FIRST so that the `require` added in step 2 always resolves. A `file_exists` guard is also added on the require for defense-in-depth.

1. NEW `includes/pinogy-bridge.php` (change #9). File exists before any `require` references it.
2. Boot gate + `WCPK_GALLERY_PARENT_MODE` constant + activation gating of the petkey cron + conditional `require` of the bridge file (changes #1, #2, #3).
3. `update_datadetails_meta` parent-mode branch (change #4).
4. Cron query filter (change #5).
5. `wcpk_gallery_detect_provider` update (change #7).
6. `wc_petkey_syncpet_statuschange` parent-mode branch (change #6).
7. `settings.php` menu-parent candidates (change #8).
8. Theme: `petland/partials/content-archive-pet-gallery.php:34` (change #12).
9. `readme.txt` updates (changes #10, #11).
10. Lint pass: `php -l` on all 57 PHP files.
11. Static QC checklist.
12. Smoke tests (three parent modes).
