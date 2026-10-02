<?php

namespace Tests\Unit;

use App\Core\Support\QrCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The QR encoder that turns a handover code into a symbol a camera can read.
 *
 * `App\Core\Support\QrCode` is written here rather than installed, so these tests are
 * the difference between "a plausible-looking grid of squares" and "a symbol the
 * decoders in the world agree on". They are deliberately *not* a mirror of
 * the encoder's internals: the golden grid below was produced out of band, by
 * feeding this encoder's output to an independent decoder (jsQR, a from-scratch
 * implementation of the same standard) and reading the payload back at three
 * different module sizes, and the structure test re-derives the format bits from
 * the finished picture rather than from the variables that wrote them. A change
 * that breaks a symbol fails here even if it touches nothing these files
 * mention.
 *
 * What is *not* covered, and cannot be from a PHP test: how small a module can
 * be on a particular phone screen and still be read by a particular camera. That
 * is a property of the renderer and the glass, and the renderers are
 * `DealsPipelineView.tsx` and `deals_screen.dart`.
 *
 * **Why this one unit test extends PHPUnit's own base class.** An encoding is
 * arithmetic on a string: no container, no database, no request. `Tests\TestCase`
 * boots the framework and then refuses to run unless the resolved schema is a
 * test schema — the guard that keeps `php artisan test` from truncating live
 * data, which is correct and which this file has no reason to pay. Extending it
 * here would mean a pure function's tests fail whenever the local config happens
 * to be cached for a deployment, so this class stays where its dependencies are:
 * autoload, assertions, nothing else.
 */
class QrCodeTest extends TestCase
{
    /** A payload in the exact shape a handover code arrives in. */
    private const HANDOVER_PAYLOAD = 'HASSAL:HANDOVER:HSL-2026-0001:KQ4T7VRX2M9PNWJC';

    /**
     * The 33 rows of that payload's symbol, as an independent decoder read them
     * back to the text it encodes.
     */
    private const HANDOVER_GRID = <<<'GRID'
        111111101111100101001100001111111
        100000100111000011011100101000001
        101110100111110110010110101011101
        101110101010001101101101001011101
        101110101000101000111000001011101
        100000101011001010110010001000001
        111111101010101010101010101111111
        000000001011100111000100100000000
        100010111100110011110010011111001
        001101010010011100001010011100101
        101010111110100111001011100100010
        100001001010001111101100101100000
        101010111011011000101001001111101
        100111001001100010010000011001111
        111000111011101010010011001111010
        111000010011010001011110100101000
        001001111111001111101101101111010
        001011011000111101001110011000100
        100101111110011110100001011110100
        000001010010111110001011110000010
        111010101000000010000010111101111
        100011010000011000111100000101111
        001101100101001100101011000100110
        000010010110001111000111010110001
        111100111011001011111010111111100
        000000001100001100110000100010101
        111111101011001111001111101010110
        100000101010001111110100100010011
        101110100101101000111000111110110
        101110101100011011010110001001110
        101110100011011011110110100011100
        100000100001001001111110001010000
        111111101110000111100011101001101
        GRID;

    public function test_a_handover_payload_encodes_to_the_grid_a_decoder_read(): void
    {
        $symbol = QrCode::tryEncode(self::HANDOVER_PAYLOAD);

        $this->assertNotNull($symbol, 'A 46-character payload is well inside what level M carries.');
        $this->assertSame(4, $symbol['version'], 'It should use the smallest version that fits, not a bigger one.');
        $this->assertSame(33, $symbol['size'], 'Version 4 is 17 + 4 × 4 modules on a side.');
        $this->assertSame('M', $symbol['ec_level']);
        $this->assertSame(self::HANDOVER_PAYLOAD, $symbol['payload'], 'The picture must name the payload it was built from.');

        $this->assertSame(
            array_map('trim', explode("\n", self::HANDOVER_GRID)),
            $symbol['rows'],
            "The symbol changed. These rows are what an independent decoder read back as this order's payload; "
            . 'if they are now deliberately different, the new grid has to be re-decoded before it is pasted in.'
        );
    }

