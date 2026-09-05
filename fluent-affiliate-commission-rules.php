<?php
/**
 * Plugin Name:       Commission Rules for Fluent Affiliate
 * Plugin URI:        https://github.com/gremy/fluent-affiliate-commission-rules
 * Description:       Per-affiliate and per-group commission rules for Fluent Affiliate, targeted at a product, a product category, or everything, with an optional date window.
 * Version:           1.1.0
 * Requires at least: 6.6
 * Tested up to:      6.8
 * Requires PHP:      8.1
 * Requires Plugins:  fluent-affiliate
 * Author:            Webbership
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fa-commission-rules
 * Domain Path:       /languages
 *
 * @package FACommissionRules
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'FACR_FILE', __FILE__ );
define( 'FACR_DIR', plugin_dir_path( __FILE__ ) );
define( 'FACR_URL', plugin_dir_url( __FILE__ ) );
define( 'FACR_VERSION', '1.1.0' );
define( 'FACR_RULES_KEY', '_fa_commission_rules' );
define( 'FACR_PAGE', 'fa-commission-rules' );

spl_autoload_register(
  static function ( string $class ): void {
    $prefix = 'FACommissionRules\\';
    if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
      return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $path     = FACR_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( is_readable( $path ) ) {
      require_once $path;
    }
  }
);

// The plugin never touches the orders tables directly — it reads line items off
// the WC_Order object Fluent hands it — so HPOS is safe. Say so, or WooCommerce
// lists us as incompatible and refuses to let a store turn HPOS on.
add_action(
  'before_woocommerce_init',
  static function (): void {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
      \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
  }
);

// Fluent Affiliate boots its own app on plugins_loaded; 20 lands after it.
add_action( 'plugins_loaded', [ 'FACommissionRules\\Plugin', 'boot' ], 20 );
