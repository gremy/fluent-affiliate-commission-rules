<?php
declare(strict_types=1);

namespace FACommissionRules;

defined( 'ABSPATH' ) || exit;

/**
 * Most-specific-wins commission resolution. Exactly one rule wins each order
 * line; rules never stack. Pure PHP on purpose: no WordPress calls, so it runs
 * under bare `php` in tests and can be reasoned about in isolation.
 *
 * score = scope * 100 + customer * 10 + target
 *   customer: B2B/B2C 1 > any 0
 *   scope:  affiliate 3 > group 2 > everyone 1
 *   target: product 3 > category 2 > all 1
 * Tied category rules: the closest matched term wins (0 = directly assigned).
 * Still tied: the newest created_at wins.
 *
 * @package FACommissionRules
 */
final class Resolver {
  /**
   * Rules that are active, inside their window at $now, and in scope for this affiliate.
   *
   * @param array<int,array<string,mixed>> $rules
   * @param array{affiliate_id:int,group_id:int,customer_type?:string} $context
   * @param string $now Y-m-d
   * @return array<int,array<string,mixed>>
   */
  public static function applicable( array $rules, array $context, string $now ): array {
    $affiliate_id = (int) ( $context['affiliate_id'] ?? 0 );
    $group_id     = (int) ( $context['group_id'] ?? 0 );

    return array_values(
      array_filter(
        $rules,
        static function ( array $rule ) use ( $affiliate_id, $group_id, $now, $context ): bool {
          if ( ( $rule['status'] ?? 'active' ) !== 'active' ) {
            return false;
          }
          $customer_type = (string) ( $rule['customer_type'] ?? 'all' );
          if ( $customer_type !== 'all' && $customer_type !== ( $context['customer_type'] ?? '' ) ) {
            return false;
          }
          $starts = (string) ( $rule['starts_at'] ?? '' );
          $ends   = (string) ( $rule['ends_at'] ?? '' );
          if ( $starts !== '' && $now < $starts ) {
            return false;
          }
          if ( $ends !== '' && $now > $ends ) {
            return false;
          }
          switch ( (string) ( $rule['scope_type'] ?? 'all' ) ) {
            case 'affiliate':
              return (int) $rule['scope_id'] === $affiliate_id;
            case 'group':
              return $group_id > 0 && (int) $rule['scope_id'] === $group_id;
            default:
              return true;
          }
        }
      )
    );
  }

  /**
   * @param array{affiliate_id:int,group_id:int,customer_type?:string} $context
   * @param float $order_total Fluent's commissionable order total.
   * @param array<int,array<string,mixed>> $lines
   * @param array<int,array<string,mixed>> $rules
   * @param string $now Y-m-d
   * @param callable(float):float $base_commission Fluent's own rate, applied to the remainder.
   * @return array<string,mixed>|null null when no rule of ours engages — caller must not touch the amount.
   */
  public static function resolve( array $context, float $order_total, array $lines, array $rules, string $now, callable $base_commission ): ?array {
    $candidates = self::applicable( $rules, $context, $now );
    if ( ! $candidates ) {
      return null;
    }

    $engaged      = false;
    $matched_sum  = 0.0;
    $commission   = 0.0;
    $out_lines    = [];

    foreach ( $lines as $line ) {
      $winner = self::winning_rule( $line, $candidates );

      if ( $winner === null ) {
        $out_lines[] = [
          'product_id'   => (int) ( $line['product_id'] ?? 0 ),
          'variation_id' => (int) ( $line['variation_id'] ?? 0 ),
          'total'        => (float) ( $line['total'] ?? 0 ),
          'rule_id'      => null,
          'rate'         => null,
          'rate_type'    => null,
          'commission'   => 0.0,
        ];
        continue;
      }

      if ( empty( $winner['readonly'] ) ) {
        $engaged = true;
      }

      $line_total      = (float) ( $line['total'] ?? 0 );
      // Fluent pays its native flat row even on a zero-total item.
      $line_commission = ! empty( $winner['readonly'] ) && $winner['rate_type'] !== 'percentage'
        ? max( 0.0, (float) $winner['rate'] )
        : self::line_commission( $line_total, (float) $winner['rate'], (string) $winner['rate_type'] );
      $matched_sum    += max( 0.0, $line_total );
      $commission     += $line_commission;

      $out_lines[] = [
        'product_id'   => (int) ( $line['product_id'] ?? 0 ),
        'variation_id' => (int) ( $line['variation_id'] ?? 0 ),
        'total'        => $line_total,
        'rule_id'      => (string) $winner['id'],
        'rate'         => (float) $winner['rate'],
        'rate_type'    => (string) $winner['rate_type'],
        'commission'   => $line_commission,
      ];
    }

    if ( ! $engaged ) {
      return null; // Fluent's own global rows alone are not a reason to rewrite money.
    }

    // Mirrors Fluent: the remainder is the order total minus the matched lines,
    // so shipping and anything else the line items miss keeps the base rate.
    $remainder_total      = max( 0.0, $order_total - $matched_sum );
    $remainder_commission = $remainder_total > 0 ? max( 0.0, (float) $base_commission( $remainder_total ) ) : 0.0;

    // Rounded once, here: this is the boundary where the engine hands real money
    // to Fluent. Every intermediate stays full precision so a long order does not
    // accumulate rounding error line by line.
    return [
      'customer_type'        => (string) ( $context['customer_type'] ?? '' ),
      'amount'               => round( $commission + $remainder_commission, 2 ),
      'lines'                => $out_lines,
      'remainder_total'      => $remainder_total,
      'remainder_commission' => $remainder_commission,
    ];
  }

