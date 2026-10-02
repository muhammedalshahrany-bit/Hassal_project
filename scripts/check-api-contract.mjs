/**
 * Does every path the web client calls actually exist on the server?
 *
 * Why this is a script and not a review comment: the endpoint functions in
 * `src/features/<module>/api.ts` are the client's whole surface on the API, and
 * the only thing that makes one of them safe is that the route it names is on the
 * server. That relationship is invisible to the type checker —
 * `request<SomeType>('/api/v1/whatever')` compiles whether the endpoint exists,
 * was renamed, or was never built. The failure shows up as a 404 under a real
 * buyer's finger.
 *
 * So the check reads the truth from both sides and compares them:
 *
 *   1. `php artisan route:list --json` from the backend — the routes that exist.
 *   2. every `request…('/api/v1/…')` literal in `src/features/<module>/api.ts` —
 *      the routes the client believes in.
 *
 * A client path with no route is an error, because it is a button that cannot
 * work. The reverse (a route the web never calls) is reported and not failed:
 * the mobile client, API keys and webhooks all speak to this server too, so an
 * unrouted-from-web endpoint is normal.
 *
 * Run:  npm run check:api
 * Requires a fresh dump; regenerate it with:
 *   (cd backend && php artisan route:list --json > storage/routes.check.json)
 */

import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const routesFile = resolve(root, 'backend/storage/routes.check.json');

if (!existsSync(routesFile)) {
  console.error(
    `Missing ${routesFile}\n` +
    'Generate it first:  cd backend && php artisan route:list --json > storage/routes.check.json\n' +
    '(It is a generated dump of the routes that exist, so it is not committed.)'
  );
  process.exit(2);
}

/**
 * `{id}`, `{organizationId}` and `${id}` are the same hole in a path. Collapsing
 * every placeholder to one token is what lets a template literal in the client
 * be matched against a route pattern from Laravel.
 */
const normalize = (path) =>
  path
    .replace(/\{[^}]*\}/g, '{}')
    .replace(/\$\{[^}]*\}/g, '{}')
    .replace(/[?#].*$/, '')
    .replace(/^\/+/, '')
    .replace(/\/+$/, '');

const serverRoutes = new Set(
  JSON.parse(readFileSync(routesFile, 'utf8'))
    .filter((route) => route.uri.startsWith('api/'))
    .map((route) => normalize(route.uri)),
);

/**
 * The client's own calls, with the function that makes each one named for the
 * error message.
 *
 * Every `/api/v1…` literal in every feature module is read, whichever shape the
 * call around it takes: `request<T>(…)`, `request(…)`, a path built by string
 * concatenation, one opened on the line above a long generic. Anchoring the search
 * on a single `async name(` pattern looked tidy and quietly skipped a dozen of
 * them, and a check that misses calls is worse than no check, because it reports
 * all clear.
 *
 * The transport itself is not read: `src/core/api/http.ts` receives a path, it
 * does not choose one, so a literal there would already be a mistake.
 */
const clientFiles = readdirSync(resolve(root, 'src/features'))
  .map((feature) => resolve(root, `src/features/${feature}/api.ts`))
  .filter((path) => existsSync(path));

const called = new Map();
for (const path of clientFiles) {
  const source = readFileSync(path, 'utf8');
  const feature = path.slice(resolve(root, 'src/features').length + 1).split('/')[0];
  const functionPositions = [...source.matchAll(/^export\s+(?:async\s+)?function\s+(\w+)/gm)].map((match) => ({
    name: match[1],
    at: match.index ?? 0,
  }));
  const functionFor = (offset) => {
    const before = functionPositions.filter((entry) => entry.at < offset).pop();
    return before ? before.name : '(inline)';
  };

  for (const match of source.matchAll(/['`](\/api\/v1[^'`\n]*)['`]/g)) {
    const key = normalize(match[1]);
    if (!called.has(key)) called.set(key, []);
    const who = `${feature}:${functionFor(match.index ?? 0)}`;
    if (!called.get(key).includes(who)) called.get(key).push(who);
  }
}

const missing = [...called.entries()].filter(([path]) => !serverRoutes.has(path));
const unused = [...serverRoutes].filter((path) => !called.has(path));

console.log(`Server API routes: ${serverRoutes.size}`);
console.log(`Client paths called from src/features/*/api.ts: ${called.size} (${clientFiles.length} modules)`);

if (missing.length) {
  console.error('\nClient calls a route the server does not have:');
  for (const [path, methods] of missing) console.error(`  /${path}  ← ${methods.join(', ')}`);
  console.error('\nEither implement the endpoint or delete the function and every screen that uses it.');
  process.exit(1);
}

console.log('\nOK — every path in the feature api modules is a route on the server.');
console.log(`(${unused.length} server routes are not called from the web — checked, not assumed:)`);
for (const path of unused) console.log(`  /${path}`);
console.log(
  '  · auth/preferences* is the mobile client\'s surface; the web keeps its own settings.\n' +
  '  · documents/{id}/file is `signed` middleware: it is reached through the `url` the\n' +
  '    payload already carries, which an `<a>` opens and a bearer-token client cannot.'
);
