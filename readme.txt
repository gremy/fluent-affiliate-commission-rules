=== Commission Rules for Fluent Affiliate ===
Contributors: webbership
Tags: affiliate, commission, fluent affiliate, woocommerce, referrals
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Per-affiliate and per-group commission rules for Fluent Affiliate, targeted at a product, a category, or everything, with an optional date window.

== Description ==

Source: [github.com/gremy/fluent-affiliate-commission-rules](https://github.com/gremy/fluent-affiliate-commission-rules) is a one-way mirror. The private monorepo this plugin is developed in is the source of truth.

Fluent Affiliate gives each affiliate one rate, each group one rate, and the whole site one product/category rate table. This add-on adds the dimension it is missing: a rate that depends on **who** the affiliate is *and* **what** they sold.

Every rule answers four questions: who (everyone, a group, one affiliate), what (all products, a category, a product or variation), how much (a percentage of the line total, or a flat amount per line), and when (an optional start and end date).

For each order line exactly one rule wins, and rules never stack:

* score = scope x 10 + target; scope: affiliate 3 > group 2 > everyone 1; target: product 3 > category 2 > everything 1
* tied category rules: the closest category wins, so a directly assigned term beats its parent
* category rules match through the ancestor chain
* still tied: the newest rule wins
* whatever no rule claimed keeps Fluent's own rate

An affiliate with no rule of yours is never touched.

Fluent's own site-wide product/category rates are not copied. They are read live and shown as read-only "Everyone" rows, and only surfaced when Fluent's own gate is on and it has built a watched-product/category list from them, matching Fluent's own price path exactly.

Every referral it touches carries an audit stamp, plus a short note appended to the description so the reason shows up in Fluent's CSV export.

The affiliate profile and portal show eligible rules, suppress fully covered targets and qualify partial coverage. Higher-priority rules take precedence; rates do not stack. Native global rates apply to initial sales only on these cards.

The rules screen is a Vue 3 + Element Plus app mounted inside Fluent Affiliate's own admin chrome, styled by Fluent's own stylesheets so it looks and behaves like one of their screens, including dark mode. It lives on a page of its own because Fluent Affiliate's compiled admin app has no module contract for third-party screens (FluentCRM's `fluentcrm_global_routes` and import-map contract is the model). Data moves over a small REST API (`fa-commission-rules/v1`) gated on Fluent's manage_all_data permission.

= Hooks it uses =

