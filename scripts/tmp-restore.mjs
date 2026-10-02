/**
 * Put every staged recovery copy back at its place in the module tree, unchanged.
 *
 * The content is the priority: this writes the replayed bytes exactly as they were
 * recovered, so nothing depends on the import rewriter being right. The rewriter
 * runs afterwards and can be re-run from here at any time.
 */
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';

const root = process.cwd();
const STAGE = 'backend/storage/web-recovery/stage';

const MOVES = new Map(
  [...readFileSync('scripts/tmp-stage3-move.mjs', 'utf8').matchAll(/\['(src\/[^']+\.(?:tsx?|d\.ts))', '(src\/[^']+\.(?:tsx?|d\.ts))'\]/g)].map(
    (match) => [match[1], match[2]],
  ),
);

if (MOVES.size !== 39) {
  console.log(`MOVES parsed: ${MOVES.size} (expected 39)`);
  for (const [from, to] of MOVES) console.log(`  ${from} -> ${to}`);
}

let copied = 0;
for (const [oldPath, newPath] of MOVES) {
  let text;
  try {
    text = readFileSync(join(root, STAGE, oldPath), 'utf8');
  } catch {
    console.log(`MISSING STAGE: ${oldPath}`);
    continue;
  }
  if (newPath === 'src/index.css') continue;
  mkdirSync(join(root, dirname(newPath)), { recursive: true });
  writeFileSync(join(root, newPath), text, 'utf8');
  copied += 1;
  console.log(`${oldPath} -> ${newPath} (${text.split('\n').length - 1} lines)`);
}
console.log(`copied: ${copied}`);