  /**
   * Rules with potentially payable coverage, in payout priority order.
   * Probe each target separately so overlapping ID lists can be trimmed.
   * The optional catalog probe includes parent/variation IDs and category ancestry.
   * Cards qualify remaining coverage because other targets can still overlap.
   */
  public static function effective( array $rules, array $context, string $now, ?callable $target_line = null ): array {
    if ( ! array_key_exists( 'customer_type', $context ) ) {
      // A rate card has no order: keep coverage that can win in any customer segment.
      $out = [];
      foreach ( [ 'b2b', 'b2c', '' ] as $customer_type ) {
        foreach ( self::effective( $rules, array_merge( $context, [ 'customer_type' => $customer_type ] ), $now, $target_line ) as $rule ) {
          $id = $rule['id'];
          $rule['target_ids'] = array_values( array_unique( array_merge( $out[ $id ]['target_ids'] ?? [], $rule['target_ids'] ) ) );
          $out[ $id ] = $rule;
        }
      }
      return array_values( $out );
    }
    $candidates = self::applicable( $rules, $context, $now );
    $out = [];
    foreach ( $candidates as $rule ) {
      $ids = $rule['target_type'] === 'all' ? [ 0 ] : $rule['target_ids'];
      $kept = [];
      foreach ( $ids as $id ) {
        $line = [
          'product_id' => $rule['target_type'] === 'product' ? (int) $id : 0,
          'term_ids' => $rule['target_type'] === 'category' ? [ (int) $id ] : [],
          'term_depths' => $rule['target_type'] === 'category' ? [ (int) $id => 0 ] : [],
        ];
        if ( $target_line ) {
          $line = $target_line( $rule, (int) $id );
        }
        $winner = self::winning_rule( $line, $candidates );
        if ( $winner && $winner['id'] === $rule['id'] ) {
          $kept[] = (int) $id;
        }
      }
      if ( $kept ) {
        $rule['target_ids'] = $rule['target_type'] === 'all' ? [] : $kept;
        $out[] = $rule;
      }
    }
    usort( $out, static function ( array $a, array $b ): int {
      if ( ! empty( $a['readonly'] ) || ! empty( $b['readonly'] ) ) {
        // Native rows keep their original order after our overrides.
        return (int) ! empty( $a['readonly'] ) <=> (int) ! empty( $b['readonly'] );
      }
      return self::score( $b ) <=> self::score( $a )
        ?: strcmp( (string) $b['created_at'], (string) $a['created_at'] );
    } );
    return $out;
  }

  public static function line_commission( float $total, float $rate, string $rate_type ): float {
    if ( $rate_type !== 'percentage' ) {
      // A flat rate is paid per line actually sold. A line that totals nothing —
      // a fully discounted item, a free gift, a refunded line — sold nothing, so
      // it earns nothing; paying the flat amount there is money out of thin air.
      return $total > 0 ? max( 0.0, $rate ) : 0.0;
    }
    return max( 0.0, ( $total * $rate ) / 100 );
  }

