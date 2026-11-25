<?php

declare(strict_types=1);

// Full-sweep Twig lint: tokenize + parse every template in templates/ and
// every module's templates dir (namespaced). Fails on any syntax error.
// Path-relative so it runs on a bare runner and inside the app container.
//
//   php scripts/ci/twig-lint.php

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$roots = [$root . '/templates' => null];
foreach (glob($root . '/src/Modules/*/templates') ?: [] as $dir) {
    $roots[$dir] = basename(dirname($dir));
}

$loader = new \Twig\Loader\FilesystemLoader($root . '/templates');
foreach ($roots as $dir => $ns) {
    if ($ns !== null) {
        $loader->addPath($dir, $ns);
    }
}
$twig = new \Twig\Environment($loader);
foreach (['url', 'path', 'trans', 'asset'] as $f) {
    $twig->addFunction(new \Twig\TwigFunction($f, static fn () => ''));
}
$twig->addFilter(new \Twig\TwigFilter('trans', static fn ($v) => $v));

$errors = 0;
$count = 0;
foreach ($roots as $dir => $ns) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.twig')) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
        $name = $ns !== null ? '@' . $ns . '/' . $rel : $rel;
        $count++;
        try {
            $twig->parse($twig->tokenize(new \Twig\Source((string) file_get_contents($file->getPathname()), $name)));
        } catch (\Throwable $e) {
            $errors++;
            echo "FAIL {$name}: {$e->getMessage()}\n";
        }
    }
}

echo $errors === 0 ? "OK — {$count} templates parsed clean\n" : "{$errors} template error(s) in {$count} templates\n";
exit($errors === 0 ? 0 : 1);
