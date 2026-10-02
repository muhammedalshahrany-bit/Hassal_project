<?php
// Line-anchored removal of the three duplicate blocks in Offer.php.
$p = 'D:/hassal/backend/app/Models/Offer.php';
$lines = file($p); // 0-based; keep "\n"

$find = function (string $needle, int $from = 0) use ($lines): int {
    foreach ($lines as $i => $l) { if ($i >= $from && strpos($l, $needle) !== false) return $i; }
    return -1;
};

$ranges = [];

// 1) second isExpired: docblock line contains "own closing date has passed"
$a = $find("own closing date has passed");
if ($a > 0) {
    $start = $a - 1; // /**
    $end = $find("isPast()", $a) + 1; // the "}" after return line
    $ranges[] = [$start - 1, $end]; // include preceding blank
}
// 2) trailing getCategoryId: anchor on the docblock line, but take the occurrence whose method is followed by items()
$occ = [];
foreach ($lines as $i => $l) if (strpos($l, 'public function getCategoryIdAttribute') !== false) $occ[] = $i;
if (count($occ) === 2) {
    $a = $occ[1];
    $start = $a; while (strpos(trim($lines[$start]), '/**') !== 0) $start--;
    $end = $a; while (trim($lines[$end]) !== "}") { $end++; }
    $ranges[] = [$start - 1, $end];
}
// 3) trailing featuredBy (last one in file)
$occ = [];
foreach ($lines as $i => $l) if (strpos($l, 'public function featuredBy') !== false) $occ[] = $i;
if (count($occ) === 2) {
    $a = $occ[1];
    $start = $a; while (strpos(trim($lines[$start]), '/**') !== 0) $start--;
    $end = $a; while (trim($lines[$end]) !== "}") { $end++; }
    $ranges[] = [$start - 1, $end];
}

if (!$ranges) { echo "nothing found\n"; exit(1); }
usort($ranges, fn ($x, $y) => $y[0] <=> $x[0]);
foreach ($ranges as [$s, $e]) {
    echo "cut lines ", $s + 1, "-", $e + 1, ": ", trim($lines[$s + 1] ?? ''), "\n";
    array_splice($lines, $s, $e - $s + 1);
}
file_put_contents($p, implode('', $lines));
echo "lines now: ", count($lines), "\n";
