<?php
declare(strict_types=1);
/**
 * The admin page shell: Fluent's chrome is printed, our assets are enqueued on our page only.
 * Run: wp --path=/path/to/wp eval 'require WP_PLUGIN_DIR . "/fluent-affiliate-commission-rules/tests/test-admin.php";'
 * (wp eval-file fatals: strict_types must be the file's first statement, but WP-CLI wraps it.)
 */

use FACommissionRules\Admin\Menu;
use FACommissionRules\Admin\Strings;
use FACommissionRules\Fluent;

if ( ! defined( 'ABSPATH' ) ) {
  fwrite( STDERR, "test-admin.php needs WordPress: run it with wp eval-file\n" );
  return;
}

$GLOBALS['facr_adm_fail'] = $GLOBALS['facr_adm_fail'] ?? 0;

if ( ! function_exists( 'facr_adm' ) ) {
  function facr_adm( string $label, bool $ok ): void {
    if ( $ok ) {
      echo "PASS {$label}\n";
      return;
    }
    $GLOBALS['facr_adm_fail']++;
    echo "FAIL {$label}\n";
  }
}

if ( ! Fluent::ready() ) {
  echo "SKIP Fluent Affiliate is not active\n";
  $GLOBALS['facr_adm_skipped'] = true;
  return;
}

$facr_a_users = []; // only ids this file actually created; nothing else is ever deleted.
$facr_a_admin = 0;
$facr_a_sub   = 0;
$facr_a_prev  = get_current_user_id();
$facr_a_page  = $_GET['page'] ?? null;

