<?php

declare(strict_types=1);

// Reproducible local artifact; does not tag, push or contact a package registry.
$root = dirname(__DIR__);
$version = $argv[1] ?? '';
$target = $argv[2] ?? '';
if (!preg_match('/^\d+\.\d+\.\d+-beta\.\d+$/D', $version) || !str_starts_with($target, '/')
    || !is_dir(dirname($target)) || file_exists($target) || !str_ends_with($target, '.zip')) {
    throw new RuntimeException('Pass a prerelease version and a new absolute artifact path');
}
$manifest = json_decode(file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$manifest['version'] = $version;
$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Cannot create artifact');
$zip->addFromString('composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
$zip->addFile($root.'/README.md', 'README.md');
foreach (['src', 'tests', 'docs', 'scripts', 'database'] as $folder) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$folder, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->isLink()) continue;
        $name = substr($file->getPathname(), strlen($root) + 1);
        $zip->addFile($file->getPathname(), $name);
        $zip->setCompressionName($name, ZipArchive::CM_DEFLATE);
    }
}
$zip->close();
echo basename($target).' '.hash_file('sha256',$target)."\n";
