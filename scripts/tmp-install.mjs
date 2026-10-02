/**
 * Install the reconstruction and replay the two rewrites the split made.
 *
 * The staged copies recovered from the transcripts are the files as they stood
 * before `src/types.ts` and `src/lib/api.ts` were split. This puts each one at its
 * place in the module tree and re-applies exactly those two effects — an import of
 * the module that owns the name, and a bare call instead of `api.method()` — using
 * the current `src/features/<module>/<types,api>.ts` and `src/core/api/http.ts` as
 * the authority for where every name lives. A name with no owner is reported, not
 * guessed at.
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';

const root = process.cwd();
const STAGE = 'backend/storage/web-recovery/stage';

const MOVES = new Map(
  [...readFileSync('scripts/tmp-stage3-move.mjs', 'utf8').matchAll(/\['(src\/[^']+)', '(src\/[^']+)'\]/g)].map(
    (match) => [match[1], match[2]],
  ),
);

const alias = (srcPath) => '@/' + srcPath.slice('src/'.length).replace(/\.(tsx?|d\.ts)$/, '');

/** The flat tree as it was, so a relative specifier can be resolved by existence. */
const oldFiles = new Set(
  execFileSync('git', ['ls-tree', '-r', '--name-only', 'HEAD', '--', 'src'], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] })
    .split('\n')
    .filter(Boolean)
    .concat(['src/components/PermissionPicker.tsx']),
);

const exportedNames = (text) => {
  const names = new Set();
  for (const match of text.matchAll(/^export\s+(?:declare\s+)?(?:async\s+)?(?:default\s+)?(?:function|const|let|var|class|interface|type|enum)\s+(\w+)/gm)) {
    names.add(match[1]);
  }
  for (const match of text.matchAll(/^export\s+type\s+(\w+)/gm)) names.add(match[1]);
  return names;
};

const typeHome = new Map();
const valueHome = new Map();
for (const feature of readdirSync(join(root, 'src/features'))) {
  const asDir = join(root, 'src/features', feature);
  if (!statSync(asDir).isDirectory()) continue;
  const typesPath = join(asDir, 'types.ts');
  if (exists(typesPath)) for (const name of exportedNames(readFileSync(typesPath, 'utf8'))) typeHome.set(name, `@/features/${feature}/types`);
  const apiPath = join(asDir, 'api.ts');
  if (exists(apiPath)) for (const name of exportedNames(readFileSync(apiPath, 'utf8'))) valueHome.set(name, `@/features/${feature}/api`);
}
const httpSource = readFileSync(join(root, 'src/core/api/http.ts'), 'utf8');
for (const name of exportedNames(httpSource)) {
  /* An interface is a type; a function is a value. The transport holds both. */
  if (/^export\s+(?:declare\s+)?(?:interface|type)\s+/m.test(`export ${name}`) || !new RegExp(`^export\\s+(?:async\\s+)?(?:function|const|let|var)\\s+${name}\\b`, 'm').test(httpSource)) {
    typeHome.set(name, '@/core/api/http');
  }
  valueHome.set(name, '@/core/api/http');
}

function exists(path) {
  try {
    return statSync(path).isFile();
  } catch {
    return false;
  }
}

const FLAT = new Map([
  ['src/lib/i18n.ts', '@/core/i18n'],
  ['src/lib/config.ts', '@/core/config'],
  ['src/lib/theme.ts', '@/core/theme'],
  ['src/lib/navigation.ts', '@/core/navigation'],
  ['src/lib/googleIdentity.ts', '@/features/auth/googleIdentity'],
]);

function resolve(fromOld, spec) {
  if (spec.startsWith('@/')) {
    const bare = 'src/' + spec.slice(2);
    for (const ext of ['.ts', '.tsx', '']) if (oldFiles.has(bare + ext)) return bare + ext;
    return null;
  }
  if (!spec.startsWith('.')) return null;
  const joined = join(dirname(fromOld), spec).replace(/\\/g, '/');
  const stripped = joined.replace(/\.tsx?$/, '');
  for (const ext of ['.ts', '.tsx', '']) if (oldFiles.has(stripped + ext)) return stripped + ext;
  return oldFiles.has(joined) ? joined : null;
}

/** Where a moved or flat-tree module is imported from now. */
const homeFor = (target) => FLAT.get(target) ?? alias(MOVES.get(target) ?? target);

const problems = [];
const installed = [];

/** One named import: `type ApiResult`, `api`, `verificationGate`. */
function classify(name, statementIsType, oldPath) {
  const isValue = valueHome.has(name);
  const isType = typeHome.has(name);
  if (statementIsType || !isValue) {
    if (!isType && !isValue) {
      problems.push(`${oldPath}: ${name} has no owner`);
      return null;
    }
    return { home: typeHome.get(name) ?? valueHome.get(name), name, asType: true };
  }
  return { home: valueHome.get(name), name, asType: false };
}

