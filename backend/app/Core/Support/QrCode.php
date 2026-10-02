<?php

namespace App\Core\Support;

/**
 * A QR Code encoder: text in, a grid of dark modules out.
 *
 * **Why this file exists.** A handover code is only proof if the person at the
 * loading bay can point a camera at it. Until now the platform printed the
 * characters and every client was left to say "read these to the scanner",
 * which is not a barcode and fails at the exact moment it is needed — a driver
 * with gloves, a dirty label, a screen at arm's length. Nothing in the stack
 * could draw one: the web client and the app only *decode* (`BarcodeDetector`
 * and `mobile_scanner`), and no QR generation package is installed for PHP,
 * Node or Dart. Rather than pull a dependency into three runtimes to render one
 * symbol, the platform encodes it once here and hands both clients a grid.
 *
 * **What it implements.** ISO/IEC 18004, QR Code Model 2, byte (octet) encoding,
 * error-correction level M, versions 1 through 10 — up to 213 bytes, which is
 * far more than a `HASSAL:HANDOVER:{order}:{code}` payload needs (about 50, so
 * a version 4 symbol of 33×33 modules). Anything longer is refused rather than
 * silently truncated; see `encode()`. The eight masks are all evaluated against
 * the standard penalty rules and the best one is written into the format
 * information, so the symbol a decoder reads is the symbol this file scored.
 *
 * **Why level M.** A printed label gets creased and a phone screen gets glare
 * on it; level L would survive neither, and level Q or H would buy that
 * robustness with a bigger symbol, i.e. smaller modules at the same print size,
 * which is harder for a camera at arm's length. M — 15% recovery — is the
 * compromise the spec itself recommends for this kind of field use.
 *
 * **The contract with the callers.** The matrix is returned *without* its quiet
 * zone, because quiet zones belong to whoever draws the symbol: an SVG on a
 * browser page and a `CustomPaint` on a phone have different amounts of room
 * around them. Every renderer must leave at least four modules of light margin
 * (`self::QUIET_ZONE_MODULES`) or the symbol's own edge stops being
 * distinguishable from its background — that margin is not decoration, it is
 * part of the standard, and a QR drawn flush to a dark panel will not scan no
 * matter how correct the modules are.
 *
 * Nothing here is stateful, and nothing here is stored: the matrix is derived
 * from the code the database already holds, so a label can be redrawn years
 * later and be the same picture.
 */
final class QrCode
{
    /** Error-correction level this encoder builds, and the level's format bits (M = 00). */
    public const EC_LEVEL = 'M';

    /** Light margin every renderer owes the symbol, in modules (§7.4 of the spec). */
    public const QUIET_ZONE_MODULES = 4;

    /** The largest version built here; the block tables stop with it. */
    private const MAX_VERSION = 10;

    /**
     * Level-M block structure, version => [total codewords, EC codewords per block, [[blocks, data codewords per block], …]].
     *
     * Straight from ISO/IEC 18004 Tables 12–15. Hard-coded rather than derived
     * because these numbers are the standard's own editorial choices — there is
     * no formula underneath them to get wrong or right, and a generated table
     * would be a formula nobody can check against the page it came from.
     */
    private const M_STRUCTURE = [
        1 => [26, 10, [[1, 16]]],
        2 => [44, 16, [[1, 28]]],
        3 => [70, 26, [[1, 44]]],
        4 => [100, 18, [[2, 32]]],
        5 => [134, 24, [[2, 43]]],
        6 => [172, 16, [[4, 27]]],
        7 => [196, 18, [[4, 31]]],
        8 => [242, 22, [[2, 38], [2, 39]]],
        9 => [292, 22, [[3, 36], [2, 37]]],
        10 => [346, 26, [[4, 43], [1, 44]]],
    ];

    /**
     * Alignment-pattern centre coordinates per version (Table E.1).
     *
     * Always beginning at 6 and ending at `size - 7`, evenly spaced — but the
     * spacings are again the standard's table, so they are copied rather than
     * recalculated.
     */
    private const M_ALIGNMENT = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /**
     * Bits the symbol has room for but the data stream does not fill.
     *
     * The grid is not an exact multiple of 8 for versions 2–6; those leftover
     * modules stay light, which is what the spec says and what every decoder
     * expects.
     */
    private const M_REMAINDER_BITS = [1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7, 7 => 0, 8 => 0, 9 => 0, 10 => 0];

