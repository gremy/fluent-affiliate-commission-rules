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
  /** Referral types this handler prices. Renewals are priced by the recurring filter. */
  private const TYPES = [ 'sale', 'payment', 'lifetime_sale' ];

  /**
   * Renewal resolutions parked between the two filters, keyed "affiliate:order".
   * A renewal is priced before its referral row exists, so the stamp can only be
   * written on the second pass.
   *
   * @var array<string,array<string,mixed>>
   */
  private static $renewal_handoff = [];

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
    if ( $type === 'recurring_sale' ) {
      // Priced already, by the recurring filter: all this pass adds is the audit
      // trail that filter had no referral row to write on.
      return $this->apply_renewal_handoff( $data );
    }
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
      [ 'affiliate_id' => (int) $affiliate->id, 'group_id' => (int) $affiliate->group_id,
        'customer_type' => LineBuilder::customer_type( $provider, $data['provider_id'] ?? 0 ) ],
      $order_total,
      $lines,
      $this->rules_for_provider( $provider, $type === 'lifetime_sale' ? 'lifetime' : 'sale' ),
      current_time( 'Y-m-d' ),
      $this->base_commission_for( $affiliate, $type, $order_total )
    );

    if ( $result === null ) {
      return $data; // Not our affiliate: Fluent's own math stands.
    }

    $data['amount'] = $result['amount'];

    return $this->annotate( $data, $result );
  }

  /**
   * The audit trail every touched referral carries: the stamp in settings, plus a
   * short tail on the description, which is the only field the CSV export shows.
   *
   * @param array<string,mixed> $data
   * @param array<string,mixed> $result
   * @return array<string,mixed>
   */
  private function annotate( array $data, array $result ): array {
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
   * Stamp a renewal referral from the resolution its own filter parked earlier.
   * The amount is deliberately left alone — it already carries this resolution,
   * and recomputing it here would price the renewal twice.
   *
   * @param array<string,mixed> $data
   * @return array<string,mixed>
   */
  private function apply_renewal_handoff( array $data ): array {
    $key = (int) ( $data['affiliate_id'] ?? 0 ) . ':' . (int) ( $data['provider_id'] ?? 0 );
    if ( ! isset( self::$renewal_handoff[ $key ] ) ) {
      return $data;
    }
    $result = self::$renewal_handoff[ $key ];
    unset( self::$renewal_handoff[ $key ] );

    return $this->annotate( $data, $result );
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
    $rules = $context === 'lifetime' ? Store::all() : Store::resolvable( $context );
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

    // Fluent's own $amount is already blended: its renewal rate table prices the
    // lines it matches and adds the base rate on the rest. Store::resolvable()
    // feeds those same rows to the resolver, so prorating $amount would pay every
    // matched line a second time. The base rate itself is asked for instead.
    $base = function ( float $remainder ) use ( $affiliate, $order_total, $amount ): float {
      if ( $remainder <= 0 || $order_total <= 0 ) {
        return 0.0;
      }
      return max( 0.0, $this->renewal_base( $affiliate, $order_total, $amount ) * ( $remainder / $order_total ) );
    };

    $result = Resolver::resolve(
      [ 'affiliate_id' => (int) $affiliate->id, 'group_id' => (int) ( $affiliate->group_id ?? 0 ),
        'customer_type' => LineBuilder::customer_type( $provider, $order instanceof \WC_Order ? $order : $this->renewal_order_id( $context ) ) ],
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

    // The referral row is built after this filter returns, so park the resolution
    // for filter_referral_data() to stamp. Keyed by order as well as affiliate, so
    // two renewals in one request cannot pick up each other's.
    $order_id = $this->renewal_order_id( $context );
    if ( $order_id > 0 ) {
      self::$renewal_handoff[ (int) $affiliate->id . ':' . $order_id ] = $result;
    }

    if ( $is_array ) {
      $commission['amount'] = $result['amount'];
      return $commission;
    }

    return (float) $result['amount'];
  }

  /**
   * Fluent's own renewal base commission for the whole order.
   *
   * @param object $affiliate
   * @param float  $blended Fluent's already-blended figure, the fallback.
   */
  private function renewal_base( $affiliate, float $order_total, float $blended ): float {
    $class = '\\FluentAffiliatePro\\App\\Services\\Integrations\\WooCommerce\\RecurringReferral';
    if ( class_exists( $class ) ) {
      // getBaseRenewalCommission() is public on RecurringCommissionTrait and reads
      // only the group or global renewal rate, so the WooCommerce host class answers
      // for every provider. Nothing in its hierarchy declares a constructor — hooks
      // are added in register() — so instantiating it here is inert.
      return (float) ( new $class() )->getBaseRenewalCommission( $affiliate, $order_total );
    }

    // ponytail: no Pro means no renewal rate table to read — and no renewals either,
    // since Pro owns that integration. The ceiling of this fallback is the bug it
    // replaces: it double-pays any line Fluent's own renewal table already priced.
    return $blended;
  }

  /**
   * The renewal order's id, which is what Fluent writes as provider_id.
   *
   * @param array<string,mixed> $context
   */
  private function renewal_order_id( array $context ): int {
    $order = $context['vendor_order'] ?? null;
    if ( $order instanceof \WC_Order ) {
      return (int) $order->get_id();
    }
    return (int) ( ( (array) ( $context['order_data'] ?? [] ) )['id'] ?? 0 );
  }

  /**
   * The rate Fluent would have used, applied to whatever no rule claimed.
   *
   * Preserve the add-on's existing policy: prorate the whole-order base onto
   * the unclaimed share. For percentage rates this equals base(remainder);
   * for flat rates it intentionally pays only that share of the flat amount.
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
    // Preserve calculation precision. The final amount and adjustment explain
    // how the unrounded components become the amount written to the referral.
    $raw = array_sum( array_column( $result['lines'], 'commission' ) ) + $result['remainder_commission'];
    $settings['fa_commission_rules'] = [
      'version'   => Store::STAMP_VERSION,
      'customer_type' => $result['customer_type'] ?? '',
      'amount'    => $result['amount'],
      'rounding_adjustment' => $result['amount'] - $raw,
      'lines'     => $result['lines'],
      'remainder' => [
        'total'      => $result['remainder_total'],
        'commission' => $result['remainder_commission'],
      ],
    ];
    return $settings;
  }

  /**
   * "1/2 · 10%", or "1/2 · 50f" for a flat rate — short enough for the description
   * column and the CSV export,
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
        // A trailing "f" so a flat 50 cannot be read as 50%.
        : rtrim( rtrim( number_format( (float) $line['rate'], 2, '.', '' ), '0' ), '.' ) . 'f';
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
