<?php
declare(strict_types=1);
/**
 * Pure-engine tests. No WordPress needed.
 * Run: php tests/test-resolver.php
 *  or: wp --path=/path/to/wp eval-file wp-content/plugins/fluent-affiliate-commission-rules/tests/test-resolver.php
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );
require_once dirname( __DIR__ ) . '/includes/Resolver.php';

use FACommissionRules\Resolver;

$GLOBALS['facr_res_fail'] = $GLOBALS['facr_res_fail'] ?? 0;

if ( ! function_exists( 'facr_rt' ) ) {
  function facr_rt( string $label, bool $ok ): void {
    if ( $ok ) {
      echo "PASS {$label}\n";
      return;
    }
    $GLOBALS['facr_res_fail']++;
    echo "FAIL {$label}\n";
  }
}

/** Rule factory with the Task 2 shape and sane defaults. */
function facr_rule( array $overrides = [] ): array {
  return array_merge(
    [
      'id'          => 'r' . wp_rand_stub(),
      'status'      => 'active',
      'scope_type'  => 'all',
      'scope_id'    => 0,
      'target_type' => 'all',
      'target_ids'  => [],
      'rate'        => 5.0,
      'rate_type'   => 'percentage',
      'starts_at'   => '',
      'ends_at'     => '',
      'note'        => '',
      'created_at'  => '2026-01-01T00:00:00+00:00',
      'readonly'    => false,
    ],
    $overrides
  );
}

function wp_rand_stub(): string {
  static $n = 0;
  return (string) ( ++$n );
}

function facr_line( int $product_id, float $total, array $depths = [], int $variation_id = 0 ): array {
  return [
    'product_id'   => $product_id,
    'variation_id' => $variation_id,
    'total'        => $total,
    'term_ids'     => array_map( 'intval', array_keys( $depths ) ),
    'term_depths'  => $depths,
  ];
}

$ctx  = [ 'affiliate_id' => 7, 'group_id' => 3 ];
$now  = '2026-09-05';
$base = static fn( float $remainder ): float => $remainder * 0.05; // stand-in for Fluent's own rate

// 1. Precedence: an affiliate product rule beats a group category rule.
$rules = [
  facr_rule( [ 'id' => 'grp-cat', 'scope_type' => 'group', 'scope_id' => 3, 'target_type' => 'category', 'target_ids' => [ 12 ], 'rate' => 10.0 ] ),
  facr_rule( [ 'id' => 'aff-prod', 'scope_type' => 'affiliate', 'scope_id' => 7, 'target_type' => 'product', 'target_ids' => [ 44 ], 'rate' => 12.0 ] ),
];
$out = Resolver::resolve( $ctx, 100.0, [ facr_line( 44, 100.0, [ 12 => 0 ] ) ], $rules, $now, $base );
facr_rt( 'affiliate+product beats group+category', $out['lines'][0]['rule_id'] === 'aff-prod' );
facr_rt( 'winning rate is applied once', abs( $out['amount'] - 12.0 ) < 0.001 );
facr_rt( 'no remainder when every line matched', abs( $out['remainder_total'] ) < 0.001 );

// 2. Scope precedence at equal target specificity.
$rules = [
  facr_rule( [ 'id' => 'all-all', 'scope_type' => 'all', 'rate' => 4.0 ] ),
  facr_rule( [ 'id' => 'grp-all', 'scope_type' => 'group', 'scope_id' => 3, 'rate' => 8.0 ] ),
  facr_rule( [ 'id' => 'aff-all', 'scope_type' => 'affiliate', 'scope_id' => 7, 'rate' => 11.0 ] ),
];
$out = Resolver::resolve( $ctx, 100.0, [ facr_line( 44, 100.0 ) ], $rules, $now, $base );
facr_rt( 'affiliate scope beats group and everyone', $out['lines'][0]['rule_id'] === 'aff-all' );

