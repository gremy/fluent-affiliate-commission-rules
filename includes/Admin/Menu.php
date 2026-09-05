<?php
declare(strict_types=1);

namespace FACommissionRules\Admin;

defined( 'ABSPATH' ) || exit;

use FACommissionRules\Fluent;
use FACommissionRules\Labels;

/**
 * Our own WP admin screen, hung under Fluent's menu and cross-linked from its
 * header. The page prints Fluent's real chrome and mounts our Vue app inside
 * it; everything else happens over REST.
 *
 * @package FACommissionRules
 */
final class Menu {
  public function register(): void {
    add_action( 'admin_menu', [ $this, 'add_page' ], 20 );
    add_filter( 'fluent_affiliate/top_menu_items', [ $this, 'add_tab' ] );
    add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    add_filter( 'admin_body_class', [ $this, 'body_class' ] );
  }

  /**
   * Fluent scopes part of its admin stylesheet to its own page's body class;
   * borrow it so inputs, backgrounds and dark mode look identical on our page.
   */
  public function body_class( string $classes ): string {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    return $screen && strpos( (string) $screen->id, FACR_PAGE ) !== false
      ? $classes . ' toplevel_page_fluent-affiliate'
      : $classes;
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
      [ __CLASS__, 'render_page' ]
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
      'label'     => __( 'Commissions', 'fa-commission-rules' ),
      'permalink' => self::page_url(),
    ];
    return $items;
  }

  /**
   * The page callback. Fluent's navbar and its empty #fluent-framework-app
   * mount point are all that is printed; facr-app (enqueued below) mounts into it.
   */
  public static function render_page(): void {
    if ( ! Fluent::can_manage() ) {
      wp_die( esc_html__( 'You do not have permission to manage commission rules.', 'fa-commission-rules' ), '', [ 'response' => 403 ] );
    }
    Fluent::render_admin_chrome();
  }

  public function enqueue( string $hook ): void {
    if ( strpos( $hook, FACR_PAGE ) === false ) {
      return;
    }

    Fluent::enqueue_admin_styles();
    wp_add_inline_style( 'facr-fa-admin', self::inline_css() );

    wp_register_script( 'facr-vue', FACR_URL . 'assets/vendor/vue.global.prod.js', [], '3.5.17', true );
    wp_register_script( 'facr-element-plus', FACR_URL . 'assets/vendor/element-plus.full.min.js', [ 'facr-vue' ], '2.9.11', true );
    wp_register_script( 'facr-helpers', FACR_URL . 'assets/admin/helpers.js', [], FACR_VERSION, true );
    wp_enqueue_script( 'facr-app', FACR_URL . 'assets/admin/app.js', [ 'facr-vue', 'facr-element-plus', 'facr-helpers' ], FACR_VERSION, true );

    // wp_localize_script casts top-level scalars to strings: has_pro/has_woo
    // arrive as '1'/'0' and the app compares against '1'.
    $zero = Fluent::money( 0 );
    wp_localize_script(
      'facr-app',
      'facrAdmin',
      [
        'rest_url'          => rest_url( 'fa-commission-rules/v1' ),
        'nonce'             => wp_create_nonce( 'wp_rest' ),
        'has_pro'           => Fluent::has_pro() ? '1' : '0',
        'has_woo'           => Fluent::has_woo() ? '1' : '0',
        'default_rate'      => Labels::default_rate_label(),
        // "$ 0.00" → "$ %s": the live sentence formats flat amounts the way the store does.
        'money_template'    => (string) preg_replace( '/0[.,]00/', '%s', $zero, 1 ),
        'decimal_separator' => strpos( $zero, '0,00' ) !== false ? ',' : '.',
        // The store's own today, not the browser's: a scheduled/expired badge has
        // to agree with the dates the server compares rules against.
        'today'             => wp_date( 'Y-m-d' ),
        'i18n'              => Strings::all(),
      ]
    );

    wp_add_inline_script( 'facr-app', self::bootstrap_script(), 'before' );
  }

  /**
   * What Fluent's own bundle would do on this page if it were loaded: apply the
   * stored colour mode, define the navbar's onclick handlers, mark our tab.
   * Mirrors app.min.js (1.6.5): class "dark" on <html> and #wpbody-content,
   * localStorage key fla_color_mode.
   */
  public static function bootstrap_script(): string {
    return <<<'JS'
( function () {
  'use strict';
  var body = document.getElementById( 'wpbody-content' );
  var stored = '';
  try { stored = window.localStorage.getItem( 'fla_color_mode' ) || ''; } catch ( e ) { stored = ''; }
  if ( stored === 'dark' ) {
    document.documentElement.classList.add( 'dark' );
    if ( body ) { body.classList.add( 'dark' ); }
  }
  window.toggleColorMode = function () {
    var el = document.getElementById( 'wpbody-content' );
    if ( ! el ) { return; }
    var isDark = el.classList.contains( 'dark' );
    el.classList.toggle( 'dark', ! isDark );
    document.documentElement.classList.toggle( 'dark', ! isDark );
    try { window.localStorage.setItem( 'fla_color_mode', isDark ? 'light' : 'dark' ); } catch ( e ) {}
  };
  window.toggleMobileMenu = function () {
    var links = document.getElementById( 'fa_mobile_menu_links' );
    if ( links ) { links.style.setProperty( 'display', links.style.display === 'block' ? 'none' : 'block' ); }
  };
  window.toggleMobileSettingsMenu = function () {
    var settings = document.querySelector( '.fa-navbar__settings' );
    if ( settings ) { settings.style.setProperty( 'display', settings.style.display === 'flex' ? '' : 'flex' ); }
  };
  var tabs = document.querySelectorAll( '.fa-navbar__link-wrapper[data-key="fa_commission_rules"] .fa-navbar__link' );
  for ( var i = 0; i < tabs.length; i++ ) { tabs[ i ].classList.add( 'fa-navbar__link--active' ); }
} )();
JS;
  }

  /** Layout the app needs that Fluent's theme has no opinion on. Variables are theirs, so dark mode follows. */
  public static function inline_css(): string {
    return
      // Fluent offsets its own .fa-navbar for the WP admin bar (32px, 46px under
      // 783px) but Element Plus's drawer/dialog overlay is a plain fixed
      // position:0 layer with no such opinion, so it renders under the admin
      // bar and hides the drawer's title/close button. Push the overlay down
      // to clear the admin bar on our page only.
      // Element Plus also sets bottom:0 and height:100% on .el-overlay, which
      // overconstrains top+bottom+height; the box then extends 32px/46px past
      // the viewport instead of shrinking, clipping the drawer footer. Pair
      // each top offset with a matching height so the box still ends flush
      // with the viewport bottom.
      'body.toplevel_page_fluent-affiliate .el-overlay{top:32px;height:calc(100% - 32px)}'
      . '@media screen and (max-width:782px){body.toplevel_page_fluent-affiliate .el-overlay{top:46px;height:calc(100% - 46px)}}'
      . '.facr-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center}'
      . '.facr-bulk-bar{border-top:1px solid var(--fla-primary-border);padding-top:12px;padding-bottom:12px}'
      . '.facr-badge-note,.facr-readonly,.facr-help{font-size:12px;color:var(--fla-secondary-text);line-height:1.4}'
      . '.facr-badge-note{margin-top:4px}'
      . '.facr-help{margin-top:4px;width:100%}'
      . '.facr-result{margin:16px 0 0;font-size:14px;color:var(--fla-primary-text)}'
      . '.facr-money,.facr-dates{display:flex;flex-wrap:wrap;gap:8px;align-items:center;width:100%}'
      . '.fa_table_wrap{overflow-x:auto}'
      . '@media (max-width:640px){.fa-affiliate-body-actions-bar{flex-direction:column;align-items:stretch;gap:12px}.facr-filters .el-select,.facr-filters .el-input{width:100%!important}}'
      . '.fa-navbar__link-wrapper[data-key="fa_commission_rules"] .fa-navbar__link{white-space:nowrap}';
  }
}
