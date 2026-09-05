<?php
declare(strict_types=1);

namespace FACommissionRules;

defined( 'ABSPATH' ) || exit;

/**
 * Human-readable labels for a rule. Shared by the REST API (which hands them to
 * the admin app so no naming logic lives in JavaScript), the affiliate profile
 * card and the affiliate portal card.
 *
 * @package FACommissionRules
 */
final class Labels {
  /**
   * The five labels the admin list and the editor show for one rule.
   *
   * @param array<string,mixed> $rule
   * @return array{scope:string,target:string,rate:string,window:string,sentence:string}
   */
  public static function for_rule( array $rule ): array {
    return [
      'scope'    => self::scope_label( $rule ),
      'target'   => self::target_label( $rule ),
      'rate'     => self::rate_label( $rule ),
      'window'   => self::window_label( $rule ),
      'sentence' => self::describe( $rule ),
    ];
  }

  /** @param array<string,mixed> $rule */
  public static function scope_label( array $rule ): string {
    switch ( $rule['scope_type'] ) {
      case 'affiliate':
        return sprintf(
          /* translators: %s: affiliate name and id */
          __( 'Affiliate: %s', 'fa-commission-rules' ),
          Fluent::affiliate_label( (int) $rule['scope_id'] )
        );
      case 'group':
        return sprintf(
          /* translators: %s: affiliate group name */
          __( 'Group: %s', 'fa-commission-rules' ),
          Fluent::group_name( (int) $rule['scope_id'] )
        );
      default:
        return __( 'Everyone', 'fa-commission-rules' );
    }
  }

  /**
   * One { id, label } per target id, so the editor can pre-fill its pickers
   * without a second lookup. Empty for an all-products rule.
   *
   * @param array<string,mixed> $rule
   * @return array<int,array{id:int,label:string}>
   */
  public static function target_options( array $rule ): array {
    if ( $rule['target_type'] === 'all' ) {
      return [];
    }
    $out = [];
    foreach ( (array) $rule['target_ids'] as $id ) {
      $out[] = [ 'id' => (int) $id, 'label' => self::target_name( (string) $rule['target_type'], (int) $id ) ];
    }
    return $out;
  }

  /** @param array<string,mixed> $rule */
  public static function target_label( array $rule ): string {
    if ( $rule['target_type'] === 'all' ) {
      return __( 'All products', 'fa-commission-rules' );
    }
    $names = [];
    foreach ( (array) $rule['target_ids'] as $id ) {
      $names[] = self::target_name( (string) $rule['target_type'], (int) $id );
    }
    $list = implode( ', ', $names );

    return $rule['target_type'] === 'category'
      ? sprintf(
        /* translators: %s: comma-separated product category names */
        __( 'Category: %s', 'fa-commission-rules' ),
        $list
      )
      : sprintf(
        /* translators: %s: comma-separated product or variation names */
        __( 'Product: %s', 'fa-commission-rules' ),
        $list
      );
  }

  /** @param array<string,mixed> $rule */
  public static function rate_label( array $rule ): string {
    if ( $rule['rate_type'] === 'percentage' ) {
      return rtrim( rtrim( number_format( (float) $rule['rate'], 2, '.', '' ), '0' ), '.' ) . '%';
    }
    return sprintf(
      /* translators: %s: a flat commission amount, per order line, already in store currency */
      __( '%s flat', 'fa-commission-rules' ),
      Fluent::money( (float) $rule['rate'] )
    );
  }

  /** A stored Y-m-d date in the site's own date format. */
  public static function show_date( string $date ): string {
    $stamp = $date !== '' ? strtotime( $date ) : false;
    return $stamp ? date_i18n( (string) get_option( 'date_format' ), $stamp ) : $date;
  }

  /** @param array<string,mixed> $rule */
  public static function window_label( array $rule ): string {
    $starts = self::show_date( (string) $rule['starts_at'] );
    $ends   = self::show_date( (string) $rule['ends_at'] );
    if ( $starts === '' && $ends === '' ) {
      return __( 'Always', 'fa-commission-rules' );
    }
    if ( $starts !== '' && $ends !== '' ) {
      return sprintf(
        /* translators: 1: start date, 2: end date */
        __( '%1$s to %2$s', 'fa-commission-rules' ),
        $starts,
        $ends
      );
    }
    return $starts !== ''
      ? sprintf(
        /* translators: %s: start date */
        __( 'From %s', 'fa-commission-rules' ),
        $starts
      )
      : sprintf(
        /* translators: %s: end date */
        __( 'Until %s', 'fa-commission-rules' ),
        $ends
      );
  }

  /** One plain-language line, reused by the affiliate profile card and the portal. */
  public static function describe( array $rule ): string {
    return sprintf(
      /* translators: 1: rate, 2: what the rate applies to, 3: the date window */
      __( '%1$s on %2$s (%3$s)', 'fa-commission-rules' ),
      self::rate_label( $rule ),
      self::target_label( $rule ),
      self::window_label( $rule )
    );
  }

  /** The store-wide rate an affiliate falls back to when nothing else matches. */
  public static function default_rate_label(): string {
    $rate      = (float) Fluent::referral_setting( 'rate', 0 );
    $rate_type = Fluent::referral_setting( 'rate_type', 'percentage' );
    return $rate_type === 'percentage'
      ? rtrim( rtrim( number_format( $rate, 2, '.', '' ), '0' ), '.' ) . '%'
      : Fluent::money( $rate );
  }

  /** The name of one category term or one product/variation, falling back to "#id". */
  private static function target_name( string $target_type, int $id ): string {
    if ( $target_type === 'category' ) {
      $term = get_term( $id, 'product_cat' );
      return ( $term && ! is_wp_error( $term ) ) ? (string) $term->name : '#' . $id;
    }
    // Same label the rule editor shows, so a variation reads as its variation
    // and not as its parent's title.
    $product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
    $title   = $product ? $product->get_formatted_name() : get_the_title( $id );
    return (string) $title !== '' ? (string) $title : '#' . $id;
  }
}
