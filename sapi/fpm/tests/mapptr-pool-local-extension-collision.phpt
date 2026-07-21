--TEST--
FPM: SHM-persisted CE-cache offsets collide with pool-local extension map_ptr slots
--SKIPIF--
<?php
include "skipif.inc";
$extDir = getenv('TEST_FPM_EXTENSION_DIR');
if (!$extDir) die('skip TEST_FPM_EXTENSION_DIR is required');
// opcache provides the shared SHM that carries the baked offset; posix is only
// a convenient pool-local extension with enough internal functions to own the
// colliding band (any such extension works)
foreach (['opcache', 'posix'] as $ext) {
    if (!file_exists("$extDir/$ext.so")) die("skip $ext.so not present in TEST_FPM_EXTENSION_DIR");
}
if (!extension_loaded('zend_test')) die('skip zend_test extension required for the observer');
?>
--FILE--
<?php

require_once "tester.inc";

// Two pools of one master share a single opcache SHM. Only the "fire" pool
// loads a pool-local extension (post-fork), so its map_ptr numbering diverges
// from the "seed" pool's. The CE-cache offset the seed pool bakes into the
// shared interned class name then points, in the fire pool, into the band of
// slots owned by that extension's internal-function run_time_caches.
$cfg = <<<EOT
[global]
error_log = {{FILE:LOG}}

[seed]
listen = {{ADDR[seed]}}
pm = static
pm.max_children = 1
catch_workers_output = yes

[fire]
listen = {{ADDR[fire]}}
pm = static
pm.max_children = 2
pm.max_requests = 1
catch_workers_output = yes
php_admin_value[extension] = posix.so
EOT;

$tester = new FPM\Tester($cfg);

$dir = sys_get_temp_dir();
$seedFile = $dir . '/mapptr_seed.php';
$fireFile = $dir . '/mapptr_fire.php';

// declares the class: persisting it bakes a CE-cache offset (allocated from the
// seed pool's counter) into the SHM-interned name
file_put_contents($seedFile, <<<'PHPCODE'
<?php
class WfLike {
    public static function shreddedUniqueStaticMethodName() { return 42; }
}
echo WfLike::shreddedUniqueStaticMethodName();
PHPCODE);

// only references the class, never declares it, so no bind-time CE-cache heal
file_put_contents($fireFile, <<<'PHPCODE'
<?php
try {
    WfLike::shreddedUniqueStaticMethodName();
    echo "UNEXPECTED-SUCCESS";
} catch (\Error $e) {
    echo $e->getMessage();
}
PHPCODE);

$tester->start(iniEntries: [
    'zend_extension'                 => 'opcache.so',
    'opcache.enable'                 => '1',
    'opcache.enable_cli'             => '0',
    'opcache.jit'                    => 'off',
    // the seed file is written moments before the request; without this the
    // default 2s protection window stops opcache from persisting it at all
    'opcache.file_update_protection' => '0',
    'opcache.jit_buffer_size'        => '0',
    // registering an fcall observer is what makes the engine zero-fill every
    // internal function's run_time_cache slot on each request
    'zend_test.observer.enabled'     => '1',
    'zend_test.observer.show_output' => '0',
]);
$tester->expectLogStartNotices();

$tester
    ->request(address: '{{ADDR[seed]}}', scriptFilename: $seedFile)
    ->expectBody('42');

// each fire request gets a fresh worker (pm.max_requests = 1); on an affected
// build these SIGSEGV instead of raising the class-not-found Error
for ($i = 0; $i < 3; $i++) {
    $tester
        ->request(address: '{{ADDR[fire]}}', scriptFilename: $fireFile)
        ->expectBody('Class "WfLike" not found');
}

$tester->terminate();
$tester->close();
@unlink($seedFile);
@unlink($fireFile);

?>
Done
--EXPECT--
Done
--CLEAN--
<?php
require_once "tester.inc";
FPM\Tester::clean();
@unlink(sys_get_temp_dir() . '/mapptr_seed.php');
@unlink(sys_get_temp_dir() . '/mapptr_fire.php');
?>
