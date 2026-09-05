<?php
declare(strict_types=1);

namespace FACommissionRules;

defined( 'ABSPATH' ) || exit;

/**
 * Where the money is actually written.
 *
 * Priority 20 on referral_data matters: Fluent Affiliate Pro's lifetime handler
 * hooks the same filter at 10 and replaces the amount, so anything earlier is
 * overwritten on lifetime orders.
 *
 * @package FACommissionRules
 */
final class ReferralHooks {
  /** Referral types this handler owns. Renewals are handled by the recurring filter. */
  private const TYPES = [ 'sale', 'payment', 'lifetime_sale' ];

  public function register(): void {
    add_filter( 'fluent_affiliate/referral_data', [ $this, 'filter_referral_data' ], 20, 2 );
    add_filter( 'fluent_affiliate/recurring_commission', [ $this, 'filter_recurring_commission' ], 20, 2 );
    add_filter( 'fluent_affiliate/ignore_zero_amount_referral', [ $this, 'ignore_zero_amount' ], 20, 2 );
  }

  /**
   * @param array<string,mixed> $data
   * @param mixed $provider
   * @return array<string,mixed>
   */
  public function filter_referral_data( $data, $provider = '' ) {
    if ( ! is_array( $data ) ) {
      return $data;
    }
    $type = (string) ( $data['type'] ?? 'sale' );
    if ( ! in_array( $type, self::TYPES, true ) ) {
      return $data;
    }

    $affiliate = Fluent::affiliate( (int) ( $data['affiliate_id'] ?? 0 ) );
    if ( ! $affiliate ) {
      return $data;
    }

    $order_total = (float) ( $data['order_total'] ?? 0 );
    $lines       = LineBuilder::build(
      (array) ( $data['products'] ?? [] ),
      $provider,
      $data['provider_id'] ?? 0,
      $order_total
    );

    $result = Resolver::resolve(
      [ 'affiliate_id' => (int) $affiliate->id, 'group_id' => (int) $affiliate->group_id ],
      $order_total,
      $lines,
      $this->rules_for_provider( $provider, 'sale' ),
      current_time( 'Y-m-d' ),
      $this->base_commission_for( $affiliate, $type, $order_total )
    );

    if ( $result === null ) {
      return $data; // Not our affiliate: Fluent's own math stands.
    }

    $data['amount']   = $result['amount'];
    $data['settings'] = $this->stamp( (array) ( $data['settings'] ?? [] ), $result );

    $summary = self::summary( $result );
    if ( $summary !== '' ) {
      // Deliberately untranslated: this tail is machine-readable shorthand that
      // ends up in Fluent's CSV export, where a locale-dependent string would
      // make two exports of the same data impossible to diff.
      $data['description'] = trim( (string) ( $data['description'] ?? '' ) ) . ' · rules: ' . $summary;
    }

    return $data;
  }

  /**
   * Fluent silently drops a sale, renewal or lifetime referral whose amount is
   * <= 0. A rule that deliberately says 0% is a decision, not noise — without
   * this the order would vanish from the affiliate's own report.
   *
   * @param mixed $ignore
   * @param array<string,mixed> $data
   * @return mixed
   */
  public function ignore_zero_amount( $ignore, $data = [] ) {
    if ( is_array( $data ) && isset( $data['settings']['fa_commission_rules'] ) ) {
      return false;
    }
    return $ignore;
  }

  /**
   * The rules that may price this payload.
   *
   * Only WooCommerce items carry real product ids; every other provider sends
   * ids from its own namespace, where a product or category target would match
   * by coincidence at best. Those rules are dropped before resolution rather
   * than left to a lookup that would also pollute the term cache.
   *
   * @param mixed $provider
   * @param string $context 'sale' or 'renewal'
   * @return array<int,array<string,mixed>>
   */
  private function rules_for_provider( $provider, string $context ): array {
    $rules = Store::resolvable( $context );
    if ( (string) $provider === 'woo' ) {
      return $rules;
    }
    return array_values(
      array_filter( $rules, static fn( array $rule ): bool => (string) $rule['target_type'] === 'all' )
    );
  }

