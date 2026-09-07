<?php
declare(strict_types=1);
/** Run with wp eval on an empty local database whose name starts with facr_tests_. */
use FACommissionRules\Store;
use FluentAffiliate\App\Helper\Utility;

if ( ! defined( 'ABSPATH' ) || wp_get_environment_type() !== 'local' || ! str_starts_with( DB_NAME, 'facr_tests_' ) || Store::all() ) {
  echo "SKIP concurrent writes: requires an empty local facr_tests_ database\n";
  return;
}
$dir = sys_get_temp_dir() . '/facr-concurrent-' . bin2hex(random_bytes(6));
mkdir($dir,0700);
$processes = [];
try {
  foreach ([1,2] as $worker) {
    $code = 'require ' . var_export(ABSPATH.'wp-load.php',true) . ';'
      . '\FACommissionRules\Store::all();'
      . 'touch(' . var_export($dir.'/ready'.$worker,true) . ');'
      . '$deadline=microtime(true)+10; while(!file_exists(' . var_export($dir.'/go',true) . ')){if(microtime(true)>$deadline){exit(2);} usleep(10000);}'
      . '[$rule,$errors]=\FACommissionRules\Store::validate(["scope_type"=>"all","target_type"=>"all","rate"=>'.$worker.']);'
      . '$result=\FACommissionRules\Store::save($rule); if(is_wp_error($result)){exit(3);}';
    $processes[] = proc_open([PHP_BINARY,'-r',$code],[0=>['file','/dev/null','r'],1=>['file',$dir.'/out'.$worker,'w'],2=>['file',$dir.'/err'.$worker,'w']],$pipes);
  }
  $deadline = microtime(true)+15;
  while (!file_exists($dir.'/ready1') || !file_exists($dir.'/ready2')) {
    if (microtime(true)>$deadline) { throw new RuntimeException('Workers did not become ready'); }
    usleep(10000);
  }
  touch($dir.'/go');
  foreach ($processes as $process) {
    if (proc_close($process)!==0) { throw new RuntimeException('A worker failed'); }
  }
  $processes = [];
  Utility::forgetCache('option_'.FACR_RULES_KEY);
  if (count(Store::all())!==2) { throw new RuntimeException('A concurrent save was lost'); }
  echo "PASS two workers starting with the same cached collection retain both rules\n";
} finally {
  foreach ($processes as $process) { if (is_resource($process)) { proc_terminate($process); proc_close($process); } }
  Utility::forgetCache('option_'.FACR_RULES_KEY);
  Store::delete_many(array_column(Store::all(),'id'));
  foreach (glob($dir.'/*') as $file) { unlink($file); }
  rmdir($dir);
}
