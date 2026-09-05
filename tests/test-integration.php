<?php
declare(strict_types=1);
/**
 * WordPress + Fluent Affiliate integration tests for the commission-rules plugin.
 * Run: wp --path=/path/to/wp eval-file wp-content/plugins/fluent-affiliate-commission-rules/tests/test-integration.php
 *
 * Creates its own affiliate, group and rules and removes them in a finally block.
 */

use FACommissionRules\Fluent;
use FACommissionRules\Store;

if ( ! defined( 'ABSPATH' ) ) {
  fwrite( STDERR, "test-integration.php needs WordPress: run it with wp eval-file\n" );
  return;
}

$GLOBALS['facr_int_fail'] = $GLOBALS['facr_int_fail'] ?? 0;

if ( ! function_exists( 'facr_it' ) ) {
  function facr_it( string $label, bool $ok ): void {
    if ( $ok ) {
      echo "PASS {$label}\n";
      return;
    }
    $GLOBALS['facr_int_fail']++;
    echo "FAIL {$label}\n";
  }
}

if ( ! Fluent::ready() ) {
  echo "SKIP Fluent Affiliate is not active\n";
  return;
}

$facr_backup      = Fluent::get_option( FACR_RULES_KEY, [] );
$facr_woo_backup  = Fluent::get_option( '_woo_connector_config', [] );
$facr_ref_backup  = get_option( '_fa_referral_settings', null );
$facr_group_id    = 0;
$facr_aff_id      = 0;
$facr_user_id     = 0;
$facr_cat_parent  = 0;
$facr_cat_child   = 0;
$facr_product_id  = 0;
$facr_product_b   = 0;
$facr_product_c   = 0;

