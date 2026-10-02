<?php
// Replay both session transcripts to reconstruct the exact pre-disaster content
// of every backend app/ file that was touched after the last commit (2026-09-24).

ini_set('memory_limit', '3G');
$root = 'D:/hassal/backend';
$transcripts = [
    'C:/Users/alshh/.qoder/projects/D--hassal-app-flutter/01160dd6-f8b1-4ee2-a4e2-fcbc55a6730c.jsonl',
    'C:/Users/alshh/.qoder/projects/D--hassal-app-flutter/91058cfd-f9a7-4637-a5bb-275d9ccd9efd.jsonl',
];

$canon = []; // lowercase key => original-case relative path
$norm = function (string $p) use (&$canon): ?string {
    $p = str_replace('\\', '/', $p);
    if (!preg_match('#^D:/hassal/backend/(app/.+\.php)$#i', $p, $m)) return null;
    $canon[strtolower($m[1])] = $m[1];
    return strtolower($m[1]);
};

$snap = [];   // key => ['seq'=>int,'content'=>string]
$edits = [];  // key => [ ['seq','old','new','multi'], ... ]
$seq = 0;
$pending = [];

foreach ($transcripts as $t) {
    $f = fopen($t, 'r');
    if (!$f) { fwrite(STDERR, "cannot open $t\n"); exit(1); }
    while (($l = fgets($f)) !== false) {
        if (strpos($l, '"tool_use"') === false && strpos($l, '"tool_result"') === false) continue;
        $o = json_decode($l, true);
        if (!$o) continue;
        $m = $o['message'] ?? null;
        if (!$m || !is_array($m['content'] ?? null)) continue;
        foreach ($m['content'] as $c) {
            if (!is_array($c)) continue;
            $type = $c['type'] ?? '';
            if ($type === 'tool_use') {
                $seq++;
                $name = $c['name'] ?? '';
                if ($name === 'Write') {
                    $k = $norm($c['input']['file_path'] ?? '');
                    if ($k !== null && isset($c['input']['content'])) $snap[$k] = ['seq' => $seq, 'content' => $c['input']['content']];
                } elseif ($name === 'Edit') {
                    $k = $norm($c['input']['file_path'] ?? '');
                    if ($k !== null && isset($c['input']['old_string'], $c['input']['new_string'])) {
                        $edits[$k][] = ['seq' => $seq, 'old' => $c['input']['old_string'], 'new' => $c['input']['new_string'], 'multi' => !empty($c['input']['allow_multiple'])];
                    }
                } elseif ($name === 'Read') {
                    $pending[$c['id']] = ['input' => $c['input'], 'seq' => $seq];
                }
            } elseif ($type === 'tool_result' && isset($pending[$c['tool_use_id']])) {
                $p = $pending[$c['tool_use_id']];
                unset($pending[$c['tool_use_id']]);
                $in = $p['input'];
                if (($in['offset'] ?? null) !== null || ($in['limit'] ?? null) !== null) continue;
                $k = $norm($in['file_path'] ?? '');
                if ($k === null) continue;
                $ct = $c['content'];
                if (is_array($ct)) { $s = ''; foreach ($ct as $part) if (($part['type'] ?? '') === 'text') $s .= $part['text']; $ct = $s; }
                if (!is_string($ct) || $ct === '' || strncmp($ct, '1\t', 2) !== 0) continue;
                $lines = explode("\n", $ct);
                $out = []; $ok = true;
                foreach ($lines as $i => $line) {
                    if ($line === '' && $i === count($lines) - 1) continue; // trailing newline
                    if (preg_match('/^\d+\t/', $line)) { $out[] = preg_replace('/^\d+\t/', '', $line, 1); }
                    else { $ok = false; break; }
                }
                if ($ok && $out) $snap[$k] = ['seq' => $p['seq'], 'content' => implode("\n", $out) . "\n"];
            }
        }
    }
    fclose($f);
    echo "parsed $t (seq=$seq)\n";
}