    /** @var array<int, int> exp/log tables for GF(256) with the QR primitive polynomial 0x11D */
    private static array $gExp = [];

    private static array $gLog = [];

    /** @var array<int, array<int, int>> generator polynomials by degree, built once each */
    private static array $genPoly = [];

    /**
     * Encode `$text` and describe the symbol that holds it.
     *
     * @return array{
     *     payload: string,
     *     version: int,
     *     size: int,
     *     mask: int,
     *     ec_level: string,
     *     quiet_zone: int,
     *     rows: array<int, string>,
     * }
     *
     * `rows` is the grid, one string per row, `'1'` for a dark module, read top
     * to bottom — a shape both a `<path>` in an SVG and a loop in a canvas can
     * draw without a parser.
     *
     * Returns null when the text is longer than version 10 at level M can carry.
     * That is a `tryEncode` decision rather than an exception because the one
     * caller that matters builds the payload from a code and an order number it
     * does not size: an API that 500s because a symbol would be too big is worse
     * for the person at the gate than the same API answering with the characters
     * and no picture.
     */
    public static function tryEncode(string $text): ?array
    {
        $bytes = $text === '' ? [] : array_values(unpack('C*', $text));

        $version = self::versionFor(count($bytes));
        if ($version === null) {
            return null;
        }

        [$blocks, $ecCodewords] = self::interleave(self::dataCodewords($text, $version), $version);

        $size = 17 + 4 * $version;
        $modules = self::emptyGrid($size);
        $reserved = self::emptyGrid($size);
        self::drawFunctionPatterns($modules, $reserved, $version, $size);
        self::placeCodewords($modules, $reserved, $size, $blocks);

        $mask = self::chooseMask($modules, $reserved, $size);
        self::applyMask($modules, $reserved, $size, $mask);
        self::drawFormatBits($modules, $size, $mask);
        self::drawVersionBits($modules, $size, $version);

        return [
            'payload' => $text,
            'version' => $version,
            'size' => $size,
            'mask' => $mask,
            'ec_level' => self::EC_LEVEL,
            'quiet_zone' => self::QUIET_ZONE_MODULES,
            'rows' => self::rows($modules, $size),
        ];
    }

