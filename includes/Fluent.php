<?php
declare(strict_types=1);

namespace FACommissionRules;

defined( 'ABSPATH' ) || exit;

use FluentAffiliate\App\Helper\Helper;
use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\AffiliateGroup;
use FluentAffiliate\App\Services\PermissionManager;

/**
 * Every touch point with Fluent Affiliate lives here.
 *
 * @package FACommissionRules
 */
final class Fluent {
  public const MIN_VERSION = '1.6';

  public static function ready(): bool {
    return defined( 'FLUENT_AFFILIATE_VERSION' )
      && version_compare( (string) FLUENT_AFFILIATE_VERSION, self::MIN_VERSION, '>=' )
      && class_exists( Affiliate::class );
  }

  public static function has_pro(): bool {
    return defined( 'FLUENT_AFFILIATE_PRO' ) && FLUENT_AFFILIATE_PRO;
  }

  public static function has_woo(): bool {
    return class_exists( 'WooCommerce' );
  }

  /** Fluent's own permission model; manage_options short-circuits to true inside userCan(). */
  public static function can_manage(): bool {
    if ( class_exists( PermissionManager::class ) ) {
      return (bool) PermissionManager::userCan( 'manage_all_data' );
    }
    return current_user_can( 'manage_options' );
  }

  /** @return mixed */
  public static function get_option( string $key, $default = null ) {
    return Utility::getOption( $key, $default );
  }

  /** @param mixed $value */
  public static function update_option( string $key, $value ): void {
    Utility::updateOption( $key, $value );
  }

  /** @return mixed */
  public static function referral_setting( string $key, $default = null ) {
    return Utility::getReferralSetting( $key, $default );
  }

  /** @return Affiliate|null */
  public static function affiliate( int $id ) {
    if ( $id <= 0 ) {
      return null;
    }
    return Affiliate::find( $id );
  }

  /** @return Affiliate|null */
  public static function affiliate_for_user( int $user_id ) {
    if ( $user_id <= 0 ) {
      return null;
    }
    return Affiliate::where( 'user_id', $user_id )->first();
  }

  /**
   * Affiliate groups as [ id => name ]. The group name is the fa_meta meta_key column.
   *
   * @return array<int,string>
   */
  public static function groups(): array {
    // ponytail: one query per request. group_name() is called once per row of the
    // rules list, and groups do not change mid-request. Add invalidation only if
    // something ever creates a group and re-reads it in the same request.
    static $cache = null;
    if ( is_array( $cache ) ) {
      return $cache;
    }
    if ( ! self::has_pro() || ! class_exists( AffiliateGroup::class ) ) {
      $cache = [];
      return $cache;
    }
    $out = [];
    foreach ( AffiliateGroup::orderBy( 'meta_key', 'ASC' )->get() as $group ) {
      $out[ (int) $group->id ] = (string) $group->meta_key;
    }
    $cache = $out;
    return $cache;
  }

  public static function group_name( int $id ): string {
    $groups = self::groups();
    return $groups[ $id ] ?? sprintf(
      /* translators: %d: affiliate group id */
      __( 'Group #%d', 'fa-commission-rules' ),
      $id
    );
  }

  public static function group_exists( int $id ): bool {
    if ( $id <= 0 || ! class_exists( AffiliateGroup::class ) ) {
      return false;
    }
    return (bool) AffiliateGroup::find( $id );
  }

  /**
   * A short label for an affiliate, for admin lists.
   *
   * full_name is an appended accessor on Fluent's User model, not on Affiliate —
   * $affiliate->full_name is always null. Go through the relation, then fall back
   * to the account email before the bare id.
   */
  public static function affiliate_label( int $id ): string {
    $affiliate = self::affiliate( $id );
    if ( ! $affiliate ) {
      return sprintf(
        /* translators: %d: affiliate id */
        __( 'Affiliate #%d', 'fa-commission-rules' ),
        $id
      );
    }

    $user  = $affiliate->user;
    $label = $user ? trim( (string) $user->full_name ) : '';
    if ( $label === '' && $user ) {
      $label = trim( (string) $user->user_email );
    }

    return $label !== '' ? $label . ' (#' . $id . ')' : '#' . $id;
  }

  /** True when Fluent is configured to exclude tax from commissionable totals. */
  public static function excludes_tax(): bool {
    return self::referral_setting( 'exclude_tax', 'yes' ) !== 'no';
  }

  /** True when Fluent is configured to exclude shipping from commissionable totals. */
  public static function excludes_shipping(): bool {
    return self::referral_setting( 'exclude_shipping', 'yes' ) !== 'no';
  }

  /**
   * A flat amount in the store's own money format. Fluent ships the formatter in
   * the free plugin; number_format_i18n is the fallback if that ever moves.
   */
  public static function money( float $amount ): string {
    if ( class_exists( Helper::class ) && method_exists( Helper::class, 'formatMoney' ) ) {
      return (string) Helper::formatMoney( $amount );
    }
    return number_format_i18n( $amount, 2 );
  }
}
