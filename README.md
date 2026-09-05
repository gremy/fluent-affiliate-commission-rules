# Commission Rules for Fluent Affiliate

Per-affiliate and per-group commission rules for [Fluent Affiliate](https://fluentaffiliate.com),
targeted at a product, a product category, or everything, with an optional date window.

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

It deliberately does **not** hook `fluent_affiliate/commission`: WooCommerce never
fires it, and hooking it alongside `referral_data` would double-apply on the
providers that do.

## Storage

One collection, in Fluent's own option storage (`fa_meta`, `object_type = 'option'`)
under the key `_fa_commission_rules`. Nothing is written to the affiliate group's
`value` array, which Fluent rebuilds from four keys on every save.
Uninstalling the plugin deletes that one row and nothing else.

## Tests

```bash
php tests/test-resolver.php                     # the engine, no WordPress needed
wp eval-file tests/test-integration.php         # end to end against Fluent
wp eval-file tests/test-neutrality.php          # brand and text-domain guard
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

**Product search on the rule form shows nothing.**
WooCommerce's product search endpoint requires the `edit_products` capability. If
the account managing commission rules does not have it, the form falls back to a
plain list of products instead of the search box.

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
