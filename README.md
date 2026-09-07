# Commission Rules for Fluent Affiliate

Per-affiliate and per-group commission rules for [Fluent Affiliate](https://fluentaffiliate.com),
targeted at a product, a product category, or everything, with an optional date window.

**Source:** Developed in a private monorepo; this GitHub repository
([github.com/gremy/fluent-affiliate-commission-rules](https://github.com/gremy/fluent-affiliate-commission-rules))
is a one-way mirror of the plugin directory (changes are mirrored out commit by
commit by a mirror script in the monorepo, which is not part of this subtree).

> Independent third-party add-on. Not affiliated with, or endorsed by, WPManageNinja.

Fluent Affiliate can give each affiliate one rate, each group one rate, and the
whole site one product/category rate table. What it cannot express is the thing
most partner programs actually need:

> Partner X earns 10% on Headphones. Partner Y earns 5% on the same category.

This plugin adds that, without touching Fluent's compiled admin app and preserving Fluent's native rate-table behavior on lines this add-on does not override.

## How rules resolve

Every rule answers four questions: **who** (everyone, a group, one affiliate),
**what** (all products, a category, a product or variation), **how much**
(a percentage of the line total, or a flat amount per line), and **when**
(an optional start and end date). Rules can also restrict the order customer type to **B2B** or **B2C**; existing rules default to **Any**.

For each order line, **exactly one rule wins. Rules never stack.**

```
score = scope × 100 + customer × 10 + target
  scope:  affiliate 3 > group 2 > everyone 1
  customer: B2B/B2C 1 > Any 0
  target: product 3 > category 2 > everything 1
```

- Highest score wins.
- Two category rules tied on score: the **closest** category wins — a directly
  assigned term beats its parent, which beats its grandparent.
- Category rules match through the **ancestor chain**, so a rule on a parent
  category still applies to a product filed only under a child.
- Still tied: the newest rule wins.
- Lines no add-on rule claims use Fluent's own first matching global row: native rows retain their saved order, direct-category matching and parent-product IDs.
- Any remaining amount uses Fluent's base rate. Flat base amounts keep this add-on's established proration policy described below.
- Lifetime referrals use their lifetime base, without importing the initial-sale global rate table.

A referral on which none of your rules wins is never touched: Fluent's amount passes
through unchanged.

Fluent's own site-wide product/category rates are **not copied**. They are read
live and shown as read-only "Everyone" rows, so there is one list to read and no
second source of truth — and only when Fluent's own gate is on and it has built
a non-empty watched-product/category list from them, exactly as Fluent's own
price path requires.

## Different B2B and B2C rates

In **Fluent Affiliate → Commission Rules → Add rule**, choose the affiliate audience,
then **Order customer type → B2B orders** or **B2C orders**, products and rate. Save a
second rule for the other customer type. For example, two Everyone / All products
rules could pay 3% on B2B orders and 10% on B2C orders; these numbers are examples,
not defaults. The editor preview, list, profile and portal identify the order type.
The order-type filter selects rules with that setting, not all rules potentially
eligible for an order of that type.

Within the same affiliate audience, a B2B/B2C rule beats an Any rule, even if the Any
rule names a product. Audience priority still comes first: an individual affiliate
rule beats an Everyone rule. Use matching audiences when setting the two rates.
Opposite customer types never conflict or override one another.

Classification comes from WooCommerce's saved `b2bking_is_b2b_order` meta (`yes` / `no`),
using WooCommerce's HPOS-compatible order API. For older or manually created orders
without that marker, active B2BKing classifies the **order customer** at first pricing;
the result is saved as `_facr_customer_type` so subsequent account changes do not
reclassify that order. Guests are B2C in this fallback. An explicit B2BKing order
marker takes priority over the fallback snapshot.

If the order cannot be loaded, the provider is not WooCommerce, or an unmarked order
has no B2BKing available, its type is unknown and only Any rules can apply. Existing
B2BKing markers and fallback snapshots remain readable if B2BKing is deactivated.
The referral audit stamp includes the resolved customer type (`b2b`, `b2c`, or empty
for unknown). No existing referrals or saved rule rates are rewritten.

These conditions apply to initial sales, lifetime repeat purchases and subscription
renewals that Fluent already attributes. They do not enable lifetime or renewal
attribution, change customer ownership, or introduce a per-customer commission term.
Configure those native features in Fluent Affiliate separately.

## Where it appears

| Surface | What you get |
|---|---|
| **Fluent Affiliate → Commission Rules** | The rules list and editor, plus a "Commission rules" tab in Fluent's own header |
| **Affiliate profile** | A read-only summary of eligible rules and their source; fully covered targets are suppressed and partial coverage is qualified — plus an "Add rule for this affiliate" link |
| **Affiliate portal** | Conditional rate summaries showing the rate, eligible targets and end date; rates do not stack |
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
  the standard `wp_rest` nonce, like their own SPA. Every label in the rules list
  is built server-side and rendered verbatim by the browser; the only thing the
  app composes itself is the live "Result:" preview in the editor, from localised
  sentence and money templates handed to it by PHP — so no naming or money format
  is ever invented client-side.

If Fluent Affiliate adds a supported module contract, evaluate moving this screen into it.

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

The pricing integration is verified against Fluent Affiliate and Pro 1.6.5.
It reads the WooCommerce connector's sale/renewal tables and watched-ID gates,
uses the public base-rate methods on Pro's renewal/lifetime handlers, and relies
on the referral payload fields and lifetime filter priority. These are versioned
implementation details: changes upstream require compatibility tests before an
upgrade. An audit stamp records what was calculated; it is not a guarantee of
compatibility with future Fluent releases.

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

## Write safety and audit data

`GET /rules` returns an opaque `revision`. Send it unchanged in the `If-Match`
header for POST, DELETE and bulk requests. Missing revisions return 428; stale
revisions return 409. The admin handles this contract and asks for a reload.
Mutations and deletion hooks use a short per-site MySQL lock around a fresh read
and write, so concurrent requests cannot replace each other's collection.

Audit schema version 2 retains unrounded line and remainder commissions and
records `amount` plus `rounding_adjustment`. Their sum reconciles to the recorded
amount. Existing referral stamps are not rewritten.

English and Romanian PO/MO catalogs are included. The editor selects the matching
Element Plus locale and warns before discarding unsaved edits. Close and Cancel
are blocked while a save is pending.

## Tests

```bash
php tests/test-resolver.php                     # the engine, no WordPress needed
php tests/test-assets.php                       # vendored Vue / Element Plus pins
php tests/test-css-coverage.php                 # every rendered component is styled
node --test tests/*.test.mjs              # pure JS helpers

# wp eval-file fatals on these (declare(strict_types=1) isn't the file's first
# statement once WP-CLI wraps it), so require them through wp eval instead:
wp eval 'require WP_PLUGIN_DIR . "/fluent-affiliate-commission-rules/tests/test-integration.php";'  # end to end against Fluent
wp eval 'require WP_PLUGIN_DIR . "/fluent-affiliate-commission-rules/tests/test-rest.php";'         # the REST API
wp eval 'require WP_PLUGIN_DIR . "/fluent-affiliate-commission-rules/tests/test-admin.php";'        # the admin page shell
wp eval 'require WP_PLUGIN_DIR . "/fluent-affiliate-commission-rules/tests/test-neutrality.php";'   # brand and text-domain guard
```

The integration and REST suites require an empty disposable WordPress database;
they intentionally skip a database containing rules. Run `tests/test-regressions.php`
through `wp eval` for native sale/renewal parity, portal coverage, audit and lock
checks. Run `tests/test-concurrent-writes.php` the same way on an empty disposable
database for a two-process lost-update check.

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

**The affiliate picker in the editor doesn't list all my affiliates.**
It loads the 500 most recent affiliates in one go and filters them in the
browser; a program larger than that needs the picker switched to a remote search
against the REST API.

**I edited a rule and got told it can't be saved.**
Stale collection revisions are rejected with HTTP 409: reload before retrying. Also refused are a rule whose id
no longer exists (deleted from another tab, or the browser back button) and one
of Fluent's own read-only "Everyone" rows (`fluent:…` ids) — that row lives in
Fluent's settings, not this plugin's storage, so there is nothing here to edit.

**How does a flat base rate apply to an unmatched remainder?**
This add-on retains its existing policy for sales, lifetime sales and renewals:
ask Fluent for the whole-order base commission, then multiply it by
`remainder / order total`. For a percentage rate this is equivalent to applying
the rate directly to the remainder. For a flat base of 50 on a 150 order with
50 unmatched, the base contribution is 16.67. Native Fluent instead applies the
full flat base to a nonzero remainder. This is an explicit policy difference,
not a promise of identical flat-base behavior. Native flat product/category
rows retain their own per-line behavior.

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
