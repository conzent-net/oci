#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build the Joomla plugin zip that the update manifest offers.
 *
 * Joomla reads the manifest at the root of the archive. The 2.0.1 zip that
 * shipped had been made on Windows, so every entry was named with backslashes
 * ("joomla_build\conzent.xml"): on a Linux host that extracts as one file with
 * a backslash in its name, no manifest is found, and the install fails.
 *
 * Usage: php scripts/build-joomla-plugin.php [--check]
 *   --check  verify the published zip instead of writing it (exit 1 if wrong)
 */

$root = dirname(__DIR__);
$source = $root . '/plugins/getconzent_joomla';
$manifestFile = $source . '/conzent.xml';

$manifest = simplexml_load_file($manifestFile);
if ($manifest === false) {
    fwrite(STDERR, "Cannot read {$manifestFile}\n");
    exit(1);
}

$version = (string) $manifest->version;
$target = $root . '/public/downloads/joomla/plg_system_conzent_' . $version . '.zip';
$skip = ['README.md', '.DS_Store'];

/** @return list<array{0: string, 1: string}> path => entry name, forward slashes only */
$collect = static function (string $dir, string $prefix = '') use (&$collect, $skip): array {
    $out = [];
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || in_array($entry, $skip, true)) {
            continue;
        }
        $path = $dir . '/' . $entry;
        $name = $prefix === '' ? $entry : $prefix . '/' . $entry;
        if (is_dir($path)) {
            $out = array_merge($out, $collect($path, $name));
            continue;
        }
        $out[] = [$path, $name];
    }

    return $out;
};

$files = $collect($source);
usort($files, static fn (array $a, array $b): int => strcmp($a[1], $b[1]));

if (in_array('--check', $argv, true)) {
    $zip = new ZipArchive();
    if ($zip->open($target) !== true) {
        fwrite(STDERR, "Cannot open {$target}\n");
        exit(1);
    }
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = (string) $zip->getNameIndex($i);
    }
    $zip->close();

    $problems = [];
    foreach ($names as $name) {
        if (str_contains($name, '\\')) {
            $problems[] = "backslash in entry: {$name}";
        }
    }
    if (!in_array('conzent.xml', $names, true)) {
        $problems[] = 'no conzent.xml at the root of the archive';
    }
    foreach ($problems as $problem) {
        fwrite(STDERR, $problem . "\n");
    }
    echo $problems === [] ? "OK — {$target} has " . count($names) . " entries, manifest at the root\n" : '';
    exit($problems === [] ? 0 : 1);
}

@mkdir(dirname($target), 0o775, true);
$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot write {$target}\n");
    exit(1);
}
foreach ($files as [$path, $name]) {
    $zip->addFile($path, $name);
}
$zip->close();

echo 'Wrote ', $target, ' (', count($files), ' files, ', filesize($target), " bytes)\n";
foreach ($files as [, $name]) {
    echo '  ', $name, "\n";
}