// 3. Category depth: the directly assigned term beats its ancestor.
$rules = [
  facr_rule( [ 'id' => 'parent-cat', 'scope_type' => 'group', 'scope_id' => 3, 'target_type' => 'category', 'target_ids' => [ 7 ], 'rate' => 10.0 ] ),
  facr_rule( [ 'id' => 'child-cat', 'scope_type' => 'group', 'scope_id' => 3, 'target_type' => 'category', 'target_ids' => [ 12 ], 'rate' => 5.0 ] ),
];
$out = Resolver::resolve( $ctx, 100.0, [ facr_line( 44, 100.0, [ 12 => 0, 7 => 1 ] ) ], $rules, $now, $base );
facr_rt( 'closest category wins the tie', $out['lines'][0]['rule_id'] === 'child-cat' );
facr_rt( 'closest category rate is used', abs( $out['amount'] - 5.0 ) < 0.001 );

// 4. Ancestor matching: a parent-category rule still applies to a product tagged only with the child.
$rules = [ facr_rule( [ 'id' => 'ancestor', 'scope_type' => 'group', 'scope_id' => 3, 'target_type' => 'category', 'target_ids' => [ 7 ], 'rate' => 9.0 ] ) ];
$out   = Resolver::resolve( $ctx, 100.0, [ facr_line( 44, 100.0, [ 12 => 0, 7 => 1 ] ) ], $rules, $now, $base );
facr_rt( 'a parent-category rule matches through the ancestor chain', $out['lines'][0]['rule_id'] === 'ancestor' );

// 5. Exact tie falls to the newest rule.
$rules = [
  facr_rule( [ 'id' => 'older', 'scope_type' => 'group', 'scope_id' => 3, 'target_type' => 'category', 'target_ids' => [ 12 ], 'rate' => 6.0, 'created_at' => '2026-01-01T00:00:00+00:00' ] ),
  facr_rule( [ 'id' => 'newer', 'scope_type' => 'group', 'scope_id' => 3, 'target_type' => 'category', 'target_ids' => [ 12 ], 'rate' => 7.0, 'created_at' => '2026-05-01T00:00:00+00:00' ] ),
];
$out = Resolver::resolve( $ctx, 100.0, [ facr_line( 44, 100.0, [ 12 => 0 ] ) ], $rules, $now, $base );
facr_rt( 'newest rule wins an exact tie', $out['lines'][0]['rule_id'] === 'newer' );

// 5b. Two rules saved in the same second are still ordered: Store mints created_at
// with microseconds and every comparison of it is a plain string compare, so the
// microsecond digits decide rather than array order. A second-precision stamp
// still sorts before a fractional one in the same second ('+' < '.').
$rules = [
  facr_rule( [ 'id' => 'micro-newer', 'scope_type' => 'group', 'scope_id' => 3, 'target_type' => 'category', 'target_ids' => [ 12 ], 'rate' => 7.0, 'created_at' => '2026-05-01T00:00:00.000917+00:00' ] ),
  facr_rule( [ 'id' => 'micro-older', 'scope_type' => 'group', 'scope_id' => 3, 'target_type' => 'category', 'target_ids' => [ 12 ], 'rate' => 6.0, 'created_at' => '2026-05-01T00:00:00.000042+00:00' ] ),
];
$out = Resolver::resolve( $ctx, 100.0, [ facr_line( 44, 100.0, [ 12 => 0 ] ) ], $rules, $now, $base );
facr_rt( 'a microsecond newer rule wins the tie regardless of array order', $out['lines'][0]['rule_id'] === 'micro-newer' );
$eff = Resolver::effective( $rules, $ctx, $now );
facr_rt( 'effective() picks the microsecond newer rule too', count( $eff ) === 1 && $eff[0]['id'] === 'micro-newer' );
$rules[] = facr_rule( [ 'id' => 'second-precision', 'scope_type' => 'group', 'scope_id' => 3, 'target_type' => 'category', 'target_ids' => [ 12 ], 'rate' => 5.0, 'created_at' => '2026-05-01T00:00:00+00:00' ] );
$out     = Resolver::resolve( $ctx, 100.0, [ facr_line( 44, 100.0, [ 12 => 0 ] ) ], $rules, $now, $base );
facr_rt( 'an existing second-precision stamp is older than a fractional one in the same second', $out['lines'][0]['rule_id'] === 'micro-newer' );

