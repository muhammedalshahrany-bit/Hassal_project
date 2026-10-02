<?php
// After git-restoring deleted backend/app files from HEAD: remove files that did
// not exist in the working tree pre-disaster.
$root = 'D:/hassal/backend';
$map = require "$root/storage/refactor_map.php";
$pre = array_flip(explode("\n", trim(file_get_contents("$root/storage/predisaster_app_set.txt"))));
$dead = array_flip($map['delete']);

exec('git -C D:/hassal ls-tree -r --name-only HEAD -- backend/app', $lines);
$removed = 0; $kept = 0;
foreach ($lines as $line) {
    $rel = preg_replace('#^backend/#', '', trim(str_replace('\\', '/', $line)));
    if (!isset($pre[$rel])) {
        if (is_file("$root/$rel")) { unlink("$root/$rel"); echo "pruned (never existed pre-disaster): $rel\n"; $removed++; }
    } elseif (isset($dead[$rel])) {
        if (is_file("$root/$rel")) { unlink("$root/$rel"); echo "re-deleted dead code: $rel\n"; $removed++; }
    } else $kept++;
}
echo "kept: $kept pruned: $removed\n";
// prune empty dirs under app
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/app", FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) if ($f->isDir() && iterator_count(new FilesystemIterator($f->getPathname(), FilesystemIterator::SKIP_DOTS)) === 0) rmdir($f->getPathname());
