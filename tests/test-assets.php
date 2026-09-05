<?php
declare(strict_types=1);
/**
 * Vendored front-end assets: present, and pinned to the versions the plugin was verified with.
 * Run: php tests/test-assets.php
 *  or: wp --path=/path/to/wp eval 'require WP_PLUGIN_DIR . "/fluent-affiliate-commission-rules/tests/test-assets.php";'
 * (wp eval-file fatals: strict_types must be the file's first statement, but WP-CLI wraps it.)
 */

$facr_vendor = dirname( __DIR__ ) . '/assets/vendor/';

$GLOBALS['facr_assets_fail'] = $GLOBALS['facr_assets_fail'] ?? 0;

if ( ! function_exists( 'facr_at' ) ) {
  function facr_at( string $label, bool $ok ): void {
    if ( $ok ) {
      echo "PASS {$label}\n";
      return;
    }
    $GLOBALS['facr_assets_fail']++;
    echo "FAIL {$label}\n";
  }
}

$facr_vue   = is_readable( $facr_vendor . 'vue.global.prod.js' ) ? (string) file_get_contents( $facr_vendor . 'vue.global.prod.js' ) : '';
$facr_ep    = is_readable( $facr_vendor . 'element-plus.full.min.js' ) ? (string) file_get_contents( $facr_vendor . 'element-plus.full.min.js' ) : '';
$facr_ver   = is_readable( $facr_vendor . 'VERSIONS.md' ) ? (string) file_get_contents( $facr_vendor . 'VERSIONS.md' ) : '';

facr_at( 'vue.global.prod.js is present', $facr_vue !== '' );
facr_at( 'vue is the 3.5.17 production global build', strpos( $facr_vue, '* vue v3.5.17' ) !== false && strpos( $facr_vue, 'var Vue=function' ) !== false );
facr_at( 'element-plus.full.min.js is present', $facr_ep !== '' );
facr_at( 'element-plus is pinned to 2.9.11', strpos( $facr_ep, '"2.9.11"' ) !== false );
facr_at( 'element-plus bundle exposes the two extra components', strpos( $facr_ep, 'ElMessageBox' ) !== false && strpos( $facr_ep, 'ElNotification' ) !== false );
facr_at( 'VERSIONS.md records both pins', strpos( $facr_ver, '3.5.17' ) !== false && strpos( $facr_ver, '2.9.11' ) !== false );

echo $GLOBALS['facr_assets_fail'] ? "\n{$GLOBALS['facr_assets_fail']} FAILURES\n" : "\nAll asset checks passed\n";
if ( PHP_SAPI === 'cli' && ! defined( 'WP_CLI' ) ) {
  exit( $GLOBALS['facr_assets_fail'] ? 1 : 0 );
}