// 6. Date windows.
$rules = [
  facr_rule( [ 'id' => 'expired', 'scope_type' => 'affiliate', 'scope_id' => 7, 'rate' => 20.0, 'ends_at' => '2026-08-31' ] ),
  facr_rule( [ 'id' => 'future', 'scope_type' => 'affiliate', 'scope_id' => 7, 'rate' => 30.0, 'starts_at' => '2026-12-01' ] ),
  facr_rule( [ 'id' => 'current', 'scope_type' => 'affiliate', 'scope_id' => 7, 'rate' => 15.0, 'starts_at' => '2026-09-01', 'ends_at' => '2026-09-30' ] ),
];
$out = Resolver::resolve( $ctx, 100.0, [ facr_line( 44, 100.0 ) ], $rules, $now, $base );
facr_rt( 'only the in-window rule applies', $out['lines'][0]['rule_id'] === 'current' );
facr_rt( 'the window is inclusive of ends_at', count( Resolver::applicable( $rules, $ctx, '2026-09-30' ) ) === 1 );
facr_rt( 'the window is inclusive of starts_at', count( Resolver::applicable( $rules, $ctx, '2026-09-01' ) ) === 1 );

// 7. Inactive rules never apply.
$rules = [ facr_rule( [ 'id' => 'off', 'scope_type' => 'affiliate', 'scope_id' => 7, 'rate' => 40.0, 'status' => 'inactive' ] ) ];
facr_rt( 'inactive rules are dropped', Resolver::resolve( $ctx, 100.0, [ facr_line( 44, 100.0 ) ], $rules, $now, $base ) === null );

// 8. Other people's rules never apply.
$rules = [
  facr_rule( [ 'id' => 'other-aff', 'scope_type' => 'affiliate', 'scope_id' => 99, 'rate' => 40.0 ] ),
  facr_rule( [ 'id' => 'other-grp', 'scope_type' => 'group', 'scope_id' => 42, 'rate' => 40.0 ] ),
];
facr_rt( 'another affiliate or group is out of scope', Resolver::resolve( $ctx, 100.0, [ facr_line( 44, 100.0 ) ], $rules, $now, $base ) === null );

// 9. Fluent's own rows alone never engage the engine.
$rules = [ facr_rule( [ 'id' => 'fluent:0', 'scope_type' => 'all', 'target_type' => 'category', 'target_ids' => [ 12 ], 'rate' => 0.0, 'readonly' => true, 'created_at' => '1970-01-01T00:00:00+00:00' ] ) ];
facr_rt( 'Fluent global rows alone return null', Resolver::resolve( $ctx, 100.0, [ facr_line( 44, 100.0, [ 12 => 0 ] ) ], $rules, $now, $base ) === null );

// 10. …but they do participate once one of our rules engages.
$rules[] = facr_rule( [ 'id' => 'ours', 'scope_type' => 'affiliate', 'scope_id' => 7, 'target_type' => 'product', 'target_ids' => [ 51 ], 'rate' => 10.0 ] );
$out     = Resolver::resolve(
  $ctx,
  200.0,
  [ facr_line( 44, 100.0, [ 12 => 0 ] ), facr_line( 51, 100.0 ) ],
  $rules,
  $now,
  $base
);
facr_rt( 'Fluent row wins its line once we are engaged', $out['lines'][0]['rule_id'] === 'fluent:0' );
facr_rt( 'zero-rate Fluent row pays nothing on that line', abs( $out['lines'][0]['commission'] ) < 0.001 );
facr_rt( 'our rule pays on its own line', abs( $out['lines'][1]['commission'] - 10.0 ) < 0.001 );
facr_rt( 'total is the sum of matched lines', abs( $out['amount'] - 10.0 ) < 0.001 );