try {
  // ------------------------------------------------------------ fixtures ---
  // One affiliate, one group and one two-level product_cat tree, reused by every
  // later section of this file so validation can check that ids really exist.
  if ( Fluent::has_pro() ) {
    $facr_group = \FluentAffiliate\App\Models\AffiliateGroup::create(
      [
        'meta_key' => 'FACR Test Group ' . wp_rand(),
        'value'    => [ 'rate_type' => 'percentage', 'rate' => 5, 'status' => 'active', 'notes' => '' ],
      ]
    );
    $facr_group_id = (int) $facr_group->id;
  }

  $facr_new_user = wp_insert_user(
    [
      'user_login' => 'facr_test_' . wp_rand(),
      'user_email' => 'facr_test_' . wp_rand() . '@example.test',
      'user_pass'  => wp_generate_password( 20 ),
    ]
  );
  if ( is_wp_error( $facr_new_user ) ) {
    echo 'SKIP could not create a test user: ' . $facr_new_user->get_error_message() . "\n";
    return;
  }
  $facr_user_id = (int) $facr_new_user;

  $facr_aff = \FluentAffiliate\App\Models\Affiliate::create(
    [
      'user_id'   => $facr_user_id,
      'status'    => 'active',
      'rate_type' => 'percentage',
      'rate'      => 5,
      'group_id'  => $facr_group_id,
    ]
  );
  $facr_aff_id = (int) $facr_aff->id;

  if ( taxonomy_exists( 'product_cat' ) ) {
    $facr_parent_term = wp_insert_term( 'FACR Parent ' . wp_rand(), 'product_cat' );
    if ( ! is_wp_error( $facr_parent_term ) ) {
      $facr_cat_parent = (int) $facr_parent_term['term_id'];
      $facr_child_term = wp_insert_term( 'FACR Child ' . wp_rand(), 'product_cat', [ 'parent' => $facr_cat_parent ] );
      if ( ! is_wp_error( $facr_child_term ) ) {
        $facr_cat_child = (int) $facr_child_term['term_id'];
      }
    }
  }

  if ( Fluent::has_woo() ) {
    $facr_product_id = (int) wp_insert_post( [ 'post_type' => 'product', 'post_title' => 'FACR Test Product A', 'post_status' => 'publish' ] );
    $facr_product_b  = (int) wp_insert_post( [ 'post_type' => 'product', 'post_title' => 'FACR Test Product B', 'post_status' => 'publish' ] );
    $facr_product_c  = (int) wp_insert_post( [ 'post_type' => 'product', 'post_title' => 'FACR Test Product C', 'post_status' => 'publish' ] );
    if ( $facr_cat_child > 0 ) {
      wp_set_object_terms( $facr_product_id, [ $facr_cat_child ], 'product_cat' );
    }
  }

  // Fluent's own global rate tables would otherwise price these test orders from
  // whatever the live store happens to have configured. Off for the whole run;
  // the adapter section switches them on deliberately and switches them back.
  $facr_neutral_woo = array_merge(
    is_array( $facr_woo_backup ) ? $facr_woo_backup : [],
    [ 'custom_affiliate_rate' => 'no', 'renewal_custom_affiliate_rate' => 'no' ]
  );
  Fluent::update_option( '_woo_connector_config', $facr_neutral_woo );

  // The renewal base rate is a global referral setting, so pin it: the renewal
  // maths below would otherwise depend on whatever the live store is set to.
  // Utility caches the settings in a static, hence the uncached re-read.
  update_option(
    '_fa_referral_settings',
    array_merge(
      is_array( $facr_ref_backup ) ? $facr_ref_backup : [],
      [ 'renewal_rate' => 5, 'renewal_rate_type' => 'percentage' ]
    )
  );
  \FluentAffiliate\App\Helper\Utility::getReferralSettings( false );

  // ---------------------------------------------------------------- store ---
  Fluent::update_option( FACR_RULES_KEY, [] );
  facr_it( 'store starts empty', Store::all() === [] );

  [ $rule, $errors ] = Store::validate(
    [
      'scope_type'  => 'affiliate',
      'scope_id'    => (string) $facr_aff_id,
      'target_type' => 'all',
      'target_ids'  => [],
      'rate'        => '10.5',
      'rate_type'   => 'percentage',
      'starts_at'   => '',
      'ends_at'     => '',
      'note'        => 'Year-1 rate',
      'status'      => 'active',
    ]
  );
  facr_it( 'valid input produces no errors', $errors === [] );
  facr_it( 'validate casts scope_id to int', $rule['scope_id'] === $facr_aff_id );
  facr_it( 'validate casts rate to float', $rule['rate'] === 10.5 );
  facr_it( 'validate mints a uuid id', (bool) preg_match( '/^[0-9a-f-]{36}$/', $rule['id'] ) );
  facr_it( 'validate stamps created_at', $rule['created_at'] !== '' );

  Store::save( $rule );
  facr_it( 'saved rule is readable', ( Store::get( $rule['id'] )['note'] ?? '' ) === 'Year-1 rate' );
  facr_it( 'saved rule is listed once', count( Store::all() ) === 1 );

  $edited          = Store::get( $rule['id'] );
  $edited['rate']  = 12.0;
  Store::save( $edited );
  facr_it( 'saving an existing id updates in place', count( Store::all() ) === 1 && Store::get( $rule['id'] )['rate'] === 12.0 );

  facr_it( 'for_scope finds the rule', count( Store::for_scope( 'affiliate', $facr_aff_id ) ) === 1 );
  facr_it( 'for_scope ignores other scopes', Store::for_scope( 'affiliate', $facr_aff_id + 100000 ) === [] );

  Store::set_status( [ $rule['id'] ], 'inactive' );
  facr_it( 'set_status flips status', Store::get( $rule['id'] )['status'] === 'inactive' );

  // ------------------------------------------------------------ validation ---
  [ , $errors ] = Store::validate( [ 'scope_type' => 'affiliate', 'scope_id' => '0', 'target_type' => 'all', 'rate' => '5', 'rate_type' => 'percentage' ] );
  facr_it( 'affiliate scope requires an id', isset( $errors['scope_id'] ) );

  [ , $errors ] = Store::validate( [ 'scope_type' => 'affiliate', 'scope_id' => (string) ( $facr_aff_id + 100000 ), 'target_type' => 'all', 'rate' => '5', 'rate_type' => 'percentage' ] );
  facr_it( 'an unknown affiliate id is rejected', isset( $errors['scope_id'] ) );

  if ( Fluent::has_pro() && $facr_group_id > 0 ) {
    [ , $errors ] = Store::validate( [ 'scope_type' => 'group', 'scope_id' => (string) $facr_group_id, 'target_type' => 'all', 'rate' => '5', 'rate_type' => 'percentage' ] );
    facr_it( 'a real group id validates', $errors === [] );
    [ , $errors ] = Store::validate( [ 'scope_type' => 'group', 'scope_id' => (string) ( $facr_group_id + 100000 ), 'target_type' => 'all', 'rate' => '5', 'rate_type' => 'percentage' ] );
    facr_it( 'an unknown group id is rejected', isset( $errors['scope_id'] ) );
  } else {
    echo "SKIP Fluent Affiliate Pro inactive: group scope validation not exercised\n";
  }

  [ , $errors ] = Store::validate( [ 'scope_type' => 'all', 'target_type' => 'product', 'target_ids' => [], 'rate' => '5', 'rate_type' => 'percentage' ] );
  facr_it( 'product target requires ids', isset( $errors['target_ids'] ) );

  [ , $errors ] = Store::validate( [ 'scope_type' => 'all', 'target_type' => 'all', 'target_ids' => [ '44' ], 'rate' => '5', 'rate_type' => 'percentage' ] );
  facr_it( 'an all-products rule may not carry target ids', isset( $errors['target_ids'] ) );

  if ( $facr_cat_child > 0 ) {
    [ $facr_crule, $errors ] = Store::validate( [ 'scope_type' => 'all', 'target_type' => 'category', 'target_ids' => [ (string) $facr_cat_child, (string) $facr_cat_parent ], 'rate' => '5', 'rate_type' => 'percentage' ] );
    facr_it( 'real category targets validate', $errors === [] );
    facr_it( 'validate casts target_ids to ints', $facr_crule['target_ids'] === [ $facr_cat_child, $facr_cat_parent ] );

    [ , $errors ] = Store::validate( [ 'scope_type' => 'all', 'target_type' => 'category', 'target_ids' => [ (string) ( $facr_cat_parent + 900000 ) ], 'rate' => '5', 'rate_type' => 'percentage' ] );
    facr_it( 'an unknown category id is rejected', isset( $errors['target_ids'] ) );
  } else {
    echo "SKIP no product_cat taxonomy: category target validation not exercised\n";
  }

  if ( Fluent::has_woo() ) {
    [ , $errors ] = Store::validate( [ 'scope_type' => 'all', 'target_type' => 'product', 'target_ids' => [ '999999999' ], 'rate' => '5', 'rate_type' => 'percentage' ] );
    facr_it( 'an unknown product id is rejected', isset( $errors['target_ids'] ) );
  } else {
    echo "SKIP WooCommerce inactive: product target validation not exercised\n";
  }

  [ , $errors ] = Store::validate( [ 'scope_type' => 'all', 'target_type' => 'all', 'rate' => '140', 'rate_type' => 'percentage' ] );
  facr_it( 'percentage rate is capped at 100', isset( $errors['rate'] ) );

  [ , $errors ] = Store::validate( [ 'scope_type' => 'all', 'target_type' => 'all', 'rate' => '-1', 'rate_type' => 'flat' ] );
  facr_it( 'flat rate cannot be negative', isset( $errors['rate'] ) );

  [ , $errors ] = Store::validate( [ 'scope_type' => 'all', 'target_type' => 'all', 'rate' => '5', 'rate_type' => 'percentage', 'starts_at' => '2026-10-01', 'ends_at' => '2026-09-01' ] );
  facr_it( 'ends_at must not precede starts_at', isset( $errors['ends_at'] ) );

  [ , $errors ] = Store::validate( [ 'scope_type' => 'all', 'target_type' => 'all', 'rate' => '5', 'rate_type' => 'percentage', 'starts_at' => '01/10/2026' ] );
  facr_it( 'dates must be Y-m-d', isset( $errors['starts_at'] ) );

  // ------------------------------------------------------------- cleanup ----
  Store::forget_affiliate( $facr_aff_id );
  facr_it( 'forget_affiliate drops that affiliate rules', Store::all() === [] );

  // --------------------------------------------- Fluent global rows adapter --
  Fluent::update_option(
    '_woo_connector_config',
    array_merge(
      $facr_neutral_woo,
      [
        'custom_affiliate_rate'  => 'yes',
        'custom_affiliate_rates' => [
          [ 'object_type' => 'category', 'object_ids' => [ 44 ], 'rate' => '0', 'rate_type' => 'percentage' ],
          [ 'object_type' => 'product', 'object_ids' => [ 101 ], 'rate' => '3', 'rate_type' => 'flat' ],
        ],
      ]
    )
  );
  $globals = Store::fluent_global_rules();
  facr_it( 'fluent rows are adapted', count( $globals ) === 2 );
  facr_it( 'fluent rows are scope all', $globals[0]['scope_type'] === 'all' && $globals[0]['scope_id'] === 0 );
  facr_it( 'fluent rows carry a synthetic id', $globals[0]['id'] === 'fluent:0' );
  facr_it( 'fluent rows are read-only', $globals[0]['readonly'] === true );
  facr_it( 'fluent rows lose ties (epoch created_at)', $globals[0]['created_at'] === '1970-01-01T00:00:00+00:00' );
  facr_it( 'fluent product row keeps rate type', $globals[1]['rate_type'] === 'flat' && $globals[1]['rate'] === 3.0 );
  facr_it( 'the sale table is not read for renewals', Store::fluent_global_rules( 'renewal' ) === [] );

  // Fluent keeps a second, independent rate table for renewals in the same option.
  Fluent::update_option(
    '_woo_connector_config',
    array_merge(
      $facr_neutral_woo,
      [
        'custom_affiliate_rate'          => 'no',
        'renewal_custom_affiliate_rate'  => 'yes',
        'renewal_custom_affiliate_rates' => [
          [ 'object_type' => 'product', 'object_ids' => [ 101 ], 'rate' => '7', 'rate_type' => 'percentage' ],
        ],
      ]
    )
  );
  $renewal_globals = Store::fluent_global_rules( 'renewal' );
  facr_it( 'the renewal table is adapted', count( $renewal_globals ) === 1 && $renewal_globals[0]['rate'] === 7.0 );
  facr_it( 'renewal rows carry their own synthetic id', $renewal_globals[0]['id'] === 'fluent:renewal:0' );
  facr_it( 'the renewal table is not read for sales', Store::fluent_global_rules() === [] );
  facr_it( 'resolvable passes the context through', Store::resolvable( 'renewal' ) === Store::fluent_global_rules( 'renewal' ) );

  Fluent::update_option( '_woo_connector_config', $facr_neutral_woo );
  // ------------------------------------------------------------- lines ------
  $lines = \FACommissionRules\LineBuilder::from_products(
    [
      [ 'item_id' => 101, 'title' => 'A', 'subtotal' => 100.0, 'tax' => 19.0, 'total' => 100.0 ],
      [ 'item_id' => 102, 'title' => 'B', 'subtotal' => 50.0, 'tax' => 9.5, 'total' => 50.0 ],
    ]
  );
  facr_it( 'one line per product item', count( $lines ) === 2 );
  facr_it( 'line carries the product id', $lines[0]['product_id'] === 101 );
  facr_it( 'line has no variation without an order', $lines[0]['variation_id'] === 0 );
  facr_it(
    'line total follows the exclude_tax setting',
    Fluent::excludes_tax() ? abs( $lines[0]['total'] - 100.0 ) < 0.001 : abs( $lines[0]['total'] - 119.0 ) < 0.001
  );
  facr_it( 'line carries term keys', array_key_exists( 'term_ids', $lines[0] ) && array_key_exists( 'term_depths', $lines[0] ) );

  $facr_bare = \FACommissionRules\LineBuilder::from_products( [ [ 'item_id' => 101, 'subtotal' => 100.0 ] ], false );
  facr_it( 'lines can be built without any term lookup', $facr_bare[0]['term_ids'] === [] && $facr_bare[0]['term_depths'] === [] );

  // item_total must mirror BaseConnector::calculateOrderTotal() term for term.
  facr_it( 'item_total excludes tax when configured', abs( \FACommissionRules\LineBuilder::item_total( [ 'subtotal' => 100.0, 'tax' => 19.0 ] ) - ( Fluent::excludes_tax() ? 100.0 : 119.0 ) ) < 0.001 );
  facr_it( 'item_total follows the exclude_shipping setting', abs( \FACommissionRules\LineBuilder::item_total( [ 'subtotal' => 100.0, 'shipping' => 12.0 ] ) - ( Fluent::excludes_shipping() ? 100.0 : 112.0 ) ) < 0.001 );
  facr_it( 'item_total subtracts a discount', abs( \FACommissionRules\LineBuilder::item_total( [ 'subtotal' => 100.0, 'discount' => 30.0 ] ) - 70.0 ) < 0.001 );
  facr_it( 'item_total is floored at zero', abs( \FACommissionRules\LineBuilder::item_total( [ 'subtotal' => 10.0, 'discount' => 40.0 ] ) ) < 0.001 );

  // An items-less payload still has to produce one line, or an all-products rule
  // would silently stop applying to it.
  $facr_whole = \FACommissionRules\LineBuilder::build( [], 'woo', 0, 250.0 );
  facr_it( 'an empty payload becomes one whole-order line', count( $facr_whole ) === 1 && abs( $facr_whole[0]['total'] - 250.0 ) < 0.001 );
  facr_it( 'the synthetic line targets nothing in particular', $facr_whole[0]['product_id'] === 0 && $facr_whole[0]['term_ids'] === [] );

  // A non-WooCommerce provider sends item ids that are not product ids, so no
  // product_cat lookup may happen for them.
  $facr_foreign = \FACommissionRules\LineBuilder::build( [ [ 'item_id' => 101, 'subtotal' => 100.0 ] ], 'fluent_cart', 0, 100.0 );
  facr_it( 'a foreign provider gets no category terms', $facr_foreign[0]['term_ids'] === [] );

  // Term ancestry: the fixture product is filed under the child term only.
  if ( Fluent::has_woo() && $facr_cat_child > 0 && $facr_product_id > 0 ) {
    $map = \FACommissionRules\LineBuilder::term_map( $facr_product_id );
    facr_it( 'term map includes the direct term at depth 0', ( $map['depths'][ $facr_cat_child ] ?? -1 ) === 0 );
    facr_it( 'term map includes the ancestor at depth 1', ( $map['depths'][ $facr_cat_parent ] ?? -1 ) === 1 );
    facr_it( 'term ids cover both levels', count( array_intersect( $map['ids'], [ $facr_cat_child, $facr_cat_parent ] ) ) === 2 );
  } else {
    echo "SKIP WooCommerce inactive: term ancestry not exercised\n";
  }

  // ------------------------------------------------------------- hooks ------
  // Product targeting needs real product ids, so this section only runs with
  // WooCommerce present; everything above it is WooCommerce-agnostic.
  if ( ! Fluent::has_woo() || $facr_product_id <= 0 ) {
    // Everything above this point is WooCommerce-agnostic and has already run.
    echo "SKIP WooCommerce inactive: referral hooks, labels and widgets not exercised\n";
    return;
  }

  // A 10% affiliate rule on product A, nothing on product B.
  [ $facr_rule ] = Store::validate(
    [
      'scope_type'  => 'affiliate',
      'scope_id'    => (string) $facr_aff_id,
      'target_type' => 'product',
      'target_ids'  => [ (string) $facr_product_id ],
      'rate'        => '10',
      'rate_type'   => 'percentage',
      'status'      => 'active',
      'note'        => 'hooks test',
    ]
  );
  Store::save( $facr_rule );

  $facr_payload = [
    'affiliate_id' => $facr_aff_id,
    'amount'       => 7.5,          // Fluent's own 5% of 150
    'order_total'  => 150.0,
    'currency'     => 'USD',
    'type'         => 'sale',
    'status'       => 'unpaid',
    'provider'     => 'woo',
    'provider_id'  => 0,            // no real order: falls back to the products array
    'description'  => 'Product A and 1 more items',
    'products'     => [
      [ 'item_id' => $facr_product_id, 'title' => 'A', 'subtotal' => 100.0, 'tax' => 0.0, 'total' => 100.0 ],
      [ 'item_id' => $facr_product_b, 'title' => 'B', 'subtotal' => 50.0, 'tax' => 0.0, 'total' => 50.0 ],
    ],
  ];

  $facr_out = apply_filters( 'fluent_affiliate/referral_data', $facr_payload, 'woo' );
  facr_it( 'sale: rule line at 10% plus remainder at the affiliate rate', abs( (float) $facr_out['amount'] - 12.5 ) < 0.001 );
  facr_it( 'sale: audit stamp is written', isset( $facr_out['settings']['fa_commission_rules']['version'] ) );
  facr_it( 'sale: stamp records the winning rule', ( $facr_out['settings']['fa_commission_rules']['lines'][0]['rule_id'] ?? '' ) === $facr_rule['id'] );
  facr_it( 'sale: stamp records the remainder', abs( (float) $facr_out['settings']['fa_commission_rules']['remainder']['total'] - 50.0 ) < 0.001 );
  facr_it( 'sale: description keeps its original text', strpos( (string) $facr_out['description'], 'Product A and 1 more items' ) === 0 );
  facr_it( 'sale: description gains a rules note', strpos( (string) $facr_out['description'], 'rules:' ) !== false );

  // recurring_sale must pass through untouched: the renewal hook owns that path.
  $facr_recurring          = $facr_payload;
  $facr_recurring['type']  = 'recurring_sale';
  $facr_out_rec            = apply_filters( 'fluent_affiliate/referral_data', $facr_recurring, 'woo' );
  facr_it( 'renewal referral_data is untouched', abs( (float) $facr_out_rec['amount'] - 7.5 ) < 0.001 && ! isset( $facr_out_rec['settings']['fa_commission_rules'] ) );

  // lifetime_sale keeps Fluent's own lifetime base on the remainder.
  if ( Fluent::has_pro() && class_exists( '\FluentAffiliatePro\App\Hooks\Handlers\LifetimeCommissionHandler' ) ) {
    $facr_lifetime         = $facr_payload;
    $facr_lifetime['type'] = 'lifetime_sale';
    $facr_out_life         = apply_filters( 'fluent_affiliate/referral_data', $facr_lifetime, 'woo' );
    // The lifetime base is taken on the whole order and prorated onto the third
    // of it no rule claimed — identical to base(50) for a percentage lifetime
    // rate, and the only correct reading of a flat one.
    $facr_expected_base = ( new \FluentAffiliatePro\App\Hooks\Handlers\LifetimeCommissionHandler() )
      ->getBaseLifetimeCommission( \FluentAffiliate\App\Models\Affiliate::find( $facr_aff_id ), 150.0 ) * ( 50.0 / 150.0 );
    facr_it( 'lifetime: rule line plus the prorated lifetime base', abs( (float) $facr_out_life['amount'] - round( 10.0 + (float) $facr_expected_base, 2 ) ) < 0.001 );
  } else {
    echo "SKIP Fluent Affiliate Pro inactive: lifetime path not exercised\n";
  }

  // Renewals: Fluent's own amount is scaled onto the remainder.
  $facr_renewal_ctx = [
    'affiliate'       => \FluentAffiliate\App\Models\Affiliate::find( $facr_aff_id ),
    'order_data'      => [
      'referral_order_total' => 150.0,
      'items'                => $facr_payload['products'],
    ],
    'provider'        => 'woo',
    'vendor_order'    => null,
    'parent_referral' => null,
  ];
  $facr_renewal = apply_filters( 'fluent_affiliate/recurring_commission', 7.5, $facr_renewal_ctx );
  facr_it( 'renewal: float payload stays a float', is_float( $facr_renewal ) || is_int( $facr_renewal ) );
  facr_it( 'renewal: 10% of the rule line plus the scaled base', abs( (float) $facr_renewal - ( 10.0 + 7.5 * ( 50.0 / 150.0 ) ) ) < 0.001 );

  $facr_renewal_array = apply_filters( 'fluent_affiliate/recurring_commission', [ 'amount' => 7.5, 'rate' => 5 ], $facr_renewal_ctx );
  facr_it( 'renewal: array payload stays an array', is_array( $facr_renewal_array ) && isset( $facr_renewal_array['amount'] ) );
  facr_it( 'renewal: array payload keeps its other keys', ( $facr_renewal_array['rate'] ?? null ) === 5 );

  // Fluent's renewal rate table prices product A itself, and Store::resolvable()
  // hands those same rows to the resolver — so the figure Fluent passes in is
  // already blended, and prorating it would pay product A twice.
  Fluent::update_option(
    '_woo_connector_config',
    array_merge(
      $facr_neutral_woo,
      [
        'renewal_custom_affiliate_rate'  => 'yes',
        'renewal_custom_affiliate_rates' => [
          [ 'object_type' => 'product', 'object_ids' => [ $facr_product_id ], 'rate' => '10', 'rate_type' => 'percentage' ],
        ],
      ]
    )
  );
  [ $facr_c_rule ] = Store::validate(
    [
      'scope_type'  => 'affiliate',
      'scope_id'    => (string) $facr_aff_id,
      'target_type' => 'product',
      'target_ids'  => [ (string) $facr_product_c ],
      'rate'        => '20',
      'rate_type'   => 'percentage',
      'status'      => 'active',
      'note'        => 'renewal base test',
    ]
  );
  Store::save( $facr_c_rule );

  $facr_blend_ctx = [
    'affiliate'       => \FluentAffiliate\App\Models\Affiliate::find( $facr_aff_id ),
    'order_data'      => [
      'id'                   => 4242,
      'referral_order_total' => 150.0,
      'items'                => [
        [ 'item_id' => $facr_product_id, 'title' => 'A', 'subtotal' => 100.0, 'tax' => 0.0, 'total' => 100.0 ],
        [ 'item_id' => $facr_product_b, 'title' => 'B', 'subtotal' => 20.0, 'tax' => 0.0, 'total' => 20.0 ],
        [ 'item_id' => $facr_product_c, 'title' => 'C', 'subtotal' => 30.0, 'tax' => 0.0, 'total' => 30.0 ],
      ],
    ],
    'provider'        => 'woo',
    'vendor_order'    => null,
    'parent_referral' => null,
  ];
  // 12.5 is what Fluent computes here: 10% of A plus its 5% base on the other 50.
  // Ours: 10 on A, 6 on C, and the 5% base on the 20 nobody claimed — 17, not 17.67.
  $facr_blended = apply_filters( 'fluent_affiliate/recurring_commission', 12.5, $facr_blend_ctx );
  facr_it( 'renewal: the remainder is priced from the base rate, not the blended amount', abs( (float) $facr_blended - 17.0 ) < 0.001 );

  // The renewal referral row is built after the pricing filter has run, so the
  // stamp can only be written on the referral_data pass.
  $facr_renewal_row = array_merge(
    $facr_payload,
    [
      'type'        => 'recurring_sale',
      'amount'      => $facr_blended,
      'provider_id' => 4242,
      'products'    => $facr_blend_ctx['order_data']['items'],
    ]
  );
  $facr_renewal_out = apply_filters( 'fluent_affiliate/referral_data', $facr_renewal_row, 'woo' );
  facr_it( 'renewal: the referral carries the audit stamp', isset( $facr_renewal_out['settings']['fa_commission_rules']['version'] ) );
  facr_it( 'renewal: stamping never rewrites the amount', abs( (float) $facr_renewal_out['amount'] - 17.0 ) < 0.001 );
  facr_it( 'renewal: the description gains a rules note', strpos( (string) $facr_renewal_out['description'], 'rules:' ) !== false );

  $facr_renewal_again = apply_filters( 'fluent_affiliate/referral_data', $facr_renewal_row, 'woo' );
  facr_it( 'renewal: the parked resolution is used once and dropped', ! isset( $facr_renewal_again['settings']['fa_commission_rules'] ) );

  Store::delete( $facr_c_rule['id'] );
  Fluent::update_option( '_woo_connector_config', $facr_neutral_woo );

  // A deliberate 0% renewal rule is a decision too: product B carries no other
  // rule, so the affiliate-wide 0% one wins the whole renewal.
  [ $facr_zero_renewal_rule ] = Store::validate(
    [
      'scope_type'  => 'affiliate',
      'scope_id'    => (string) $facr_aff_id,
      'target_type' => 'all',
      'rate'        => '0',
      'rate_type'   => 'percentage',
      'status'      => 'active',
      'note'        => 'zero renewal test',
    ]
  );
  Store::save( $facr_zero_renewal_rule );
  $facr_zero_ctx = [
    'affiliate'       => \FluentAffiliate\App\Models\Affiliate::find( $facr_aff_id ),
    'order_data'      => [
      'id'                   => 4343,
      'referral_order_total' => 20.0,
      'items'                => [ [ 'item_id' => $facr_product_b, 'title' => 'B', 'subtotal' => 20.0, 'tax' => 0.0, 'total' => 20.0 ] ],
    ],
    'provider'        => 'woo',
    'vendor_order'    => null,
    'parent_referral' => null,
  ];
  $facr_zero_renewal_amount = apply_filters( 'fluent_affiliate/recurring_commission', 1.0, $facr_zero_ctx );
  facr_it( 'renewal: a 0% rule zeroes the renewal', abs( (float) $facr_zero_renewal_amount ) < 0.001 );
  $facr_zero_renewal_out = apply_filters(
    'fluent_affiliate/referral_data',
    array_merge( $facr_payload, [ 'type' => 'recurring_sale', 'amount' => $facr_zero_renewal_amount, 'provider_id' => 4343 ] ),
    'woo'
  );
  facr_it( 'renewal: a 0% rule is not discarded as a zero-amount referral', apply_filters( 'fluent_affiliate/ignore_zero_amount_referral', true, $facr_zero_renewal_out ) === false );
  Store::delete( $facr_zero_renewal_rule['id'] );

  // A renewal referral nobody parked a resolution for keeps Fluent's own figure.
  $facr_orphan_out = apply_filters(
    'fluent_affiliate/referral_data',
    array_merge( $facr_payload, [ 'type' => 'recurring_sale', 'provider_id' => 9999 ] ),
    'woo'
  );
  facr_it( 'renewal: an unparked referral is untouched', abs( (float) $facr_orphan_out['amount'] - 7.5 ) < 0.001 && ! isset( $facr_orphan_out['settings']['fa_commission_rules'] ) );

  // A group rule applies to every member of the group, whatever the member's own
  // rate type. Fluent's own group RATE is conditional (it only applies when the
  // affiliate's rate_type is literally 'group'); our group RULES are not, and
  // this affiliate carries its own percentage rate.
  if ( Fluent::has_pro() && $facr_group_id > 0 ) {
    [ $facr_group_rule ] = Store::validate(
      [
        'scope_type'  => 'group',
        'scope_id'    => (string) $facr_group_id,
        'target_type' => 'all',
        'rate'        => '8',
        'rate_type'   => 'percentage',
        'status'      => 'active',
        'note'        => 'group rule test',
      ]
    );
    Store::save( $facr_group_rule );
    $facr_group_out = apply_filters( 'fluent_affiliate/referral_data', $facr_payload, 'woo' );
    facr_it( 'a group rule applies although the affiliate has its own percentage rate', abs( (float) $facr_group_out['amount'] - 14.0 ) < 0.001 );
    Store::delete( $facr_group_rule['id'] );
  } else {
    echo "SKIP Fluent Affiliate Pro inactive: group rule not exercised\n";
  }

  // A foreign provider's item ids are not WooCommerce product ids, so a product
  // or category rule must never be allowed to claim one of its lines.
  $facr_foreign_out = apply_filters( 'fluent_affiliate/referral_data', $facr_payload, 'fluent_cart' );
  facr_it( 'a product rule never fires for a foreign provider', abs( (float) $facr_foreign_out['amount'] - 7.5 ) < 0.001 );

  [ $facr_all_rule ] = Store::validate(
    [
      'scope_type'  => 'affiliate',
      'scope_id'    => (string) $facr_aff_id,
      'target_type' => 'all',
      'rate'        => '20',
      'rate_type'   => 'percentage',
      'status'      => 'active',
      'note'        => 'foreign provider test',
    ]
  );
  Store::save( $facr_all_rule );
  $facr_foreign_out = apply_filters( 'fluent_affiliate/referral_data', $facr_payload, 'fluent_cart' );
  facr_it( 'an all-products rule still fires for a foreign provider', abs( (float) $facr_foreign_out['amount'] - 30.0 ) < 0.001 );

  // An items-less payload must still be priced by an all-products rule.
  $facr_no_items             = $facr_payload;
  $facr_no_items['products'] = [];
  $facr_no_items_out         = apply_filters( 'fluent_affiliate/referral_data', $facr_no_items, 'woo' );
  facr_it( 'an items-less payload is still priced', abs( (float) $facr_no_items_out['amount'] - 30.0 ) < 0.001 );
  facr_it( 'the synthetic line is stamped like any other', ( $facr_no_items_out['settings']['fa_commission_rules']['lines'][0]['rule_id'] ?? '' ) === $facr_all_rule['id'] );

  Store::delete( $facr_all_rule['id'] );

  // A flat base rate is a per-order figure. Asking Fluent for it again on the
  // remainder would pay the whole flat amount a second time.
  \FluentAffiliate\App\Models\Affiliate::where( 'id', $facr_aff_id )->update( [ 'rate_type' => 'flat', 'rate' => 50 ] );
  $facr_flat_out = apply_filters( 'fluent_affiliate/referral_data', $facr_payload, 'woo' );
  facr_it( 'flat base: the rule line plus the prorated flat share', abs( (float) $facr_flat_out['amount'] - 26.67 ) < 0.001 );

  [ $facr_flat_all ] = Store::validate(
    [
      'scope_type'  => 'affiliate',
      'scope_id'    => (string) $facr_aff_id,
      'target_type' => 'all',
      'rate'        => '10',
      'rate_type'   => 'percentage',
      'status'      => 'active',
      'note'        => 'flat base test',
    ]
  );
  Store::save( $facr_flat_all );
  $facr_flat_full = apply_filters( 'fluent_affiliate/referral_data', $facr_payload, 'woo' );
  facr_it( 'flat base: a fully matched order pays no flat amount at all', abs( (float) $facr_flat_full['amount'] - 15.0 ) < 0.001 );
  Store::delete( $facr_flat_all['id'] );
  \FluentAffiliate\App\Models\Affiliate::where( 'id', $facr_aff_id )->update( [ 'rate_type' => 'percentage', 'rate' => 5 ] );

  // A deliberate 0% rule has to record a referral. Fluent silently drops
  // zero-amount sale referrals unless the ignore filter is told not to.
  Store::delete( $facr_rule['id'] );
  [ $facr_zero_rule ] = Store::validate(
    [
      'scope_type'  => 'affiliate',
      'scope_id'    => (string) $facr_aff_id,
      'target_type' => 'all',
      'rate'        => '0',
      'rate_type'   => 'percentage',
      'status'      => 'active',
      'note'        => 'zero rate test',
    ]
  );
  Store::save( $facr_zero_rule );
  $facr_zero_out = apply_filters( 'fluent_affiliate/referral_data', $facr_payload, 'woo' );
  facr_it( 'a 0% rule zeroes the amount', abs( (float) $facr_zero_out['amount'] ) < 0.001 );
  facr_it( 'a 0% rule is not discarded as a zero-amount referral', apply_filters( 'fluent_affiliate/ignore_zero_amount_referral', true, $facr_zero_out ) === false );
  facr_it( 'a referral we never touched is still discarded when zero', apply_filters( 'fluent_affiliate/ignore_zero_amount_referral', true, $facr_payload ) === true );
  Store::delete( $facr_zero_rule['id'] );

  // An affiliate with no rules is never touched.
  $facr_untouched = apply_filters( 'fluent_affiliate/referral_data', $facr_payload, 'woo' );
  facr_it( 'no rules means no change to the amount', abs( (float) $facr_untouched['amount'] - 7.5 ) < 0.001 );
  facr_it( 'no rules means no audit stamp', ! isset( $facr_untouched['settings']['fa_commission_rules'] ) );

  // ------------------------------------------------------------ labels ------
  $facr_lbl = [
    'id'          => 'lbl',
    'status'      => 'active',
    'scope_type'  => 'affiliate',
    'scope_id'    => $facr_aff_id,
    'target_type' => 'all',
    'target_ids'  => [],
    'rate'        => 12.5,
    'rate_type'   => 'percentage',
    'starts_at'   => '',
    'ends_at'     => '',
    'note'        => '',
    'created_at'  => '2026-01-01T00:00:00+00:00',
    'readonly'    => false,
  ];
  $facr_lbl_of = static fn( array $overrides ): array => array_merge( $facr_lbl, $overrides );

  facr_it( 'scope_label names the affiliate', strpos( \FACommissionRules\Admin\RulesPage::scope_label( $facr_lbl ), '#' . $facr_aff_id ) !== false );
  facr_it( 'scope_label for everyone carries no id', \FACommissionRules\Admin\RulesPage::scope_label( $facr_lbl_of( [ 'scope_type' => 'all', 'scope_id' => 0 ] ) ) === __( 'Everyone', 'fa-commission-rules' ) );
  if ( Fluent::has_pro() && $facr_group_id > 0 ) {
    facr_it( 'scope_label names the group', strpos( \FACommissionRules\Admin\RulesPage::scope_label( $facr_lbl_of( [ 'scope_type' => 'group', 'scope_id' => $facr_group_id ] ) ), 'FACR Test Group' ) !== false );
  } else {
    echo "SKIP Fluent Affiliate Pro inactive: group label not exercised\n";
  }

  facr_it( 'target_label says all products', \FACommissionRules\Admin\RulesPage::target_label( $facr_lbl ) === __( 'All products', 'fa-commission-rules' ) );
  facr_it( 'target_label names the category', strpos( \FACommissionRules\Admin\RulesPage::target_label( $facr_lbl_of( [ 'target_type' => 'category', 'target_ids' => [ $facr_cat_child ] ] ) ), 'FACR Child' ) !== false );
  facr_it( 'target_label names the product', strpos( \FACommissionRules\Admin\RulesPage::target_label( $facr_lbl_of( [ 'target_type' => 'product', 'target_ids' => [ $facr_product_id ] ] ) ), 'FACR Test Product A' ) !== false );
  facr_it( 'target_label falls back to the id', strpos( \FACommissionRules\Admin\RulesPage::target_label( $facr_lbl_of( [ 'target_type' => 'product', 'target_ids' => [ 999999999 ] ] ) ), '#999999999' ) !== false );

  facr_it( 'rate_label trims trailing zeros', \FACommissionRules\Admin\RulesPage::rate_label( $facr_lbl ) === '12.5%' );
  facr_it( 'rate_label keeps whole percentages whole', \FACommissionRules\Admin\RulesPage::rate_label( $facr_lbl_of( [ 'rate' => 10.0 ] ) ) === '10%' );
  facr_it( 'rate_label formats a flat rate as money', strpos( \FACommissionRules\Admin\RulesPage::rate_label( $facr_lbl_of( [ 'rate' => 3.0, 'rate_type' => 'flat' ] ) ), Fluent::money( 3.0 ) ) !== false );

  facr_it( 'window_label says always when unbounded', \FACommissionRules\Admin\RulesPage::window_label( $facr_lbl ) === __( 'Always', 'fa-commission-rules' ) );
  facr_it( 'window_label renders dates in the site format', strpos( \FACommissionRules\Admin\RulesPage::window_label( $facr_lbl_of( [ 'starts_at' => '2026-09-01', 'ends_at' => '2026-09-30' ] ) ), date_i18n( (string) get_option( 'date_format' ), strtotime( '2026-09-30' ) ) ) !== false );
  facr_it( 'window_label handles an open end', strpos( \FACommissionRules\Admin\RulesPage::window_label( $facr_lbl_of( [ 'starts_at' => '2026-09-01' ] ) ), date_i18n( (string) get_option( 'date_format' ), strtotime( '2026-09-01' ) ) ) !== false );
  facr_it( 'window_label handles an open start', strpos( \FACommissionRules\Admin\RulesPage::window_label( $facr_lbl_of( [ 'ends_at' => '2026-09-30' ] ) ), date_i18n( (string) get_option( 'date_format' ), strtotime( '2026-09-30' ) ) ) !== false );

  $facr_described = \FACommissionRules\Admin\RulesPage::describe( $facr_lbl_of( [ 'ends_at' => '2026-09-30' ] ) );
  facr_it( 'describe composes rate, target and window', strpos( $facr_described, '12.5%' ) !== false && strpos( $facr_described, __( 'All products', 'fa-commission-rules' ) ) !== false );

  // ---------------------------------------------------- missing/readonly ---
  // Task 7 fix round 1: an edit link or resubmit against an id the store no
  // longer has must never fall through to "create a new rule" behaviour, and
  // a submit against a Fluent-owned id must say so rather than "Rule saved."
  facr_it( 'Store::get() returns null for an id that does not exist', Store::get( 'facr_does_not_exist' ) === null );

  $facr_notice_reflection = new ReflectionMethod( \FACommissionRules\Admin\RulesPage::class, 'notice' );
  $facr_notice_reflection->setAccessible( true );
  $facr_render_notice     = static function ( string $notice ) use ( $facr_notice_reflection ): string {
    $_GET['facr_notice'] = $notice;
    ob_start();
    $facr_notice_reflection->invoke( null );
    unset( $_GET['facr_notice'] );
    return (string) ob_get_clean();
  };

  facr_it(
    'the missing-rule notice states the rule is gone',
    strpos( $facr_render_notice( 'missing' ), esc_html__( 'That rule no longer exists.', 'fa-commission-rules' ) ) !== false
  );
  facr_it(
    "the readonly notice points at Fluent Affiliate's own settings",
    strpos( $facr_render_notice( 'readonly' ), esc_html__( "Fluent's global rates are read-only here; edit them in Fluent Affiliate's WooCommerce settings.", 'fa-commission-rules' ) ) !== false
  );


  // ----------------------------------------------------------- widgets ------
  [ $facr_wrule ] = Store::validate(
    [
      'scope_type'  => 'affiliate',
      'scope_id'    => (string) $facr_aff_id,
      'target_type' => 'all',
      'rate'        => '10',
      'rate_type'   => 'percentage',
      'ends_at'     => '2027-09-14',
      'status'      => 'active',
      'note'        => 'widget test',
    ]
  );
  Store::save( $facr_wrule );

  $facr_widgets = apply_filters( 'fluent_affiliate/affiliate_widgets', [], \FluentAffiliate\App\Models\Affiliate::find( $facr_aff_id ) );
  facr_it( 'profile widget is added', count( $facr_widgets ) === 1 );
  facr_it( 'profile widget has the documented keys', isset( $facr_widgets[0]['title'], $facr_widgets[0]['action'], $facr_widgets[0]['content'] ) );
  facr_it( 'profile widget lists the rule', strpos( (string) $facr_widgets[0]['content'], '10%' ) !== false );
  facr_it( 'profile widget links a pre-scoped add form', strpos( (string) $facr_widgets[0]['action'], 'affiliate_id=' . $facr_aff_id ) !== false );

  wp_set_current_user( $facr_user_id );
  $facr_portal = apply_filters( 'fluent_affiliate/portal_notice_html', '' );
  facr_it( 'portal card mentions the rate', strpos( $facr_portal, '10%' ) !== false );
  facr_it( 'portal card states the window end date', strpos( $facr_portal, date_i18n( (string) get_option( 'date_format' ), strtotime( '2027-09-14' ) ) ) !== false );
  facr_it( 'portal card survives wp_kses_post', trim( wp_kses_post( $facr_portal ) ) !== '' );
  wp_set_current_user( 0 );

  // A rule whose window has closed must not be advertised to the affiliate.
  [ $facr_expired ] = Store::validate(
    [
      'scope_type'  => 'affiliate',
      'scope_id'    => (string) $facr_aff_id,
      'target_type' => 'all',
      'rate'        => '99',
      'rate_type'   => 'percentage',
      'ends_at'     => '2020-01-01',
      'status'      => 'active',
      'note'        => 'expired widget test',
    ]
  );
  Store::save( $facr_expired );
  wp_set_current_user( $facr_user_id );
  facr_it( 'portal card hides an expired rule', strpos( (string) apply_filters( 'fluent_affiliate/portal_notice_html', '' ), '99%' ) === false );
  wp_set_current_user( 0 );
  Store::delete( $facr_expired['id'] );

  Store::delete( $facr_wrule['id'] );
  wp_set_current_user( $facr_user_id );
  facr_it( 'portal card is empty with no rules', trim( (string) apply_filters( 'fluent_affiliate/portal_notice_html', '' ) ) === '' );
  wp_set_current_user( 0 );

  // Fix round 1: rules from other sources (group, everyone) must reach the
  // admin card with the right Source label, since rules_for_affiliate() now
  // merges them in and Resolver::effective() collapses per target.
  if ( Fluent::has_pro() && $facr_group_id > 0 ) {
    [ $facr_widget_group_rule ] = Store::validate(
      [
        'scope_type'  => 'group',
        'scope_id'    => (string) $facr_group_id,
        'target_type' => 'category',
        'target_ids'  => [ $facr_cat_child ],
        'rate'        => '7',
        'rate_type'   => 'percentage',
        'status'      => 'active',
        'note'        => 'widget group test',
      ]
    );
    Store::save( $facr_widget_group_rule );

    $facr_group_widgets = apply_filters( 'fluent_affiliate/affiliate_widgets', [], \FluentAffiliate\App\Models\Affiliate::find( $facr_aff_id ) );
    $facr_group_content = (string) ( $facr_group_widgets[0]['content'] ?? '' );
    facr_it(
      'admin card shows a group rule with Source Group',
      strpos( $facr_group_content, '7%' ) !== false && strpos( $facr_group_content, __( 'Group', 'fa-commission-rules' ) ) !== false
    );

    Store::delete( $facr_widget_group_rule['id'] );
  } else {
    echo "SKIP Fluent Affiliate Pro inactive: widget group rule not exercised\n";
  }

  if ( $facr_product_b > 0 ) {
    [ $facr_everyone_rule ] = Store::validate(
      [
        'scope_type'  => 'all',
        'target_type' => 'product',
        'target_ids'  => [ $facr_product_b ],
        'rate'        => '6',
        'rate_type'   => 'percentage',
        'status'      => 'active',
        'note'        => 'widget everyone test',
      ]
    );
    Store::save( $facr_everyone_rule );

    $facr_everyone_widgets = apply_filters( 'fluent_affiliate/affiliate_widgets', [], \FluentAffiliate\App\Models\Affiliate::find( $facr_aff_id ) );
    $facr_everyone_content = (string) ( $facr_everyone_widgets[0]['content'] ?? '' );
    facr_it(
      'admin card shows an Everyone rule when nothing more specific targets the same product',
      strpos( $facr_everyone_content, '6%' ) !== false && strpos( $facr_everyone_content, __( 'Everyone', 'fa-commission-rules' ) ) !== false
    );

    Store::delete( $facr_everyone_rule['id'] );
  } else {
    echo "SKIP WooCommerce inactive: widget Everyone rule not exercised\n";
  }

} finally {
  Fluent::update_option( FACR_RULES_KEY, $facr_backup );
  Fluent::update_option( '_woo_connector_config', $facr_woo_backup );
  if ( is_array( $facr_ref_backup ) ) {
    update_option( '_fa_referral_settings', $facr_ref_backup );
  } else {
    delete_option( '_fa_referral_settings' );
  }
  \FluentAffiliate\App\Helper\Utility::getReferralSettings( false );
  foreach ( [ $facr_product_id, $facr_product_b, $facr_product_c ] as $facr_dead_product ) {
    if ( ! empty( $facr_dead_product ) ) {
      wp_delete_post( (int) $facr_dead_product, true );
    }
  }
  if ( ! empty( $facr_aff_id ) ) {
    \FluentAffiliate\App\Models\Affiliate::where( 'id', $facr_aff_id )->delete();
  }
  if ( ! empty( $facr_group_id ) ) {
    \FluentAffiliate\App\Models\AffiliateGroup::where( 'id', $facr_group_id )->delete();
  }
  if ( ! empty( $facr_cat_child ) ) {
    wp_delete_term( $facr_cat_child, 'product_cat' );
  }
  if ( ! empty( $facr_cat_parent ) ) {
    wp_delete_term( $facr_cat_parent, 'product_cat' );
  }
  if ( ! empty( $facr_user_id ) ) {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user( $facr_user_id );
  }
}

echo $GLOBALS['facr_int_fail'] ? "\n{$GLOBALS['facr_int_fail']} FAILURES\n" : "\nAll integration checks passed\n";
