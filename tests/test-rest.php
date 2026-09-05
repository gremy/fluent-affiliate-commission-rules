<?php
declare(strict_types=1);
/**
 * REST API tests for the commission-rules admin app, via rest_do_request().
 * Run: wp --path=/path/to/wp eval-file wp-content/plugins/fluent-affiliate-commission-rules/tests/test-rest.php
 *
 * Creates its own users, affiliate, category and product and removes them in a finally block.
 */

use FACommissionRules\Fluent;
use FACommissionRules\Store;

if ( ! defined( 'ABSPATH' ) ) {
  fwrite( STDERR, "test-rest.php needs WordPress: run it with wp eval-file\n" );
  return;
}

$GLOBALS['facr_rest_fail'] = $GLOBALS['facr_rest_fail'] ?? 0;

if ( ! function_exists( 'facr_rest' ) ) {
  function facr_rest( string $label, bool $ok ): void {
    if ( $ok ) {
      echo "PASS {$label}\n";
      return;
    }
    $GLOBALS['facr_rest_fail']++;
    echo "FAIL {$label}\n";
  }
}

if ( ! function_exists( 'facr_rest_call' ) ) {
  /**
   * @param array<string,mixed> $body  JSON body (POST)
   * @param array<string,mixed> $query query-string params (GET)
   */
  function facr_rest_call( string $method, string $path, array $body = [], array $query = [] ): WP_REST_Response {
    $request = new WP_REST_Request( $method, '/fa-commission-rules/v1' . $path );
    if ( $body ) {
      $request->set_header( 'Content-Type', 'application/json' );
      $request->set_body( (string) wp_json_encode( $body ) );
    }
    foreach ( $query as $key => $value ) {
      $request->set_param( $key, $value );
    }
    return rest_do_request( $request );
  }
}

if ( ! Fluent::ready() ) {
  echo "SKIP Fluent Affiliate is not active\n";
  return;
}

if ( ! empty( Fluent::get_option( FACR_RULES_KEY, [] ) ) ) {
  echo "SKIP this store already has commission rules: the REST test writes to the rule collection, so it only runs against an empty store\n";
  return;
}

$facr_r_backup  = Fluent::get_option( FACR_RULES_KEY, [] );
$facr_r_admin   = 0;
$facr_r_sub     = 0;
$facr_r_aff_usr = 0;
$facr_r_aff_id  = 0;
$facr_r_cat_p   = 0;
$facr_r_cat_c   = 0;
$facr_r_product = 0;
$facr_r_prev    = get_current_user_id();