// 11. Flat rates are per line, not per unit and not per order.
$rules = [ facr_rule( [ 'id' => 'flat', 'scope_type' => 'affiliate', 'scope_id' => 7, 'rate' => 3.0, 'rate_type' => 'flat' ] ) ];
$out   = Resolver::resolve( $ctx, 200.0, [ facr_line( 44, 100.0 ), facr_line( 51, 100.0 ) ], $rules, $now, $base );
facr_rt( 'flat pays once per line', abs( $out['amount'] - 6.0 ) < 0.001 );

// 12. Remainder: unmatched money keeps the base rate, computed off order_total.
$rules = [ facr_rule( [ 'id' => 'prod', 'scope_type' => 'affiliate', 'scope_id' => 7, 'target_type' => 'product', 'target_ids' => [ 44 ], 'rate' => 20.0 ] ) ];
$out   = Resolver::resolve( $ctx, 150.0, [ facr_line( 44, 100.0 ), facr_line( 51, 40.0 ) ], $rules, $now, $base );
facr_rt( 'remainder is order_total minus matched lines', abs( $out['remainder_total'] - 50.0 ) < 0.001 );
facr_rt( 'remainder uses the injected base rate', abs( $out['remainder_commission'] - 2.5 ) < 0.001 );
facr_rt( 'amount is matched plus remainder', abs( $out['amount'] - 22.5 ) < 0.001 );
facr_rt( 'unmatched lines are reported with a null rule', $out['lines'][1]['rule_id'] === null );

// 13. Variation targeting.
$rules = [ facr_rule( [ 'id' => 'var', 'scope_type' => 'affiliate', 'scope_id' => 7, 'target_type' => 'product', 'target_ids' => [ 512 ], 'rate' => 25.0 ] ) ];
$out   = Resolver::resolve( $ctx, 80.0, [ facr_line( 44, 80.0, [], 512 ) ], $rules, $now, $base );
facr_rt( 'a product rule matches the variation id', $out['lines'][0]['rule_id'] === 'var' );

// 14. Negative or absurd totals never produce negative money.
$out = Resolver::resolve( $ctx, 0.0, [ facr_line( 44, 0.0 ) ], [ facr_rule( [ 'id' => 'z', 'scope_type' => 'affiliate', 'scope_id' => 7, 'rate' => 10.0 ] ) ], $now, $base );
facr_rt( 'a zero-total order yields zero', abs( $out['amount'] ) < 0.001 );

// 14b. A negative matched line is paid zero (clamped in line_commission), but
// must not shrink matched_sum below what was actually claimed — otherwise the
// remainder grows and the base rate gets re-paid on money a matched rule
// already touched. order_total 150, lines +100 and -50, both matched by the
// same 20% rule, 10% flat base on the remainder:
//   matched_sum = max(0,100) + max(0,-50) = 100  =>  remainder = 50
//   commission  = 20 (line1) + 0 (line2, clamped) = 20
//   amount      = 20 + 0.10*50 = 25.00 (the old bug summed raw totals,
//   matched_sum=50, remainder=100, giving 30.00 instead).
$flat10 = static fn( float $remainder ): float => $remainder * 0.10;
$out    = Resolver::resolve(
  $ctx,
  150.0,
  [ facr_line( 44, 100.0 ), facr_line( 51, -50.0 ) ],
  [ facr_rule( [ 'id' => 'neg', 'scope_type' => 'affiliate', 'scope_id' => 7, 'rate' => 20.0 ] ) ],
  $now,
  $flat10
);
facr_rt( 'a negative matched line does not shrink the remainder', abs( $out['amount'] - 25.0 ) < 0.001 );
facr_rt( 'no line commission is ever negative', $out['lines'][0]['commission'] >= 0.0 && $out['lines'][1]['commission'] >= 0.0 );

