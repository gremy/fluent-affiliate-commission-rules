<?php
/**
 * Removes the rules collection when the plugin is uninstalled.
 *
 * The collection lives in Fluent Affiliate's own option storage, so this is a
 * no-op when Fluent is already gone — its own uninstall took the table with it.
 *
 * @package FACommissionRules
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( class_exists( '\FluentAffiliate\App\Helper\Utility' ) && method_exists( '\FluentAffiliate\App\Helper\Utility', 'deleteOption' ) ) {
  \FluentAffiliate\App\Helper\Utility::deleteOption( '_fa_commission_rules' );
  return;
}

// Fluent's helper is not loaded (deactivated, or the class moved) but its table
// may still be there, holding our row. Delete it the way Utility::deleteOption()
// does: the fa_meta table, object_type 'option', meta_key = our key.
// ponytail: single-site only. Fluent Affiliate stores per site, so a network
// uninstall would need to loop the blogs — add that if Fluent ever goes network-wide.
global $wpdb;

$facr_table = $wpdb->prefix . 'fa_meta';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall, no cache to keep.
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $facr_table ) ) ) === $facr_table ) {
  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- ditto.
  $wpdb->delete(
    $facr_table,
    [
      'object_type' => 'option',
      'meta_key'    => '_fa_commission_rules', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- indexed by Fluent's own migrator.
    ],
    [ '%s', '%s' ]
  );
}
