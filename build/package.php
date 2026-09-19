<?php
/** Build an installable module ZIP from a fixed allowlist (no local/test data). */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$version = isset($argv[1]) ? $argv[1] : '';
if (!preg_match('/^\d+\.\d+\.\d+$/D', $version) || !class_exists('ZipArchive')) {
    fwrite(STDERR, "Usage: php build/package.php X.Y.Z [output-directory] (requires ext-zip)\n");
    exit(1);
}
$root = dirname(__DIR__);
$dist = isset($argv[2]) ? $argv[2] : $root.'/dist';
if (!is_dir($dist) && !mkdir($dist, 0775, true)) throw new RuntimeException('Cannot create dist directory');
$target = $dist.'/module_bankimport-'.$version.'.zip';
$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot create ZIP');
$files = array('import.php', 'README.md', 'INSTALL.md', 'PERMISSIONS.md', 'ChangeLog.md', 'License.txt');
foreach (array('core', 'langs', 'img') as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isLink()) throw new RuntimeException('Symlinks are not allowed in the package');
        if ($file->isFile()) $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    }
}
sort($files);
foreach ($files as $file) {
    if (!$zip->addFile($root.'/'.$file, 'bankimport/'.$file)) throw new RuntimeException('Cannot add '.$file);
}
$descriptor = str_replace('{{VERSION}}', $version, file_get_contents(__DIR__.'/module_descriptor.json'));
json_decode($descriptor, true, 512, JSON_THROW_ON_ERROR);
$zip->addFromString('bankimport/module_descriptor.json', $descriptor);
$zip->addFromString('bankimport/.env', 'VERSION='.$version."\n");
if (!$zip->close()) throw new RuntimeException('Cannot finish ZIP');
echo $target."\nSHA256 ".hash_file('sha256', $target)."\n";