// 15. Shadow map for the admin list.
// Overlap is deliberately strict: a rule only shadows another when both apply to
// everyone, or when they name the same audience. Affiliate 7 may or may not be in
// group 3 and the map has no way to know, so a group rule is never reported as
// shadowed by an affiliate rule.
$rules = [
  facr_rule( [ 'id' => 'everyone', 'scope_type' => 'all', 'target_type' => 'all', 'rate' => 4.0 ] ),
  facr_rule( [ 'id' => 'wide', 'scope_type' => 'group', 'scope_id' => 3, 'target_type' => 'all', 'rate' => 10.0 ] ),
  facr_rule( [ 'id' => 'narrow', 'scope_type' => 'group', 'scope_id' => 3, 'target_type' => 'category', 'target_ids' => [ 7 ], 'rate' => 5.0 ] ),
  facr_rule( [ 'id' => 'deeper', 'scope_type' => 'group', 'scope_id' => 3, 'target_type' => 'product', 'target_ids' => [ 44 ], 'rate' => 3.0 ] ),
  facr_rule( [ 'id' => 'mine', 'scope_type' => 'affiliate', 'scope_id' => 7, 'target_type' => 'product', 'target_ids' => [ 44 ], 'rate' => 12.0 ] ),
  facr_rule( [ 'id' => 'elsewhere', 'scope_type' => 'group', 'scope_id' => 99, 'target_type' => 'category', 'target_ids' => [ 7 ], 'rate' => 3.0 ] ),
];
$shadow = Resolver::shadow_map( $rules );
facr_rt( 'a narrower target in the same group shadows a wider one', $shadow['wide'] === 'deeper' );
facr_rt( 'a product rule shadows a category rule in the same group', $shadow['narrow'] === 'deeper' );
facr_rt( 'the narrowest rule in a group is not shadowed', $shadow['deeper'] === null );
facr_rt( 'a group rule is never shadowed by an affiliate rule', $shadow['wide'] !== 'mine' && $shadow['narrow'] !== 'mine' );
facr_rt( 'a different group is not shadowed', $shadow['elsewhere'] === null );
facr_rt( 'an Everyone rule is shadowed by the narrowest rule anywhere', $shadow['mine'] === null && $shadow['everyone'] === 'mine' );

// 15b. shadow_map() must respect date windows: a narrower-but-expired rule
// must never be reported as shadowing a live rule (it can't win any line,
// expired or not, so reporting it as the override is simply wrong).
$rules = [
  facr_rule( [ 'id' => 'live-wide', 'scope_type' => 'group', 'scope_id' => 3, 'target_type' => 'all', 'rate' => 5.0, 'starts_at' => '2026-01-01' ] ),
  facr_rule( [ 'id' => 'expired-narrow', 'scope_type' => 'group', 'scope_id' => 3, 'target_type' => 'product', 'target_ids' => [ 44 ], 'rate' => 15.0, 'ends_at' => '2020-01-01' ] ),
];
$shadow = Resolver::shadow_map( $rules );
facr_rt( 'an expired narrower rule does not shadow a live rule', $shadow['live-wide'] === null );

// 16. A flat base rate is a per-order figure, so it is prorated onto the
// remainder rather than paid again in full.
$flat_base = static fn( float $remainder ): float => 50.0 * ( $remainder / 150.0 );
$rules     = [ facr_rule( [ 'id' => 'ten', 'scope_type' => 'affiliate', 'scope_id' => 7, 'target_type' => 'product', 'target_ids' => [ 44 ], 'rate' => 10.0 ] ) ];
$out       = Resolver::resolve( $ctx, 150.0, [ facr_line( 44, 100.0 ), facr_line( 51, 50.0 ) ], $rules, $now, $flat_base );
facr_rt( 'a flat base rate is prorated onto the remainder', abs( $out['amount'] - 26.67 ) < 0.001 );
facr_rt( 'the amount is rounded to two decimals exactly once', $out['amount'] === round( $out['amount'], 2 ) );
$out = Resolver::resolve( $ctx, 100.0, [ facr_line( 44, 100.0 ) ], $rules, $now, $flat_base );
facr_rt( 'a fully matched order pays no base at all', abs( $out['amount'] - 10.0 ) < 0.001 && abs( $out['remainder_commission'] ) < 0.001 );