    /**
     * The same answer as an inline `<svg>`, for anything that prints.
     *
     * The label a box carries has to be a file, not a widget tree, and an SVG is
     * the one output that needs no image extension: `QrCode::svg()` is what a
     * Blade view, a PDF or a `curl` to this endpoint writes. Modules are unit
     * squares so the caller scales it with `width`/`height`, and the quiet zone
     * is drawn rather than assumed — a printed code with no margin is a code
     * that does not scan.
     */
    public static function svg(string $text, int $quiet = self::QUIET_ZONE_MODULES): ?string
    {
        $symbol = self::tryEncode($text);
        if ($symbol === null) {
            return null;
        }

        $size = $symbol['size'];
        $edge = $size + 2 * $quiet;

        // One path, not $size² rectangles: a 33×33 grid is over a thousand dark
        // modules, and a label that ships 1 200 nodes to the printer is a label
        // that a browser renders slowly and a person watches spin.
        $ink = '';
        foreach ($symbol['rows'] as $y => $row) {
            $run = 0;
            for ($x = 0; $x < $size; $x++) {
                if ($row[$x] === '1') {
                    $run++;
                    continue;
                }
                if ($run > 0) {
                    $ink .= 'M' . ($x - $run + $quiet) . ' ' . ($y + $quiet) . 'h' . $run . 'v1h-' . $run . 'z';
                }
                $run = 0;
            }
            if ($run > 0) {
                $ink .= 'M' . ($size - $run + $quiet) . ' ' . ($y + $quiet) . 'h' . $run . 'v1h-' . $run . 'z';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $edge . ' ' . $edge . '" '
            . 'shape-rendering="crispEdges" role="img" aria-label="QR">'
            . '<rect width="' . $edge . '" height="' . $edge . '" fill="#ffffff"/>'
            . '<path d="' . $ink . '" fill="#000000"/>'
            . '</svg>';
    }

    // ── Building the bit stream ───────────────────────────────────────────────

    /** The smallest version at level M that carries `$length` bytes, or null. */
    private static function versionFor(int $length): ?int
    {
        for ($version = 1; $version <= self::MAX_VERSION; $version++) {
            if ($length <= self::capacity($version)) {
                return $version;
            }
        }

        return null;
    }

    /** Byte-mode capacity at level M, in characters. */
    private static function capacity(int $version): int
    {
        [$total, $ecPerBlock, $groups] = self::M_STRUCTURE[$version];
        $dataCodewords = $total - $ecPerBlock * array_sum(array_column($groups, 0));
        $headerBits = 4 + ($version < 10 ? 8 : 16);

        return intdiv($dataCodewords * 8 - $headerBits, 8);
    }

    /**
     * Mode indicator, character count, the bytes, terminator and pad codewords.
     *
     * @return array<int, int> the data codewords, exactly as many as this version's blocks hold
     */
    private static function dataCodewords(string $text, int $version): array
    {
        [$total, $ecPerBlock, $groups] = self::M_STRUCTURE[$version];
        $blockCount = array_sum(array_column($groups, 0));
        $needed = $total - $ecPerBlock * $blockCount;

        $bits = '';
        $bits .= '0100'; // byte mode
        $countBits = $version < 10 ? 8 : 16;
        $bits .= self::bits(strlen($text), $countBits);
        for ($i = 0; $i < strlen($text); $i++) {
            $bits .= self::bits(ord($text[$i]), 8);
        }

        // The terminator is up to four light bits — "up to", because a stream
        // that ends within four bits of full does not have four to give, and a
        // decoder reads the count field and stops there regardless.
        $bits .= substr('0000', 0, min(4, max(0, $needed * 8 - strlen($bits))));
        if (strlen($bits) % 8 !== 0) {
            $bits .= substr('0000000', 0, 8 - strlen($bits) % 8);
        }

        $codewords = [];
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $codewords[] = bindec(substr($bits, $i, 8));
        }

        // 0xEC and 0x11 alternate: two patterns rather than zeros, so a symbol
        // whose tail was all light does not look like a symbol whose tail is
        // missing.
        $pads = [0xEC, 0x11];
        for ($i = 0; count($codewords) < $needed; $i++) {
            $codewords[] = $pads[$i % 2];
        }

        return $codewords;
    }

    /**
     * Split into blocks, add each block's Reed–Solomon tail, interleave.
     *
     * @return array{0: array<int, int>, 1: int} the final codeword sequence and its length
     */
    private static function interleave(array $data, int $version): array
    {
        [, $ecPerBlock, $groups] = self::M_STRUCTURE[$version];

        $blocks = [];
        $tails = [];
        $offset = 0;
        foreach ($groups as [$count, $length]) {
            for ($i = 0; $i < $count; $i++) {
                $block = array_slice($data, $offset, $length);
                $offset += $length;
                $blocks[] = $block;
                $tails[] = self::remainder($block, self::generatorPolynomial($ecPerBlock));
            }
        }

        $out = [];
        $longest = max(array_map('count', $blocks));
        for ($i = 0; $i < $longest; $i++) {
            foreach ($blocks as $block) {
                if ($i < count($block)) {
                    $out[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($tails as $tail) {
                $out[] = $tail[$i];
            }
        }

        return [$out, count($out)];
    }

    // ── GF(256) and Reed–Solomon ─────────────────────────────────────────────

    private static function initTables(): void
    {
        if (self::$gExp !== []) {
            return;
        }

        // Primitive polynomial x^8 + x^4 + x^3 + x^2 + 1, generator α = 2.
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$gExp[$i] = $x;
            self::$gLog[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 510; $i++) {
            // Doubled indices keep `gmul` from having to take a modulus.
            self::$gExp[$i] = self::$gExp[$i - 255];
        }
    }

    private static function gexp(int $power): int
    {
        self::initTables();

        return self::$gExp[$power % 255];
    }

    private static function gmul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        self::initTables();

        return self::$gExp[self::$gLog[$a] + self::$gLog[$b]];
    }

    /**
     * The generator polynomial of the given degree: ∏(x − αⁱ), descending coefficients, monic.
     *
     * Built by multiplying the linear factors out rather than copied from a
     * table: the table for every degree up to 30 is a table with 30 chances to
     * have a digit in the wrong place, and this is eight lines.
     */
    private static function generatorPolynomial(int $degree): array
    {
        if (isset(self::$genPoly[$degree])) {
            return self::$genPoly[$degree];
        }

        $poly = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $j => $coefficient) {
                $next[$j] ^= self::gmul($coefficient, 1);
                $next[$j + 1] ^= self::gmul($coefficient, self::gexp($i));
            }
            $poly = $next;
        }

        return self::$genPoly[$degree] = $poly;
    }

    /**
     * The remainder of message·x^degree ÷ generator: the EC codewords.
     *
     * Synthetic division, one incoming coefficient at a time. The generator is
     * monic, so its leading term is what cancels and is never stored — `$gen[$i + 1]`
     * skips it.
     */
    private static function remainder(array $message, array $gen): array
    {
        $degree = count($gen) - 1;
        $res = array_fill(0, $degree, 0);

        foreach ($message as $byte) {
            $lead = $byte ^ $res[0];
            array_shift($res);
            $res[] = 0;
            if ($lead !== 0) {
                for ($i = 0; $i < $degree; $i++) {
                    $res[$i] ^= self::gmul($gen[$i + 1], $lead);
                }
            }
        }

        return $res;
    }

    // ── The grid ─────────────────────────────────────────────────────────────

    /** @return array<int, array<int, bool>> */
    private static function emptyGrid(int $size): array
    {
        return array_fill(0, $size, array_fill(0, $size, false));
    }

    /** Finder patterns, separators, timing patterns, alignment patterns, and the reserved format/version areas. */
    private static function drawFunctionPatterns(array &$modules, array &$reserved, int $version, int $size): void
    {
        for ($i = 0; $i < $size; $i++) {
            self::setFunction($modules, $reserved, 6, $i, $i % 2 === 0);
            self::setFunction($modules, $reserved, $i, 6, $i % 2 === 0);
        }

        self::drawFinder($modules, $reserved, 3, 3, $size);
        self::drawFinder($modules, $reserved, $size - 4, 3, $size);
        self::drawFinder($modules, $reserved, 3, $size - 4, $size);

        foreach (self::M_ALIGNMENT[$version] as $row) {
            foreach (self::M_ALIGNMENT[$version] as $col) {
                // The three corners where an alignment pattern would sit on a
                // finder are left to the finder: the overlap is the standard's
                // own, and drawing a 5×5 block over a 7×7 one would be a symbol
                // no decoder has ever met.
                if ((($row <= 8 && $col <= 8) || ($row <= 8 && $col >= $size - 8) || ($row >= $size - 8 && $col <= 8))) {
                    continue;
                }
                self::drawAlignment($modules, $reserved, $col, $row, $size);
            }
        }

        // The format strips are reserved here and written last, from the same
        // list of coordinates, so the data can neither land on them nor be
        // masked over — and so the two can never disagree about where they are.
        foreach (self::formatPositions($size) as [$x, $y]) {
            self::markReserved($reserved, $x, $y, $size);
        }
        self::setFunction($modules, $reserved, 8, $size - 8, true);

        if ($version >= 7) {
            for ($i = 0; $i < 6; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    self::markReserved($reserved, $size - 11 + $j, $i, $size);
                    self::markReserved($reserved, $i, $size - 11 + $j, $size);
                }
            }
        }
    }

