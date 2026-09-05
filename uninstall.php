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
}