  /**
   * WooCommerce Subscriptions renewals. Installed Fluent 1.6.5 passes a float;
   * the docs describe an array with an `amount` key. Both are handled.
   *
   * @param mixed $commission
   * @param array<string,mixed> $context
   * @return mixed same shape as it arrived in
   */
  public function filter_recurring_commission( $commission, $context = [] ) {
    $is_array = is_array( $commission );
    $amount   = $is_array ? (float) ( $commission['amount'] ?? 0 ) : (float) $commission;

    $affiliate = $context['affiliate'] ?? null;
    if ( ! is_object( $affiliate ) || ! isset( $affiliate->id ) ) {
      return $commission;
    }

    $order_data  = (array) ( $context['order_data'] ?? [] );
    $order_total = (float) ( $order_data['referral_order_total'] ?? 0 );
    if ( $order_total <= 0 ) {
      return $commission;
    }

    $provider = (string) ( $context['provider'] ?? '' );
    $is_woo   = $provider === 'woo' && Fluent::has_woo();

    $order = $context['vendor_order'] ?? null;
    $lines = ( $is_woo && $order instanceof \WC_Order )
      ? LineBuilder::from_order( $order )
      : LineBuilder::from_products( (array) ( $order_data['items'] ?? [] ), $is_woo );
    if ( ! $lines ) {
      $lines = [ LineBuilder::whole_order_line( $order_total ) ];
    }

    // ponytail: the renewal base rate lives on getBaseRenewalCommission(), which is
    // public but only reachable on a RecurringReferral instance whose construction
    // registers hooks. Scaling Fluent's own figure by remainder/total is exact for a
    // percentage base rate and only approximate for a flat one — the documented ceiling.
    $base = static fn( float $remainder ): float => $amount * ( $remainder / $order_total );

    $result = Resolver::resolve(
      [ 'affiliate_id' => (int) $affiliate->id, 'group_id' => (int) ( $affiliate->group_id ?? 0 ) ],
      $order_total,
      $lines,
      // Fluent keeps a separate global rate table for renewals; reading the sale
      // one here would apply rates the store owner set for a different event.
      $this->rules_for_provider( $provider, 'renewal' ),
      current_time( 'Y-m-d' ),
      $base
    );

    if ( $result === null ) {
      return $commission;
    }

    if ( $is_array ) {
      $commission['amount'] = $result['amount'];
      return $commission;
    }

    return (float) $result['amount'];
  }

  /**
   * The rate Fluent would have used, applied to whatever no rule claimed.
   *
   * ponytail: a base rate is a per-ORDER figure, not a per-remainder one.
   * Affiliate::getCommission() returns the bare flat rate and ignores the amount
   * entirely when the rate type is flat or fixed, so asking it again for the
   * remainder would pay the whole flat amount a second time on top of our rule
   * lines. The base is therefore taken once on the full order and prorated onto
   * the remainder's share: algebraically identical to base(remainder) for a
   * percentage rate, and the only correct reading of a flat one. It also covers
   * flat GROUP and GLOBAL rates, whose rate type Fluent does not expose — the
   * lifetime lookup that would tell us is private on its trait.
   *
   * @param object $affiliate
   * @return callable(float):float
   */
  private function base_commission_for( $affiliate, string $type, float $order_total ): callable {
    return function ( float $remainder ) use ( $affiliate, $type, $order_total ): float {
      if ( $order_total <= 0 || $remainder <= 0 ) {
        return 0.0;
      }
      $full = $this->whole_order_base( $affiliate, $type, $order_total );
      return max( 0.0, $full * ( $remainder / $order_total ) );
    };
  }

  /**
   * Fluent's own commission for the whole order, by referral type.
   *
   * @param object $affiliate
   */
  private function whole_order_base( $affiliate, string $type, float $order_total ): float {
    if ( $type === 'lifetime_sale' && class_exists( '\FluentAffiliatePro\App\Hooks\Handlers\LifetimeCommissionHandler' ) ) {
      // getBaseLifetimeCommission() is public on LifetimeCommissionTrait and its
      // host handler has a default constructor, so we call Fluent's own code
      // rather than cloning the group-override lookup.
      $handler = new \FluentAffiliatePro\App\Hooks\Handlers\LifetimeCommissionHandler();
      return (float) $handler->getBaseLifetimeCommission( $affiliate, $order_total );
    }

    return (float) $affiliate->getCommission( $order_total, 'sale' );
  }

  /**
   * @param array<string,mixed> $settings
   * @param array<string,mixed> $result
   * @return array<string,mixed>
   */
  private function stamp( array $settings, array $result ): array {
    // Rounded here, at the boundary, so the stamp a human reads actually adds up
    // to the amount that was written.
    $lines = [];
    foreach ( $result['lines'] as $line ) {
      $line['commission'] = round( (float) $line['commission'], 2 );
      $lines[]            = $line;
    }

    $settings['fa_commission_rules'] = [
      'version'   => Store::STAMP_VERSION,
      'lines'     => $lines,
      'remainder' => [
        'total'      => round( (float) $result['remainder_total'], 2 ),
        'commission' => round( (float) $result['remainder_commission'], 2 ),
      ],
    ];
    return $settings;
  }

  /**
   * "1/2 · 10%" — short enough for the description column and the CSV export,
   * which is the only export either plugin exposes. Deliberately untranslated:
   * it is machine-readable shorthand, and a locale-dependent one would make two
   * exports of the same data impossible to diff.
   *
   * @param array<string,mixed> $result
   */
  public static function summary( array $result ): string {
    $matched = 0;
    $rates   = [];
    foreach ( $result['lines'] as $line ) {
      if ( $line['rule_id'] === null ) {
        continue;
      }
      $matched++;
      $rates[] = $line['rate_type'] === 'percentage'
        ? rtrim( rtrim( number_format( (float) $line['rate'], 2, '.', '' ), '0' ), '.' ) . '%'
        : rtrim( rtrim( number_format( (float) $line['rate'], 2, '.', '' ), '0' ), '.' );
    }
    if ( $matched === 0 ) {
      return '';
    }
    return sprintf(
      '%1$d/%2$d · %3$s',
      $matched,
      count( $result['lines'] ),
      implode( ', ', array_values( array_unique( $rates ) ) )
    );
  }
}
