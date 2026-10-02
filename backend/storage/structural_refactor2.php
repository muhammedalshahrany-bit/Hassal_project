<?php
// Structural refactor engine v2 — module-first layout move.
// Differences from v1: full pre-validation before any write, verified writes
// before unlinking sources, and automatic `use` injection for files whose
// same-namespace siblings move to a different module (unqualified references
// like `Warehouse::class` inside models would otherwise silently break).

$root = 'D:/hassal/backend';

$map = require "$root/storage/refactor_map.php";
$move = $map['move'];
$delete = $map['delete'];
$tests = $map['tests'];

$fqnOf = fn (string $path): string => 'App\\' . str_replace('/', '\\', preg_replace(['#^app/#', '#\.php$#'], '', $path));

// ── 0) Validate everything BEFORE touching the disk ─────────────────────
$errors = [];
$targets = array_values($move);
if (count($targets) !== count(array_unique($targets))) {
    $errors[] = 'duplicate move targets exist';
}
foreach ($move as $old => $new) {
    if (!is_file("$root/$old")) $errors[] = "missing source: $old";
    if (is_file("$root/$new") && !isset($move[$new])) $errors[] = "target already occupied: $new";
    $src = @file_get_contents("$root/$old") ?: '';
    $oldNs = $fqnOf(dirname($old));
    if (!preg_match('/^namespace\s+' . preg_quote($oldNs, '/') . '\s*;/m', $src)) {
        $errors[] = "namespace '$oldNs' not found in $old";
    }
}
foreach ($delete as $d) {
    if (file_exists("$root/$d") && !is_file("$root/$d")) $errors[] = "delete path is not a file: $d";
}
$allTests = [];
foreach ($tests as $folder => $names) {
    foreach ($names as $t) {
        if (isset($allTests[$t])) $errors[] = "test $t listed twice";
        $allTests[$t] = $folder;
        if (!is_file("$root/tests/Feature/$t.php")) $errors[] = "missing test: $t";
    }
}
if ($errors) {
    fwrite(STDERR, "ABORT (nothing changed):\n" . implode("\n", $errors) . "\n");
    exit(1);
}
echo "validated: " . count($move) . " moves, " . count($delete) . " deletes, " . count($allTests) . " tests\n";

// ── 1) Global FQN reference rewrite across every scanned PHP file ───────
$fqnMap = [];
foreach ($move as $old => $new) {
    $fqnMap[$fqnOf($old)] = $fqnOf($new);
}

$scanDirs = ['app', 'routes', 'config', 'database', 'tests', 'bootstrap'];
$files = [];
foreach ($scanDirs as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') $files[] = $file->getPathname();
    }
}

$changed = 0;
foreach ($files as $file) {
    $src = file_get_contents($file);
    $out = strtr($src, $fqnMap);
    if ($out !== $src) {
        file_put_contents($file, $out);
        $changed++;
    }
}
echo "reference-rewritten files: $changed\n";
if ($changed < 50) {
    fwrite(STDERR, "ABORT: only $changed files reference-rewritten; expected most of the tree. Nothing else changed.\n");
    exit(1);
}

// ── 2) Per-file namespace fix + sibling-import injection, then move ─────
$byOldDir = [];
foreach ($move as $old => $new) $byOldDir[dirname($old)][] = $old;

$injected = 0;
$moved = 0;
foreach ($move as $old => $new) {
    $path = "$root/$old";
    $src = file_get_contents($path);

    $oldNs = $fqnOf(dirname($old));
    $newNs = $fqnOf(dirname($new));
    $src = preg_replace(
        '/^namespace\s+' . preg_quote($oldNs, '/') . '\s*;/m',
        'namespace ' . $newNs . ';',
        $src,
        1,
        $c
    );
    if ($c !== 1) {
        fwrite(STDERR, "ABORT mid-run (manual fix needed): namespace line not replaced in $old\n");
        exit(2);
    }

    // Same old namespace, different new namespace: any unqualified sibling
    // reference now needs an explicit import.
    $need = [];
    $probe = preg_replace('/^(?:namespace|use)\s+[^;]+;\s*$/m', '', $src);
    foreach ($byOldDir[dirname($old)] ?? [] as $siblingOld) {
        if ($siblingOld === $old) continue;
        $siblingNew = $move[$siblingOld];
        if (dirname($siblingNew) === dirname($new)) continue; // travels together
        $class = basename($siblingOld, '.php');
        if (preg_match('/^use\s+[^;]*\\\\' . preg_quote($class, '/') . '\s*;/m', $src)) continue; // already imported
        if (preg_match('/(?<![\w\\\\$>])' . preg_quote($class, '/') . '\b/', $probe)) {
            $need[] = $fqnOf($siblingNew);
        }
    }
    if ($need !== []) {
        $block = implode("\n", array_map(fn (string $f): string => "use $f;", $need));
        $src = preg_replace_callback(
            '/^namespace\s+[^;]+;/m',
            fn (array $m): string => $m[0] . "\n\n" . $block,
            $src,
            1
        );
        $injected += count($need);
    }

    $target = "$root/$new";
    $dir = dirname($target);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fwrite(STDERR, "ABORT: cannot create $dir\n");
        exit(2);
    }
    $written = file_put_contents($target, $src);
    if ($written === false || $written !== strlen($src) || filesize($target) < 1) {
        fwrite(STDERR, "ABORT: write failed for $new (source kept)\n");
        exit(2);
    }
    unlink($path);
    $moved++;
}
echo "moved: $moved (injected imports: $injected)\n";

// ── 3) Test folders: namespace rewrite + move ───────────────────────────
$testsMoved = 0;
foreach ($tests as $folder => $names) {
    if (!is_dir("$root/tests/Feature/$folder") && !mkdir("$root/tests/Feature/$folder", 0777, true)) {
        fwrite(STDERR, "cannot create tests/Feature/$folder\n");
        exit(2);
    }
    foreach ($names as $t) {
        $old = "$root/tests/Feature/$t.php";
        $src = file_get_contents($old);
        $src = preg_replace('/^namespace\s+Tests\\\\Feature\s*;/m', "namespace Tests\\Feature\\$folder;", $src, 1, $c);
        if ($c !== 1) {
            fwrite(STDERR, "ABORT: test namespace line not found in $t\n");
            exit(2);
        }
        if (file_put_contents($old, $src) === false) {
            fwrite(STDERR, "ABORT: cannot write $t\n");
            exit(2);
        }
        if (!rename($old, "$root/tests/Feature/$folder/$t.php")) {
            fwrite(STDERR, "ABORT: cannot move test $t\n");
            exit(2);
        }
        $testsMoved++;
    }
}
echo "tests moved: $testsMoved\n";

// ── 4) Delete dead code ─────────────────────────────────────────────────
foreach ($delete as $d) {
    if (is_file("$root/$d")) { unlink("$root/$d"); echo "deleted $d\n"; }
}

// ── 5) Prune empty directories under app ────────────────────────────────
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator("$root/app", FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $file) {
    if ($file->isDir()) {
        $remaining = iterator_count(new FilesystemIterator($file->getPathname(), FilesystemIterator::SKIP_DOTS));
        if ($remaining === 0) {
            rmdir($file->getPathname());
            echo 'rmdir ' . str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)) . "\n";
        }
    }
}
echo "DONE\n";
