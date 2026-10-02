/**
 * Replay the stage-1/2 module split over the recovered screens.
 *
 * The copies that came back out of the transcripts are the files as they stood
 * before `src/types.ts` and `src/lib/api.ts` were broken up: the split itself ran
 * through scripts, so it left no Edit events to replay. This re-applies exactly
 * that rewrite — each name imported from one of the two split modules is sent to the
 * module that exports it now, and each `api.method()` call becomes a bare call of
 * the function that owns it. A name with no owner is reported, not guessed at.
 *
 * Every other import is rewritten in place, at the line it already sits on, so the
 * comments a screen wrote between its imports stay where the author put them.
 */
import { execFileSync } from 'node:child_process';
import { readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';

const root = process.cwd();
const read = (path) => readFileSync(root + '/' + path, 'utf8');
const SPLIT = new Set(['src/types.ts', 'src/lib/api.ts']);

const MOVES = new Map(
  [...read('scripts/tmp-stage3-move.mjs').matchAll(/\['(src\/[^']+\.(?:tsx?|d\.ts))', '(src\/[^']+\.(?:tsx?|d\.ts))'\]/g)].map(
    (m) => [m[1], m[2]],
  ),
);

const FLAT = new Map([
  ['src/lib/i18n.ts', '@/core/i18n'],
  ['src/lib/config.ts', '@/core/config'],
  ['src/lib/theme.ts', '@/core/theme'],
  ['src/lib/navigation.ts', '@/core/navigation'],
  ['src/lib/googleIdentity.ts', '@/features/auth/googleIdentity'],
]);

const alias = (srcPath) => '@/' + srcPath.slice('src/'.length).replace(/\.tsx?$/, '');
const homeFor = (target) => FLAT.get(target) ?? alias(MOVES.get(target) ?? target);

/** The flat tree as it was, so an old relative specifier resolves by existence. */
const oldFiles = new Set(
  execFileSync('git', ['ls-tree', '-r', '--name-only', 'HEAD', '--', 'src'], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] })
    .split('\n')
    .filter(Boolean)
    .concat([...MOVES.keys()], ['src/components/PermissionPicker.tsx', 'src/types.ts', 'src/lib/api.ts', 'src/index.css']),
);

function resolve(fromPath, spec) {
  if (spec.startsWith('@/')) {
    const bare = 'src/' + spec.slice(2);
    for (const ext of ['.ts', '.tsx', '']) if (oldFiles.has(bare + ext)) return bare + ext;
    return null;
  }
  if (!spec.startsWith('.')) return null;
  const segments = [];
  for (const seg of (dirname(fromPath) + '/' + spec).split('/')) {
    if (seg === '.') continue;
    if (seg === '..') segments.pop();
    else segments.push(seg);
  }
  const guess = segments.join('/');
  for (const ext of ['.ts', '.tsx', '']) if (oldFiles.has(guess + ext)) return guess + ext;
  return null;
}

/* ---------- what the tree that exists now actually exports ---------- */

function exportsOf(text) {
  const types = new Set();
  const values = new Set();
  for (const m of text.matchAll(/^export\s+(?:declare\s+)?(?:async\s+)?(?:function|const|let|var|class|enum)\s+(\w+)/gm)) values.add(m[1]);
  for (const m of text.matchAll(/^export\s+(?:declare\s+)?(?:interface|type)\s+(\w+)/gm)) types.add(m[1]);
  for (const m of text.matchAll(/^export\s*\{([^}]*)\}/gm)) {
    for (const item of m[1].split(',')) {
      const name = item.trim().split(/\s+as\s+/).pop();
      if (name) (/(^|[\s,])type\s/.test(item.trim()) ? types : values).add(name);
    }
  }
  return { types, values };
}

const typeHome = new Map();
const valueHome = new Map();
/** Every module path on disk, forward-slashed, because `path.join` is not. */
function walk(dir) {
  const found = [];
  for (const entry of readdirSync(root + '/' + dir)) {
    const path = `${dir}/${entry}`;
    if (statSync(root + '/' + path).isDirectory()) found.push(...walk(path));
    else if (/\.tsx?$/.test(entry)) found.push(path);
  }
  return found;
}
for (const path of walk('src').filter((p) => /^src\/(features\/[^/]+\/(types|api)\.ts|core\/api\/http\.ts)$/.test(p))) {
  const parts = path.split('/');
  const home = parts[1] === 'features' ? `@/features/${parts[2]}/${parts[3].replace(/\.ts$/, '')}` : '@/core/api/http';
  const { types, values } = exportsOf(read(path));
  for (const name of types) typeHome.set(name, home);
  for (const name of values) valueHome.set(name, home);
}

/* ---------- import parsing ---------- */

