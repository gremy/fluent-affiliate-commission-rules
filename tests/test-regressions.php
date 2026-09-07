<?php
declare(strict_types=1);
/** Regression checks for native pricing, portal coverage, audit data and write conflicts. */

use FACommissionRules\Resolver;
use FACommissionRules\ReferralHooks;
use FACommissionRules\Store;

if ( ! defined( 'ABSPATH' ) || ! \FACommissionRules\Fluent::ready() ) {
  fwrite( STDERR, "Run with wp eval 'require .../tests/test-regressions.php';\n" );
  return;
}
$GLOBALS['facr_reg_fail'] = 0;
$check = static function ( string $label, bool $ok ): void {
  echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
  $GLOBALS['facr_reg_fail'] += $ok ? 0 : 1;
};
$rule = static fn( array $values ): array => array_merge( [
  'id' => 'own', 'status' => 'active', 'scope_type' => 'affiliate', 'scope_id' => 7,
  'target_type' => 'all', 'target_ids' => [], 'rate' => 15, 'rate_type' => 'percentage',
  'starts_at' => '', 'ends_at' => '', 'created_at' => '2026-01-01T00:00:00+00:00', 'readonly' => false,
], $values );
$line = static fn( int $id, float $total, array $depths = [] ): array => [
  'product_id' => $id, 'variation_id' => 0, 'total' => $total,
  'term_ids' => array_keys( $depths ), 'term_depths' => $depths,
];
$context = [ 'affiliate_id' => 7, 'group_id' => 3 ];
$today = '2026-09-07';
$base = static fn( float $total ): float => $total * .05;
$native_category = $rule( [ 'id' => 'fluent:0', 'scope_type' => 'all', 'scope_id' => 0, 'target_type' => 'category', 'target_ids' => [12], 'rate' => 5, 'readonly' => true ] );
$native_product = $rule( [ 'id' => 'fluent:1', 'scope_type' => 'all', 'scope_id' => 0, 'target_type' => 'product', 'target_ids' => [44], 'rate' => 20, 'readonly' => true ] );
$own = $rule( [ 'target_type' => 'product', 'target_ids' => [55], 'rate' => 10 ] );
$lines = [ $line(44, 100, [12 => 0]), $line(55, 100) ];

if ( trait_exists( '\FluentAffiliatePro\App\Services\Integrations\RecurringCommissionTrait' ) ) {
  $connector = new class extends \FluentAffiliate\App\Modules\Integrations\BaseConnector {
    use \FluentAffiliatePro\App\Services\Integrations\RecurringCommissionTrait;
    public function getConfig() {
      $rows = [
        ['object_type'=>'category', 'object_ids'=>[12], 'rate'=>5, 'rate_type'=>'percentage'],
        ['object_type'=>'product', 'object_ids'=>[44], 'rate'=>20, 'rate_type'=>'percentage'],
      ];
      return [
        'custom_affiliate_rate'=>'yes', 'watched_product_ids'=>[44], 'watched_cat_ids'=>[12], 'custom_affiliate_rates'=>$rows,
        'renewal_custom_affiliate_rate'=>'yes', 'renewal_watched_product_ids'=>[44], 'renewal_watched_cat_ids'=>[12], 'renewal_custom_affiliate_rates'=>$rows,
      ];
    }
    public function getPostTermsMaps($ids, $taxonomy) { return [44 => [12]]; }
    public function getBaseRenewalCommission($affiliate, $amount) { return $amount * .05; }
  };
  $affiliate = new class { public function getCommission($total, $type) { return $total * .05; } };
  $order = ['referral_order_total'=>200, 'items'=>[['item_id'=>44, 'subtotal'=>100], ['item_id'=>55, 'subtotal'=>100]]];
  foreach ( [ 'sale', 'renewal' ] as $type ) {
    $native = $type === 'sale' ? $connector->calculateFinalCommissionAmount($affiliate, $order, 'product_cat') : $connector->calculateFinalRecurringCommissionAmount($affiliate, $order, 'product_cat');
    $out = Resolver::resolve( $context, 200, $lines, [$own, $native_category, $native_product], $today, $base );
    $check( "$type preserves the untouched native line (15, not 30)", $native === 10.0 && $out['amount'] === 15.0 && $out['lines'][0]['rate'] === 5.0 );
  }
}
$out = Resolver::resolve( $context, 200, [$line(44,100,[12=>1]), $line(55,100)], [$own,$native_category], $today, $base );
$check( 'native category rows do not match ancestors', $out['lines'][0]['rule_id'] === null && $out['amount'] === 15.0 );
$variation = $line(44,100); $variation['variation_id'] = 99;
$out = Resolver::resolve( $context, 200, [$variation,$line(55,100)], [$own, $rule(array_merge($native_product,['target_ids'=>[99]]))], $today, $base );
$check( 'native rows do not acquire variation matching', $out['lines'][0]['rule_id'] === null );
$out = Resolver::resolve( $context, 100, [$line(44,0),$line(55,100)], [$own,$rule(array_merge($native_product,['rate_type'=>'flat','rate'=>3]))], $today, $base );
$check( 'native zero-total flat behavior is preserved', $out['amount'] === 13.0 );
$check( 'native rows cannot shadow or tie with an add-on override',
  Resolver::shadow_map([$rule(['scope_type'=>'all']),$native_category])['own'] === null
  && Resolver::tie_map([$rule(['scope_type'=>'all','target_type'=>'category','target_ids'=>[12]]),$native_category])['own'] === [] );
