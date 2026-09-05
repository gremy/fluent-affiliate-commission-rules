<?php
declare(strict_types=1);

namespace FACommissionRules;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin once Fluent Affiliate is present.
 *
 * @package FACommissionRules
 */
final class Plugin {
  public static function boot(): void {
    add_action( 'init', [ __CLASS__, 'load_textdomain' ] );

    if ( ! Fluent::ready() ) {
      add_action( 'admin_notices', [ __CLASS__, 'missing_dependency_notice' ] );
      return;
    }

    ( new ReferralHooks() )->register();

    // Widgets stays global: portal_notice_html renders on the front end.
    ( new Admin\Widgets() )->register();

    // The REST API is global too: rest_api_init fires outside is_admin().
    ( new Rest\Controller() )->register();

    if ( is_admin() ) {
      ( new Admin\Menu() )->register();
      ( new Admin\RuleForm() )->register();
    }

    add_action( 'fluent_affiliate/after_delete_affiliate', [ Store::class, 'forget_affiliate' ] );
    add_action( 'fluent_affiliate/after_delete_affiliate_group', [ Store::class, 'forget_group' ] );
  }

  public static function load_textdomain(): void {
    // init, not plugins_loaded, to avoid the WP 6.7 just-in-time textdomain notice.
    load_plugin_textdomain( 'fa-commission-rules', false, dirname( plugin_basename( FACR_FILE ) ) . '/languages' );
  }

  public static function missing_dependency_notice(): void {
    if ( ! current_user_can( 'activate_plugins' ) ) {
      return;
    }
    printf(
      '<div class="notice notice-warning"><p>%s</p></div>',
      esc_html(
        sprintf(
          /* translators: %s: minimum Fluent Affiliate version */
          __( 'Commission Rules for Fluent Affiliate needs Fluent Affiliate %s or newer to be installed and active.', 'fa-commission-rules' ),
          Fluent::MIN_VERSION
        )
      )
    );
  }
}
