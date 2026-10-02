<?php
// Extract the move map from the disaster script into a reusable map file.
$src = file_get_contents('D:/hassal/backend/storage/structural_refactor.php');
$cut = strpos($src, '// Validate the map');
$head = substr($src, 0, $cut);
$code = "<?php\nreturn (function () {\n" . preg_replace('/^\s*<\?php\s*/', '', $head, 1) . "\nreturn ['move' => \$move, 'delete' => \$delete, 'tests' => \$tests];\n})();\n";
file_put_contents('D:/hassal/backend/storage/refactor_map.php', $code);
$map = require 'D:/hassal/backend/storage/refactor_map.php';
echo 'move entries: ' . count($map['move']) . "\n";
echo 'delete entries: ' . count($map['delete']) . "\n";
$n = 0; foreach ($map['tests'] as $f => $l) $n += count($l);
echo 'test entries: ' . $n . "\n";
$paths = [];
foreach ($map['move'] as $o => $nw) {
    if (isset($paths[$nw])) { echo "COLLISION: $nw\n"; exit(1); }
    $paths[$nw] = $o;
}
echo "targets all unique\n";