// canonical original-case path: rebuild from lowercase key by listing? Keys are lowercase;
// real filesystem on Windows is case-insensitive, but git and composer want original case.
// Recover case from the transcripts' own inputs during a light second pass is overkill:
// use current worktree path casing where the file exists; otherwise exact-match via directory listing.
function casify(string $root, string $lower): string {
    $parts = explode('/', $lower);
    $cur = $root;
    foreach ($parts as $i => $part) {
        $want = "$cur/$part";
        if (file_exists($want)) { $cur = $want; continue; }
        $siblings = is_dir($cur) ? array_diff(scandir($cur), ['.', '..']) : [];
        $found = null;
        foreach ($siblings as $s) if (strcasecmp($s, $part) === 0) { $found = $s; break; }
        if ($found === null && $i < count($parts) - 1) { @mkdir("$cur/$part"); $found = $part; }
        if ($found === null) return $want;
        $cur = "$cur/$found";
    }
    return $cur;
}

$report = ['recovered' => 0, 'identical' => 0, 'created' => 0, 'edit_fail' => []];
$noSnap = [];
foreach ($edits as $k => $_) if (!isset($snap[$k])) $noSnap[] = $k;

$keys = array_unique(array_merge(array_keys($snap), array_keys($edits)));
sort($keys);
if (!empty($argv[1]) && is_file($argv[1])) {
    $only = array_map('trim', array_filter(file($argv[1])));
    $keys = array_values(array_intersect($keys, $only));
    echo "filtered to " . count($keys) . " files\n";
}
foreach ($keys as $k) {
    $path = $root . '/' . ($canon[$k] ?? $k);
    if (isset($snap[$k])) {
        $content = $snap[$k]['content']; $from = $snap[$k]['seq'];
    } else {
        if (!is_file($path)) { $report['edit_fail'][] = "$k: no snapshot AND no file (edits orphaned)"; continue; }
        $content = file_get_contents($path); $from = -1;
    }
    $fails = 0;
    foreach (($edits[$k] ?? []) as $e) {
        if ($e['seq'] <= $from) continue;
        $cnt = substr_count($content, $e['old']);
        if ($cnt >= 1 && ($cnt === 1 || $e['multi'])) {
            if ($cnt > 1 && $e['multi']) {
                $content = str_replace($e['old'], $e['new'], $content);
            } else {
                $pos = strpos($content, $e['old']);
                $content = substr_replace($content, $e['new'], $pos, strlen($e['old']));
            }
            if ($cnt > 1) $report['edit_fail'][] = "$k: ambiguous x$cnt applied to first (verify)";
        } elseif ($cnt === 0) {
            // already included in the snapshot? then skip silently when snapshot came after this edit — guarded by $from; count as failure otherwise
            $fails++; $report['edit_fail'][] = "$k: old_string not found (edit seq {$e['seq']})";
        } else {
            $fails++; $report['edit_fail'][] = "$k: count>1 without allow_multiple (verify)";
        }
    }
    $cur = is_file($path) ? file_get_contents($path) : null;
    if ($cur === null) { @mkdir(dirname($path), 0777, true); file_put_contents($path, $content); $report['created']++; echo "created: $k\n"; }
    elseif ($cur !== $content) { file_put_contents($path, $content); $report['recovered']++; echo "recovered: $k\n"; }
    else $report['identical']++;
}

echo "\nrecovered(differs-from-HEAD): {$report['recovered']}\ncreated(missing): {$report['created']}\nidentical: {$report['identical']}\n";
echo "edit issues: " . count($report['edit_fail']) . "\n";
foreach (array_slice($report['edit_fail'], 0, 60) as $f) echo "  $f\n";
echo "files with edits but no snapshot: " . count($noSnap) . "\n";
foreach ($noSnap as $n) echo "  $n\n";