  /**
   * Which rules would be overridden by a narrower one, for the admin list badge.
   *
   * @param array<int,array<string,mixed>> $rules
   * @return array<string,string|null> rule id => id of the narrowest rule that overrides it
   */
  public static function shadow_map( array $rules ): array {
    $map = [];
    foreach ( $rules as $rule ) {
      $best       = null;
      $best_score = -1;
      foreach ( $rules as $other ) {
        if ( ! empty( $other['readonly'] ) || (string) $other['id'] === (string) $rule['id'] || ( $other['status'] ?? 'active' ) !== 'active' ) {
          continue;
        }
        if ( self::score( $other ) <= self::score( $rule ) ) {
          continue;
        }
        if ( ! self::scope_overlaps( $rule, $other ) || ! self::target_overlaps( $rule, $other ) ) {
          continue;
        }
        if ( ! self::customer_types_overlap( $rule, $other ) || ! self::windows_overlap( $rule, $other ) ) {
          continue;
        }
        if ( self::score( $other ) > $best_score ) {
          $best       = (string) $other['id'];
          $best_score = self::score( $other );
        }
      }
      $map[ (string) $rule['id'] ] = $best;
    }
    return $map;
  }

  /**
   * Rules of exactly equal specificity that could both win the same line for the
   * same audience — the case where "newest wins" is the only thing deciding real
   * money, which is worth saying out loud in the admin list.
   *
   * @param array<int,array<string,mixed>> $rules
   * @return array<string,string[]> rule id => the ids it ties with
   */
  public static function tie_map( array $rules ): array {
    $map = [];
    foreach ( $rules as $rule ) {
      $id         = (string) $rule['id'];
      $map[ $id ] = [];
      if ( ! empty( $rule['readonly'] ) || ( $rule['status'] ?? 'active' ) !== 'active' ) {
        continue;
      }
      foreach ( $rules as $other ) {
        if ( ! empty( $other['readonly'] ) || (string) $other['id'] === $id || ( $other['status'] ?? 'active' ) !== 'active' ) {
          continue;
        }
        if ( self::score( $other ) !== self::score( $rule ) ) {
          continue;
        }
        if ( (string) $rule['scope_type'] !== (string) $other['scope_type']
          || (int) $rule['scope_id'] !== (int) $other['scope_id']
          || (string) $rule['target_type'] !== (string) $other['target_type'] ) {
          continue;
        }
        if ( (string) $rule['target_type'] !== 'all'
          && ! array_intersect(
            array_map( 'intval', (array) $rule['target_ids'] ),
            array_map( 'intval', (array) $other['target_ids'] )
          ) ) {
          continue;
        }
        if ( ! self::customer_types_overlap( $rule, $other ) || ! self::windows_overlap( $rule, $other ) ) {
          continue;
        }
        $map[ $id ][] = (string) $other['id'];
      }
    }
    return $map;
  }

  private static function customer_types_overlap( array $a, array $b ): bool {
    $a_type = $a['customer_type'] ?? 'all';
    $b_type = $b['customer_type'] ?? 'all';
    return $a_type === 'all' || $b_type === 'all' || $a_type === $b_type;
  }

  /**
   * Do two rules' date windows share at least one day? An empty bound is open.
   *
   * @param array<string,mixed> $a
   * @param array<string,mixed> $b
   */
  private static function windows_overlap( array $a, array $b ): bool {
    $a_start = (string) ( $a['starts_at'] ?? '' ) !== '' ? (string) $a['starts_at'] : '0000-01-01';
    $a_end   = (string) ( $a['ends_at'] ?? '' ) !== '' ? (string) $a['ends_at'] : '9999-12-31';
    $b_start = (string) ( $b['starts_at'] ?? '' ) !== '' ? (string) $b['starts_at'] : '0000-01-01';
    $b_end   = (string) ( $b['ends_at'] ?? '' ) !== '' ? (string) $b['ends_at'] : '9999-12-31';

    return $a_start <= $b_end && $b_start <= $a_end;
  }