    public function test_a_symbol_is_the_size_its_version_declares(): void
    {
        foreach (['a', self::HANDOVER_PAYLOAD, str_repeat('HASSAL-QR-VERSION-BYTES-', 8) . 'END'] as $text) {
            $symbol = QrCode::tryEncode($text);
            $this->assertNotNull($symbol);
            $this->assertSame(17 + 4 * $symbol['version'], $symbol['size']);
            $this->assertCount($symbol['size'], $symbol['rows']);
            foreach ($symbol['rows'] as $row) {
                $this->assertSame($symbol['size'], strlen($row), 'A QR is square; a short row is a truncated row.');
                $this->assertMatchesRegularExpression('/^[01]+$/', $row);
            }
        }
    }

    /**
     * The three finder patterns, the timing paths, and the always-dark module.
     *
     * A decoder does not start by reading data — it looks for the 1:1:3:1:1
     * proportion of a finder in an image and works out where it is. A grid whose
     * corners are correct is a grid a camera will try to read at all, so this
     * checks the corners of the finished picture rather than trusting the code
     * that drew them.
     */
    public function test_the_corner_geometry_a_locator_looks_for(): void
    {
        $rows = QrCode::tryEncode(self::HANDOVER_PAYLOAD)['rows'];
        $size = 33;

        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$top, $left]) {
            for ($i = 0; $i < 7; $i++) {
                $this->assertSame('1', $rows[$top][$left + $i], 'Top edge of a finder is dark');
                $this->assertSame('1', $rows[$top + $i][$left], 'Left edge of a finder is dark');
                $this->assertSame('1', $rows[$top + 6][$left + $i], 'Bottom edge of a finder is dark');
                $this->assertSame('1', $rows[$top + $i][$left + 6], 'Right edge of a finder is dark');
            }
            // The 3×3 eye, and the light ring that separates it from the edge.
            for ($y = 2; $y <= 4; $y++) {
                for ($x = 2; $x <= 4; $x++) {
                    $this->assertSame('1', $rows[$top + $y][$left + $x]);
                }
            }
            $this->assertSame('0', $rows[$top + 1][$left + 1]);
        }

        // Timing: alternating from finder to finder along row 6 and column 6.
        for ($i = 8; $i < $size - 8; $i++) {
            $this->assertSame($i % 2 === 0 ? '1' : '0', $rows[6][$i], "Row 6, column {$i}");
            $this->assertSame($i % 2 === 0 ? '1' : '0', $rows[$i][6], "Column 6, row {$i}");
        }

        $this->assertSame('1', $rows[$size - 8][8], 'The dark module, without which a format read is ambiguous.');
    }

    /**
     * Read the format information back out of the finished grid.
     *
     * This is the test that catches a mask applied without being announced, or
     * announced without being applied — both of which produce a picture that
     * looks exactly like a QR Code and that no decoder will touch. The bits are
     * lifted from the symbol's own coordinates, the BCH check is recomputed from
     * the standard's generator, and what is left has to say "level M, the mask
     * this encoder reported" in both copies.
     */
    public function test_the_format_information_announces_the_mask_that_was_applied(): void
    {
        $symbol = QrCode::tryEncode(self::HANDOVER_PAYLOAD);
        $rows = $symbol['rows'];
        $size = $symbol['size'];

        $read = function (array $positions) use ($rows): int {
            $bits = 0;
            foreach ($positions as $index => [$x, $y]) {
                $bits |= ($rows[$y][$x] === '1' ? 1 : 0) << $index;
            }

            return $bits;
        };

        $first = [];
        for ($i = 0; $i <= 5; $i++) {
            $first[] = [8, $i];
        }
        $first[] = [8, 7];
        $first[] = [8, 8];
        $first[] = [7, 8];
        for ($i = 9; $i < 15; $i++) {
            $first[] = [14 - $i, 8];
        }

        $second = [];
        for ($i = 0; $i < 8; $i++) {
            $second[] = [$size - 1 - $i, 8];
        }
        for ($i = 8; $i < 15; $i++) {
            $second[] = [8, $size - 15 + ($i - 8)];
        }

        foreach (['top-left' => $first, 'opposite corners' => $second] as $where => $positions) {
            $bits = $read($positions) ^ 0x5412;

            $this->assertSame(
                0,
                $this->bchRemainder($bits),
                "The format bits in the {$where} copy are not a valid BCH(15,5) codeword."
            );

            $data = $bits >> 10;
            $this->assertSame(0b00, $data >> 3, 'Level M is the only level this encoder builds.');
            $this->assertSame($symbol['mask'], $data & 0b111, "The mask written in {$where} is not the mask reported.");
        }
    }

    public function test_the_quiet_zone_is_left_to_the_renderer_but_is_documented(): void
    {
        $symbol = QrCode::tryEncode(self::HANDOVER_PAYLOAD);

        // Rows carry the symbol and nothing around it: the margin is the
        // renderer's, and a client that assumes one is included would draw a
        // code inside a code.
        $this->assertSame($symbol['size'], strlen($symbol['rows'][0]));
        $this->assertSame(4, $symbol['quiet_zone']);
        $this->assertSame(4, QrCode::QUIET_ZONE_MODULES);
    }

    public function test_a_printable_label_is_an_svg_with_its_margin_drawn(): void
    {
        $svg = QrCode::svg(self::HANDOVER_PAYLOAD);

        $this->assertNotNull($svg);
        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
        // 33 modules plus four of quiet zone on each side, and the light ground
        // the symbol sits on — a label printed straight onto brown cardboard is
        // a label that does not scan.
        $this->assertStringContainsString('viewBox="0 0 41 41"', $svg);
        $this->assertStringContainsString('fill="#ffffff"', $svg);
        $this->assertMatchesRegularExpression('/<path d="M\d+ \d+h\d+v1h-\d+z/', $svg);
    }

    #[DataProvider('tooLongProvider')]
    public function test_a_payload_bigger_than_the_encoder_builds_is_refused_not_truncated(int $length): void
    {
        $this->assertNull(
            QrCode::tryEncode(str_repeat('A', $length)),
            'Version 10 at level M holds 213 bytes; past that there is no symbol to draw, and a picture of a '
            . 'truncated code is worse than no picture: it scans, and it scans as the wrong order.'
        );
    }

    /** @return array<string, array{int}> */
    public static function tooLongProvider(): array
    {
        return [
            'one byte past version 10' => [214],
            'far past it' => [400],
        ];
    }

    public function test_the_boundary_itself_is_carried(): void
    {
        $symbol = QrCode::tryEncode(str_repeat('A', 213));

        $this->assertNotNull($symbol);
        $this->assertSame(10, $symbol['version']);
        $this->assertSame(57, $symbol['size']);
        // From version 7 up the symbol also carries an 18-bit version block —
        // the part of the standard a version-4 handover code never exercises.
        $this->assertVersionBlockIsTheBchCodeOf($symbol['rows'], 10);
    }

    public function test_the_same_payload_always_draws_the_same_picture(): void
    {
        $this->assertSame(
            QrCode::tryEncode(self::HANDOVER_PAYLOAD),
            QrCode::tryEncode(self::HANDOVER_PAYLOAD),
            'A label reprinted next year has to be the label printed this year.'
        );
    }

    /** The BCH(15,5) remainder of a 15-bit format word; zero is a valid codeword. */
    private function bchRemainder(int $bits): int
    {
        $rem = $bits;
        for ($i = 14; $i >= 10; $i--) {
            if (($rem >> $i) & 1) {
                $rem ^= 0x537 << ($i - 10);
            }
        }

        return $rem & 0x3FF;
    }

    /**
     * Read the version block out of the two corners it is written in, and check
     * it against the BCH(18,6) code of the version number.
     *
     * Named as a sentence because it is the assertion that catches a version
     * block laid out transposed — the two copies are mirror images across the
     * diagonal, and only a symbol from version 7 upward has one at all.
     */
    private function assertVersionBlockIsTheBchCodeOf(array $rows, int $version): void
    {
        $size = count($rows);
        $read = 0;

        for ($i = 0; $i < 18; $i++) {
            $a = $size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->assertSame($rows[$b][$a], $rows[$a][$b], 'The two copies of the version block disagree.');
            $read |= ($rows[$b][$a] === '1' ? 1 : 0) << $i;
        }

        $rem = $version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }

        $this->assertSame(
            ($version << 12) | $rem,
            $read,
            "Version {$version}'s information block is not its own BCH code."
        );
    }
}