    private static function drawFinder(array &$modules, array &$reserved, int $x, int $y, int $size): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $distance = max(abs($dx), abs($dy));
                self::setFunction(
                    $modules,
                    $reserved,
                    $x + $dx,
                    $y + $dy,
                    $distance !== 2 && $distance !== 4,
                    $size
                );
            }
        }
    }

    private static function drawAlignment(array &$modules, array &$reserved, int $x, int $y, int $size): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                self::setFunction(
                    $modules,
                    $reserved,
                    $x + $dx,
                    $y + $dy,
                    max(abs($dx), abs($dy)) !== 1,
                    $size
                );
            }
        }
    }

    private static function markReserved(array &$reserved, int $x, int $y, int $size): void
    {
        if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
            $reserved[$y][$x] = true;
        }
    }

    private static function setFunction(array &$modules, array &$reserved, int $x, int $y, bool $dark, ?int $size = null): void
    {
        if ($size !== null && ($x < 0 || $x >= $size || $y < 0 || $y >= $size)) {
            return;
        }
        // A separator that falls off the edge is not a bug: the finders are
        // drawn from their centres and their −4..4 box is one module larger than
        // the 7×7 pattern, so at a corner part of it is outside the symbol.
        if ($x < 0 || $y < 0 || !isset($modules[$y][$x])) {
            return;
        }

        $modules[$y][$x] = $dark;
        $reserved[$y][$x] = true;
    }

    /**
     * Lay the codewords over the grid: two-module-wide columns, right to left,
     * alternating upward and downward, skipping every function module.
     *
     * Column 6 is the vertical timing pattern and would leave an odd single
     * column, so the walk steps over it — the detail that distinguishes a
     * symbol that reads from one that is a mirror image of itself.
     */
    private static function placeCodewords(array &$modules, array $reserved, int $size, array $codewords): void
    {
        $totalBits = count($codewords) * 8;
        $bit = 0;

        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $size - 1 - $vert : $vert;

                    if (!$reserved[$y][$x] && $bit < $totalBits) {
                        $modules[$y][$x] = self::bitAt($codewords[$bit >> 3], 7 - ($bit & 7));
                        $bit++;
                    }
                }
            }
        }
    }

    private static function maskCondition(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
            5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
            6 => ((($x * $y) % 2) + (($x * $y) % 3)) % 2 === 0,
            7 => ((($x + $y) % 2) + (($x * $y) % 3)) % 2 === 0,
            default => false,
        };
    }

    private static function applyMask(array &$modules, array $reserved, int $size, int $mask): void
    {
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if (!$reserved[$y][$x] && self::maskCondition($mask, $x, $y)) {
                    $modules[$y][$x] = !$modules[$y][$x];
                }
            }
        }
    }

    /**
     * Try all eight masks, keep the one the spec's own penalty rules like least.
     *
     * The format bits are written for whichever mask is scored, so every choice
     * here is a legal symbol — the score is about how easily a camera finds it,
     * not about whether it is correct. Applied, scored, and undone in place, so
     * the grid handed to `applyMask` at the end is the one that was measured.
     */
    private static function chooseMask(array &$modules, array $reserved, int $size): int
    {
        $best = 0;
        $bestScore = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            self::applyMask($modules, $reserved, $size, $mask);
            self::drawFormatBits($modules, $size, $mask);
            $score = self::penalty($modules, $size);
            self::applyMask($modules, $reserved, $size, $mask);

            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $mask;
            }
        }

        return $best;
    }

    /** The four penalty rules of §8.8.2: long runs, blocky areas, near-finder patterns, and a lopsided dark ratio. */
    private static function penalty(array $modules, int $size): int
    {
        $score = 0;

        for ($line = 0; $line < $size; $line++) {
            $score += self::runPenalty(self::readLine($modules, $size, $line, true));
            $score += self::runPenalty(self::readLine($modules, $size, $line, false));
        }

        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $colour = $modules[$y][$x];
                if ($colour === $modules[$y][$x + 1]
                    && $colour === $modules[$y + 1][$x]
                    && $colour === $modules[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }

        // Rule 4: how far the symbol strays from half dark, in steps of 5%, ten
        // points each.
        $dark = 0;
        foreach ($modules as $row) {
            $dark += count(array_filter($row));
        }
        $percent = $dark * 100 / ($size * $size);
        $score += (int) (abs($percent - 50) / 5) * 10;

        return $score;
    }

    /** @return array<int, bool> */
    private static function readLine(array $modules, int $size, int $index, bool $horizontal): array
    {
        $line = [];
        for ($i = 0; $i < $size; $i++) {
            $line[] = $horizontal ? $modules[$index][$i] : $modules[$i][$index];
        }

        return $line;
    }

    private static function runPenalty(array $line): int
    {
        $penalty = 0;
        $run = 1;

        for ($i = 1; $i < count($line); $i++) {
            if ($line[$i] === $line[$i - 1]) {
                $run++;
                continue;
            }
            if ($run >= 5) {
                $penalty += 3 + ($run - 5);
            }
            $run = 1;
        }
        if ($run >= 5) {
            $penalty += 3 + ($run - 5);
        }

        // Rule 3, the pattern a decoder's finder-search looks for: 1011101 with
        // four light modules either side. Counted as a substring of the line in
        // both orientations, which is what the rule describes.
        $bits = '';
        foreach ($line as $dark) {
            $bits .= $dark ? '1' : '0';
        }
        $needle = '1011101';
        foreach (['0000' . $needle, $needle . '0000'] as $pattern) {
            $offset = 0;
            while (($found = strpos($bits, $pattern, $offset)) !== false) {
                $penalty += 40;
                $offset = $found + 1;
            }
        }

        return $penalty;
    }

    /**
     * The 15 format bits — level M and this mask, BCH-protected and masked —
     * in both corners that carry them.
     *
     * The final XOR with 0x5412 is what keeps a symbol that is all light from
     * reading as a format of all zeros, and a decoder without it rejects the
     * picture outright.
     */
    private static function drawFormatBits(array &$modules, int $size, int $mask): void
    {
        $data = (0b00 << 3) | $mask; // the five bits are level M (00) then the mask
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;

        foreach (self::formatPositions($size) as $i => [$x, $y]) {
            // Thirty coordinates, fifteen bits: the list carries each bit twice,
            // once for each copy, so the index wraps rather than running off the
            // end of the word. Written out of line here it looks like a stray
            // modulo, and it is the line that keeps the second copy — the one a
            // camera reads when a corner of the label is torn — from being
            // fifteen light modules.
            $modules[$y][$x] = self::bitAt($bits, $i % 15);
        }
    }

    /**
     * The 15 coordinates that hold the format information, in bit order, as [x, y].
     *
     * One list, read by the pass that reserves the space and the pass that
     * writes into it. The strips run down column 8 and along row 8 on both
     * sides of the symbol, skipping the timing column and the separators —
     * geometry that is easy to state once and easy to get subtly wrong twice,
     * and a format bit that lands on a data module is a symbol no camera will
     * ever read.
     *
     * @return array<int, array{0: int, 1: int}>  thirty [x, y] pairs: the fifteen bits, twice
     */
    private static function formatPositions(int $size): array
    {
        $positions = [];

        // Bits 0–5: column 8, rows 0–5, then bit 6 above the separator and bit 7
        // on the corner itself; bit 8 turns the corner onto row 8, and bits 9–14
        // run back down that row to the edge.
        for ($i = 0; $i <= 5; $i++) {
            $positions[] = [8, $i];
        }
        $positions[] = [8, 7];
        $positions[] = [8, 8];
        $positions[] = [7, 8];
        for ($i = 9; $i < 15; $i++) {
            $positions[] = [14 - $i, 8];
        }

        // The second copy: bits 0–7 along row 8 against the right-hand edge,
        // bits 8–14 down column 8 against the bottom edge.
        for ($i = 0; $i < 8; $i++) {
            $positions[] = [$size - 1 - $i, 8];
        }
        for ($i = 8; $i < 15; $i++) {
            $positions[] = [8, $size - 15 + ($i - 8)];
        }

        return $positions;
    }

    /** The 18 version bits, from version 7 up: a BCH(18,6) code of the version number. */
    private static function drawVersionBits(array &$modules, int $size, int $version): void
    {
        if ($version < 7) {
            return;
        }

        $rem = $version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = ($version << 12) | $rem;

        for ($i = 0; $i < 18; $i++) {
            $dark = self::bitAt($bits, $i);
            $a = $size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $modules[$b][$a] = $dark;
            $modules[$a][$b] = $dark;
        }
    }

    private static function bitAt(int $value, int $index): bool
    {
        return (($value >> $index) & 1) === 1;
    }

    private static function bits(int $value, int $width): string
    {
        return str_pad(decbin($value), $width, '0', STR_PAD_LEFT);
    }

    /** @return array<int, string> one string per row: '1' dark, '0' light */
    private static function rows(array $modules, int $size): array
    {
        $rows = [];
        foreach ($modules as $row) {
            $line = '';
            foreach ($row as $dark) {
                $line .= $dark ? '1' : '0';
            }
            $rows[] = $line;
        }

        return $rows;
    }
}
