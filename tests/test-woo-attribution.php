<?php
/** Native checkout and renewal events. Run only against a disposable facr_tests_* database. */
use FACommissionRules\Fluent;
use FACommissionRules\Plugin;
use FACommissionRules\Store;
use FACommissionRules\WooCompatibility;
use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\Customer;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliatePro\App\Hooks\Handlers\LifetimeCommissionHandler;
use FluentAffiliatePro\App\Services\Integrations\WooCommerce\Bootstrap;
use FluentAffiliatePro\App\Services\Integrations\WooCommerce\RecurringReferral;

$GLOBALS['facr_woo_fail'] = 0;
if ( ! defined( 'DB_NAME' ) || ! str_starts_with( DB_NAME, 'facr_tests_' )
  || ! class_exists( RecurringReferral::class ) || ! function_exists( 'wcs_create_subscription' ) ) {
  echo "SKIP native Woo attribution requires a disposable facr_tests_* database, Fluent Pro and WooCommerce Subscriptions\n";
  $GLOBALS['facr_woo_skipped'] = true;
  return;
}

(function () {
  global $wp_filter;
  $check = static function ( $label, $ok ) {
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
    $GLOBALS['facr_woo_fail'] += $ok ? 0 : 1;
  };
  $settings = get_option( '_fa_referral_settings', null );
  $config = Fluent::get_option( '_woo_connector_config', [] );
  $rules = Store::all();
  $cookie = $_COOKIE['f_aff'] ?? null;
  $hooks = [];
  foreach ( $wp_filter as $name => $hook ) { $hooks[ $name ] = clone $hook; }
  $orders = []; $users = []; $affiliate = null; $product = null; $coupon = null;
  try {
    add_filter( 'pre_wp_mail', '__return_false' );
    unset( $_COOKIE['f_aff'] );
    Utility::updateReferralSettings( [ 'enabled_integrations' => [ 'woo' ], 'enable_lifetime_commission' => 'yes',
      'lifetime_expiry_days' => 0, 'lifetime_rate' => 10, 'lifetime_rate_type' => 'percentage',
      'enable_subscription_renewal' => 'yes', 'renewal_rate' => 5, 'renewal_rate_type' => 'percentage',
      'max_renewal_count' => 0, 'self_referral_disabled' => 'yes', 'referral_format' => 'id' ] );
    Fluent::update_option( '_woo_connector_config', [ 'enable_subscription_renewal' => 'yes' ] );
    Fluent::update_option( FACR_RULES_KEY, [] );
    foreach ( [ 'b2b' => 3, 'b2c' => 7 ] as $type => $rate ) {
      [ $rule, $errors ] = Store::validate( [ 'scope_type' => 'all', 'scope_id' => 0, 'customer_type' => $type,
        'target_type' => 'all', 'rate' => $rate, 'rate_type' => 'percentage', 'status' => 'active' ] );
      if ( $errors ) { throw new RuntimeException( wp_json_encode( $errors ) ); }
      Store::save( $rule );
    }
    $uid = wp_insert_user( [ 'user_login' => 'facr_checkout_' . wp_rand(), 'user_email' => 'facr_checkout_' . wp_rand() . '@example.test', 'user_pass' => wp_generate_password() ] );
    if ( is_wp_error( $uid ) ) { throw new RuntimeException( $uid->get_error_message() ); }
    $users[] = $uid;
    $email = get_userdata( $uid )->user_email;
    $affiliate = Affiliate::create( [ 'user_id' => 1, 'status' => 'active', 'rate_type' => 'percentage', 'rate' => 5 ] );
    $customer = Customer::create( [ 'user_id' => $uid, 'email' => $email, 'by_affiliate_id' => $affiliate->id ] );
    $product = new WC_Product_Simple();
    $product->set_name( 'Attribution test product' ); $product->set_status( 'draft' );
    $product->set_regular_price( 100 ); $product->set_price( 100 ); $product->save();
    $makeOrder = static function ( $type = 'yes', $userId = null, $billing = null ) use ( &$orders, $product, $uid, $email ) {
      $order = wc_create_order( [ 'customer_id' => $userId ?? $uid ] ); $orders[] = $order;
      $order->set_billing_email( $billing ?? $email );
      $order->add_product( $product, 1, [ 'subtotal' => 100, 'total' => 100 ] );
      $order->set_total( 100 ); $order->update_meta_data( 'b2bking_is_b2b_order', $type ); $order->save();
      return $order;
    };
    $lifetime = new LifetimeCommissionHandler();
    $lifetime->register();
    ( new Bootstrap() )->register();
    ( new RecurringReferral() )->register();
    Plugin::woo_compatibility();
    $count = static function ( $hook ) use ( &$wp_filter ) {
      $n = 0;
      foreach ( $wp_filter[ $hook ]->callbacks ?? [] as $callbacks ) {
        foreach ( $callbacks as $callback ) {
          if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof WooCompatibility ) { $n++; }
        }
      }
      return $n;
    };
    foreach ( [ 'woocommerce_checkout_order_processed', 'woocommerce_store_api_checkout_order_processed', 'woocommerce_subscription_renewal_payment_complete' ] as $hook ) {
      $check( 'native enabled callback replaced once: ' . $hook, $count( $hook ) === 1 );
    }
    Plugin::woo_compatibility();
    $check( 'compatibility installation is idempotent', $count( 'woocommerce_checkout_order_processed' ) === 1 );
    $parent = $makeOrder();
    do_action( 'woocommerce_checkout_order_processed', $parent->get_id(), [], $parent );
    $referral = Referral::where( 'provider', 'woo' )->where( 'provider_id', $parent->get_id() )->first();
    $check( 'manually bound B2B customer creates a lifetime referral at the B2B rate', $referral && $referral->type === 'lifetime_sale' && (float) $referral->amount === 3.0 );
    if ( ! $referral ) { throw new RuntimeException( 'No parent referral' ); }
    do_action( 'woocommerce_checkout_order_processed', $parent->get_id(), [], $parent );
    $check( 'checkout callback retry does not duplicate a referral', Referral::where( 'provider', 'woo' )->where( 'provider_id', $parent->get_id() )->count() === 1 );
    $b2c = $makeOrder( 'no' );
    do_action( 'woocommerce_store_api_checkout_order_processed', $b2c );
    $b2cReferral = Referral::where( 'provider', 'woo' )->where( 'provider_id', $b2c->get_id() )->first();
    $check( 'Blocks checkout uses the B2C lifetime rate', $b2cReferral && (float) $b2cReferral->amount === 7.0 );
    $guest = $makeOrder( 'no', 0 );
    do_action( 'woocommerce_store_api_checkout_order_processed', $guest );
    $check( 'guest repeat purchase resolves a bound billing email', (bool) Referral::where( 'provider', 'woo' )->where( 'provider_id', $guest->get_id() )->first() );

    $subscription = wcs_create_subscription( [ 'order_id' => $parent->get_id(), 'customer_id' => $uid, 'billing_period' => 'month', 'billing_interval' => 1 ] );
    if ( is_wp_error( $subscription ) ) { throw new RuntimeException( $subscription->get_error_message() ); }
    $orders[] = $subscription;
    $renewal = $makeOrder();
    do_action( 'woocommerce_subscription_renewal_payment_complete', $subscription, $renewal );
    $renewalReferral = Referral::where( 'provider', 'woo' )->where( 'provider_id', $renewal->get_id() )->first();
    $check( 'a real subscription with a lifetime parent earns its B2B renewal rate', $renewalReferral && $renewalReferral->type === 'recurring_sale' && (int) $renewalReferral->parent_id === (int) $referral->id && (float) $renewalReferral->amount === 3.0 );
    $check( 'renewal carries the rule audit stamp', $renewalReferral && isset( $renewalReferral->settings['fa_commission_rules'] ) );
    do_action( 'woocommerce_subscription_renewal_payment_complete', $subscription, $renewal );
    $check( 'renewal event retry does not duplicate a referral', Referral::where( 'provider', 'woo' )->where( 'provider_id', $renewal->get_id() )->count() === 1 );
    Utility::updateReferralSettings( [ 'max_renewal_count' => 2 ] );
    $limited = $makeOrder();
    do_action( 'woocommerce_subscription_renewal_payment_complete', $subscription, $limited );
    $check( 'native renewal limit includes the initial payment', ! Referral::where( 'provider', 'woo' )->where( 'provider_id', $limited->get_id() )->exists() );
    Utility::updateReferralSettings( [ 'max_renewal_count' => 0 ] );
    $affiliate->status = 'inactive'; $affiliate->save();
    do_action( 'woocommerce_subscription_renewal_payment_complete', $subscription, $limited );
    $check( 'inactive affiliates cannot earn lifetime-parent renewals', ! Referral::where( 'provider', 'woo' )->where( 'provider_id', $limited->get_id() )->exists() );
    $affiliate->status = 'active'; $affiliate->save();
    $referral->type = 'sale'; $referral->save();
    do_action( 'woocommerce_subscription_renewal_payment_complete', $subscription, $limited );
    $check( 'ordinary sale-parent renewals still use the native handler', (bool) Referral::where( 'provider', 'woo' )->where( 'provider_id', $limited->get_id() )->first() );
    $referral->type = 'lifetime_sale'; $referral->save();

    // A renewal paid through checkout must still use renewal eligibility and pricing.
    $subscription->set_billing_email( $email );
    $subscription->add_product( $product, 1, [ 'subtotal' => 100, 'total' => 100 ] );
    $subscription->set_total( 100 ); $subscription->save();
    $segmentRules = Store::all(); Fluent::update_option( FACR_RULES_KEY, [] );
    foreach ( [ 'woocommerce_checkout_order_processed', 'woocommerce_store_api_checkout_order_processed' ] as $checkoutHook ) {
      $checkoutRenewal = wcs_create_renewal_order( $subscription );
      if ( is_wp_error( $checkoutRenewal ) ) { throw new RuntimeException( $checkoutRenewal->get_error_message() ); }
      $orders[] = $checkoutRenewal;
      Utility::updateReferralSettings( [ 'max_renewal_count' => 1 ] );
      $_COOKIE['f_aff'] = $affiliate->id . '|0';
      do_action( $checkoutHook, $checkoutHook === 'woocommerce_checkout_order_processed' ? $checkoutRenewal->get_id() : $checkoutRenewal, [], $checkoutRenewal );
      unset( $_COOKIE['f_aff'] );
      do_action( $checkoutHook, $checkoutHook === 'woocommerce_checkout_order_processed' ? $checkoutRenewal->get_id() : $checkoutRenewal, [], $checkoutRenewal );
      do_action( 'woocommerce_subscription_renewal_payment_complete', $subscription, $checkoutRenewal );
      $check( $checkoutHook . ' cannot bypass renewal limits with or without a cookie', ! Referral::where( 'provider', 'woo' )->where( 'provider_id', $checkoutRenewal->get_id() )->exists() );
      Utility::updateReferralSettings( [ 'max_renewal_count' => 0 ] );
      do_action( 'woocommerce_subscription_renewal_payment_complete', $subscription, $checkoutRenewal );
      $checkoutReferral = Referral::where( 'provider', 'woo' )->where( 'provider_id', $checkoutRenewal->get_id() )->first();
      $check( $checkoutHook . ' uses the renewal rate and parent after payment', $checkoutReferral && $checkoutReferral->type === 'recurring_sale' && (float) $checkoutReferral->amount === 5.0 && (int) $checkoutReferral->parent_id === (int) $referral->id );
    }
    Fluent::update_option( FACR_RULES_KEY, $segmentRules );

    // Force overlap at the SQL lock attempt; workers boot the real enabled integration.
    $runWorkers = static function ( array $orderIds, bool $holdLock = false ) use ( $subscription, $parent ) {
      global $wpdb;
      $lock = 'facr:' . hash( 'sha224', DB_NAME . ':' . $wpdb->prefix . ':renewal:' . $parent->get_id() );
      $dir = sys_get_temp_dir() . '/facr-renewals-' . bin2hex( random_bytes( 6 ) ); mkdir( $dir, 0700 );
      $processes = [];
      try {
        if ( $holdLock && (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 1)', $lock ) ) !== '1' ) { throw new RuntimeException( 'Could not hold test lock' ); }
        foreach ( $orderIds as $worker => $orderId ) {
          $code = 'require ' . var_export( ABSPATH . 'wp-load.php', true ) . ';'
            . 'add_filter("pre_wp_mail","__return_false");'
            . 'add_filter("query",function($sql){if(str_contains($sql,"GET_LOCK")){touch(' . var_export( $dir . '/attempt' . $worker, true ) . ');}return $sql;});'
            . 'add_filter("fluent_affiliate/recurring_commission",function($amount){touch(' . var_export( $dir . '/inside', true ) . ');'
            . '$deadline=microtime(true)+15;while(!file_exists(' . var_export( $dir . '/go', true ) . ')){if(microtime(true)>$deadline){exit(2);}usleep(10000);}return $amount;},99);'
            . 'do_action("woocommerce_subscription_renewal_payment_complete",wcs_get_subscription(' . $subscription->get_id() . '),wc_get_order(' . $orderId . '));';
          $processes[] = proc_open( [ PHP_BINARY, '-r', $code ], [ 0 => [ 'file', '/dev/null', 'r' ], 1 => [ 'file', $dir . '/out' . $worker, 'w' ], 2 => [ 'file', $dir . '/err' . $worker, 'w' ] ], $pipes );
        }
        $deadline = microtime( true ) + 15;
        while ( count( glob( $dir . '/attempt*' ) ) < count( $orderIds ) || ( ! $holdLock && ! file_exists( $dir . '/inside' ) ) ) {
          if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'Workers did not reach the renewal lock' ); }
          usleep( 10000 );
        }
        touch( $dir . '/go' );
        foreach ( $processes as $worker => $process ) {
          if ( proc_close( $process ) !== 0 ) { throw new RuntimeException( file_get_contents( $dir . '/err' . $worker ) ); }
        }
        $processes = [];
      } finally {
        foreach ( $processes as $process ) { if ( is_resource( $process ) ) { proc_terminate( $process ); proc_close( $process ); } }
        if ( $holdLock ) { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
        foreach ( glob( $dir . '/*' ) as $file ) { unlink( $file ); } rmdir( $dir );
      }
    };
    foreach ( [ 'sale', 'lifetime_sale' ] as $parentType ) {
      $referral->type = $parentType; $referral->save();
      $concurrent = $makeOrder();
      $runWorkers( [ $concurrent->get_id(), $concurrent->get_id() ] );
      $check( $parentType . ': concurrent duplicate events create one commission', Referral::where( 'provider', 'woo' )->where( 'provider_id', $concurrent->get_id() )->count() === 1 && (float) Referral::where( 'provider', 'woo' )->where( 'provider_id', $concurrent->get_id() )->sum( 'amount' ) === 3.0 );
      $used = Referral::where( 'parent_id', $referral->id )->where( 'type', 'recurring_sale' )->count();
      Utility::updateReferralSettings( [ 'max_renewal_count' => $used + 2 ] );
      $first = $makeOrder(); $second = $makeOrder();
      $runWorkers( [ $first->get_id(), $second->get_id() ] );
      $check( $parentType . ': concurrent different renewals cannot exceed the shared limit', Referral::where( 'provider', 'woo' )->whereIn( 'provider_id', [ $first->get_id(), $second->get_id() ] )->count() === 1 );
      Utility::updateReferralSettings( [ 'max_renewal_count' => 0 ] );
    }
    $busy = $makeOrder(); $busy->set_status( 'processing' ); $busy->save();
    $runWorkers( [ $busy->get_id() ], true );
    $retryArgs = [ $subscription->get_id(), $busy->get_id() ];
    $check( 'lock timeout queues a retry without recording an unlocked commission', as_has_scheduled_action( 'facr_retry_woo_renewal', $retryArgs, 'fa-commission-rules' ) && ! Referral::where( 'provider', 'woo' )->where( 'provider_id', $busy->get_id() )->exists() );
    Utility::updateReferralSettings( [ 'enable_subscription_renewal' => 'no' ] );
    do_action( 'facr_retry_woo_renewal', ...$retryArgs );
    $check( 'retry respects renewal settings changed since the original event', ! Referral::where( 'provider', 'woo' )->where( 'provider_id', $busy->get_id() )->exists() );
    Utility::updateReferralSettings( [ 'enable_subscription_renewal' => 'yes' ] );
    $busy->set_status( 'refunded' ); $busy->save();
    do_action( 'facr_retry_woo_renewal', ...$retryArgs );
    $check( 'retry does not commission a refunded order', ! Referral::where( 'provider', 'woo' )->where( 'provider_id', $busy->get_id() )->exists() );
    $busy->set_status( 'processing' ); $busy->save();
    do_action( 'facr_retry_woo_renewal', ...$retryArgs );
    do_action( 'facr_retry_woo_renewal', ...$retryArgs );
    $check( 'deferred retries record the paid renewal only once', Referral::where( 'provider', 'woo' )->where( 'provider_id', $busy->get_id() )->count() === 1 );
    $throw = static function () { throw new RuntimeException( 'Test pricing failure' ); };
    $failed = $makeOrder();
    add_filter( 'fluent_affiliate/recurring_commission', $throw, 99 );
    try { ( new WooCompatibility() )->handleSubscriptionRenewal( $subscription, $failed ); } catch ( RuntimeException $error ) {
      if ( $error->getMessage() !== 'Test pricing failure' ) { throw $error; }
    } finally { remove_filter( 'fluent_affiliate/recurring_commission', $throw, 99 ); }
    $runWorkers( [ $failed->get_id() ] );
    $check( 'an exception releases the lock for another worker', Referral::where( 'provider', 'woo' )->where( 'provider_id', $failed->get_id() )->count() === 1 );


    $customer->created_at = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ); $customer->save();
    Utility::updateReferralSettings( [ 'lifetime_expiry_days' => 1 ] );
    $expired = $makeOrder();
    do_action( 'woocommerce_store_api_checkout_order_processed', $expired );
    $check( 'expired lifetime relationships do not create a referral', ! Referral::where( 'provider', 'woo' )->where( 'provider_id', $expired->get_id() )->exists() );
    Utility::updateReferralSettings( [ 'lifetime_expiry_days' => 0 ] );
    $connector = new WooCompatibility();
    $connector->getCurrentAffiliateFromOrder( $parent );
    $check( 'order identity does not leak into subsequent lookups', $connector->getCurrentAffiliate() === null );
    remove_filter( 'fluent_affiliate/checkout_affiliate', [ $lifetime, 'resolveAffiliate' ], 10 );
    $check( 'compatibility does not independently enable lifetime attribution', $connector->getCurrentAffiliateFromOrder( $parent ) === null );
    add_filter( 'fluent_affiliate/checkout_affiliate', [ $lifetime, 'resolveAffiliate' ], 10, 3 );

    $customer->delete();
    $_COOKIE['f_aff'] = $affiliate->id . '|0';
    $linked = $makeOrder( 'no' );
    do_action( 'woocommerce_store_api_checkout_order_processed', $linked );
    $linkedReferral = Referral::where( 'provider', 'woo' )->where( 'provider_id', $linked->get_id() )->first();
    $check( 'a link cookie creates an initial sale and binds the customer', $linkedReferral && $linkedReferral->type === 'sale' && (int) Customer::where( 'user_id', $uid )->first()->by_affiliate_id === (int) $affiliate->id );
    $selfOrder = $makeOrder( 'no', 1, get_userdata( 1 )->user_email );
    do_action( 'woocommerce_store_api_checkout_order_processed', $selfOrder );
    $check( 'native checkout self-referral protection is retained', ! Referral::where( 'provider', 'woo' )->where( 'provider_id', $selfOrder->get_id() )->exists() );
    unset( $_COOKIE['f_aff'] );
    $repeat = $makeOrder( 'no' );
    do_action( 'woocommerce_store_api_checkout_order_processed', $repeat );
    $repeatReferral = Referral::where( 'provider', 'woo' )->where( 'provider_id', $repeat->get_id() )->first();
    $check( 'a customer acquired through a link later earns a lifetime referral without the cookie', $repeatReferral && $repeatReferral->type === 'lifetime_sale' && (float) $repeatReferral->amount === 7.0 );
    $coupon = new WC_Coupon(); $coupon->set_code( 'facr-attribution-' . wp_rand() );
    $coupon->update_meta_data( '_fa_affiliate_id', $affiliate->id ); $coupon->save();
    Fluent::update_option( '_woo_connector_config', [ 'affiliate_on_discount_product' => 'yes', 'enable_subscription_renewal' => 'yes' ] );
    // An expired relationship still permits a coupon attribution, as in Fluent.
    Utility::updateReferralSettings( [ 'lifetime_expiry_days' => 1 ] );
    $bound = Customer::where( 'user_id', $uid )->first();
    $bound->created_at = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ); $bound->save();
    $couponOrder = $makeOrder();
    $item = new WC_Order_Item_Coupon(); $item->set_code( $coupon->get_code() );
    $couponOrder->add_item( $item ); $couponOrder->save();
    $check( 'native coupon attribution takes precedence over expired lifetime fallback', (int) $connector->getCurrentAffiliateFromOrder( $couponOrder )->id === (int) $affiliate->id );

  } finally {
    foreach ( array_reverse( $orders ) as $order ) {
      if ( isset( $subscription ) && $subscription instanceof WC_Subscription ) {
        foreach ( as_get_scheduled_actions( [ 'hook' => 'facr_retry_woo_renewal', 'args' => [ $subscription->get_id(), $order->get_id() ], 'group' => 'fa-commission-rules', 'per_page' => -1 ], 'ids' ) as $actionId ) {
          ActionScheduler::store()->delete_action( $actionId );
        }
      }
      Referral::where( 'provider', 'woo' )->where( 'provider_id', $order->get_id() )->delete(); $order->delete( true );
    }
    if ( $product ) { $product->delete( true ); }
    if ( $coupon ) { $coupon->delete( true ); }
    foreach ( $users as $uid ) { Customer::where( 'user_id', $uid )->delete(); }
    if ( $affiliate ) { $affiliate->delete(); }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ( $users as $uid ) { wp_delete_user( $uid ); }
    Fluent::update_option( FACR_RULES_KEY, $rules );
    Fluent::update_option( '_woo_connector_config', $config );
    if ( $settings === null ) { delete_option( '_fa_referral_settings' ); } else { update_option( '_fa_referral_settings', $settings ); }
    Utility::getReferralSettings( false );
    if ( $cookie === null ) { unset( $_COOKIE['f_aff'] ); } else { $_COOKIE['f_aff'] = $cookie; }
    $wp_filter = $hooks;
  }
})();
