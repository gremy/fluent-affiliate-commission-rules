=== Commission Rules for Fluent Affiliate ===
Contributors: webbership
Tags: affiliate, commission, fluent affiliate, woocommerce, referrals
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Per-affiliate and per-group commission rules for Fluent Affiliate, targeted at a product, a category, or everything, with an optional date window.

== Description ==

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

The affiliate profile card and the affiliate portal card both show only the rules currently in force, one row per target, naming the Source (Individual, Group or Everyone) of the rule that actually won.

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

= Product search on the rule form shows nothing. =

WooCommerce's product search endpoint requires the edit_products capability. Without it the form falls back to a plain list of products.

= I edited a rule and got told it can't be saved. =

Two rules are refused rather than silently discarded: one whose id no longer exists (deleted elsewhere), and one of Fluent's own read-only "Everyone" rows — edit those in Fluent Affiliate's own WooCommerce settings instead.

= The commission on a renewal is slightly off when my base rate is a flat amount. =

Renewals are priced from Fluent's own exact per-affiliate renewal rate wherever that is reachable, so the flat case is exact there, never prorated. Only when that route can't be reached does this plugin fall back to deriving the base rate for the unclaimed remainder by scaling Fluent's own figure by remainder / order total. That is exact for a percentage base rate; for a flat one it is a deliberate reading, because a flat rate is a per-order amount and only the share belonging to the unclaimed part of the order should be paid. It is never paid twice.

= The docs say `fluent_affiliate/recurring_commission` filters an array, but I get a float. =

Fluent 1.6.5 passes a float; the developer docs describe an array with an `amount` key. This plugin handles both shapes and returns whichever it received.

= The docs say `fluent_affiliate/commission` receives a rate and rate_type. =

Installed 1.6.5 passes only affiliate, order_data, provider and vendor_order, and the WooCommerce integration never fires that filter at all. This plugin works on `fluent_affiliate/referral_data` instead.

= What parts of Fluent Affiliate does this plugin depend on internally? =

Fluent Affiliate exposes no public API for commission pricing, so a few internals are read directly: `RecurringReferral::getBaseRenewalCommission()` and `LifetimeCommissionHandler::getBaseLifetimeCommission()` (Pro) for Fluent's own base rate on renewals and lifetime sales; the `_woo_connector_config` option (its `custom_affiliate_rate(s)` and `renewal_*` gates and rate rows, plus the `watched_product_ids` / `watched_cat_ids` lists) for Fluent's global rate table, which is read live and never copied; the `order_total` and `products` keys of the `fluent_affiliate/referral_data` payload for the order lines; priority 10 of Pro's lifetime handler on that same filter, which is why this plugin hooks it at 20; and the float payload of `fluent_affiliate/recurring_commission`.

None of them can produce a wrong payout if it changes. Where a base-rate method is unreachable the plugin falls back to a documented, conservative figure instead of guessing, and every touched referral's audit stamp records the remainder and exactly what was paid on it. Where anything else changes, the worst case is that Fluent's global rows stop being surfaced here, or that no rule engages at all and Fluent's own pricing stands untouched.

One detail worth knowing when reading old audit stamps: the synthetic `fluent:<n>` ids for Fluent's own global rows are position-based — `<n>` is the row's index in the connector option's rate table, so reordering that table in Fluent's settings renumbers them.

= Does it need Fluent Affiliate Pro? =

No. Pro is needed for affiliate groups, lifetime commissions and the WooCommerce integration. Without Pro, the group scope is hidden and everything else works.

== Changelog ==

= 1.0.0 =
* Initial release.
