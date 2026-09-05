<?php
declare(strict_types=1);
/**
 * Every Element Plus component the admin app renders must be styled by Fluent
 * Affiliate's own app.min.css and admin.css.
 * Also: no console.log left behind, and strict mode is on.
 * Run: php tests/test-css-coverage.php
 *  or: wp --path=/path/to/wp eval 'require WP_PLUGIN_DIR . "/fluent-affiliate-commission-rules/tests/test-css-coverage.php";'
 * (wp eval-file fatals: strict_types must be the file's first statement, but WP-CLI wraps it.)
 */

$facr_root = dirname( __DIR__ );

$GLOBALS['facr_css_fail'] = $GLOBALS['facr_css_fail'] ?? 0;

if ( ! function_exists( 'facr_css' ) ) {
  function facr_css( string $label, bool $ok ): void {
    if ( $ok ) {
      echo "PASS {$label}\n";
      return;
    }
    $GLOBALS['facr_css_fail']++;
    echo "FAIL {$label}\n";
  }
}

$facr_app     = is_readable( $facr_root . '/assets/admin/app.js' ) ? (string) file_get_contents( $facr_root . '/assets/admin/app.js' ) : '';
$facr_helpers = is_readable( $facr_root . '/assets/admin/helpers.js' ) ? (string) file_get_contents( $facr_root . '/assets/admin/helpers.js' ) : '';
$facr_fluent  = dirname( $facr_root ) . '/fluent-affiliate/assets/admin/app.min.css';
$facr_theme   = dirname( $facr_root ) . '/fluent-affiliate/assets/admin/admin.css';

facr_css( 'app.js exists', $facr_app !== '' );
facr_css( 'app.js uses strict mode', strpos( $facr_app, "'use strict'" ) !== false );
facr_css( 'app.js has no console.log', strpos( $facr_app, 'console.log' ) === false );
facr_css( 'helpers.js has no console.log', strpos( $facr_helpers, 'console.log' ) === false );
facr_css( 'app.js contains no hard-coded English UI text (every visible string comes from i18n.*)', ! preg_match( '/>\s*[A-Z][a-z]+(?: [a-z]+)*\s*</', $facr_app ) );

if ( ! is_readable( $facr_fluent ) || ! is_readable( $facr_theme ) ) {
  echo "SKIP fluent-affiliate/assets/admin/app.min.css or admin.css not found next to this plugin: component css coverage not checked\n";
} else {
  $facr_css_all = (string) file_get_contents( $facr_fluent ) . "\n" . (string) file_get_contents( $facr_theme );

  // Tags that render under a different root class than their own name.
  $facr_alias = [
    'el-table-column' => 'el-table',
    'el-option'       => 'el-select-dropdown',
  ];

  preg_match_all( '/<el-([a-z-]+)/', $facr_app, $facr_m );
  $facr_used = array_values( array_unique( array_map( static fn( string $tag ): string => 'el-' . $tag, $facr_m[1] ) ) );
  if ( strpos( $facr_app, 'ElMessageBox' ) !== false ) {
    $facr_used[] = 'el-message-box';
  }
  if ( strpos( $facr_app, 'ElNotification' ) !== false ) {
    $facr_used[] = 'el-notification';
  }
  if ( strpos( $facr_app, 'v-loading' ) !== false ) {
    $facr_used[] = 'el-loading-mask';
  }
  facr_css( 'app.js renders at least one Element Plus component', count( $facr_used ) > 0 );

  foreach ( $facr_used as $facr_component ) {
    $facr_selector = '.' . ( $facr_alias[ $facr_component ] ?? $facr_component );
    // Word boundary: ".el-select" must not be satisfied by ".el-select-dropdown".
    facr_css( "{$facr_component} is styled ({$facr_selector})", (bool) preg_match( '/' . preg_quote( $facr_selector, '/' ) . '(?![a-z0-9_-])/', $facr_css_all ) );
  }
}

echo $GLOBALS['facr_css_fail'] ? "\n{$GLOBALS['facr_css_fail']} FAILURES\n" : "\nAll css coverage checks passed\n";
if ( PHP_SAPI === 'cli' && ! defined( 'WP_CLI' ) ) {
  exit( $GLOBALS['facr_css_fail'] ? 1 : 0 );
}