* `fluent_affiliate/referral_data` at priority 20 (after Pro's lifetime handler at 10) — first orders, coupon and manual-link attribution, lifetime sales
* `fluent_affiliate/recurring_commission` — WooCommerce Subscriptions renewals, priced from Fluent's exact per-affiliate renewal rate wherever reachable
* `fluent_affiliate/ignore_zero_amount_referral` at priority 20 — so a deliberate 0% rule still records a referral
* `fluent_affiliate/top_menu_items`, `fluent_affiliate/affiliate_widgets`, `fluent_affiliate/portal_notice_html` — the three UI surfaces
* `fluent_affiliate/after_delete_affiliate` and `fluent_affiliate/after_delete_affiliate_group` — cleanup

== Installation ==

1. Install and activate Fluent Affiliate 1.6 or newer.
2. Upload this plugin to `/wp-content/plugins/` and activate it.
3. Go to Fluent Affiliate > Commission Rules.

Uninstalling the plugin deletes its single stored option (`_fa_commission_rules`) from Fluent Affiliate's own option storage. Nothing else is touched, and no referral that was already recorded changes.

== Frequently Asked Questions ==

= Does a group rule apply to an affiliate who has their own custom rate? =

Yes. A group rule applies to every member of the group, whatever that member's own rate type is. This differs on purpose from Fluent's own group rate, which only takes effect when the affiliate's rate_type is literally "group". Precedence still applies: a rule scoped to that one affiliate beats the group's.

= Product search in the rule editor shows nothing. =

The editor searches through this plugin's own endpoint (`GET /fa-commission-rules/v1/products?search=`), which needs only Fluent Affiliate's manage_all_data permission and WooCommerce active. Type at least two characters; products and variations are matched by title, SKU and content, the same way WooCommerce's own admin search works.

= I edited a rule and got told it can't be saved. =

Two rules are refused rather than silently discarded: one whose id no longer exists (deleted elsewhere), and one of Fluent's own read-only "Everyone" rows — edit those in Fluent Affiliate's own WooCommerce settings instead.

= The commission on a renewal is slightly off when my base rate is a flat amount. =

This add-on prorates the whole-order base commission by remainder / order total for sales, renewals and lifetime referrals. With a flat base of 50 on a 150 order with 50 unmatched, the base contribution is 16.67. This preserves the add-on's existing policy; native Fluent instead pays the full flat base on a nonzero remainder. Native flat product/category rows keep their own per-line behavior.

= The docs say `fluent_affiliate/recurring_commission` filters an array, but I get a float. =

Fluent 1.6.5 passes a float; the developer docs describe an array with an `amount` key. This plugin handles both shapes and returns whichever it received.

= The docs say `fluent_affiliate/commission` receives a rate and rate_type. =

Installed 1.6.5 passes only affiliate, order_data, provider and vendor_order, and the WooCommerce integration never fires that filter at all. This plugin works on `fluent_affiliate/referral_data` instead.

= What parts of Fluent Affiliate does this plugin depend on internally? =

Fluent Affiliate exposes no public API for commission pricing, so a few internals are read directly: `RecurringReferral::getBaseRenewalCommission()` and `LifetimeCommissionHandler::getBaseLifetimeCommission()` (Pro) for Fluent's own base rate on renewals and lifetime sales; the `_woo_connector_config` option (its `custom_affiliate_rate(s)` and `renewal_*` gates and rate rows, plus the `watched_product_ids` / `watched_cat_ids` lists) for Fluent's global rate table, which is read live and never copied; the `order_total` and `products` keys of the `fluent_affiliate/referral_data` payload for the order lines; priority 10 of Pro's lifetime handler on that same filter, which is why this plugin hooks it at 20; and the float payload of `fluent_affiliate/recurring_commission`.

This integration is verified against Fluent Affiliate and Pro 1.6.5. Upstream changes to those internals require compatibility testing. The audit stamp records the calculation, not a guarantee of compatibility with future releases.

One detail worth knowing when reading old audit stamps: the synthetic `fluent:<n>` ids for Fluent's own global rows are position-based — `<n>` is the row's index in the connector option's rate table, so reordering that table in Fluent's settings renumbers them.

= Does it need Fluent Affiliate Pro? =

No. Pro is needed for affiliate groups, lifetime commissions and the WooCommerce integration. Without Pro, the group scope is hidden and everything else works.

== Changelog ==

= 1.3.0 =
* Add Any, B2B and B2C order customer types to commission rules.
* Use B2BKing order classification for sales, lifetime purchases and subscription renewals.
* Show customer types in rule summaries, filters and affiliate cards, with English and Romanian translations.

= 1.2.0 =
* Preserve native global rate order and matching on untouched lines; exclude sale tables from lifetime referrals.
* Correct overlapping portal coverage and qualify partial coverage.
* Reconcile audit amounts while retaining unrounded calculations.
* Require If-Match revisions and serialize writes to prevent lost updates.
* Add accessible field names, unsaved-edit protection, request-state fixes and Romanian catalogs/locale.
* Clarify inherited-rate and flat-base behavior.

= 1.1.0 =
* The Commission Rules screen is now a Vue 3 + Element Plus app inside Fluent Affiliate's own admin chrome, with a rule editor drawer, remote product search and dark mode.
* New REST API under fa-commission-rules/v1 (rules, options, product search).
* Removed the server-rendered rules screen and its admin-post handlers.

= 1.0.0 =
* Initial release.