$provider_rules = new ReflectionMethod( ReferralHooks::class, 'rules_for_provider' );
$check( 'lifetime referrals do not import native sale rows', $provider_rules->invoke(new ReferralHooks(), 'woo', 'lifetime') === Store::all() );

$group = $rule(['id'=>'group', 'scope_type'=>'group', 'scope_id'=>3, 'target_type'=>'category', 'target_ids'=>[12], 'rate'=>8]);
$effective = Resolver::effective([$rule([]),$group],$context,$today);
$check('portal suppresses a category rule covered by the affiliate-wide rule', array_column($effective,'id') === ['own']);
$effective = Resolver::effective([
  $rule(['id'=>'group','scope_type'=>'group','scope_id'=>3,'target_type'=>'product','target_ids'=>[44,55]]),
  $rule(['target_type'=>'product','target_ids'=>[44]]),
],$context,$today);
$check('portal trims partially overridden target lists', $effective[0]['id'] === 'own' && $effective[1]['target_ids'] === [55]);

$out = Resolver::resolve($context,.10,[$line(44,.05),$line(55,.05)],[$rule(['rate'=>10])],$today,$base);
$stamp_method = new ReflectionMethod(ReferralHooks::class, 'stamp');
$stamp = $stamp_method->invoke(new ReferralHooks(),[], $out)['fa_commission_rules'];
$sum = array_sum(array_column($stamp['lines'],'commission')) + $stamp['remainder']['commission'] + $stamp['rounding_adjustment'];
$check('fractional-cent stamp reconciles without changing payout rounding', $out['amount'] === .01 && abs($sum-$stamp['amount']) < .000000001 && $stamp['version'] === 2);

// No rule mutation is needed to exercise the lock and compare-and-write boundary.
$revision = Store::revision();
$called = false;
$conflict = Store::mutate(static function () use (&$called) { $called = true; }, 'outdated');
$check('stale collection revision prevents the callback', is_wp_error($conflict) && $conflict->get_error_code() === 'facr_conflict' && !$called);
$check('a matching revision runs after the conflict released its lock', Store::mutate(static fn() => 'ok', $revision) === 'ok');
$check('nested store writes share the outer mutation boundary', Store::mutate(static fn() => Store::mutate(static fn() => 'nested'),$revision) === 'nested');

// Both shipped catalogs cover the current template, including plural forms.
foreach (['en_US','ro_RO'] as $locale) {
  $mo = new MO();
  $check("$locale catalog loads",$mo->import_from_file(FACR_DIR.'languages/fa-commission-rules-'.$locale.'.mo'));
  $check("$locale includes conflict recovery",$mo->translate('Reload rules') !== '' && ($locale !== 'ro_RO' || $mo->translate('Reload rules') !== 'Reload rules'));
}
