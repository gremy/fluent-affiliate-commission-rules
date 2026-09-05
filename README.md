# Commission Rules for Fluent Affiliate

Per-affiliate and per-group commission rules for [Fluent Affiliate](https://fluentaffiliate.com),
targeted at a product, a product category, or everything, with an optional date window.

**Source:** Developed in a private monorepo; this GitHub repository
([github.com/gremy/fluent-affiliate-commission-rules](https://github.com/gremy/fluent-affiliate-commission-rules))
is a one-way mirror of the plugin directory (changes are mirrored out by
`bin/mirror-fa-commission-rules.sh`).

> Independent third-party add-on. Not affiliated with, or endorsed by, WPManageNinja.

Fluent Affiliate can give each affiliate one rate, each group one rate, and the
whole site one product/category rate table. What it cannot express is the thing
most partner programs actually need:

> Partner X earns 10% on Coffee. Partner Y earns 5% on the same category.

This plugin adds that, without touching Fluent's compiled admin app and without
replacing any of its own maths.

## How rules resolve

Every rule answers four questions: **who** (everyone, a group, one affiliate),
**what** (all products, a category, a product or variation), **how much**
(a percentage of the line total, or a flat amount per line), and **when**
(an optional start and end date).

For each order line, **exactly one rule wins. Rules never stack.**

```
score = scope × 10 + target
  scope:  affiliate 3 > group 2 > everyone 1
  target: product 3 > category 2 > everything 1
```

- Highest score wins.
- Two category rules tied on score: the **closest** category wins — a directly
  assigned term beats its parent, which beats its grandparent.
- Category rules match through the **ancestor chain**, so a rule on a parent
  category still applies to a product filed only under a child.
- Still tied: the newest rule wins.
- Whatever no rule claimed keeps Fluent's own rate, exactly as before.

An affiliate with no rule of yours is never touched: Fluent's amount passes
through unchanged.

Fluent's own site-wide product/category rates are **not copied**. They are read
live and shown as read-only "Everyone" rows, so there is one list to read and no
second source of truth — and only when Fluent's own gate is on and it has built
a non-empty watched-product/category list from them, exactly as Fluent's own
price path requires.

## Where it appears

| Surface | What you get |
|---|---|
| **Fluent Affiliate → Commission Rules** | The rules list and editor, plus a "Commission rules" tab in Fluent's own header |
| **Affiliate profile** | A read-only card of the rules currently in force — one row per target, the winning rule only, with its Source (Individual/Group/Everyone) — plus an "Add rule for this affiliate" link |
| **Affiliate portal** | A plain-language rate card of the rules currently in force — the rate, what it covers, and the date it ends |
| **Every referral** | An audit stamp in `referrals.settings` and a short `rules:` note appended to the description, which is the only field in Fluent's CSV export |

An affiliate rule that overrides a group or site-wide rate for some products
carries a shadow badge reading "Overridden for some products by …" rather than
implying the whole group rate is dead — the underlying group membership isn't
knowable from the rules alone.

## Architecture: why a separate page

The Commission Rules screen is a Vue 3 + Element Plus app of its own, mounted
inside Fluent Affiliate's admin chrome rather than a route inside their app.

Fluent Affiliate's admin is a compiled single-page app with a fixed route table:
there is no catch-all route, no JavaScript hook, and no global (`window.Vue`,
`window.ElementPlus` are absent), so a third party cannot register a screen in
it the way a FluentCRM module can. FluentCRM ships a module contract for this
— `FLUENTCRM_MODULE_API`, the `fluentcrm_global_routes` filter and an import
map that exposes `@fluentcrm/vue` and Element Plus to add-ons — and that
contract is exactly what this plugin would ask the Fluent team for. Until it
exists, the plugin does the next best thing:

- **Their chrome, printed by them.** The page callback calls
  `AdminMenuHandler::render()`, so Fluent's real navbar (with a "Commission
  rules" tab added through `fluent_affiliate/top_menu_items`) and their
  `#fluent-framework-app` mount point are on the page. Their two stylesheets are
  enqueued through their own `Vite::enqueueStyle()` helper, so RTL and dark mode
  (`fla_color_mode`) behave as on every other Fluent screen.
- **Our app, their look.** A plain-script Vue 3 app (`assets/admin/app.js`, no
  build step) mounts into that div. Vue is pinned to Fluent's own version
  (3.5.17) and Element Plus to 2.9.11; both are vendored under `assets/vendor/`
  (versions recorded in `assets/vendor/VERSIONS.md`).
  No component CSS is shipped at all: Fluent's two admin stylesheets
  (`app.min.css`, `admin.css`) style every Element Plus component the app
  renders; a smoke test fails the build if that ever stops being true.
- **A small REST API** (`fa-commission-rules/v1`: rules, options, product
  search) gated on Fluent's `manage_all_data` permission and authenticated with
  the standard `wp_rest` nonce, like their own SPA. Every rule label is built
  server-side, so the browser holds no naming or money-formatting logic.

If Fluent Affiliate ever grows a module contract, the app is one `createApp()`
away from becoming a route in theirs.

## Requirements

- WordPress 6.6+
- PHP 8.1+
- Fluent Affiliate 1.6+
- Fluent Affiliate **Pro** — optional; required for affiliate groups, lifetime
  commissions and the WooCommerce integration. Without Pro the group scope is
  hidden and everything else works.
- WooCommerce — optional; required for product and category targeting.

## What it hooks

| Hook | Priority | Why |
|---|---|---|
| `fluent_affiliate/referral_data` | 20 | The universal choke point for every referral from every integration. Priority 20 because Fluent Affiliate Pro's lifetime handler hooks the same filter at 10 and replaces the amount. Handles `sale`, `payment` and `lifetime_sale`. |
| `fluent_affiliate/recurring_commission` | 20 | WooCommerce Subscriptions renewals, priced from Fluent's exact renewal rate for that affiliate (`RecurringReferral::getBaseRenewalCommission()`) — the proportional-remainder formula below is only a fallback when that method is unreachable. |
| `fluent_affiliate/ignore_zero_amount_referral` | 20 | Fluent drops zero-amount referrals. A rule that deliberately says 0% is a decision, so the referral is still recorded — the filter is overridden for any referral this plugin stamped. |
| `fluent_affiliate/top_menu_items` | 10 | The header tab. |
| `fluent_affiliate/affiliate_widgets` | 10 | The profile card. |
| `fluent_affiliate/portal_notice_html` | 10 | The portal rate card. |
| `fluent_affiliate/after_delete_affiliate` / `…_affiliate_group` | 10 | Drops the rules of a deleted affiliate or group. |
| `rest_api_init` | — | Registers the `fa-commission-rules/v1` REST routes (`Controller::routes()`). |

It deliberately does **not** hook `fluent_affiliate/commission`: WooCommerce never
fires it, and hooking it alongside `referral_data` would double-apply on the
providers that do.

## Fluent Affiliate internals this plugin relies on

Fluent Affiliate exposes no public API for commission pricing, so a handful of
internals are read directly. None of them can produce a **wrong payout** if it
changes: for the two base-rate methods the plugin falls back to a documented,
conservative reading rather than guessing, and the referral's audit stamp always
records the remainder total and exactly what was paid on it, so any order can be
re-derived from what was written. What degrades is precision, never correctness.

| Internal | Used for | What degrades if it changes |
|---|---|---|
| `RecurringReferral::getBaseRenewalCommission()` (Pro) | Fluent's own whole-order base commission for a subscription renewal, which is then prorated by `remainder ÷ order_total` to price the part of the renewal no rule claimed. | Falls back to Fluent's already-blended figure, prorated the same way. That figure includes lines Fluent's own renewal rate table priced, so the unclaimed remainder can be paid slightly generously — a known ceiling, documented in the FAQ. It is never paid twice on a line a rule claimed. |
| `LifetimeCommissionHandler::getBaseLifetimeCommission()` (Pro) | The same, for `lifetime_sale` referrals. | Falls back to `$affiliate->getCommission( $total, 'sale' )` — the affiliate's ordinary sale rate instead of their lifetime rate. Rules still price every line they claim; only the unclaimed remainder shifts. |
| The `_woo_connector_config` option: the `custom_affiliate_rate` / `renewal_custom_affiliate_rate` gates, the `custom_affiliate_rates` / `renewal_custom_affiliate_rates` rows (`object_type`, `object_ids`, `rate`, `rate_type`) | Reading Fluent's own site-wide product/category rate table **live**, so it takes part in resolution as read-only "Everyone" rules instead of being silently overridden. Nothing is ever copied out of it. | Those rows stop being surfaced here. This plugin's own rules keep working, and Fluent keeps applying its table itself to anything no rule of ours claims. Because nothing is cached, no stale rate can ever be paid. |
| `watched_product_ids` / `watched_cat_ids` and their `renewal_` twins | The second gate Fluent itself checks before pricing those rows — mirrored, so this plugin never synthesises a rule Fluent would ignore. | At worst a Fluent global row is listed that Fluent no longer prices, or one is hidden that it does. Our own rules are unaffected. |
| The `order_total` and `products` keys of the `fluent_affiliate/referral_data` payload (and `order_data.referral_order_total` / `order_data.items` on `fluent_affiliate/recurring_commission`) | Building the order lines a rule is matched against. | With no usable total the plugin returns the amount untouched; with no usable lines it falls back to a single whole-order line. Either way Fluent's own pricing stands rather than a guess. |
| Priority 10 of Pro's lifetime handler on `fluent_affiliate/referral_data` | Why this plugin hooks that filter at 20: it has to run after Pro has replaced the amount, or its result is discarded. | If Pro moves later, lifetime commissions revert to Pro's own amount — surprising, but exactly what the store would have paid without this plugin. |
| The float payload of `fluent_affiliate/recurring_commission` | Renewal pricing. Fluent 1.6.5 passes a float; the developer docs describe an array with an `amount` key. | Both shapes are handled and whichever came in is what goes back out, so a version that switches shapes needs no change here. |

The synthetic `fluent:<n>` (and `fluent:renewal:<n>`) ids that stand for Fluent's
own rows — in the rules list and in audit stamps — are **position-based**: `<n>`
is that row's index in the connector option's rate table, not a stable id.
Reordering the table in Fluent's settings renumbers them, so read a `fluent:<n>`
in an old stamp as "one of Fluent's global rows at the time", not as a row you
can still look up today.

## Storage

One collection, in Fluent's own option storage (`fa_meta`, `object_type = 'option'`)
under the key `_fa_commission_rules`. Nothing is written to the affiliate group's
`value` array, which Fluent rebuilds from four keys on every save.
Uninstalling the plugin deletes that one row and nothing else.

## Tests

```bash
php tests/test-resolver.php                     # the engine, no WordPress needed
php tests/test-assets.php                       # vendored Vue / Element Plus pins
php tests/test-css-coverage.php                 # every rendered component is styled
node --test tests/helpers.test.mjs              # pure JS helpers

# wp eval-file fatals on these (declare(strict_types=1) isn't the file's first
# statement once WP-CLI wraps it), so require them through wp eval instead:
wp eval 'require WP_PLUGIN_DIR . "/fluent-affiliate-commission-rules/tests/test-integration.php";'  # end to end against Fluent
wp eval 'require WP_PLUGIN_DIR . "/fluent-affiliate-commission-rules/tests/test-rest.php";'         # the REST API
wp eval 'require WP_PLUGIN_DIR . "/fluent-affiliate-commission-rules/tests/test-admin.php";'        # the admin page shell
wp eval 'require WP_PLUGIN_DIR . "/fluent-affiliate-commission-rules/tests/test-neutrality.php";'   # brand and text-domain guard
```

## FAQ

**Does a group rule apply to an affiliate who has their own custom rate?**
Yes. A group *rule* applies to every member of the group, whatever that member's
own rate type is. This differs on purpose from Fluent's own group **rate**, which
only takes effect when the affiliate's `rate_type` is literally `group` — an
affiliate with a custom percentage never sees their group's rate. Rules are about
*what was sold*, so tying them to the affiliate's rate type would make them
unusable for exactly the partner you set a custom rate for. Precedence still
applies: a rule scoped to that one affiliate beats the group's.

**Product search in the rule editor shows nothing.**
The editor searches through this plugin's own endpoint (`GET /fa-commission-rules/v1/products?search=`), which needs only Fluent Affiliate's `manage_all_data` permission (via the REST route's `permission_callback`) and WooCommerce active. Type at least two characters; products and variations are matched by title, SKU and content, the same way WooCommerce's own admin search works.

**I edited a rule and got told it can't be saved.**
Two rules are refused outright rather than silently discarded: a rule whose id
no longer exists (deleted from another tab, or the browser back button) and one
of Fluent's own read-only "Everyone" rows (`fluent:…` ids) — that row lives in
Fluent's settings, not this plugin's storage, so there is nothing here to edit.

**The commission on a renewal is slightly off when my base rate is a flat amount.**
Renewals are priced from Fluent's own exact per-affiliate renewal rate wherever
that is reachable (`RecurringReferral::getBaseRenewalCommission()`), so the flat
case is exact, not prorated, whenever Pro's renewal machinery is present. Only
when that method can't be reached does this plugin fall back to deriving the
base rate for the unclaimed remainder by scaling Fluent's own figure by
`remainder ÷ order total` — exact for a percentage rate, and a deliberate
reading for a flat one: a flat rate is a per-order amount, so the share of it
that belongs to the unclaimed part of the order is what gets paid, never twice.
The same fallback proration applies to first-order and lifetime base rates.

**Fluent's docs say `fluent_affiliate/recurring_commission` filters an array, but
my snippet receives a float.**
Both are true depending on the version. Fluent 1.6.5 passes a float; the
developer docs describe an array with an `amount` key. This plugin handles both
and returns whichever shape it received.

**Fluent's docs say `fluent_affiliate/commission` receives a `rate` and a
`rate_type` in its context.**
Installed 1.6.5 passes only `affiliate`, `order_data`, `provider` and
`vendor_order` — and the WooCommerce integration never fires that filter at all,
because it calls `calculateFinalCommissionAmount()` directly. That is why this
plugin works on `referral_data` instead.

**What happens if I uninstall the plugin?**
The single `_fa_commission_rules` row is deleted from Fluent's own storage.
Referrals already recorded, and their audit stamps, are untouched — this only
stops new referrals from being priced by rules that no longer exist.

## Licence

GPL-2.0-or-later.