try {
  // wp_insert_user() returns WP_Error on failure, and (int) WP_Error is 1 — the
  // site administrator. Keep the raw result, bail on an error, and only ever
  // delete ids this block confirmed it created.
  foreach ( [ 'administrator', 'subscriber' ] as $facr_a_role ) {
    $facr_a_created = wp_insert_user( [ 'user_login' => 'facr_adm_' . $facr_a_role . '_' . wp_rand(), 'user_pass' => wp_generate_password( 20 ), 'user_email' => 'facr_adm_' . $facr_a_role . '_' . wp_rand() . '@example.test', 'role' => $facr_a_role ] );
    if ( is_wp_error( $facr_a_created ) || (int) $facr_a_created <= 0 ) {
      facr_adm( "fixture user ({$facr_a_role}) created", false );
      return;
    }
    $facr_a_users[] = (int) $facr_a_created;
    if ( $facr_a_role === 'administrator' ) {
      $facr_a_admin = (int) $facr_a_created;
    } else {
      $facr_a_sub = (int) $facr_a_created;
    }
  }

  // Plugin::boot() only wires Menu when is_admin(); under WP-CLI it is not.
  $facr_a_menu = new Menu();
  $facr_a_menu->register();
  wp_set_current_user( $facr_a_admin );
  $_GET['page'] = FACR_PAGE;

  // ------------------------------------------------------------ strings ---
  $facr_a_strings = Strings::all();
  facr_adm( 'Strings::all() is a non-empty string map', $facr_a_strings !== [] && count( array_filter( $facr_a_strings, static fn( $v, $k ): bool => is_string( $k ) && is_string( $v ) && $v !== '', ARRAY_FILTER_USE_BOTH ) ) === count( $facr_a_strings ) );
  foreach ( [ 'page_title', 'add_rule', 'empty_body', 'confirm_delete_one', 'sentence', 'sentence_between', 'flat_suffix', 'tie_warning', 'editor_add_title', 'preset_year' ] as $facr_a_key ) {
    facr_adm( "Strings::all() has {$facr_a_key}", isset( $facr_a_strings[ $facr_a_key ] ) );
  }
  facr_adm( 'empty_body names the default rate placeholder', strpos( $facr_a_strings['empty_body'] ?? '', '%s' ) !== false );
  facr_adm( 'sentence has three placeholders', strpos( $facr_a_strings['sentence'] ?? '', '%3$s' ) !== false );

  // ------------------------------------------------------------- render ---
  ob_start();
  Menu::render_page();
  $facr_a_html = (string) ob_get_clean();
  facr_adm( 'render_page prints the Fluent mount point', strpos( $facr_a_html, 'id="fluent-framework-app"' ) !== false );
  facr_adm( 'render_page prints the Fluent navbar', strpos( $facr_a_html, 'fa-navbar' ) !== false && strpos( $facr_a_html, 'id="fluent-affiliate-app"' ) !== false );
  facr_adm( 'render_page prints our tab', strpos( $facr_a_html, 'data-key="fa_commission_rules"' ) !== false );
  facr_adm( 'render_page prints the colour-mode toggle', strpos( $facr_a_html, 'toggleColorMode()' ) !== false );
  facr_adm( 'render_page prints nothing of the old PHP list', strpos( $facr_a_html, 'wp-list-table' ) === false && strpos( $facr_a_html, 'admin-post.php' ) === false );

  // ------------------------------------------------------------ enqueue ---
  $facr_a_menu->enqueue( 'toplevel_page_some-other-plugin' );
  facr_adm( 'a foreign admin page enqueues nothing', ! wp_script_is( 'facr-app', 'enqueued' ) && ! wp_style_is( 'facr-fa-admin', 'enqueued' ) );

  // The real hook name in wp-admin: sanitize_title( 'FluentAffiliate' ) . '_page_' . FACR_PAGE.
  $facr_a_menu->enqueue( 'fluentaffiliate_page_fa-commission-rules' );
  facr_adm( 'Fluent component css is enqueued', wp_style_is( 'facr-fa-app', 'enqueued' ) );
  facr_adm( 'Fluent theme css is enqueued after the component css', wp_style_is( 'facr-fa-admin', 'enqueued' ) && in_array( 'facr-fa-app', (array) wp_styles()->registered['facr-fa-admin']->deps, true ) );
  facr_adm( 'Fluent css comes from the fluent-affiliate plugin', strpos( (string) wp_styles()->registered['facr-fa-app']->src, 'fluent-affiliate/assets/admin/app.min.css' ) !== false );
  facr_adm( 'the app script is enqueued', wp_script_is( 'facr-app', 'enqueued' ) );
  $facr_a_deps = (array) wp_scripts()->registered['facr-app']->deps;
  facr_adm( 'the app depends on vue, element-plus and helpers', in_array( 'facr-vue', $facr_a_deps, true ) && in_array( 'facr-element-plus', $facr_a_deps, true ) && in_array( 'facr-helpers', $facr_a_deps, true ) );
  facr_adm( 'element-plus depends on vue', in_array( 'facr-vue', (array) wp_scripts()->registered['facr-element-plus']->deps, true ) );
  facr_adm( 'vue is pinned in its handle version', (string) wp_scripts()->registered['facr-vue']->ver === '3.5.17' );
  facr_adm( 'element-plus is pinned in its handle version', (string) wp_scripts()->registered['facr-element-plus']->ver === '2.9.11' );
  facr_adm( 'scripts load in the footer', ! empty( wp_scripts()->registered['facr-app']->extra['group'] ) );
  $facr_a_data = (string) wp_scripts()->get_data( 'facr-app', 'data' );
  facr_adm( 'facrAdmin is localised', strpos( $facr_a_data, 'var facrAdmin' ) !== false );
  foreach ( [ '"rest_url"', '"nonce"', '"has_pro"', '"has_woo"', '"default_rate"', '"money_template"', '"decimal_separator"', '"i18n"', '"page_title"' ] as $facr_a_needle ) {
    facr_adm( "facrAdmin carries {$facr_a_needle}", strpos( $facr_a_data, $facr_a_needle ) !== false );
  }
  facr_adm( 'rest_url points at our namespace', strpos( $facr_a_data, 'fa-commission-rules\/v1' ) !== false || strpos( $facr_a_data, 'fa-commission-rules/v1' ) !== false );
  // Decode the localised payload rather than substring-matching it: "%s" appears
  // in half a dozen i18n strings, so the raw-string check passed whatever
  // money_template actually held.
  $facr_a_json    = json_decode( (string) preg_replace( '/^var facrAdmin = |;$/', '', trim( $facr_a_data ) ), true );
  facr_adm( 'the localised payload decodes as JSON', is_array( $facr_a_json ) );
  facr_adm( 'money_template carries the amount placeholder', is_array( $facr_a_json ) && strpos( (string) ( $facr_a_json['money_template'] ?? '' ), '%s' ) !== false );
  facr_adm( 'today is the site date in Y-m-d', is_array( $facr_a_json ) && (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $facr_a_json['today'] ?? '' ) ) );
  facr_adm( 'today is the site timezone, not UTC', is_array( $facr_a_json ) && ( $facr_a_json['today'] ?? '' ) === wp_date( 'Y-m-d' ) );
  foreach ( [ 'status_scheduled', 'status_expired' ] as $facr_a_i18n_key ) {
    facr_adm( "i18n carries {$facr_a_i18n_key}", is_array( $facr_a_json ) && ( $facr_a_json['i18n'][ $facr_a_i18n_key ] ?? '' ) !== '' );
  }
  $facr_a_before = implode( "\n", (array) wp_scripts()->get_data( 'facr-app', 'before' ) );
  facr_adm( 'the dark-mode bootstrap is inlined before the app', strpos( $facr_a_before, 'fla_color_mode' ) !== false && strpos( $facr_a_before, 'window.toggleColorMode' ) !== false );
  facr_adm( 'the bootstrap defines the navbar mobile toggles', strpos( $facr_a_before, 'window.toggleMobileMenu' ) !== false && strpos( $facr_a_before, 'window.toggleMobileSettingsMenu' ) !== false );
  facr_adm( 'the bootstrap marks our tab active', strpos( $facr_a_before, 'fa_commission_rules' ) !== false && strpos( $facr_a_before, 'fa-navbar__link--active' ) !== false );
  facr_adm( 'the bootstrap uses strict mode', strpos( $facr_a_before, "'use strict'" ) !== false );

  // --------------------------------------------------------- permissions ---
  wp_set_current_user( $facr_a_sub );
  facr_adm( 'a subscriber cannot manage', ! Fluent::can_manage() );
  facr_adm( 'add_tab hides the tab from a non-manager', $facr_a_menu->add_tab( [] ) === [] );
  wp_set_current_user( $facr_a_admin );
  $facr_a_tabs = $facr_a_menu->add_tab( [] );
  facr_adm( 'add_tab adds the tab for a manager', ( $facr_a_tabs[0]['key'] ?? '' ) === 'fa_commission_rules' && strpos( (string) ( $facr_a_tabs[0]['permalink'] ?? '' ), 'page=' . FACR_PAGE ) !== false );

  // ----------------------------------------------------------- body class ---
  // Fluent scopes ~29 admin.css rules to their own toplevel_page_fluent-affiliate
  // body class; we borrow it on our page only.
  set_current_screen( 'fluentaffiliate_page_fa-commission-rules' );
  facr_adm( 'body_class borrows Fluent\'s scoping class on our screen', strpos( (string) apply_filters( 'admin_body_class', 'x' ), 'toplevel_page_fluent-affiliate' ) !== false );
  set_current_screen( 'dashboard' );
  facr_adm( 'body_class leaves other screens alone', strpos( (string) apply_filters( 'admin_body_class', 'x' ), 'toplevel_page_fluent-affiliate' ) === false );

} finally {
  wp_set_current_user( $facr_a_prev );
  if ( $facr_a_page === null ) {
    unset( $_GET['page'] );
  } else {
    $_GET['page'] = $facr_a_page;
  }
  require_once ABSPATH . 'wp-admin/includes/user.php';
  foreach ( $facr_a_users as $facr_a_uid ) {
    // > 1 as belt and braces: id 1 is never ours to delete, whatever went wrong.
    if ( $facr_a_uid > 1 ) {
      wp_delete_user( $facr_a_uid );
    }
  }
}

echo $GLOBALS['facr_adm_fail'] ? "\n{$GLOBALS['facr_adm_fail']} FAILURES\n" : "\nAll admin-shell checks passed\n";
