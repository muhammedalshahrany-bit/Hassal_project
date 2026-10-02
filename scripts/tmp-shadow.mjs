/**
 * Find every screen where a local declaration shadows an imported name.
 *
 * The split moved the API functions from one `api` object into bare module
 * exports, so a screen that used to write `api.createProduct(payload)` beside its
 * own `const createProduct = async () => …` now imports a `createProduct` that its
 * local hides. TypeScript does not flag it — the local is in an inner scope, and
 * shadowing is legal — so the call quietly becomes recursion. This walks the tree
 * and lists the collisions for a human to rename.
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';

const root = process.cwd();

function walk(dir) {
  const found = [];
  for (const entry of readdirSync(root + '/' + dir)) {
    const path = `${dir}/${entry}`;
    if (statSync(root + '/' + path).isDirectory()) found.push(...walk(path));
    else if (/\.tsx?$/.test(entry)) found.push(path);
  }
  return found;
}

let hits = 0;
for (const path of walk('src')) {
  if (/(types|api)\.tsx?$/.test(path) && path.startsWith('src/features')) continue;
  const lines = readFileSync(root + '/' + path, 'utf8').split('\n');
  const imported = new Set();
  for (const line of lines) {
    const m = line.match(/^import\s+(?:type\s+)?(?:[\w*\s]+,\s*)?\{([^}]*)\}\s*from\s*['"][^'"]+['"];?\s*$/);
    if (!m) continue;
    for (const raw of m[1].split(',')) {
      const name = raw.trim().replace(/^type\s+/, '').split(/\s+as\s+/)[0];
      if (name) imported.add(name);
    }
  }
  if (!imported.size) continue;

  /* Multi-line import blocks too: the rewriter kept the long lucide lists as-is. */
  let inImport = false;
  let buffer = '';
  for (const line of lines) {
    if (!inImport && /^import\b/.test(line) && !/from\s*['"]/.test(line)) {
      inImport = true;
      buffer = line;
      continue;
    }
    if (inImport) {
      buffer += ' ' + line;
      if (/from\s*['"]/.test(line)) {
        inImport = false;
        const m = buffer.match(/\{([^}]*)\}\s*from/);
        if (m) for (const raw of m[1].split(',')) {
          const name = raw.trim().replace(/^type\s+/, '').split(/\s+as\s+/)[0];
          if (name) imported.add(name);
        }
        buffer = '';
      }
      continue;
    }
  }

  for (let i = 0; i < lines.length; i += 1) {
    const line = lines[i];
    if (/^\s*(\/\/|\*)/.test(line)) continue;
    const m = line.match(/^\s*(?:export\s+)?(?:async\s+)?(?:const|let|var|function|class)\s+(\w+)\b/);
    if (!m) continue;
    if (imported.has(m[1])) {
      hits += 1;
      console.log(`${path}:${i + 1}: local ${m[1]} shadows the imported name`);
    }
  }
}
console.log(`\nshadowing collisions: ${hits}`);
