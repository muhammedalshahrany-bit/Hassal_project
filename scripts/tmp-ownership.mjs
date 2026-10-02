/**
 * Who owns which name.
 *
 * The rewriter below needs to know, for every identifier a screen imports, which
 * module exports it. This builds that index from the tree that already exists — the
 * feature `types.ts` / `api.ts` files and the transport — and reports the cases the
 * rewriter cannot decide on its own: a name no module exports, and a name two of
 * them export.
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();

function sources(dir) {
  const out = [];
  for (const entry of readdirSync(join(root, dir))) {
    const path = `${dir}/${entry}`;
    if (statSync(join(root, path)).isDirectory()) out.push(...sources(path));
    else if (/\.tsx?$/.test(entry)) out.push(path);
  }
  return out;
}

/** `export [default] [async] function|const|let|var|class|interface|type|enum NAME`, plus `export { a, b }`. */
function exportsOf(text) {
  const types = new Set();
  const values = new Set();
  for (const m of text.matchAll(/^export\s+(?:declare\s+)?(?:async\s+)?(?:function|const|let|var|class|enum)\s+(\w+)/gm)) values.add(m[1]);
  for (const m of text.matchAll(/^export\s+(?:interface|type)\s+(\w+)/gm)) types.add(m[1]);
  for (const m of text.matchAll(/^export\s*\{([^}]*)\}/gm)) {
    for (const item of m[1].split(',')) {
      const name = item.trim().split(/\s+as\s+/).pop();
      if (name && !/^\*\s+as/.test(item)) values.add(name);
    }
  }
  return { types, values };
}

const owners = new Map();
const add = (name, home, kind, path) => {
  if (!owners.has(name)) owners.set(name, []);
  owners.get(name).push({ home, kind, path });
};

for (const path of sources('src/features').concat(['src/core/api/http.ts'])) {
  if (!/(types|api|http)\.tsx?$/.test(path)) continue;
  const text = readFileSync(join(root, path), 'utf8');
  const parts = path.split('/');
  const home = parts[1] === 'features' ? `@/features/${parts[2]}/${parts[3].replace(/\.ts$/, '')}` : '@/core/api/http';
  const { types, values } = exportsOf(text);
  for (const name of types) add(name, home, 'type', path);
  for (const name of values) add(name, home, 'value', path);
}

const collisions = [];
for (const [name, list] of owners) {
  const homes = new Set(list.map((e) => `${e.kind}:${e.home}`));
  if (homes.size > 1) collisions.push(`${name} -> ${[...homes].join(', ')}`);
}
console.log(`indexed names: ${owners.size}`);
console.log(`CROSS-MODULE COLLISIONS:\n  ${collisions.join('\n  ') || 'none'}`);

/* Every specifier the restored screens ask for, matched against the index. */
const asked = new Set();
for (const path of sources('src')) {
  if (/(types|api|http)\.tsx?$/.test(path) && path.startsWith('src/features')) continue;
  const text = readFileSync(join(root, path), 'utf8');
  for (const m of text.matchAll(/^import\s+(type\s+)?\{([^}]*)\}\s*from\s*['"]\.\.\/types['"]/gm)) {
    for (const item of m[2].split(',')) {
      const name = item.trim().replace(/^type\s+/, '');
      if (name) asked.add(`TYPE ${name}`);
    }
  }
}
const missing = [...asked].filter((entry) => !owners.has(entry.split(' ')[1]));
console.log(`\nnames imported from the old src/types.ts: ${asked.size}`);
console.log(`UNOWNED: ${missing.join(', ') || 'none'}`);
console.log(`\ntyped-as-value (a name in ../types that is actually a value): ${[...asked].filter((e) => (owners.get(e.split(' ')[1]) ?? []).every((o) => o.kind !== 'type')).join(', ') || 'none'}`);
