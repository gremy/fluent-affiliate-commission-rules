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

    return self::label_for( $affiliate );
  }

  /**
   * Every affiliate as { id, label } for a picker, newest first, labelled
   * exactly like affiliate_label() but with the user relation eager-loaded so
   * a 500-row list is two queries rather than a thousand.
   *
   * @return array<int,array{id:int,label:string}>
   */
  public static function affiliates( int $limit = 500 ): array {
    // ponytail: a 500-row cap, not paging. The picker is a filterable select;
    // switch to remote search only if a store actually outgrows this.
    $out = [];
    foreach ( Affiliate::with( 'user' )->orderBy( 'id', 'DESC' )->limit( $limit )->get() as $affiliate ) {
      $out[] = [ 'id' => (int) $affiliate->id, 'label' => self::label_for( $affiliate ) ];
    }
    return $out;
  }

  /**
   * The "Name (#id) / email (#id) / #id" label shared by affiliate_label() and
   * affiliates(): full name, else the account email, else the bare id.
   *
   * @param object $affiliate an Affiliate model with its `user` relation loaded (or loadable)
   */
  private static function label_for( $affiliate ): string {
    $id    = (int) $affiliate->id;
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

  /**
   * Fluent Affiliate's own admin chrome: the navbar (with our tab, added via
   * fluent_affiliate/top_menu_items) and the empty #fluent-framework-app div
   * our app mounts into. AdminMenuHandler::render() is public and its
   * constructor takes no arguments (verified against 1.6.5); the
   * admin_enqueue_scripts listener the constructor adds is inert on our page.
   */
  public static function render_admin_chrome(): void {
    $handler = '\FluentAffiliate\App\Hooks\Handlers\AdminMenuHandler';
    if ( class_exists( $handler ) && method_exists( $handler, 'render' ) ) {
      ( new $handler() )->render();
      return;
    }
    // Their handler moved: keep the page usable, just without their navbar.
    echo '<div id="fluent-affiliate-app" class="warp fconnector_app"><div class="fframe_app"><div class="fframe_body"><div id="fluent-framework-app" class="fs_route_wrapper"></div></div></div></div>';
  }

  /**
   * Their tree-shaken Element Plus css and their theme, in that order, through
   * their own helper so RTL is handled. Both are no-ops if the helper is gone,
   * which leaves the page unstyled rather than broken.
   */
  public static function enqueue_admin_styles(): void {
    $vite = '\FluentAffiliate\App\Vite';
    if ( ! class_exists( $vite ) || ! method_exists( $vite, 'enqueueStyle' ) ) {
      return;
    }
    $vite::enqueueStyle( 'facr-fa-app', 'admin_app_css', [], (string) FLUENT_AFFILIATE_VERSION );
    $vite::enqueueStyle( 'facr-fa-admin', 'admin_css', [ 'facr-fa-app' ], (string) FLUENT_AFFILIATE_VERSION );
  }
}