// 17. Tie map: two rules of identical specificity that could both win a line.
$rules = [
  facr_rule( [ 'id' => 'tie-a', 'scope_type' => 'group', 'scope_id' => 3, 'target_type' => 'category', 'target_ids' => [ 12 ], 'rate' => 6.0, 'starts_at' => '2026-01-01' ] ),
  facr_rule( [ 'id' => 'tie-b', 'scope_type' => 'group', 'scope_id' => 3, 'target_type' => 'category', 'target_ids' => [ 12, 44 ], 'rate' => 7.0, 'starts_at' => '2026-01-01' ] ),
  facr_rule( [ 'id' => 'tie-c', 'scope_type' => 'group', 'scope_id' => 3, 'target_type' => 'category', 'target_ids' => [ 99 ], 'rate' => 8.0, 'starts_at' => '2026-01-01' ] ),
  facr_rule( [ 'id' => 'tie-past', 'scope_type' => 'group', 'scope_id' => 3, 'target_type' => 'category', 'target_ids' => [ 12 ], 'rate' => 9.0, 'ends_at' => '2025-12-31' ] ),
  facr_rule( [ 'id' => 'tie-other', 'scope_type' => 'affiliate', 'scope_id' => 7, 'target_type' => 'category', 'target_ids' => [ 12 ], 'rate' => 9.0 ] ),
];
$ties = Resolver::tie_map( $rules );
facr_rt( 'two equally specific overlapping rules tie', $ties['tie-a'] === [ 'tie-b' ] );
facr_rt( 'the tie is reported on both rules', in_array( 'tie-a', $ties['tie-b'], true ) );
facr_rt( 'a rule on a different category does not tie', $ties['tie-c'] === [] );
facr_rt( 'a rule for a different audience does not tie', $ties['tie-other'] === [] );
facr_rt( 'a rule whose window has already closed does not tie', $ties['tie-past'] === [] );


// 18. Target precedence at equal scope: product beats category beats all,
// with all three rules sharing the exact same scope.
$rules = [
  facr_rule( [ 'id' => 'scope-all', 'scope_type' => 'group', 'scope_id' => 3, 'target_type' => 'all', 'rate' => 5.0 ] ),
  facr_rule( [ 'id' => 'scope-cat', 'scope_type' => 'group', 'scope_id' => 3, 'target_type' => 'category', 'target_ids' => [ 12 ], 'rate' => 10.0 ] ),
  facr_rule( [ 'id' => 'scope-prod', 'scope_type' => 'group', 'scope_id' => 3, 'target_type' => 'product', 'target_ids' => [ 44 ], 'rate' => 15.0 ] ),
];
$out = Resolver::resolve( $ctx, 100.0, [ facr_line( 44, 100.0, [ 12 => 0 ] ) ], $rules, $now, $base );
facr_rt( 'product target beats category and all at equal scope', $out['lines'][0]['rule_id'] === 'scope-prod' );
facr_rt( 'the product rate is applied', abs( $out['amount'] - 15.0 ) < 0.001 );

// 19. A parent-product rule still matches a variation line: target_ids names
// the parent product id, the line carries a different variation_id.
$rules = [ facr_rule( [ 'id' => 'parent', 'scope_type' => 'affiliate', 'scope_id' => 7, 'target_type' => 'product', 'target_ids' => [ 44 ], 'rate' => 18.0 ] ) ];
$out   = Resolver::resolve( $ctx, 80.0, [ facr_line( 44, 80.0, [], 999 ) ], $rules, $now, $base );
facr_rt( 'a parent-product rule matches a variation of that product', $out['lines'][0]['rule_id'] === 'parent' );
facr_rt( 'the parent rule rate is applied to the variation line', abs( $out['amount'] - 14.4 ) < 0.001 );

