<?php
declare(strict_types=1);

namespace FACommissionRules;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the single rules collection, and adapts Fluent's own global
 * product/category rate rows into the same shape so there is one list to read.
 *
 * Storage: Fluent's own option storage (fa_meta, object_type 'option') under
 * FACR_RULES_KEY. The Meta model maybe_serialize()s, so nested arrays round-trip.
 *
 * @package FACommissionRules
 */
final class Store {
  /** Shape version of the audit stamp written onto each referral. */
  public const STAMP_VERSION = 1;

  public const SCOPES  = [ 'all', 'group', 'affiliate' ];
  public const TARGETS = [ 'all', 'category', 'product' ];
  public const STATUSES = [ 'active', 'inactive' ];
  public const RATE_TYPES = [ 'percentage', 'flat' ];

  /** @return array<int,array<string,mixed>> newest first */
  public static function all(): array {
    $stored = Fluent::get_option( FACR_RULES_KEY, [] );
    if ( ! is_array( $stored ) ) {
      return [];
    }
    $rules = [];
    foreach ( $stored as $rule ) {
      if ( is_array( $rule ) && isset( $rule['id'] ) ) {
        $rules[] = self::hydrate( $rule );
      }
    }
    usort( $rules, static fn( array $a, array $b ): int => strcmp( (string) $b['created_at'], (string) $a['created_at'] ) );
    return $rules;
  }

  /** @return array<string,mixed>|null */
  public static function get( string $id ): ?array {
    foreach ( self::all() as $rule ) {
      if ( (string) $rule['id'] === $id ) {
        return $rule;
      }
    }
    return null;
  }

  /** @return array<int,array<string,mixed>> */
  public static function for_scope( string $scope_type, int $scope_id ): array {
    return array_values(
      array_filter(
        self::all(),
        static fn( array $rule ): bool => $rule['scope_type'] === $scope_type && (int) $rule['scope_id'] === $scope_id
      )
    );
  }

  /**
   * Our rules plus Fluent's own global rows for this referral context. No
   * status/date/scope filtering here — Resolver::applicable() owns that so it
   * stays unit-testable without WordPress.
   *
   * @param string $context 'sale' or 'renewal'
   * @return array<int,array<string,mixed>>
   */
  public static function resolvable( string $context = 'sale' ): array {
    return array_merge( self::all(), self::fluent_global_rules( $context ) );
  }

  /**
   * Fluent's site-wide product/category rate table, read live and never copied.
   *
   * Fluent keeps two independent tables in the same connector option: one for
   * sales and one for renewals. Reading the sale table on a renewal would apply
   * rates the store owner deliberately set elsewhere, so the context picks.
   *
   * @param string $context 'sale' or 'renewal'
   * @return array<int,array<string,mixed>>
   */
  public static function fluent_global_rules( string $context = 'sale' ): array {
    $renewal = $context === 'renewal';
    $gate    = $renewal ? 'renewal_custom_affiliate_rate' : 'custom_affiliate_rate';
    $key     = $renewal ? 'renewal_custom_affiliate_rates' : 'custom_affiliate_rates';
    $prefix  = $renewal ? 'fluent:renewal:' : 'fluent:';

    $config = Fluent::get_option( '_woo_connector_config', [] );
    if ( ! is_array( $config ) || ( $config[ $gate ] ?? 'no' ) !== 'yes' ) {
      return [];
    }
    $rows = $config[ $key ] ?? [];
    if ( ! is_array( $rows ) ) {
      return [];
    }

    $rules = [];
    foreach ( array_values( $rows ) as $index => $row ) {
      $ids = array_values( array_filter( array_map( 'intval', (array) ( $row['object_ids'] ?? [] ) ) ) );
      if ( ! $ids ) {
        continue;
      }
      $rules[] = self::hydrate(
        [
          'id'          => $prefix . $index,
          'status'      => 'active',
          'scope_type'  => 'all',
          'scope_id'    => 0,
          'target_type' => ( $row['object_type'] ?? '' ) === 'product' ? 'product' : 'category',
          'target_ids'  => $ids,
          'rate'        => (float) ( $row['rate'] ?? 0 ),
          'rate_type'   => ( $row['rate_type'] ?? 'percentage' ) === 'percentage' ? 'percentage' : 'flat',
          'starts_at'   => '',
          'ends_at'     => '',
          'note'        => '',
          // Epoch, so a Fluent row always loses the newest-wins tie-break to ours.
          'created_at'  => '1970-01-01T00:00:00+00:00',
          'readonly'    => true,
        ]
      );
    }
    return $rules;
  }

