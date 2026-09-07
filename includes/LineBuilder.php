<?php
declare(strict_types=1);

namespace FACommissionRules;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a referral payload into resolver lines.
 *
 * Fluent's own formatted items carry the parent product id only, so when the
 * provider is WooCommerce and the order can be loaded we rebuild the lines from
 * the order itself to get variation ids and true per-line totals.
 *
 * @package FACommissionRules
 */
final class LineBuilder {
  /** @var array<int,array{ids:int[],depths:array<int,int>}> */
  private static array $term_cache = [];

  /**
   * Fluent's own WooCommerce connector calls itself 'woo'; every other provider
   * (FluentCart, LMS integrations, third-party ones) sends item ids that are not
   * WooCommerce product ids, so their lines must never reach a product_cat lookup.
   *
   * @param array<int,array<string,mixed>> $products Fluent's formatted items.
   * @param mixed $provider
   * @param mixed $provider_id
   * @param float $order_total Used only when the payload carries no items at all.
   * @return array<int,array<string,mixed>>
   */
  public static function build( array $products, $provider, $provider_id, float $order_total ): array {
    $is_woo = (string) $provider === 'woo' && Fluent::has_woo();

    if ( $is_woo && (int) $provider_id > 0 && function_exists( 'wc_get_order' ) ) {
      $order = wc_get_order( (int) $provider_id );
      if ( $order instanceof \WC_Order ) {
        $lines = self::from_order( $order );
        if ( $lines ) {
          return $lines;
        }
      }
    }

    $lines = self::from_products( $products, $is_woo );

    // A payload with no items (a manual referral, a provider that reports only a
    // total) still has to be resolvable, or an "all products" rule would quietly
    // stop applying to it.
    return $lines ? $lines : [ self::whole_order_line( $order_total ) ];
  }

  /**
   * @param array<int,array<string,mixed>> $products
   * @param bool $with_terms Look product_cat terms up. Only ever true for WooCommerce ids.
   * @return array<int,array<string,mixed>>
   */
  public static function from_products( array $products, bool $with_terms = true ): array {
    $lines = [];
    foreach ( $products as $item ) {
      $product_id = (int) ( $item['item_id'] ?? 0 );
      if ( $product_id <= 0 ) {
        continue;
      }
      $lines[] = self::line( $product_id, 0, self::item_total( (array) $item ), $with_terms );
    }
    return $lines;
  }

  /** Catalog probe for rule summaries; it never prices a real order. */
  public static function target_line( array $rule, int $id ): array {
    if ( $rule['target_type'] === 'product' ) {
      $product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
      $parent = $product ? (int) $product->get_parent_id() : 0;
      return self::line( $parent ?: $id, $parent ? $id : 0, 0.0, taxonomy_exists( 'product_cat' ) );
    }
    $line = self::whole_order_line( 0.0 );
    if ( $rule['target_type'] === 'category' ) {
      $line['term_depths'] = [ $id => 0 ];
      foreach ( get_ancestors( $id, 'product_cat', 'taxonomy' ) as $depth => $parent ) {
        $line['term_depths'][ (int) $parent ] = $depth + 1;
      }
      $line['term_ids'] = array_keys( $line['term_depths'] );
    }
    return $line;
  }

  /**
   * The one line that stands in for an entire order when no items are known.
   *
   * @return array<string,mixed>
   */
  public static function whole_order_line( float $order_total ): array {
    return [
      'product_id'   => 0,
      'variation_id' => 0,
      'total'        => max( 0.0, $order_total ),
      'term_ids'     => [],
      'term_depths'  => [],
    ];
  }

  /**
   * @param \WC_Order $order
   * @return array<int,array<string,mixed>>
   */
  public static function from_order( $order ): array {
    $lines = [];
    foreach ( $order->get_items() as $item ) {
      if ( ! method_exists( $item, 'get_product_id' ) ) {
        continue;
      }
      $product_id = (int) $item->get_product_id();
      if ( $product_id <= 0 ) {
        continue;
      }
      $lines[] = self::line(
        $product_id,
        (int) $item->get_variation_id(),
        // WC_Order_Item_Product::get_total() is already net of discounts and has
        // no shipping component of its own, so those two terms are simply absent.
        self::item_total(
          [
            'subtotal' => (float) $item->get_total(),
            'tax'      => (float) $item->get_total_tax(),
          ]
        ),
        true
      );
    }
    return $lines;
  }

  /**
   * BaseConnector::calculateOrderTotal(), term for term:
   * subtotal, plus shipping unless excluded, plus tax unless excluded, minus
   * discount, floored at 0. The method is public on their connector but only
   * reachable on an instance we do not hold, hence the local copy — and it is
   * copied in full rather than reduced, because other integrations do put real
   * `shipping` and `discount` keys on their items.
   *
   * @param array<string,mixed> $totals Any of subtotal, tax, shipping, discount.
   */
  public static function item_total( array $totals ): float {
    $total = (float) ( $totals['subtotal'] ?? 0 );

    if ( ! Fluent::excludes_shipping() ) {
      $total += (float) ( $totals['shipping'] ?? 0 );
    }
    if ( ! Fluent::excludes_tax() ) {
      $total += (float) ( $totals['tax'] ?? 0 );
    }

    $total -= (float) ( $totals['discount'] ?? 0 );

    return max( 0.0, $total );
  }

  /**
   * Direct product_cat terms plus every ancestor, with the distance from the product.
   *
   * @return array{ids:int[],depths:array<int,int>}
   */
  public static function term_map( int $product_id ): array {
    if ( isset( self::$term_cache[ $product_id ] ) ) {
      return self::$term_cache[ $product_id ];
    }

    $depths = [];
    $direct = wp_get_object_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
    if ( ! is_wp_error( $direct ) ) {
      foreach ( array_map( 'intval', $direct ) as $term_id ) {
        if ( ! isset( $depths[ $term_id ] ) || $depths[ $term_id ] > 0 ) {
          $depths[ $term_id ] = 0;
        }
        // get_ancestors() returns the immediate parent first, then upwards.
        foreach ( array_values( array_map( 'intval', get_ancestors( $term_id, 'product_cat', 'taxonomy' ) ) ) as $step => $ancestor_id ) {
          $depth = $step + 1;
          if ( ! isset( $depths[ $ancestor_id ] ) || $depths[ $ancestor_id ] > $depth ) {
            $depths[ $ancestor_id ] = $depth;
          }
        }
      }
    }

    self::$term_cache[ $product_id ] = [
      'ids'    => array_map( 'intval', array_keys( $depths ) ),
      'depths' => $depths,
    ];

    return self::$term_cache[ $product_id ];
  }

  /** @return array<string,mixed> */
  private static function line( int $product_id, int $variation_id, float $total, bool $with_terms ): array {
    $terms = $with_terms ? self::term_map( $product_id ) : [ 'ids' => [], 'depths' => [] ];
    return [
      'product_id'   => $product_id,
      'variation_id' => $variation_id,
      'total'        => $total,
      'term_ids'     => $terms['ids'],
      'term_depths'  => $terms['depths'],
    ];
  }
}