// 20. effective(): one rule per distinct target, same specificity order as
// order-line resolution — the portal/admin-card fix. An affiliate's own
// "all products" rule beats their group's "all products" rule outright
// (only the own rule ever pays), but rules on different targets both survive.
$rules = [
  facr_rule( [ 'id' => 'own-all', 'scope_type' => 'affiliate', 'scope_id' => 7, 'target_type' => 'all', 'rate' => 15.0 ] ),
  facr_rule( [ 'id' => 'grp-all', 'scope_type' => 'group', 'scope_id' => 3, 'target_type' => 'all', 'rate' => 10.0 ] ),
];
$eff = Resolver::effective( $rules, $ctx, $now );
facr_rt( 'effective(): own all-products rule wins over the group all-products rule', count( $eff ) === 1 && $eff[0]['id'] === 'own-all' );

$rules = [
  facr_rule( [ 'id' => 'own-prod', 'scope_type' => 'affiliate', 'scope_id' => 7, 'target_type' => 'product', 'target_ids' => [ 44 ], 'rate' => 12.0 ] ),
  facr_rule( [ 'id' => 'grp-prod', 'scope_type' => 'group', 'scope_id' => 3, 'target_type' => 'product', 'target_ids' => [ 44 ], 'rate' => 9.0 ] ),
];
$eff = Resolver::effective( $rules, $ctx, $now );
facr_rt( 'effective(): own product rule wins over a group rule on the same product', count( $eff ) === 1 && $eff[0]['id'] === 'own-prod' );

$rules = [
  facr_rule( [ 'id' => 'grp-cat', 'scope_type' => 'group', 'scope_id' => 3, 'target_type' => 'category', 'target_ids' => [ 12 ], 'rate' => 8.0 ] ),
  facr_rule( [ 'id' => 'own-all', 'scope_type' => 'affiliate', 'scope_id' => 7, 'target_type' => 'all', 'rate' => 15.0 ] ),
];
$eff     = Resolver::effective( $rules, $ctx, $now );
$eff_ids = array_map( static fn( array $r ): string => (string) $r['id'], $eff );
sort( $eff_ids );
facr_rt( 'effective(): an affiliate all-products rule suppresses the group category rate', $eff_ids === [ 'own-all' ] );

// 21. A flat rate on a line that sold nothing pays nothing. The line is still
// claimed by the rule — it just is not worth anything — so the untouched part of
// the order keeps the base rate and nobody is paid for a free gift.
$rules  = [ facr_rule( [ 'id' => 'flat50', 'scope_type' => 'affiliate', 'scope_id' => 7, 'target_type' => 'product', 'target_ids' => [ 101 ], 'rate' => 50.0, 'rate_type' => 'flat' ] ) ];
$base10 = static fn( float $remainder ): float => $remainder * 0.10;
$out    = Resolver::resolve( $ctx, 100.0, [ facr_line( 101, 0.0 ), facr_line( 202, 100.0 ) ], $rules, $now, $base10 );
facr_rt( 'a flat rate pays nothing on a zero-total line', abs( (float) $out['lines'][0]['commission'] ) < 0.001 );
facr_rt( 'a zero-total flat line does not inflate the order commission', abs( $out['amount'] - 10.0 ) < 0.001 );
facr_rt( 'a flat rate still pays in full on a line that sold something', abs( Resolver::line_commission( 100.0, 50.0, 'flat' ) - 50.0 ) < 0.001 );
facr_rt( 'a percentage on a zero-total line is still zero', abs( Resolver::line_commission( 0.0, 10.0, 'percentage' ) ) < 0.001 );

echo $GLOBALS['facr_res_fail'] ? "\n{$GLOBALS['facr_res_fail']} FAILURES\n" : "\nAll resolver checks passed\n";
if ( PHP_SAPI === 'cli' && ! defined( 'WP_CLI' ) ) {
  exit( $GLOBALS['facr_res_fail'] ? 1 : 0 );
}
