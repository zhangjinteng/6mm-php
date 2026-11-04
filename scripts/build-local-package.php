<?php
declare(strict_types=1);

// Build a portable Composer artifact without publishing or changing source version metadata.
$root = dirname(__DIR__);
$version = $argv[1] ?? '';
$output = $argv[2] ?? '';
if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-beta\.[0-9]+)?$/D', $version) || !is_dir($output)) {
    throw new InvalidArgumentException('Usage: php scripts/build-local-package.php VERSION EXISTING_OUTPUT_DIRECTORY');
}
$path = rtrim($output, '/').'/6mm-php-'.$version.'-customer-service.zip';
if (file_exists($path)) throw new RuntimeException('Artifact already exists; do not overwrite a versioned package');
$zip = new ZipArchive();
if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Cannot create artifact');
$manifest = json_decode(file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$manifest['version'] = $version;
$zip->addFromString('composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
foreach (['src', 'database', 'docs'] as $directory) {
    if (!is_dir($root.'/'.$directory)) continue;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) if ($file->isFile()) $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($root) + 1));
}
foreach (['README.md', 'LICENSE'] as $file) if (is_file($root.'/'.$file)) $zip->addFile($root.'/'.$file, $file);
$zip->close();
echo $path."\n";
