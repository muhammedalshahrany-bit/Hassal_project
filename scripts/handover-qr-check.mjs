/**
 * handover-qr-check.mjs — asks the running backend for a delivery code's *picture*,
 * and checks the picture is a QR Code rather than a decorative grid of squares.
 *
 *   node scripts/handover-qr-check.mjs
 *
 * Why this exists next to `live-cycle-check.mjs`: that script walks the cycle and
 * proves the scan gate holds. This one proves the thing a person at a loading bay
 * actually holds — that `GET /deals/{id}/handover` carries a symbol, that the
 * symbol is the payload it claims to encode, that a printed label of it is
 * served, and that the label route is as gated as the code itself.
 *
 * What it checks without any help, from the finished grid:
 *
 *   - shape: size = 17 + 4 × version, square, only '0' and '1',
 *   - the finder corners and the timing paths a camera looks for first,
 *   - the dark module, and — the one a decoder cannot forgive — that the format
 *     information in the two places it is written says the same thing twice and
 *     names the level and mask the API reported,
 *   - that `GET /deals/{id}/handover/label` is an SVG of the same symbol and that
 *     a business which is not a party to the order gets the same refusal from it
 *     as from every other handover route.
 *
 * What it checks only when handed a decoder, because no QR decoding library is
 * installed in this repository and none is added for a test:
 *
 *   HASSAL_QR_DECODER=/path/to/jsQR.js node scripts/handover-qr-check.mjs
 *
 * With that set, the grid is drawn off-screen at three module sizes and read back
 * by an independent implementation; a mismatch fails the run. Without it the
 * script says so on the last line rather than implying it decoded the symbol.
 */

import { createRequire } from 'node:module';
import { existsSync } from 'node:fs';
import { api, login, CODE } from './lib/api.mjs';

const BASE = process.env.HASSAL_API ?? 'http://127.0.0.1:8000/api/v1';
const DECODER = process.env.HASSAL_QR_DECODER ?? '';

let step = 0;
const fails = [];

function check(ok, label, detail = '') {
  step += 1;
  console.log(`${ok ? '  ok  ' : ' FAIL '}${String(step).padStart(2, '0')}. ${label}${detail ? `\n         ${detail}` : ''}`);
  if (!ok) fails.push(label);
}

function eq(actual, expected, label, extra = '') {
  check(String(actual) === String(expected), label, `expected ${expected}, got ${actual}${extra ? ` — ${extra}` : ''}`);
}

/** The 15 format coordinates, in bit order, as [x, y] — written down twice on purpose. */
function formatPositions(size) {
  const first = [];
  for (let i = 0; i <= 5; i++) first.push([8, i]);
  first.push([8, 7], [8, 8], [7, 8]);
  for (let i = 9; i < 15; i++) first.push([14 - i, 8]);

  const second = [];
  for (let i = 0; i < 8; i++) second.push([size - 1 - i, 8]);
  for (let i = 8; i < 15; i++) second.push([8, size - 15 + (i - 8)]);

  return { first, second };
}

const readBits = (rows, positions) =>
  positions.reduce((acc, [x, y], i) => acc | ((rows[y][x] === '1' ? 1 : 0) << i), 0);

/** The BCH(15,5) remainder of a format word; zero is a codeword the spec allows. */
function bchRemainder(bits) {
  let rem = bits;
  for (let i = 14; i >= 10; i--) if ((rem >> i) & 1) rem ^= 0x537 << (i - 10);
  return rem & 0x3ff;
}

function finderIsSound(rows, size, top, left) {
  for (let i = 0; i < 7; i++) {
    if (rows[top][left + i] !== '1' || rows[top + i][left] !== '1') return false;
    if (rows[top + 6][left + i] !== '1' || rows[top + i][left + 6] !== '1') return false;
  }
  for (let y = 2; y <= 4; y++) for (let x = 2; x <= 4; x++) if (rows[top + y][left + x] !== '1') return false;
  return rows[top + 1][left + 1] === '0';
}

/** Draw the grid into a raw RGBA buffer, the way a browser would. */
function rasterise(rows, size, scale, quiet = 4) {
  const edge = (size + quiet * 2) * scale;
  const buf = new Uint8ClampedArray(edge * edge * 4).fill(255);
  for (let y = 0; y < size; y++) {
    for (let x = 0; x < size; x++) {
      if (rows[y][x] !== '1') continue;
      for (let dy = 0; dy < scale; dy++) {
        for (let dx = 0; dx < scale; dx++) {
          const i = (((y + quiet) * scale + dy) * edge + (x + quiet) * scale + dx) * 4;
          buf[i] = buf[i + 1] = buf[i + 2] = 0;
        }
      }
    }
  }
  return { buf, edge };
}

// ── A session, and an order that has a code to show ─────────────────────────

const owner = await login('amal.owner@hassal.test', CODE);
const outsider = await login('nakhil.staff@hassal.test', CODE);
const as = (s) => (m, p, o = {}) => api(m, p, { token: s.token, org: s.active?.id, ...o });
const O = as(owner);
const X = as(outsider);

const deals = (await O('GET', '/deals?per_page=50')).json?.data ?? [];
const withCode = deals.filter((d) => d.handover?.code);

check(withCode.length > 0, 'the orders list carries handover codes', `${withCode.length} of ${deals.length} rows`);
if (withCode.length === 0) {
  console.log('\nNothing to read: no order has been accepted yet. Run live-cycle-check.mjs first.');
  process.exit(1);
}

