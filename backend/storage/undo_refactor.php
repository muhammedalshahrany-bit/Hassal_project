<?php
// Undo the disaster: invert the FQN rewrite on surviving files, move tests back,
// then restore deleted app files from HEAD and prune files that did not exist
// pre-disaster (deleted earlier on purpose).

$root = 'D:/hassal/backend';
$map = require "$root/storage/refactor_map.php";

$fqnOf = fn (string $path) => 'App\\' . str_replace('/', '\\', preg_replace('#^app/#', '', $path));

// --- 1) inverse FQN substitution on surviving files -----------------------
$inv = [];
foreach ($map['move'] as $old => $new) $inv[$fqnOf($new)] = $fqnOf($old);

$dirs = ['app', 'routes', 'config', 'database', 'bootstrap'];
$changed = 0;
foreach ($dirs as $dir) {
    if (!is_dir("$root/$dir")) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;
        $src = file_get_contents($file->getPathname());
        $out = strtr($src, $inv);
        if ($out !== $src) { file_put_contents($file->getPathname(), $out); $changed++; }
    }
}
echo "inverse-rewritten (app part): $changed\n";

// --- 2) tests: only inverse content rewrite here (they have no App\\ ns refs in declarations)
$tchanged = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/tests", FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $src = file_get_contents($file->getPathname());
    $out = strtr($src, $inv);
    if ($out !== $src) { file_put_contents($file->getPathname(), $out); $tchanged++; }
}
echo "tests content reverted: $tchanged\n";

// --- 3) move test files back and revert their namespace --------------------
$back = 0;
foreach ($map['tests'] as $folder => $names) {
    foreach ($names as $t) {
        $cur = "$root/tests/Feature/$folder/$t.php";
        if (!is_file($cur)) { echo "test not in folder (ok): $t\n"; continue; }
        $src = file_get_contents($cur);
        $src = preg_replace('/^namespace\s+Tests\\\\Feature\\\\' . preg_quote($folder, '/') . '\s*;/m', 'namespace Tests\Feature;', $src, 1, $c);
        if ($c !== 1) { fwrite(STDERR, "namespace revert failed: $t\n"); exit(1); }
        file_put_contents("$root/tests/Feature/$t.php", $src);
        unlink($cur);
        $back++;
    }
}
echo "tests moved back: $back\n";
// prune now-empty test folders
foreach (glob("$root/tests/Feature/*", GLOB_ONLYDIR) as $d) {
    if (iterator_count(new FilesystemIterator($d, FilesystemIterator::SKIP_DOTS)) === 0) { rmdir($d); echo "rmdir " . basename($d) . "\n"; }
}

// --- 4) pre-disaster app/ file set ----------------------------------------
$preSet = [];
foreach (array_keys($map['move']) as $p) $preSet[$p] = true;
foreach ($map['delete'] as $p) $preSet[$p] = true; // existed pre-disaster; deleted again below

// files that legitimately stayed: Console, Providers
$keeps = [];
foreach (['app/Console', 'app/Providers'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->isFile()) $keeps[str_replace("\\", '/', substr($f->getPathname(), strlen($root) + 1))] = true;
}
foreach ($keeps as $p => $_) $preSet[$p] = true;

file_put_contents("$root/storage/predisaster_app_set.txt", implode("\n", array_keys($preSet)) . "\n");
echo 'pre-disaster app file count: ' . count($preSet) . "\n";
echo "NOW RUN: git restore of deleted backend/app files, then prune_extras.php\n";