try {
  // ------------------------------------------------------------ fixtures ---
  $facr_r_admin = (int) wp_insert_user( [ 'user_login' => 'facr_rest_admin_' . wp_rand(), 'user_pass' => wp_generate_password( 20 ), 'user_email' => 'facr_rest_admin_' . wp_rand() . '@example.test', 'role' => 'administrator' ] );
  $facr_r_sub   = (int) wp_insert_user( [ 'user_login' => 'facr_rest_sub_' . wp_rand(), 'user_pass' => wp_generate_password( 20 ), 'user_email' => 'facr_rest_sub_' . wp_rand() . '@example.test', 'role' => 'subscriber' ] );
  $facr_r_aff_usr = (int) wp_insert_user( [ 'user_login' => 'facr_rest_aff_' . wp_rand(), 'user_pass' => wp_generate_password( 20 ), 'user_email' => 'facr_rest_aff_' . wp_rand() . '@example.test', 'first_name' => 'Rest', 'last_name' => 'Affiliate' ] );
  $facr_r_aff = \FluentAffiliate\App\Models\Affiliate::create( [ 'user_id' => $facr_r_aff_usr, 'status' => 'active', 'rate_type' => 'percentage', 'rate' => 5, 'group_id' => 0 ] );
  $facr_r_aff_id = (int) $facr_r_aff->id;

  if ( taxonomy_exists( 'product_cat' ) ) {
    $facr_r_term_p = wp_insert_term( 'FACR Rest Parent ' . wp_rand(), 'product_cat' );
    if ( ! is_wp_error( $facr_r_term_p ) ) {
      $facr_r_cat_p  = (int) $facr_r_term_p['term_id'];
      $facr_r_term_c = wp_insert_term( 'FACR Rest Child ' . wp_rand(), 'product_cat', [ 'parent' => $facr_r_cat_p ] );
      if ( ! is_wp_error( $facr_r_term_c ) ) {
        $facr_r_cat_c = (int) $facr_r_term_c['term_id'];
      }
    }
  }
  if ( Fluent::has_woo() ) {
    $facr_r_product = (int) wp_insert_post( [ 'post_type' => 'product', 'post_title' => 'FACR Rest Product Zebra', 'post_status' => 'publish' ] );
    if ( function_exists( 'wc_get_product' ) && $facr_r_product > 0 ) {
      // search_products() reads the product lookup table; save through WC so the row exists.
      $facr_r_wc = wc_get_product( $facr_r_product );
      if ( $facr_r_wc ) {
        $facr_r_wc->save();
      }
    }
  }

  // --------------------------------------------------------- permissions ---
  wp_set_current_user( 0 );
  facr_rest( 'anonymous GET /rules is 401', facr_rest_call( 'GET', '/rules' )->get_status() === 401 );
  wp_set_current_user( $facr_r_sub );
  facr_rest( 'subscriber GET /rules is 403', facr_rest_call( 'GET', '/rules' )->get_status() === 403 );
  facr_rest( 'subscriber GET /options is 403', facr_rest_call( 'GET', '/options' )->get_status() === 403 );
  facr_rest( 'subscriber GET /products is 403', facr_rest_call( 'GET', '/products', [], [ 'search' => 'zebra' ] )->get_status() === 403 );

  // --------------------------------------------------------------- reads ---
  wp_set_current_user( $facr_r_admin );
  $facr_r_index = facr_rest_call( 'GET', '/rules' );
  $facr_r_data  = (array) $facr_r_index->get_data();
  facr_rest( 'admin GET /rules is 200', $facr_r_index->get_status() === 200 );
  facr_rest( 'index has rules, shadow, tie and default_rate', isset( $facr_r_data['rules'], $facr_r_data['shadow'], $facr_r_data['tie'] ) && array_key_exists( 'default_rate', $facr_r_data ) );
  facr_rest( 'shadow and tie serialise as objects even when empty', is_object( $facr_r_data['shadow'] ) && is_object( $facr_r_data['tie'] ) );
  facr_rest( 'default_rate is the label helper output', $facr_r_data['default_rate'] === \FACommissionRules\Labels::default_rate_label() );
  $facr_r_fluent_ok = true;
  foreach ( (array) $facr_r_data['rules'] as $facr_r_row ) {
    if ( strncmp( (string) $facr_r_row['id'], 'fluent:', 7 ) === 0 && empty( $facr_r_row['readonly'] ) ) {
      $facr_r_fluent_ok = false;
    }
  }
  facr_rest( 'fluent rows carry readonly true', $facr_r_fluent_ok );

  [ $facr_r_rule ] = Store::validate( [ 'scope_type' => 'affiliate', 'scope_id' => (string) $facr_r_aff_id, 'target_type' => 'all', 'rate' => '12.5', 'rate_type' => 'percentage', 'status' => 'active', 'note' => 'rest read test' ] );
  Store::save( $facr_r_rule );
  $facr_r_data = (array) facr_rest_call( 'GET', '/rules' )->get_data();
  $facr_r_mine = null;
  foreach ( (array) $facr_r_data['rules'] as $facr_r_row ) {
    if ( (string) $facr_r_row['id'] === $facr_r_rule['id'] ) {
      $facr_r_mine = $facr_r_row;
    }
  }
  facr_rest( 'a saved rule is listed', is_array( $facr_r_mine ) );
  facr_rest( 'a listed rule carries the five labels', is_array( $facr_r_mine ) && array_keys( (array) $facr_r_mine['labels'] ) === [ 'scope', 'target', 'rate', 'window', 'sentence' ] );
  facr_rest( 'labels.scope names the affiliate', is_array( $facr_r_mine ) && strpos( (string) $facr_r_mine['labels']['scope'], '#' . $facr_r_aff_id ) !== false );
  facr_rest( 'labels.rate is formatted', is_array( $facr_r_mine ) && $facr_r_mine['labels']['rate'] === '12.5%' );
  facr_rest( 'target_options is an empty array for all products', is_array( $facr_r_mine ) && $facr_r_mine['target_options'] === [] );
  facr_rest( 'shadow map has an entry for the rule', is_array( $facr_r_mine ) && property_exists( $facr_r_data['shadow'], $facr_r_rule['id'] ) );
  facr_rest( 'tie map has an entry for the rule', is_array( $facr_r_mine ) && property_exists( $facr_r_data['tie'], $facr_r_rule['id'] ) );

  if ( $facr_r_cat_c > 0 ) {
    [ $facr_r_crule ] = Store::validate( [ 'scope_type' => 'all', 'target_type' => 'category', 'target_ids' => [ (string) $facr_r_cat_c ], 'rate' => '3', 'rate_type' => 'flat', 'status' => 'active' ] );
    Store::save( $facr_r_crule );
    $facr_r_data = (array) facr_rest_call( 'GET', '/rules' )->get_data();
    $facr_r_crow = null;
    foreach ( (array) $facr_r_data['rules'] as $facr_r_row ) {
      if ( (string) $facr_r_row['id'] === $facr_r_crule['id'] ) {
        $facr_r_crow = $facr_r_row;
      }
    }
    facr_rest( 'a category rule lists its target_options', is_array( $facr_r_crow ) && ( $facr_r_crow['target_options'][0]['id'] ?? 0 ) === $facr_r_cat_c && strpos( (string) $facr_r_crow['target_options'][0]['label'], 'FACR Rest Child' ) !== false );
    Store::delete( $facr_r_crule['id'] );
  } else {
    echo "SKIP no product_cat taxonomy: category target_options not exercised\n";
  }

  $facr_r_opts = facr_rest_call( 'GET', '/options' );
  $facr_r_o    = (array) $facr_r_opts->get_data();
  facr_rest( 'admin GET /options is 200', $facr_r_opts->get_status() === 200 );
  facr_rest( 'options has every key', isset( $facr_r_o['affiliates'], $facr_r_o['groups'], $facr_r_o['categories'], $facr_r_o['default_rate'] ) && array_key_exists( 'has_pro', $facr_r_o ) && array_key_exists( 'has_woo', $facr_r_o ) );
  facr_rest( 'options flags are booleans', is_bool( $facr_r_o['has_pro'] ) && is_bool( $facr_r_o['has_woo'] ) );
  $facr_r_aff_opt = null;
  foreach ( (array) $facr_r_o['affiliates'] as $facr_r_a ) {
    if ( (int) $facr_r_a['id'] === $facr_r_aff_id ) {
      $facr_r_aff_opt = $facr_r_a;
    }
  }
  facr_rest( 'options lists the affiliate with the same label the list uses', is_array( $facr_r_aff_opt ) && $facr_r_aff_opt['label'] === Fluent::affiliate_label( $facr_r_aff_id ) );
  facr_rest( 'options affiliate label carries the name', is_array( $facr_r_aff_opt ) && strpos( (string) $facr_r_aff_opt['label'], 'Rest Affiliate' ) === 0 );
  facr_rest( 'options groups match Fluent::groups()', count( (array) $facr_r_o['groups'] ) === count( Fluent::groups() ) );
  if ( Fluent::has_woo() && $facr_r_cat_c > 0 ) {
    $facr_r_cat_opt = null;
    foreach ( (array) $facr_r_o['categories'] as $facr_r_c ) {
      if ( (int) $facr_r_c['id'] === $facr_r_cat_c ) {
        $facr_r_cat_opt = $facr_r_c;
      }
    }
    facr_rest( 'options categories carry the ancestor path', is_array( $facr_r_cat_opt ) && strpos( (string) $facr_r_cat_opt['label'], 'FACR Rest Parent' ) !== false && strpos( (string) $facr_r_cat_opt['label'], ' / FACR Rest Child' ) !== false );
  } elseif ( ! Fluent::has_woo() ) {
    facr_rest( 'options categories are empty without WooCommerce', $facr_r_o['categories'] === [] );
  }

  facr_rest( 'GET /products rejects a one-character search', facr_rest_call( 'GET', '/products', [], [ 'search' => 'z' ] )->get_status() === 400 );
  facr_rest( 'GET /products rejects a missing search', facr_rest_call( 'GET', '/products' )->get_status() === 400 );
  $facr_r_search = facr_rest_call( 'GET', '/products', [], [ 'search' => 'FACR Rest Product Zebra' ] );
  facr_rest( 'GET /products is 200', $facr_r_search->get_status() === 200 );
  if ( Fluent::has_woo() && $facr_r_product > 0 ) {
    $facr_r_hit = false;
    foreach ( (array) $facr_r_search->get_data() as $facr_r_p ) {
      if ( (int) $facr_r_p['id'] === $facr_r_product && strpos( (string) $facr_r_p['label'], 'FACR Rest Product Zebra' ) !== false ) {
        $facr_r_hit = true;
      }
    }
    facr_rest( 'GET /products finds the product by title with a label', $facr_r_hit );
  } else {
    facr_rest( 'GET /products is an empty list without WooCommerce', $facr_r_search->get_data() === [] );
  }

  Store::delete( $facr_r_rule['id'] );

  // ------------------------------------------------------------- writes ---
  // (Task 4 appends here.)

} finally {
  wp_set_current_user( $facr_r_prev );
  Fluent::update_option( FACR_RULES_KEY, $facr_r_backup );
  if ( $facr_r_product > 0 ) {
    wp_delete_post( $facr_r_product, true );
  }
  if ( $facr_r_aff_id > 0 ) {
    \FluentAffiliate\App\Models\Affiliate::where( 'id', $facr_r_aff_id )->delete();
  }
  if ( $facr_r_cat_c > 0 ) {
    wp_delete_term( $facr_r_cat_c, 'product_cat' );
  }
  if ( $facr_r_cat_p > 0 ) {
    wp_delete_term( $facr_r_cat_p, 'product_cat' );
  }
  require_once ABSPATH . 'wp-admin/includes/user.php';
  foreach ( [ $facr_r_admin, $facr_r_sub, $facr_r_aff_usr ] as $facr_r_uid ) {
    if ( $facr_r_uid > 0 ) {
      wp_delete_user( $facr_r_uid );
    }
  }
}

echo $GLOBALS['facr_rest_fail'] ? "\n{$GLOBALS['facr_rest_fail']} FAILURES\n" : "\nAll REST checks passed\n";