  /**
   * @param array<string,mixed> $line
   * @param array<int,array<string,mixed>> $candidates
   * @return array<string,mixed>|null
   */
  private static function winning_rule( array $line, array $candidates ): ?array {
    $winner = null;
    $native = null;
    $best   = [ 'score' => -1, 'depth' => PHP_INT_MAX, 'created_at' => '' ];

    foreach ( $candidates as $rule ) {
      if ( ! empty( $rule['readonly'] ) ) {
        // Native Woo rows use parent product IDs and directly assigned terms,
        // with the first matching row winning regardless of target specificity.
        $native_line = $line;
        $native_line['variation_id'] = 0;
        $native_line['term_ids'] = array_values( array_filter(
          (array) ( $line['term_ids'] ?? [] ),
          static fn( $id ): bool => (int) ( $line['term_depths'][ $id ] ?? 0 ) === 0
        ) );
        if ( $native === null && self::match_depth( $native_line, $rule ) !== null ) {
          $native = $rule;
        }
        continue;
      }
      $depth = self::match_depth( $line, $rule );
      if ( $depth === null ) {
        continue;
      }
      $score      = self::score( $rule );
      $created_at = (string) ( $rule['created_at'] ?? '' );

      $better = $score > $best['score']
        || ( $score === $best['score'] && $depth < $best['depth'] )
        || ( $score === $best['score'] && $depth === $best['depth'] && strcmp( $created_at, $best['created_at'] ) > 0 );

      if ( $better ) {
        $winner = $rule;
        $best   = [ 'score' => $score, 'depth' => $depth, 'created_at' => $created_at ];
      }
    }

    return $winner ?? $native;
  }

  /**
   * null when the rule does not target this line; otherwise the term distance
   * (0 for a directly assigned category, 0 for product and all targets).
   *
   * @param array<string,mixed> $line
   * @param array<string,mixed> $rule
   */
  private static function match_depth( array $line, array $rule ): ?int {
    switch ( (string) ( $rule['target_type'] ?? 'all' ) ) {
      case 'product':
        $ids          = array_map( 'intval', (array) ( $rule['target_ids'] ?? [] ) );
        $product_id   = (int) ( $line['product_id'] ?? 0 );
        $variation_id = (int) ( $line['variation_id'] ?? 0 );
        if ( ( $variation_id > 0 && in_array( $variation_id, $ids, true ) ) || in_array( $product_id, $ids, true ) ) {
          return 0;
        }
        return null;

      case 'category':
        $ids    = array_map( 'intval', (array) ( $rule['target_ids'] ?? [] ) );
        $depths = (array) ( $line['term_depths'] ?? [] );
        $best   = null;
        foreach ( array_map( 'intval', (array) ( $line['term_ids'] ?? [] ) ) as $term_id ) {
          if ( ! in_array( $term_id, $ids, true ) ) {
            continue;
          }
          $depth = (int) ( $depths[ $term_id ] ?? 0 );
          if ( $best === null || $depth < $best ) {
            $best = $depth;
          }
        }
        return $best;

      default:
        return 0;
    }
  }

  /** @param array<string,mixed> $rule */
  private static function score( array $rule ): int {
    $scope = [ 'affiliate' => 3, 'group' => 2, 'all' => 1 ][ (string) ( $rule['scope_type'] ?? 'all' ) ] ?? 1;
    $target = [ 'product' => 3, 'category' => 2, 'all' => 1 ][ (string) ( $rule['target_type'] ?? 'all' ) ] ?? 1;
    $customer = ( $rule['customer_type'] ?? 'all' ) === 'all' ? 0 : 1;
    return $scope * 100 + $customer * 10 + $target;
  }

  /**
   * Could $other apply wherever $rule applies?
   *
   * Only two cases are certain: one of them applies to everyone, or both name the
   * same audience. Group membership is not knowable from the rules alone, so an
   * affiliate rule is NOT reported as overriding a group rule — telling an admin
   * that a rule is dead when it is very much alive is worse than saying nothing.
   *
   * @param array<string,mixed> $rule
   * @param array<string,mixed> $other
   */
  private static function scope_overlaps( array $rule, array $other ): bool {
    if ( (string) $rule['scope_type'] === 'all' || (string) $other['scope_type'] === 'all' ) {
      return true;
    }
    return (string) $rule['scope_type'] === (string) $other['scope_type']
      && (int) $rule['scope_id'] === (int) $other['scope_id'];
  }

  /**
   * @param array<string,mixed> $rule
   * @param array<string,mixed> $other
   */
  private static function target_overlaps( array $rule, array $other ): bool {
    if ( (string) $rule['target_type'] === 'all' || (string) $other['target_type'] === 'all' ) {
      return true;
    }
    if ( in_array( 'category', [ $rule['target_type'], $other['target_type'] ], true ) && in_array( 'product', [ $rule['target_type'], $other['target_type'] ], true ) ) {
      return true; // which products carry the term is unknown here.
    }
    if ( (string) $rule['target_type'] !== (string) $other['target_type'] ) {
      return false;
    }
    return (bool) array_intersect(
      array_map( 'intval', (array) $rule['target_ids'] ),
      array_map( 'intval', (array) $other['target_ids'] )
    );
  }
}
