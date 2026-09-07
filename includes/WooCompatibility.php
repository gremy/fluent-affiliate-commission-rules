<?php
declare(strict_types=1);

namespace FACommissionRules;

defined( 'ABSPATH' ) || exit;

use FluentAffiliate\App\Models\Referral;
use FluentAffiliatePro\App\Services\Integrations\WooCommerce\Bootstrap;
use FluentAffiliatePro\App\Services\Integrations\WooCommerce\RecurringReferral;

/** WooCommerce compatibility for Fluent Affiliate Pro 1.6.5. No vendor files are changed. */
final class WooCompatibility extends RecurringReferral {
  private array $order_customer = [];

  public static function install(): void {
    global $wp_filter;
    $connector = new self();
    $hooks = [
      'woocommerce_store_api_checkout_order_processed' => [ Bootstrap::class, 'addPendingReferral' ],
      'woocommerce_checkout_order_processed' => [ Bootstrap::class, 'addPendingReferral' ],
      'woocommerce_subscription_renewal_payment_complete' => [ RecurringReferral::class, 'handleSubscriptionRenewal' ],
    ];
    // Replace only callbacks Fluent actually enabled; keep its settings gates and priorities.
    foreach ( $hooks as $hook => [ $class, $method ] ) {
      foreach ( ( $wp_filter[ $hook ]->callbacks ?? [] ) as $priority => $callbacks ) {
        foreach ( $callbacks as $callback ) {
          $fn = $callback['function'];
          if ( is_array( $fn ) && is_object( $fn[0] ) && get_class( $fn[0] ) === $class && $fn[1] === $method ) {
            remove_action( $hook, $fn, $priority );
            add_action( $hook, [ $connector, $method ], $priority, $callback['accepted_args'] );
          }
        }
      }
    }
  }

  public function getCurrentAffiliateFromOrder( $order ) {
    $previous = $this->order_customer;
    $this->order_customer = [ 'user_id' => $order->get_user_id(), 'email' => $order->get_billing_email() ];
    try {
      // The parent retains coupon precedence and cookie/lifetime lookup. Supply the
      // missing identity to every fallback it invokes, then clear this order's context.
      return parent::getCurrentAffiliateFromOrder( $order );
    } finally {
      $this->order_customer = $previous;
    }
  }

  public function getCurrentAffiliate( $customerData = [] ) {
    return parent::getCurrentAffiliate( $customerData ?: $this->order_customer );
  }

  public function handleSubscriptionRenewal( $subscription, $renewalOrder ) {
    if ( ! $renewalOrder instanceof \WC_Order || $this->getExistingReferral( $renewalOrder->get_id() ) ) {
      return;
    }
    $parentOrder = $subscription->get_parent();
    if ( ! $parentOrder ) {
      return;
    }
    $parentReferral = $this->getExistingReferral( $parentOrder->get_id() );
    if ( ! $parentReferral || $parentReferral->type !== 'lifetime_sale' ) {
      return parent::handleSubscriptionRenewal( $subscription, $renewalOrder );
    }

    // The native handler hard-codes type=sale with no parent-lookup filter.
    // Keep this lifetime-only path aligned with 1.6.5; reuse its pricing and persistence.
    $affiliate = Fluent::affiliate( (int) $parentReferral->affiliate_id );
    if ( ! $affiliate || $affiliate->status !== 'active' ) {
      return;
    }
    $maxCount = $this->getMaxRenewalCount( $affiliate );
    if ( $maxCount > 0 && Referral::where( 'parent_id', $parentReferral->id )
      ->where( 'type', 'recurring_sale' )->count() + 1 >= $maxCount ) {
      return;
    }
    $orderData = $this->getFormattedOrderData( $renewalOrder );
    if ( ! $orderData ) {
      return;
    }
    $commission = apply_filters( 'fluent_affiliate/recurring_commission',
      $this->calculateFinalRecurringCommissionAmount( $affiliate, $orderData, 'product_cat' ), [
        'affiliate' => $affiliate,
        'order_data' => $orderData,
        'provider' => $this->provider,
        'vendor_order' => $renewalOrder,
        'parent_referral' => $parentReferral,
      ]
    );
    $items = $orderData['items'];
    $description = $items[0]['title'] ?? __( 'Renewal order', 'fa-commission-rules' );
    if ( count( $items ) > 1 ) {
      /* translators: %d: number of additional order lines */
      $description .= sprintf( _n( ' and %d more item', ' and %d more items', count( $items ) - 1, 'fa-commission-rules' ), count( $items ) - 1 );
    }
    $referral = $this->recordReferral( [
      'affiliate_id' => $affiliate->id,
      'customer_id' => $parentReferral->customer_id,
      'visit_id' => $parentReferral->visit_id,
      'parent_id' => $parentReferral->id,
      /* translators: %s: order item description */
      'description' => sprintf( __( '%s (Renewal)', 'fa-commission-rules' ), $description ),
      'status' => 'unpaid',
      'type' => 'recurring_sale',
      'amount' => $commission,
      'order_total' => $this->calculateOrderTotal( $orderData ),
      'currency' => $renewalOrder->get_currency(),
      'utm_campaign' => $parentReferral->utm_campaign,
      'provider' => $this->provider,
      'provider_id' => $renewalOrder->get_id(),
      'products' => $items,
    ] );
    if ( $referral ) {
      do_action( 'fluent_affiliate/recurring_referral_created', $referral );
      /* translators: %d: Fluent Affiliate referral ID */
      $renewalOrder->add_order_note( sprintf( __( 'Recurring affiliate referral #%d created.', 'fa-commission-rules' ), $referral->id ) );
    }
  }
}