function parseHead(head) {
  let typeOnly = false;
  let rest = head;
  if (rest.startsWith('type ')) {
    typeOnly = true;
    rest = rest.slice(5).trim();
  }
  const brace = rest.indexOf('{');
  if (brace === -1) {
    if (/^\*\s+as\s+\w+$/.test(rest)) return { typeOnly, forms: [rest], items: [] };
    return { typeOnly, forms: [rest], items: [] };
  }
  const before = rest.slice(0, brace).replace(/,\s*$/, '').trim();
  const close = rest.lastIndexOf('}');
  const items = rest
    .slice(brace + 1, close)
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean);
  return { typeOnly, forms: before ? [before] : [], items };
}

/** The leading import run: statement ranges, plus the comment/blank lines between them. */
function scan(lines) {
  let i = 0;
  while (i < lines.length && !/^import\b/.test(lines[i])) i += 1;
  const first = i;
  const entries = [];
  let inBlock = false;
  while (i < lines.length) {
    const line = lines[i];
    if (inBlock) {
      entries.push({ kind: 'keep', lines: [line] });
      if (/\*\//.test(line)) inBlock = false;
      i += 1;
      continue;
    }
    if (line.trim() === '' || /^\/\//.test(line)) {
      entries.push({ kind: 'keep', lines: [line] });
      i += 1;
      continue;
    }
    if (/^\/\*/.test(line)) {
      inBlock = !/\*\//.test(line);
      entries.push({ kind: 'keep', lines: [line] });
      i += 1;
      continue;
    }
    if (!/^import\b/.test(line)) break;
    let stmt = line;
    let j = i;
    while (!/(^|\s)from\s*(['"])[^'"]*\2\s*;?\s*$/.test(stmt) && !/^import\s*(['"])[^'"]*\1\s*;?\s*$/.test(stmt)) {
      j += 1;
      if (j >= lines.length) break;
      stmt += '\n' + lines[j];
    }
    entries.push({ kind: 'import', text: stmt, from: i, to: j });
    i = j + 1;
  }
  return { first, entries, end: i };
}

/* ---------- rewrite ---------- */

const problems = [];
const report = [];

for (const [oldPath, newPath] of MOVES) {
  if (oldPath === 'src/index.css' || oldPath === 'src/components/PermissionPicker.tsx') continue;
  let text;
  try {
    text = read('backend/storage/web-recovery/stage/' + oldPath);
  } catch {
    problems.push(`${oldPath}: nothing staged`);
    continue;
  }

  /* The reconciliation panel was removed by a one-shot splice that left no Edit
     event. Its state and its fetch are absent from the replay, and the built
     bundle carries no trace of it, so the JSX that read them goes with them. */
  if (oldPath === 'src/components/LedgerView.tsx') {
    const before = text;
    text = text.replace(/\n *\{\/\* Reconciliation audit \*\/\}\n[\s\S]*?\n *<\/PanelShell>\n/, '\n');
    if (text === before) problems.push('LedgerView: the reconciliation block was not found');
  }

  const lines = text.split('\n');
  const region = scan(lines);
  if (!region.entries.some((entry) => entry.kind === 'import')) {
    writeFileSync(root + '/' + newPath, text, 'utf8');
    report.push(`${newPath} (${text.split('\n').length - 1} lines, no imports)`);
    continue;
  }
  const body = lines.slice(region.end).join('\n');

  /** Where the redistributed names go, gathered across the whole file. */
  const pending = new Map();
  const note = (home, asType, name) => {
    if (!pending.has(home)) pending.set(home, { types: new Set(), values: new Set() });
    pending.get(home)[asType ? 'types' : 'values'].add(name);
  };

  const own = (name, asType, where) => {
    if (asType && typeHome.has(name)) return { home: typeHome.get(name), asType: true };
    if (valueHome.has(name)) return { home: valueHome.get(name), asType: false };
    if (typeHome.has(name)) return { home: typeHome.get(name), asType: true };
    problems.push(`${oldPath}: ${name} (from ${where}) has no owner`);
    return null;
  };

  const localNames = new Map();
  const out = [];
  let pendingAt = -1;

  region.entries.forEach((entry, index) => {
    if (entry.kind === 'keep') {
      out.push(...entry.lines);
      return;
    }
    const stmt = entry.text.replace(/\s+/g, ' ').trim();
    const from = stmt.match(/^import\s+([\s\S]*?)\s*from\s*(['"])([^'"]+)\2\s*;?\s*$/);
    const bare = stmt.match(/^import\s*(['"])([^'"]+)\1\s*;?\s*$/);

    if (bare) {
      if (resolve(oldPath, bare[2]) === 'src/index.css') {
        const up = '../'.repeat(Math.max(newPath.split('/').length - 2, 1));
        out.push(`import '${up}index.css';`);
        return;
      }
      out.push(stmt);
      return;
    }
    if (!from) {
      problems.push(`${oldPath}: unparsed import ${stmt.slice(0, 70)}`);
      out.push(stmt);
      return;
    }
    const spec = from[3];
    const target = resolve(oldPath, spec);
    if (!target) {
      /* A package import is left exactly as the author laid it out: its specifier
         changes, its list of icons does not, and collapsing twelve lines of lucide
         names into one is a formatting edit this script has no business making. */
      out.push(entry.text.trimEnd());
      return;
    }
    const { typeOnly, forms, items } = parseHead(from[1]);

    if (SPLIT.has(target)) {
      if (forms.length) problems.push(`${oldPath}: default import from the split ${spec}`);
      for (const raw of items) {
        const asType = typeOnly || /^type\s+/.test(raw);
        const name = raw.replace(/^type\s+/, '').trim().split(/\s+as\s+/)[0];
        if (!name || name === 'api') continue;
        const owner = own(name, asType, spec);
        if (!owner) continue;
        note(owner.home, owner.asType, raw.replace(/^type\s+/, '').trim());
      }
      if (pendingAt < 0) pendingAt = out.length;
      return;
    }

    const home = homeFor(target);
    const seen = localNames.get(home) ?? { types: new Set(), values: new Set() };
    for (const raw of items) {
      const asType = typeOnly || /^type\s+/.test(raw);
      seen[asType ? 'types' : 'values'].add(raw.replace(/^type\s+/, '').trim().split(/\s+as\s+/)[0]);
    }
    localNames.set(home, seen);

    const marked = items.map((raw) => ({ text: raw.replace(/^type\s+/, '').trim(), asType: typeOnly || /^type\s+/.test(raw) }));
    const valueItems = marked.filter((item) => !item.asType).map((item) => item.text);
    const typeItems = marked.filter((item) => item.asType).map((item) => item.text);

    if (typeOnly) {
      /* `import type React, { X }` is not a thing; a statement-level type marker
         only ever sits on a braces import. */
      if (typeItems.length) out.push(`import type { ${typeItems.join(', ')} } from '${home}';`);
      return;
    }
    if (forms.length && !valueItems.length && !typeItems.length) {
      for (const form of forms) out.push(`import ${form} from '${home}';`);
      return;
    }
    const prefix = forms.length ? `${forms.join(', ')}, ` : '';
    if (valueItems.length) out.push(`import ${prefix}{ ${valueItems.join(', ')} } from '${home}';`);
    else if (prefix) out.push(`import ${prefix.replace(/,\s*$/, '')} from '${home}';`);
    if (typeItems.length) out.push(`import type { ${typeItems.join(', ')} } from '${home}';`);
  });

  /* `api.method(...)` becomes a bare call, owned by whichever module exports it. */
  const rewritten = body.replace(/(?<![\w.'"])api\.(\w+)(\s*(?:<[^>]*>)?\()/g, (match, name, tail) => {
    if (!valueHome.has(name)) {
      problems.push(`${oldPath}: api.${name} is exported by no module`);
      return match;
    }
    note(valueHome.get(name), false, name);
    return name + tail;
  });

  const left = [...rewritten.matchAll(/(?<![\w.'"`])api\.\w+/g)].map((m) => m[0]);
  if (left.length) problems.push(`${oldPath}: still referencing ${[...new Set(left)].join(', ')}`);

  if (pendingAt >= 0 || pending.size) {
    const block = [];
    for (const home of [...pending.keys()].sort()) {
      const slot = pending.get(home);
      const already = localNames.get(home) ?? { types: new Set(), values: new Set() };
      const values = [...slot.values].filter((name) => !already.values.has(name)).sort();
      const types = [...slot.types].filter((name) => !already.types.has(name)).sort();
      if (values.length) block.push(`import { ${values.join(', ')} } from '${home}';`);
      if (types.length) block.push(`import type { ${types.join(', ')} } from '${home}';`);
    }
    out.splice(pendingAt < 0 ? out.length : pendingAt, 0, ...block);
  }

  while (out.length && out[out.length - 1].trim() === '') out.pop();
  const result = [...lines.slice(0, region.first), ...out, '', rewritten.replace(/^\n+/, '')].join('\n');
  writeFileSync(root + '/' + newPath, result, 'utf8');
  report.push(`${newPath} (${result.split('\n').length - 1} lines)`);
}

console.log(report.join('\n'));
console.log(`\nrewritten: ${report.length} of 38`);
if (problems.length) {
  console.log('\nPROBLEMS:');
  for (const p of problems) console.log('  ' + p);
}
