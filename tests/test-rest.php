<?php
declare(strict_types=1);
/**
 * REST API tests for the commission-rules admin app, via rest_do_request().
 * Run: wp --path=/path/to/wp eval 'require WP_PLUGIN_DIR . "/fluent-affiliate-commission-rules/tests/test-rest.php";'
 * (wp eval-file fatals: strict_types must be the file's first statement, but WP-CLI wraps it.)
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
    if ( $method !== 'GET' ) {
      $request->set_header( 'If-Match', Store::revision() );
    }
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
  $GLOBALS['facr_rest_skipped'] = true;
  return;
}

if ( ! empty( Fluent::get_option( FACR_RULES_KEY, [] ) ) ) {
  echo "SKIP this store already has commission rules: the REST test writes to the rule collection, so it only runs against an empty store\n";
  $GLOBALS['facr_rest_skipped'] = true;
  return;
}

$facr_r_backup  = Fluent::get_option( FACR_RULES_KEY, [] );
$facr_r_woo_backup = Fluent::get_option( '_woo_connector_config', [] );
$facr_r_users   = []; // only ids this file actually created; nothing else is ever deleted.
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
  // wp_insert_user() returns WP_Error on failure, and (int) WP_Error is 1 — the
  // site administrator. Never cast blind: keep the raw result, bail on an error,
  // and only ever delete ids this block confirmed it created.
  foreach (
    [
      'admin' => [ 'user_login' => 'facr_rest_admin_' . wp_rand(), 'user_pass' => wp_generate_password( 20 ), 'user_email' => 'facr_rest_admin_' . wp_rand() . '@example.test', 'role' => 'administrator' ],
      'sub'   => [ 'user_login' => 'facr_rest_sub_' . wp_rand(), 'user_pass' => wp_generate_password( 20 ), 'user_email' => 'facr_rest_sub_' . wp_rand() . '@example.test', 'role' => 'subscriber' ],
      'aff'   => [ 'user_login' => 'facr_rest_aff_' . wp_rand(), 'user_pass' => wp_generate_password( 20 ), 'user_email' => 'facr_rest_aff_' . wp_rand() . '@example.test', 'first_name' => 'Rest', 'last_name' => 'Affiliate' ],
    ] as $facr_r_which => $facr_r_args
  ) {
    $facr_r_created_user = wp_insert_user( $facr_r_args );
    if ( is_wp_error( $facr_r_created_user ) || (int) $facr_r_created_user <= 0 ) {
      facr_rest( "fixture user ({$facr_r_which}) created", false );
      return;
    }
    $facr_r_users[] = (int) $facr_r_created_user;
    if ( $facr_r_which === 'admin' ) {
      $facr_r_admin = (int) $facr_r_created_user;
    } elseif ( $facr_r_which === 'sub' ) {
      $facr_r_sub = (int) $facr_r_created_user;
    } else {
      $facr_r_aff_usr = (int) $facr_r_created_user;
    }
  }
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
    $facr_r_new_post = wp_insert_post( [ 'post_type' => 'product', 'post_title' => 'FACR Rest Product Zebra', 'post_status' => 'publish' ], true );
    $facr_r_product  = is_wp_error( $facr_r_new_post ) ? 0 : (int) $facr_r_new_post;
    if ( function_exists( 'wc_get_product' ) && $facr_r_product > 0 ) {
      // search_products() reads the product lookup table; save through WC so the row exists.
      $facr_r_wc = wc_get_product( $facr_r_product );
      if ( $facr_r_wc ) {
        $facr_r_wc->save();
      }
      // A variation of the search term's own product: WC_Product_Variation::get_formatted_name()
      // always wraps its attribute list in a '<span class="description">' suffix (see abstract-wc-product.php
      // vs. class-wc-product-variation.php), so this is what surfaces the raw-HTML bug in a search hit.
      // Its SKU repeats the search phrase so the same LIKE search returns it.
      $facr_r_var = new WC_Product_Variation();
      $facr_r_var->set_parent_id( $facr_r_product );
      $facr_r_var->set_sku( 'FACR Rest Product Zebra VAR ' . wp_rand() );
      $facr_r_var->set_regular_price( '10' );
      $facr_r_var->save();
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
  // Seed one of Fluent's own global rate rows so this is not a vacuous check on a
  // store that has none. Its shape is what Store::fluent_global_rules() reads:
  // the gate, a non-empty watched-id list, and one row of the rate table.
  Fluent::update_option(
    '_woo_connector_config',
    [
      'custom_affiliate_rate'  => 'yes',
      'watched_product_ids'    => [ 4242 ],
      'custom_affiliate_rates' => [
        [ 'object_type' => 'product', 'object_ids' => [ 4242 ], 'rate' => '12', 'rate_type' => 'percentage' ],
      ],
    ]
  );
  $facr_r_data      = (array) facr_rest_call( 'GET', '/rules' )->get_data();
  $facr_r_fluent_ok = true;
  $facr_r_fluent_n  = 0;
  foreach ( (array) $facr_r_data['rules'] as $facr_r_row ) {
    if ( strncmp( (string) $facr_r_row['id'], 'fluent:', 7 ) === 0 ) {
      $facr_r_fluent_n++;
      if ( empty( $facr_r_row['readonly'] ) ) {
        $facr_r_fluent_ok = false;
      }
    }
  }
  facr_rest( "GET /rules lists Fluent's own global row", $facr_r_fluent_n > 0 );
  facr_rest( 'fluent rows carry readonly true', $facr_r_fluent_ok );
  Fluent::update_option( '_woo_connector_config', $facr_r_woo_backup );

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
    // Only the product picker rehydrates from target_options; the category picker
    // gets the whole tree from GET /options, so a category rule ships none.
    facr_rest( 'a category rule ships no target_options', is_array( $facr_r_crow ) && $facr_r_crow['target_options'] === [] );
    facr_rest( 'a category rule still gets a server-built target label', is_array( $facr_r_crow ) && strpos( (string) $facr_r_crow['labels']['target'], 'FACR Rest Child' ) !== false );
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
    $facr_r_labels_clean = true;
    foreach ( (array) $facr_r_search->get_data() as $facr_r_p ) {
      if ( strpos( (string) $facr_r_p['label'], '<' ) !== false ) {
        $facr_r_labels_clean = false;
      }
    }
    facr_rest( 'GET /products labels never contain raw HTML', $facr_r_labels_clean );
  } else {
    facr_rest( 'GET /products is an empty list without WooCommerce', $facr_r_search->get_data() === [] );
  }

  Store::delete( $facr_r_rule['id'] );

  // ------------------------------------------------------------- writes ---
  wp_set_current_user( $facr_r_sub );
  facr_rest( 'subscriber POST /rules is 403', facr_rest_call( 'POST', '/rules', [ 'rate' => '1' ] )->get_status() === 403 );
  facr_rest( 'subscriber DELETE /rules/x is 403', facr_rest_call( 'DELETE', '/rules/x' )->get_status() === 403 );
  facr_rest( 'subscriber POST /rules/bulk is 403', facr_rest_call( 'POST', '/rules/bulk', [ 'action' => 'delete', 'ids' => [ 'x' ] ] )->get_status() === 403 );
  wp_set_current_user( $facr_r_admin );

  $facr_r_bad = facr_rest_call( 'POST', '/rules', [ 'scope_type' => 'affiliate', 'scope_id' => 0, 'target_type' => 'all', 'rate' => '', 'rate_type' => 'percentage' ] );
  facr_rest( 'invalid rule is 422', $facr_r_bad->get_status() === 422 );
  facr_rest( '422 carries field errors', isset( $facr_r_bad->get_data()['errors']['scope_id'], $facr_r_bad->get_data()['errors']['rate'] ) );
  facr_rest( 'nothing was saved on 422', Store::all() === [] );

  $facr_r_arrays = facr_rest_call( 'POST', '/rules', [ 'scope_type' => [ 'affiliate' ], 'scope_id' => [ $facr_r_aff_id ], 'target_type' => 'all', 'rate' => [ '10' ], 'rate_type' => [ 'flat' ], 'note' => [ 'x' ] ] );
  facr_rest( 'arrays where scalars are expected are rejected, not fatal', $facr_r_arrays->get_status() === 422 && isset( $facr_r_arrays->get_data()['errors']['rate'] ) );

  // A malformed field must never fall through to Store::validate()'s defaults:
  // those are the WIDEST values (all / percentage / active), so a broken submit
  // would silently save a live rule that pays on everything.
  $facr_r_arr_scope = facr_rest_call( 'POST', '/rules', [ 'scope_type' => [ 'affiliate' ], 'scope_id' => (string) $facr_r_aff_id, 'target_type' => 'all', 'rate' => '10', 'rate_type' => 'percentage' ] );
  facr_rest( 'an array scope_type is 422 on scope_type, not silently widened to all', $facr_r_arr_scope->get_status() === 422 && isset( $facr_r_arr_scope->get_data()['errors']['scope_type'] ) );
  $facr_r_bad_enum = facr_rest_call( 'POST', '/rules', [ 'scope_type' => 'all', 'target_type' => 'all', 'rate' => '10', 'rate_type' => 'bogus' ] );
  facr_rest( 'an unknown rate_type is 422, not silently a percentage', $facr_r_bad_enum->get_status() === 422 && isset( $facr_r_bad_enum->get_data()['errors']['rate_type'] ) );
  $facr_r_arr_date = facr_rest_call( 'POST', '/rules', [ 'scope_type' => 'all', 'target_type' => 'all', 'rate' => '10', 'rate_type' => 'percentage', 'starts_at' => [ '2026-01-01' ] ] );
  facr_rest( 'an array starts_at is 422 on starts_at', $facr_r_arr_date->get_status() === 422 && isset( $facr_r_arr_date->get_data()['errors']['starts_at'] ) );
  $facr_r_bad_ids = facr_rest_call( 'POST', '/rules', [ 'scope_type' => 'all', 'target_type' => 'product', 'target_ids' => 'not-an-array', 'rate' => '10', 'rate_type' => 'percentage' ] );
  facr_rest( 'a non-array target_ids is 422 on target_ids', $facr_r_bad_ids->get_status() === 422 && isset( $facr_r_bad_ids->get_data()['errors']['target_ids'] ) );
  $facr_r_bad_status = facr_rest_call( 'POST', '/rules', [ 'scope_type' => 'all', 'target_type' => 'all', 'rate' => '10', 'rate_type' => 'percentage', 'status' => 'enabled' ] );
  facr_rest( 'an unknown status is 422, not silently active', $facr_r_bad_status->get_status() === 422 && isset( $facr_r_bad_status->get_data()['errors']['status'] ) );
  facr_rest( 'no malformed submit wrote a rule', Store::all() === [] );
  // Omitting a field is still fine: absent means "use the default".
  $facr_r_defaults = facr_rest_call( 'POST', '/rules', [ 'rate' => '4', 'rate_type' => 'percentage' ] );
  facr_rest( 'a body that omits the optional fields still saves', $facr_r_defaults->get_status() === 201 && ( $facr_r_defaults->get_data()['rule']['scope_type'] ?? '' ) === 'all' );
  Store::delete( (string) ( $facr_r_defaults->get_data()['rule']['id'] ?? '' ) );

  $facr_r_created = facr_rest_call(
    'POST',
    '/rules',
    [ 'id' => '', 'scope_type' => 'affiliate', 'scope_id' => (string) $facr_r_aff_id, 'target_type' => 'all', 'rate' => 10, 'rate_type' => 'percentage', 'starts_at' => '', 'ends_at' => '2027-09-04', 'note' => 'rest write test', 'status' => 'active', 'created_at' => '2001-01-01T00:00:00+00:00' ]
  );
  $facr_r_cd = (array) $facr_r_created->get_data();
  facr_rest( 'create is 201', $facr_r_created->get_status() === 201 );
  facr_rest( 'create returns the rule with labels', isset( $facr_r_cd['rule']['id'], $facr_r_cd['rule']['labels']['sentence'] ) );
  facr_rest( 'create returns a tie list', isset( $facr_r_cd['tie'] ) && is_array( $facr_r_cd['tie'] ) );
  facr_rest( 'create ignores a client-supplied created_at', ( $facr_r_cd['rule']['created_at'] ?? '' ) !== '2001-01-01T00:00:00+00:00' && ( $facr_r_cd['rule']['created_at'] ?? '' ) !== '' );
  facr_rest( 'create casts rate to float', ( $facr_r_cd['rule']['rate'] ?? null ) === 10.0 );
  $facr_r_new_id = (string) ( $facr_r_cd['rule']['id'] ?? '' );
  facr_rest( 'created rule is in the store', $facr_r_new_id !== '' && Store::get( $facr_r_new_id ) !== null );

  $facr_r_tie = facr_rest_call(
    'POST',
    '/rules',
    [ 'scope_type' => 'affiliate', 'scope_id' => (string) $facr_r_aff_id, 'target_type' => 'all', 'rate' => 11, 'rate_type' => 'percentage', 'status' => 'active', 'note' => 'rest tie test' ]
  );
  facr_rest( 'an equally specific rival is reported in tie', in_array( $facr_r_new_id, (array) ( $facr_r_tie->get_data()['tie'] ?? [] ), true ) );
  Store::delete( (string) ( $facr_r_tie->get_data()['rule']['id'] ?? '' ) );

  $facr_r_stored_created = (string) ( Store::get( $facr_r_new_id )['created_at'] ?? '' );
  $facr_r_updated = facr_rest_call(
    'POST',
    '/rules',
    [ 'id' => $facr_r_new_id, 'scope_type' => 'affiliate', 'scope_id' => (string) $facr_r_aff_id, 'target_type' => 'all', 'rate' => 12, 'rate_type' => 'percentage', 'note' => 'rest write test edited', 'status' => 'inactive', 'created_at' => '2001-01-01T00:00:00+00:00' ]
  );
  facr_rest( 'update is 200', $facr_r_updated->get_status() === 200 );
  facr_rest( 'update changes the rule in place', count( Store::all() ) === 1 && ( Store::get( $facr_r_new_id )['note'] ?? '' ) === 'rest write test edited' && ( Store::get( $facr_r_new_id )['status'] ?? '' ) === 'inactive' );
  facr_rest( 'update keeps the stored created_at', ( Store::get( $facr_r_new_id )['created_at'] ?? '' ) === $facr_r_stored_created );

  $facr_r_ro = facr_rest_call( 'POST', '/rules', [ 'id' => 'fluent:0', 'scope_type' => 'all', 'target_type' => 'all', 'rate' => 1, 'rate_type' => 'percentage' ] );
  facr_rest( 'a fluent: id is refused on save with 403', $facr_r_ro->get_status() === 403 && ( $facr_r_ro->get_data()['code'] ?? '' ) === 'facr_readonly' );
  $facr_r_gone = facr_rest_call( 'POST', '/rules', [ 'id' => 'facr_does_not_exist', 'scope_type' => 'all', 'target_type' => 'all', 'rate' => 1, 'rate_type' => 'percentage' ] );
  facr_rest( 'an unknown id is refused on save with 404', $facr_r_gone->get_status() === 404 && ( $facr_r_gone->get_data()['code'] ?? '' ) === 'facr_missing' );
  facr_rest( 'refused saves wrote nothing', count( Store::all() ) === 1 );

  facr_rest( 'DELETE of a fluent: id is 403', facr_rest_call( 'DELETE', '/rules/fluent:0' )->get_status() === 403 );
  facr_rest( 'DELETE of an unknown id is 404', facr_rest_call( 'DELETE', '/rules/facr_does_not_exist' )->get_status() === 404 );
  $facr_r_del = facr_rest_call( 'DELETE', '/rules/' . $facr_r_new_id );
  facr_rest( 'DELETE of a real rule is 200 and names it', $facr_r_del->get_status() === 200 && ( $facr_r_del->get_data()['deleted'] ?? '' ) === $facr_r_new_id );
  facr_rest( 'DELETE removed the rule', Store::get( $facr_r_new_id ) === null );

  $facr_r_ids = [];
  foreach ( [ 'bulk a', 'bulk b', 'bulk c' ] as $facr_r_note ) {
    [ $facr_r_b ] = Store::validate( [ 'scope_type' => 'affiliate', 'scope_id' => (string) $facr_r_aff_id, 'target_type' => 'all', 'rate' => '5', 'rate_type' => 'percentage', 'status' => 'active', 'note' => $facr_r_note ] );
    Store::save( $facr_r_b );
    $facr_r_ids[] = $facr_r_b['id'];
  }
  $facr_r_bulk = facr_rest_call( 'POST', '/rules/bulk', [ 'action' => 'deactivate', 'ids' => [ $facr_r_ids[0], $facr_r_ids[1], 'fluent:0', [ 'nested' ], 42 ] ] );
  facr_rest( 'bulk deactivate is 200 with the count', $facr_r_bulk->get_status() === 200 && ( $facr_r_bulk->get_data()['count'] ?? -1 ) === 2 );
  facr_rest( 'bulk deactivate flipped exactly those two', ( Store::get( $facr_r_ids[0] )['status'] ?? '' ) === 'inactive' && ( Store::get( $facr_r_ids[1] )['status'] ?? '' ) === 'inactive' && ( Store::get( $facr_r_ids[2] )['status'] ?? '' ) === 'active' );
  facr_rest( 'bulk response carries a server-built message with the real count', strpos( (string) ( $facr_r_bulk->get_data()['message'] ?? '' ), '2' ) !== false );
  facr_rest( 'bulk activate is 200 with the count', ( facr_rest_call( 'POST', '/rules/bulk', [ 'action' => 'activate', 'ids' => $facr_r_ids ] )->get_data()['count'] ?? -1 ) === 2 );
  facr_rest( 'bulk with an unknown action is 400', facr_rest_call( 'POST', '/rules/bulk', [ 'action' => 'explode', 'ids' => $facr_r_ids ] )->get_status() === 400 );
  facr_rest( 'bulk with no usable ids is 200 count 0', ( facr_rest_call( 'POST', '/rules/bulk', [ 'action' => 'delete', 'ids' => [ 'fluent:0', 'fluent:renewal:1' ] ] )->get_data()['count'] ?? -1 ) === 0 );
  facr_rest( 'bulk delete is 200 with the count', ( facr_rest_call( 'POST', '/rules/bulk', [ 'action' => 'delete', 'ids' => $facr_r_ids ] )->get_data()['count'] ?? -1 ) === 3 );
  facr_rest( 'bulk delete emptied the store', Store::all() === [] );

  $facr_r_request = new WP_REST_Request( 'POST', '/fa-commission-rules/v1/rules' );
  $facr_r_request->set_header( 'Content-Type', 'application/json' );
  $facr_r_request->set_body( wp_json_encode( [ 'scope_type' => 'all', 'target_type' => 'all', 'rate' => 5 ] ) );
  facr_rest( 'writes without a revision require reloading', rest_do_request( $facr_r_request )->get_status() === 428 );
  $facr_r_request->set_header( 'If-Match', 'stale' );
  facr_rest( 'stale revision is a conflict', rest_do_request( $facr_r_request )->get_status() === 409 );
  facr_rest( 'a conflicted write creates no rule', Store::all() === [] );
  $facr_r_request->set_header( 'If-Match', Store::revision() );
  $facr_r_created = rest_do_request( $facr_r_request );
  facr_rest( 'current revision allows creation', $facr_r_created->get_status() === 201 );
  $facr_r_edit_id = $facr_r_created->get_data()['rule']['id'];
  $facr_r_stale = Store::revision();
  Store::set_status( [ $facr_r_edit_id ], 'inactive' );
  $facr_r_request->set_body( wp_json_encode( [ 'id' => $facr_r_edit_id, 'scope_type' => 'all', 'target_type' => 'all', 'rate' => 9 ] ) );
  $facr_r_request->set_header( 'If-Match', $facr_r_stale );
  facr_rest( 'editing a stale snapshot cannot overwrite a newer change', rest_do_request( $facr_r_request )->get_status() === 409 && Store::get( $facr_r_edit_id )['status'] === 'inactive' );
  Store::delete( $facr_r_edit_id );

  // --------------------------------------------------- input hardening ---
  facr_rest( 'scalar() flattens an array to an empty string', \FACommissionRules\Rest\Controller::scalar( [ 'edit' ] ) === '' );
  facr_rest( 'scalar() passes a scalar through as a string', \FACommissionRules\Rest\Controller::scalar( 42 ) === '42' );
  facr_rest( 'a sanitiser survives an array-valued field', sanitize_key( \FACommissionRules\Rest\Controller::scalar( [ 'edit' ] ) ) === '' );

} finally {
  wp_set_current_user( $facr_r_prev );
  Fluent::update_option( FACR_RULES_KEY, $facr_r_backup );
  Fluent::update_option( '_woo_connector_config', $facr_r_woo_backup );
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
  foreach ( $facr_r_users as $facr_r_uid ) {
    // > 1 as belt and braces: id 1 is never ours to delete, whatever went wrong.
    if ( $facr_r_uid > 1 ) {
      wp_delete_user( $facr_r_uid );
    }
  }
}

echo $GLOBALS['facr_rest_fail'] ? "\n{$GLOBALS['facr_rest_fail']} FAILURES\n" : "\nAll REST checks passed\n";
