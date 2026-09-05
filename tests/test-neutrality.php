<?php
declare(strict_types=1);
/**
 * Brand-neutrality and text-domain guard for the published plugin.
 * Run: php tests/test-neutrality.php
 *  or: wp --path=/Users/gremy/sites/ovride/ovride_woo eval-file wp-content/plugins/fluent-affiliate-commission-rules/tests/test-neutrality.php
 */

$facr_root = dirname( __DIR__ );

// $GLOBALS, not a plain local: under `wp eval-file` this file runs inside a
// function body, so a `global $facr_fail` in the helper would bind a different
// variable than the one at the top of the file and every failure would be lost.
$GLOBALS['facr_fail'] = $GLOBALS['facr_fail'] ?? 0;

if ( ! function_exists( 'facr_assert' ) ) {
  function facr_assert( string $label, bool $ok ): void {
    if ( $ok ) {
      echo "PASS {$label}\n";
      return;
    }
    $GLOBALS['facr_fail']++;
    echo "FAIL {$label}\n";
  }
}

/** @return string[] every PHP/MD/TXT/POT file shipped in the plugin */
function facr_plugin_files( string $root ): array {
  $out      = [];
  $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
  foreach ( $iterator as $file ) {
    if ( preg_match( '/\.(php|md|txt|pot|json|css|js)$/i', $file->getFilename() ) ) {
      $out[] = $file->getPathname();
    }
  }
  sort( $out );
  return $out;
}

$files = facr_plugin_files( $facr_root );
facr_assert( 'plugin has files to scan', count( $files ) > 0 );

$branded = [];
foreach ( $files as $file ) {
  if ( basename( $file ) === 'test-neutrality.php' ) {
    continue; // this file necessarily contains the banned word
  }
  if ( preg_match( '/ovride/i', (string) file_get_contents( $file ) ) ) {
    $branded[] = $file;
  }
}
facr_assert( 'no store branding in plugin files: ' . implode( ', ', $branded ), $branded === [] );

$wrong_domain = [];
foreach ( $files as $file ) {
  if ( substr( $file, -4 ) !== '.php' || basename( $file ) === 'test-neutrality.php' ) {
    continue;
  }
  $src = (string) file_get_contents( $file );
  if ( preg_match_all( "/\b(?:__|_e|_n|_x|_nx|_ex|esc_html__|esc_html_e|esc_html_x|esc_attr__|esc_attr_e|esc_attr_x|esc_xml__|_n_noop|_nx_noop)\s*\(.*?,\s*'([a-z0-9-]+)'\s*\)/s", $src, $m ) ) {
    foreach ( $m[1] as $domain ) {
      if ( $domain !== 'fa-commission-rules' ) {
        $wrong_domain[] = basename( $file ) . ':' . $domain;
      }
    }
  }
}
facr_assert( 'every translated string uses the fa-commission-rules domain: ' . implode( ', ', $wrong_domain ), $wrong_domain === [] );

$header = (string) file_get_contents( $facr_root . '/fluent-affiliate-commission-rules.php' );
facr_assert( 'plugin header declares the text domain', strpos( $header, 'Text Domain:       fa-commission-rules' ) !== false );
facr_assert( 'plugin header declares GPL-2.0-or-later', strpos( $header, 'GPL-2.0-or-later' ) !== false );
facr_assert( 'plugin header declares its Fluent Affiliate dependency', strpos( $header, 'Requires Plugins:  fluent-affiliate' ) !== false );

echo $GLOBALS['facr_fail'] ? "\n{$GLOBALS['facr_fail']} FAILURES\n" : "\nAll neutrality checks passed\n";
if ( PHP_SAPI === 'cli' && ! defined( 'WP_CLI' ) ) {
  exit( $GLOBALS['facr_fail'] ? 1 : 0 );
}