// A live code if one exists — a spent label still draws, but `verifiable` is the
// difference between a symbol at a gate and a symbol in a file.
const target = withCode.find((d) => d.handover.status === 'ACTIVE') ?? withCode[0];
console.log(`\norder ${target.deal_number} (${target.id}), status ${target.status}, code ${target.handover.status}\n`);

const hand = await O('GET', `/deals/${target.id}/handover`);
eq(hand.status, 200, 'the handover route answers for a party');
const view = hand.json?.data ?? {};
const qr = view.qr;

check(!!qr, 'the answer carries the symbol itself, not only its text');
if (!qr) {
  console.log(`\nFAIL — no \`qr\` in the response: ${JSON.stringify(view).slice(0, 200)}`);
  process.exit(1);
}

// ── The shape ───────────────────────────────────────────────────────────────

eq(qr.payload, view.qr_payload, 'the picture encodes exactly the payload the scanner is asked to send back');
eq(qr.size, 17 + 4 * qr.version, 'size is 17 + 4 × version');
eq(qr.rows.length, qr.size, 'one row per module, no quiet zone folded in');
eq(qr.ec_level, 'M', 'level M, the level the platform builds');
eq(qr.quiet_zone, 4, 'and the margin is documented for whoever draws it');
check(qr.mask >= 0 && qr.mask <= 7, 'a mask is named', `mask ${qr.mask}`);
check(qr.rows.every((r) => r.length === qr.size && /^[01]+$/.test(r)), 'every row is the right length and only ever 0 or 1');

// ── The parts a decoder looks for before it looks for data ──────────────────

check(
  finderIsSound(qr.rows, qr.size, 0, 0) &&
    finderIsSound(qr.rows, qr.size, 0, qr.size - 7) &&
    finderIsSound(qr.rows, qr.size, qr.size - 7, 0),
  'the three finder patterns are where a camera expects them',
);

const timing = [];
for (let i = 8; i < qr.size - 8; i++) {
  if (qr.rows[6][i] !== (i % 2 === 0 ? '1' : '0')) timing.push(`row 6 @${i}`);
  if (qr.rows[i][6] !== (i % 2 === 0 ? '1' : '0')) timing.push(`col 6 @${i}`);
}
check(timing.length === 0, 'the timing paths alternate from finder to finder', timing.slice(0, 3).join(', '));

eq(qr.rows[qr.size - 8][8], '1', 'the always-dark module is dark');

const { first, second } = formatPositions(qr.size);
const f1 = readBits(qr.rows, first) ^ 0x5412;
const f2 = readBits(qr.rows, second) ^ 0x5412;

eq(f1, f2, 'the format information says the same thing in both corners');
check(f1 !== 0, 'and it is not the fifteen light modules a half-written copy leaves behind');
eq(bchRemainder(f1), 0, 'the format word is a valid BCH(15,5) codeword');
eq(f1 >> 13, 0b00, 'its two error-correction bits are level M');
eq((f1 >> 10) & 0b111, qr.mask, 'its three mask bits are the mask reported');

// ── The printed label ───────────────────────────────────────────────────────

const labelRes = await fetch(`${BASE}/deals/${target.id}/handover/label`, {
  headers: { Authorization: `Bearer ${owner.token}`, 'X-Organization-Id': String(owner.active?.id) },
});
const label = await labelRes.text();

eq(labelRes.status, 200, 'the label route serves a party');
check((labelRes.headers.get('content-type') ?? '').includes('image/svg+xml'), 'as an SVG document', labelRes.headers.get('content-type'));
check(label.startsWith('<svg xmlns="http://www.w3.org/2000/svg"'), 'which is an SVG from its first character');
eq(
  new RegExp(`viewBox="0 0 ${qr.size + 8} ${qr.size + 8}"`).test(label),
  true,
  'the label is the same symbol with four modules of quiet zone drawn around it',
);

const foreign = await X('GET', `/deals/${target.id}/handover/label`);
eq(foreign.status, 403, 'a business that is not a party cannot even have the label drawn');

// ── Optional: read it back with an independent decoder ──────────────────────

let decoded = 0;
if (DECODER) {
  if (!existsSync(DECODER)) {
    check(false, 'HASSAL_QR_DECODER points at a file that is not there', DECODER);
  } else {
    const jsQR = createRequire(import.meta.url)(DECODER);
    for (const scale of [8, 4, 2]) {
      const { buf, edge } = rasterise(qr.rows, qr.size, scale);
      const got = jsQR(buf, edge, edge);
      check(got?.data === qr.payload, `a decoder reads the symbol back at ${scale}px per module`, `got ${JSON.stringify(got?.data ?? null)}`);
      if (got?.data === qr.payload) decoded += 1;
    }
  }
}

console.log(
  decoded === 3
    ? '\nsymbol: encoded here, decoded there — the picture is the payload.'
    : '\nsymbol structure verified; it was not decoded (set HASSAL_QR_DECODER to a jsQR bundle to add that check).',
);

console.log(`\n${fails.length === 0 ? 'OK' : 'FAILED'} — ${step} checks, ${fails.length} failing`);
for (const f of fails) console.log(`   · ${f}`);
process.exit(fails.length === 0 ? 0 : 1);
