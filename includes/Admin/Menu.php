<?php
declare(strict_types=1);

namespace FACommissionRules\Admin;

defined( 'ABSPATH' ) || exit;

use FACommissionRules\Fluent;

/**
 * Our own WP admin screen, hung under Fluent's menu and cross-linked from its header.
 *
 * @package FACommissionRules
 */
final class Menu {
  public function register(): void {
    add_action( 'admin_menu', [ $this, 'add_page' ], 20 );
    add_filter( 'fluent_affiliate/top_menu_items', [ $this, 'add_tab' ] );
    add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
  }

  /** @param array<string,string> $args */
  public static function page_url( array $args = [] ): string {
    return add_query_arg( array_merge( [ 'page' => FACR_PAGE ], $args ), admin_url( 'admin.php' ) );
  }

  public function add_page(): void {
    if ( ! Fluent::can_manage() ) {
      return; // Fluent's permission model decides; the menu simply does not exist otherwise.
    }
    add_submenu_page(
      'fluent-affiliate',
      __( 'Commission Rules', 'fa-commission-rules' ),
      __( 'Commission Rules', 'fa-commission-rules' ),
      'read',
      FACR_PAGE,
      [ RulesPage::class, 'render' ]
    );
  }

  /**
   * @param array<int,array<string,string>> $items
   * @return array<int,array<string,string>>
   */
  public function add_tab( $items ) {
    if ( ! is_array( $items ) || ! Fluent::can_manage() ) {
      return $items;
    }
    $items[] = [
      'key'       => 'fa_commission_rules',
      'label'     => __( 'Commission rules', 'fa-commission-rules' ),
      'permalink' => self::page_url(),
    ];
    return $items;
  }

  public function enqueue( string $hook ): void {
    if ( strpos( $hook, FACR_PAGE ) === false ) {
      return;
    }
    // WooCommerce's own product search control; no bundle of ours. Its AJAX
    // endpoint is gated on edit_products, so a manager without that capability
    // gets the plain fallback select and does not need the script at all.
    if ( Fluent::has_woo() && current_user_can( 'edit_products' ) ) {
      wp_enqueue_script( 'wc-enhanced-select' );
      wp_enqueue_style( 'woocommerce_admin_styles' );
    }
  }
}