  /**
   * Normalise and check one submitted rule.
   *
   * @param array<string,mixed> $input
   * @return array{0:array<string,mixed>,1:array<string,string>}
   */
  public static function validate( array $input ): array {
    $errors = [];

    $scope_type = in_array( (string) ( $input['scope_type'] ?? '' ), self::SCOPES, true ) ? (string) $input['scope_type'] : 'all';
    $scope_id   = $scope_type === 'all' ? 0 : (int) ( $input['scope_id'] ?? 0 );
    if ( $scope_type !== 'all' && $scope_id <= 0 ) {
      $errors['scope_id'] = $scope_type === 'group'
        ? __( 'Choose an affiliate group.', 'fa-commission-rules' )
        : __( 'Choose an affiliate.', 'fa-commission-rules' );
    } elseif ( $scope_type === 'affiliate' && ! Fluent::affiliate( $scope_id ) ) {
      $errors['scope_id'] = __( 'That affiliate no longer exists.', 'fa-commission-rules' );
    } elseif ( $scope_type === 'group' && ! Fluent::group_exists( $scope_id ) ) {
      $errors['scope_id'] = __( 'That affiliate group no longer exists.', 'fa-commission-rules' );
    }

    $target_type   = in_array( (string) ( $input['target_type'] ?? '' ), self::TARGETS, true ) ? (string) $input['target_type'] : 'all';
    $submitted_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $input['target_ids'] ?? [] ) ) ) ) );
    $target_ids    = $target_type === 'all' ? [] : $submitted_ids;

    if ( $target_type === 'all' && $submitted_ids ) {
      // A stale hidden field or a hand-rolled POST; silently dropping the ids
      // would save a rule that reads nothing like what was submitted.
      $errors['target_ids'] = __( 'A rule for all products cannot also name products or categories.', 'fa-commission-rules' );
    } elseif ( $target_type !== 'all' && ! $target_ids ) {
      $errors['target_ids'] = $target_type === 'category'
        ? __( 'Choose at least one product category.', 'fa-commission-rules' )
        : __( 'Choose at least one product or variation.', 'fa-commission-rules' );
    } elseif ( $target_ids ) {
      $unknown = self::unknown_targets( $target_type, $target_ids );
      if ( $unknown ) {
        $errors['target_ids'] = sprintf(
          /* translators: %s: comma-separated list of ids that could not be found */
          _n(
            'This no longer exists: %s.',
            'These no longer exist: %s.',
            count( $unknown ),
            'fa-commission-rules'
          ),
          implode( ', ', $unknown )
        );
      }
    }

    $rate_type = in_array( (string) ( $input['rate_type'] ?? '' ), self::RATE_TYPES, true ) ? (string) $input['rate_type'] : 'percentage';
    $raw_rate  = str_replace( ',', '.', (string) ( $input['rate'] ?? '' ) );
    $rate      = (float) $raw_rate;
    if ( $raw_rate === '' || ! is_numeric( $raw_rate ) ) {
      $errors['rate'] = __( 'Enter a commission rate.', 'fa-commission-rules' );
    } elseif ( $rate < 0 ) {
      $errors['rate'] = __( 'The rate cannot be negative.', 'fa-commission-rules' );
    } elseif ( $rate_type === 'percentage' && $rate > 100 ) {
      $errors['rate'] = __( 'A percentage rate cannot be above 100.', 'fa-commission-rules' );
    }

    $starts_at = self::clean_date( (string) ( $input['starts_at'] ?? '' ) );
    $ends_at   = self::clean_date( (string) ( $input['ends_at'] ?? '' ) );
    if ( ( $input['starts_at'] ?? '' ) !== '' && $starts_at === '' ) {
      $errors['starts_at'] = __( 'Use the YYYY-MM-DD date format.', 'fa-commission-rules' );
    }
    if ( ( $input['ends_at'] ?? '' ) !== '' && $ends_at === '' ) {
      $errors['ends_at'] = __( 'Use the YYYY-MM-DD date format.', 'fa-commission-rules' );
    }
    if ( $starts_at !== '' && $ends_at !== '' && $ends_at < $starts_at ) {
      $errors['ends_at'] = __( 'The end date must not be before the start date.', 'fa-commission-rules' );
    }

    $rule = self::hydrate(
      [
        'id'          => (string) ( $input['id'] ?? '' ) !== '' ? (string) $input['id'] : wp_generate_uuid4(),
        'status'      => in_array( (string) ( $input['status'] ?? '' ), self::STATUSES, true ) ? (string) $input['status'] : 'active',
        'scope_type'  => $scope_type,
        'scope_id'    => $scope_id,
        'target_type' => $target_type,
        'target_ids'  => $target_ids,
        'rate'        => $rate,
        'rate_type'   => $rate_type,
        'starts_at'   => $starts_at,
        'ends_at'     => $ends_at,
        'note'        => sanitize_text_field( (string) ( $input['note'] ?? '' ) ),
        'created_at'  => (string) ( $input['created_at'] ?? '' ) !== ''
          ? (string) $input['created_at']
          : gmdate( 'c' ),
        'readonly'    => false,
      ]
    );

    return [ $rule, $errors ];
  }

  /** @param array<string,mixed> $rule */
  public static function save( array $rule ): void {
    if ( strncmp( (string) $rule['id'], 'fluent:', 7 ) === 0 ) {
      return; // Fluent's own rows are read-only by construction.
    }
    $rules   = self::all();
    $written = false;
    foreach ( $rules as $index => $existing ) {
      if ( (string) $existing['id'] === (string) $rule['id'] ) {
        $rules[ $index ] = $rule;
        $written         = true;
        break;
      }
    }
    if ( ! $written ) {
      $rules[] = $rule;
    }
    self::persist( $rules );
  }

  public static function delete( string $id ): bool {
    $rules = self::all();
    $kept  = array_values( array_filter( $rules, static fn( array $rule ): bool => (string) $rule['id'] !== $id ) );
    if ( count( $kept ) === count( $rules ) ) {
      return false;
    }
    self::persist( $kept );
    return true;
  }

  /** @param string[] $ids */
  public static function delete_many( array $ids ): int {
    $ids     = array_map( 'strval', $ids );
    $rules   = self::all();
    $kept    = array_values( array_filter( $rules, static fn( array $rule ): bool => ! in_array( (string) $rule['id'], $ids, true ) ) );
    $removed = count( $rules ) - count( $kept );
    if ( $removed > 0 ) {
      self::persist( $kept );
    }
    return $removed;
  }

  /** @param string[] $ids */
  public static function set_status( array $ids, string $status ): int {
    if ( ! in_array( $status, self::STATUSES, true ) ) {
      return 0;
    }
    $ids     = array_map( 'strval', $ids );
    $rules   = self::all();
    $changed = 0;
    foreach ( $rules as $index => $rule ) {
      if ( in_array( (string) $rule['id'], $ids, true ) && $rule['status'] !== $status ) {
        $rules[ $index ]['status'] = $status;
        $changed++;
      }
    }
    if ( $changed > 0 ) {
      self::persist( $rules );
    }
    return $changed;
  }

  /**
   * fluent_affiliate/after_delete_affiliate passes the id as an int.
   *
   * @param int|string $affiliate_id
   */
  public static function forget_affiliate( $affiliate_id ): void {
    self::forget( 'affiliate', (int) $affiliate_id );
  }

  /**
   * fluent_affiliate/after_delete_affiliate_group passes the model.
   *
   * @param object|int $group
   */
  public static function forget_group( $group ): void {
    $id = is_object( $group ) ? (int) ( $group->id ?? 0 ) : (int) $group;
    self::forget( 'group', $id );
  }

  private static function forget( string $scope_type, int $scope_id ): void {
    if ( $scope_id <= 0 ) {
      return;
    }
    $rules = self::all();
    $kept  = array_values(
      array_filter(
        $rules,
        static fn( array $rule ): bool => ! ( $rule['scope_type'] === $scope_type && (int) $rule['scope_id'] === $scope_id )
      )
    );
    if ( count( $kept ) !== count( $rules ) ) {
      self::persist( $kept );
    }
  }

  /** @param array<int,array<string,mixed>> $rules */
  private static function persist( array $rules ): void {
    Fluent::update_option( FACR_RULES_KEY, array_values( $rules ) );
  }

  /**
   * @param array<string,mixed> $rule
   * @return array<string,mixed>
   */
  private static function hydrate( array $rule ): array {
    return [
      'id'          => (string) ( $rule['id'] ?? '' ),
      'status'      => in_array( (string) ( $rule['status'] ?? '' ), self::STATUSES, true ) ? (string) $rule['status'] : 'active',
      'scope_type'  => in_array( (string) ( $rule['scope_type'] ?? '' ), self::SCOPES, true ) ? (string) $rule['scope_type'] : 'all',
      'scope_id'    => (int) ( $rule['scope_id'] ?? 0 ),
      'target_type' => in_array( (string) ( $rule['target_type'] ?? '' ), self::TARGETS, true ) ? (string) $rule['target_type'] : 'all',
      'target_ids'  => array_values( array_map( 'intval', (array) ( $rule['target_ids'] ?? [] ) ) ),
      'rate'        => (float) ( $rule['rate'] ?? 0 ),
      'rate_type'   => ( $rule['rate_type'] ?? 'percentage' ) === 'flat' ? 'flat' : 'percentage',
      'starts_at'   => (string) ( $rule['starts_at'] ?? '' ),
      'ends_at'     => (string) ( $rule['ends_at'] ?? '' ),
      'note'        => (string) ( $rule['note'] ?? '' ),
      'created_at'  => (string) ( $rule['created_at'] ?? '' ),
      'readonly'    => ! empty( $rule['readonly'] ),
    ];
  }

  /**
   * Target ids that do not resolve to something of the declared type.
   *
   * @param int[] $ids
   * @return string[] the offending ids
   */
  private static function unknown_targets( string $target_type, array $ids ): array {
    $unknown = [];

    foreach ( $ids as $id ) {
      if ( $target_type === 'category' ) {
        $term = get_term( $id, 'product_cat' );
        if ( ! $term || is_wp_error( $term ) ) {
          $unknown[] = (string) $id;
        }
        continue;
      }

      // ponytail: only WooCommerce can say whether a product id is real. Without
      // it we accept any positive int, so a rule authored on a live store is not
      // destroyed by an admin visit while WooCommerce happens to be deactivated.
      if ( Fluent::has_woo() && function_exists( 'wc_get_product' ) ) {
        if ( ! wc_get_product( $id ) ) {
          $unknown[] = (string) $id;
        }
      } elseif ( $id <= 0 ) {
        $unknown[] = (string) $id;
      }
    }

    return $unknown;
  }

  private static function clean_date( string $value ): string {
    $value = trim( $value );
    if ( $value === '' ) {
      return '';
    }
    $date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
    return ( $date && $date->format( 'Y-m-d' ) === $value ) ? $value : '';
  }
}