for (const [oldPath, newPath] of MOVES) {
  let text;
  try {
    text = readFileSync(join(root, STAGE, oldPath), 'utf8');
  } catch {
    problems.push(`${oldPath}: nothing staged`);
    continue;
  }

  /*
   * The reconciliation panel was deleted on 2026-09-25 by a one-shot `node -e`
   * splice, which leaves neither a Write nor an Edit in the transcript. Its state
   * and its fetch are already absent from the reconstruction, and the built bundle
   * has no trace of it, so the JSX that read them goes with them.
   */
  if (oldPath === 'src/components/LedgerView.tsx') {
    const before = text;
    text = text.replace(/\n *\{\/\* Reconciliation audit \*\/\n[\s\S]*?\n *<\/PanelShell>\n/, '\n');
    if (text === before) problems.push('LedgerView: the reconciliation block was not found');
  }

  const pending = new Map();
  const add = (entry) => {
    if (!entry) return;
    if (!pending.has(entry.home)) pending.set(entry.home, new Map());
    /* A name known as a value stays a value even when another file typed it. */
    const previous = pending.get(entry.home).get(entry.name);
    pending.get(entry.home).set(entry.name, previous === false ? false : entry.asType);
  };

  /* Named imports are redistributed; default and bare imports stay where they are. */
  text = text.replace(/^import\s+(type\s+)?\{([^}]*)\}\s*from\s*(['"])([^'"]+)\3;?\n/gm, (match, statementTypes, inner, _q, spec) => {
    const target = resolve(oldPath, spec);
    if (!target) return match;
    const asTypeStatement = Boolean(statementTypes);
    if (target === 'src/types.ts') {
      for (const item of inner.split(',')) {
        const name = item.trim().replace(/^type\s+/, '');
        if (name) add(classify(name, true, oldPath));
      }
      return '';
    }
    if (target === 'src/lib/api.ts') {
      for (const item of inner.split(',')) {
        const trimmed = item.trim();
        const name = trimmed.replace(/^type\s+/, '');
        if (!name || name === 'api') continue;
        add(classify(name, asTypeStatement || trimmed.startsWith('type '), oldPath));
      }
      return '';
    }
    const home = homeFor(target);
    for (const item of inner.split(',')) {
      const trimmed = item.trim();
      const name = trimmed.replace(/^type\s+/, '');
      if (!name) continue;
      /* `api.method` names that live in the transport or a module keep their kind. */
      if (home.startsWith('@/features/') || home === '@/core/api/http') add(classify(name, asTypeStatement || trimmed.startsWith('type '), oldPath));
      else add({ home, name, asType: asTypeStatement || trimmed.startsWith('type ') });
    }
    return '';
  });

  text = text.replace(/^import\s+(\w+)(?:\s*,\s*\{([^}]*)\})?\s*from\s*(['"])([^'"]+)\3;?\n/gm, (match, defaultName, inner, _q, spec) => {
    const target = resolve(oldPath, spec);
    if (!target) return match;
    const home = homeFor(target);
    let head = `import ${defaultName}`;
    if (inner) {
      for (const item of inner.split(',')) {
        const trimmed = item.trim();
        const name = trimmed.replace(/^type\s+/, '');
        if (name) add(classify(name, trimmed.startsWith('type '), oldPath));
      }
      const values = [];
      head = `import ${defaultName}, { ${inner.trim()} }`;
      void values;
    }
    return `${head} from '${home}';\n`;
  });

  text = text.replace(/^import\s+(['"])([^'"]+)\1;?\n/gm, (match, _q, spec) => {
    const target = resolve(oldPath, spec);
    if (!target) return match;
    const relative = '../'.repeat(MOVES.get(newPath) === newPath ? 0 : dirname(newPath).split('/').length - 2) + 'index.css';
    return target === 'src/index.css' ? `import '${relative}';\n` : match;
  });

  /* `api.method(...)` becomes a bare call, and its owner is imported above. */
  text = text.replace(/\bapi\.(\w+)\s*(?:<[^>]*>)?\(/g, (match, name) => {
    if (!valueHome.has(name)) {
      problems.push(`${oldPath}: api.${name} is not exported anywhere`);
      return match;
    }
    add({ home: valueHome.get(name), name, asType: false });
    return `${name}(`;
  });

  if (/\bapi\./.test(text)) problems.push(`${oldPath}: a leftover api. reference`);

  const block = [...pending.entries()]
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([home, names]) => {
      const values = [...names.entries()].filter(([, asType]) => !asType).map(([name]) => name).sort();
      const types = [...names.entries()].filter(([, asType]) => asType).map(([name]) => name).sort();
      const lines = [];
      if (values.length) lines.push(`import { ${values.join(', ')} } from '${home}';`);
      if (types.length) lines.push(`import type { ${types.join(', ')} } from '${home}';`);
      return lines.join('\n');
    })
    .join('\n');

  /*
   * The rebuilt block goes at the end of the file's leading import run. Placing an
   * import after a statement is a syntax error, which is how the first pass at
   * this ended with TS1232 in six screens.
   */
  const leading = text.match(/^(?:\s*(?:\/\*\*[\s\S]*?\*\/|\/\/[^\n]*|import[^;]*;)\s*)*/)[0];
  const insertAt = leading.length;
  text = text.slice(0, insertAt) + (block ? block + '\n' : '') + text.slice(insertAt);

  text = text
    .replace(/\blib\/googleIdentity\.ts\b/g, 'features/auth/googleIdentity.ts')
    .replace(/\blib\/(config|theme|i18n|navigation)\.ts\b/g, 'core/$1.ts')
    .replace(/src\/components\/([A-Za-z]+)\.tsx\b/g, (match, name) => {
      const target = `src/components/${name}.tsx`;
      return MOVES.has(target) ? MOVES.get(target) : match;
    });

  mkdirSync(join(root, dirname(newPath)), { recursive: true });
  writeFileSync(join(root, newPath), text, 'utf8');
  installed.push(`${oldPath} -> ${newPath} (${text.split('\n').length} lines)`);
}

console.log(installed.join('\n'));
console.log(`\ninstalled: ${installed.length} of ${MOVES.size}`);
if (problems.length) {
  console.log('PROBLEMS:');
  for (const problem of problems) console.log('  ' + problem);
}
