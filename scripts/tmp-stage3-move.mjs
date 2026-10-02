/**
 * Stage 3 of the web restructure: the components move into their modules.
 *
 * One flat `src/components/` directory held thirty-two files, which said nothing
 * about who owns what — `DealsPipelineView` sat beside `BrandMark` as if a screen
 * and a logo were the same kind of thing. This script relocates every file onto
 * the module map the backend and `src/features/<module>/` already follow, then rewrites
 * every import to an alias, so the tree a reader sees is the tree the code uses.
 *
 * It runs once and is deleted after; the shape it produces is the deliverable.
 */
import {
  existsSync,
  mkdirSync,
  readdirSync,
  readFileSync,
  rmSync,
  statSync,
  writeFileSync,
} from 'node:fs';
import { dirname, join } from 'node:path';

const root = process.cwd();

/** old src-relative path -> new src-relative path. */
const MOVES = new Map([
  // — the app shell: what paints the frame and decides which screen is on view
  ['src/App.tsx', 'src/app/App.tsx'],
  ['src/main.tsx', 'src/app/main.tsx'],
  ['src/components/Header.tsx', 'src/app/Header.tsx'],

  // — core: the things every module reads and no module owns
  ['src/lib/config.ts', 'src/core/config.ts'],
  ['src/lib/i18n.ts', 'src/core/i18n.ts'],
  ['src/lib/theme.ts', 'src/core/theme.ts'],
  ['src/lib/navigation.ts', 'src/core/navigation.ts'],

  // — shared/ui: controls with no business meaning
  ['src/components/panel.tsx', 'src/shared/ui/panel.tsx'],
  ['src/components/BrandMark.tsx', 'src/shared/ui/BrandMark.tsx'],
  ['src/components/CameraCapture.tsx', 'src/shared/ui/CameraCapture.tsx'],

  // — auth: the session, the account's own screen, and what the provider needs
  ['src/lib/googleIdentity.ts', 'src/features/auth/googleIdentity.ts'],
  ['src/components/AuthModal.tsx', 'src/features/auth/components/AuthModal.tsx'],
  ['src/components/AccountSetupCard.tsx', 'src/features/auth/components/AccountSetupCard.tsx'],
  ['src/components/SigninDevicesPanel.tsx', 'src/features/auth/components/SigninDevicesPanel.tsx'],
  ['src/components/ProfileView.tsx', 'src/features/auth/pages/ProfileView.tsx'],

  // — catalog
  ['src/components/CatalogView.tsx', 'src/features/catalog/pages/CatalogView.tsx'],

  // — community: the conversation and its moderation desk
  ['src/components/CommunityModerationPanel.tsx', 'src/features/community/components/CommunityModerationPanel.tsx'],
  ['src/components/OfferCommunity.tsx', 'src/features/community/components/OfferCommunity.tsx'],

  // — identity: the permission vocabulary and who holds it
  ['src/components/PermissionPicker.tsx', 'src/features/identity/components/PermissionPicker.tsx'],
  ['src/components/AccountPermissionsEditor.tsx', 'src/features/identity/components/AccountPermissionsEditor.tsx'],
  ['src/components/PlatformPermissionsPanel.tsx', 'src/features/identity/components/PlatformPermissionsPanel.tsx'],

  // — inventory: stock on shelves and the warehouses holding it
  ['src/components/SellerInventoryView.tsx', 'src/features/inventory/pages/SellerInventoryView.tsx'],
  ['src/components/WarehouseMapView.tsx', 'src/features/inventory/pages/WarehouseMapView.tsx'],

  // — marketplace: offers, demand and quotations
  ['src/components/MarketplaceView.tsx', 'src/features/marketplace/pages/MarketplaceView.tsx'],
  ['src/components/DemandsView.tsx', 'src/features/marketplace/pages/DemandsView.tsx'],
  ['src/components/PromotedStrip.tsx', 'src/features/marketplace/components/PromotedStrip.tsx'],

  // — notifications
  ['src/components/NotificationBell.tsx', 'src/features/notifications/components/NotificationBell.tsx'],

  // — organizations: the business, its people, its papers
  ['src/components/MembersView.tsx', 'src/features/organizations/pages/MembersView.tsx'],
  ['src/components/OrganizationProfileView.tsx', 'src/features/organizations/pages/OrganizationProfileView.tsx'],
  ['src/components/OrganizationDocumentsPanel.tsx', 'src/features/organizations/components/OrganizationDocumentsPanel.tsx'],
  ['src/components/OrganizationRolesPanel.tsx', 'src/features/organizations/components/OrganizationRolesPanel.tsx'],

  // — platform: the operator's own doors
  ['src/components/AdminConsoleView.tsx', 'src/features/platform/pages/AdminConsoleView.tsx'],
  ['src/components/PlatformConfigView.tsx', 'src/features/platform/pages/PlatformConfigView.tsx'],
  ['src/components/IntegrationsPanel.tsx', 'src/features/platform/components/IntegrationsPanel.tsx'],
  ['src/components/SetupWizardPanel.tsx', 'src/features/platform/components/SetupWizardPanel.tsx'],

  // — support
  ['src/components/SupportDeskPanel.tsx', 'src/features/support/components/SupportDeskPanel.tsx'],

  // — trading: reservations, purchase requests, deals, handover, disputes, ledger
  ['src/components/DealsPipelineView.tsx', 'src/features/trading/pages/DealsPipelineView.tsx'],
  ['src/components/DisputesView.tsx', 'src/features/trading/pages/DisputesView.tsx'],
  ['src/components/LedgerView.tsx', 'src/features/trading/pages/LedgerView.tsx'],
]);

const EXTENSIONS = ['', '.ts', '.tsx', '.d.ts'];

/** Every source file that exists now, keyed by its current src-relative path. */
function walk(dir) {
  const found = [];
  for (const entry of readdirSync(join(root, dir))) {
    const path = join(dir, entry);
    if (statSync(join(root, path)).isDirectory()) found.push(...walk(path));
    else if (/\.(ts|tsx)$/.test(entry)) found.push(path);
  }
  return found;
}

const files = [...walk('src')];
const contents = new Map(files.map((path) => [path, readFileSync(join(root, path), 'utf8')]));

/** Where a file lives after the move, as an import specifier. */
const aliasOf = (path) => {
  const target = MOVES.get(path) ?? path;
  if (!target.startsWith('src/')) throw new Error(`${path}: outside src`);
  if (target === 'src/index.css') return null;
  return '@/' + target.slice('src/'.length).replace(/\.(tsx?|d\.ts)$/, '');
};

/** Resolve one specifier to a real src-relative file, or null for a package. */
function resolveTarget(fromPath, spec) {
  if (spec.startsWith('@/')) {
    const bare = 'src/' + spec.slice(2);
    for (const ext of EXTENSIONS) if (contents.has(bare + ext)) return bare + ext;
    return null;
  }
  if (spec.startsWith('.')) {
    const base = dirname(fromPath);
    const joined = join(base, spec).replace(/\\/g, '/');
    const stripped = joined.replace(/\.tsx?$/, '');
    for (const ext of ['', '.ts', '.tsx']) if (contents.has(stripped + ext)) return stripped + ext;
    return null;
  }
  return null;
}

let rewritten = 0;
const outputs = new Map();
for (const path of files) {
  const text = contents.get(path);
  const nextPath = MOVES.get(path) ?? path;
  let out = text.replace(
    /(\bfrom|\bimport)(\s*)(['"])([^'"]+)\3/g,
    (match, keyword, gap, quote, spec) => {
      const target = resolveTarget(path, spec);
      if (!target) return match;
      if (target === 'src/index.css') {
        const rel = ('../'.repeat(nextPath.split('/').length - 2)) + 'index.css';
        rewritten += 1;
        return `${keyword}${gap}${quote}${rel}${quote}`;
      }
      const alias = aliasOf(target);
      if (!alias) return match;
      if (alias === spec) return match;
      rewritten += 1;
      return `${keyword}${gap}${quote}${alias}${quote}`;
    },
  );

  /*
   * The same files are named inside the docblocks, and a comment that points at a
   * deleted path is worse than no comment: the next reader follows it and finds
   * nothing. Rewritten from the same table, so it cannot drift from the move.
   */
  out = out
    .replace(/\bsrc\/lib\/googleIdentity\.ts\b/g, 'src/features/auth/googleIdentity.ts')
    .replace(/\blib\/googleIdentity\.ts\b/g, 'features/auth/googleIdentity.ts')
    .replace(/\bsrc\/lib\/(config|theme|i18n|navigation)\.ts\b/g, 'src/core/$1.ts')
    .replace(/\blib\/(config|theme|i18n|navigation)\.ts\b/g, 'core/$1.ts')
    .replace(/\bsrc\/components\/([A-Za-z]+)\.tsx\b/g, (match, name) => {
      const target = `src/components/${name}.tsx`;
      if (!MOVES.has(target)) return match;
      return MOVES.get(target);
    });

  outputs.set(nextPath, out);
}

for (const [nextPath, out] of outputs) {
  mkdirSync(join(root, dirname(nextPath)), { recursive: true });
  writeFileSync(join(root, nextPath), out, 'utf8');
}
for (const old of MOVES.keys()) {
  if (existsSync(join(root, old))) rmSync(join(root, old));
}

/* Anything that was only ever reachable through a moved file would still be sat
   in the old directory; an empty shell is left behind by construction. */
for (const dir of ['src/components', 'src/lib']) {
  if (existsSync(join(root, dir))) {
    const left = readdirSync(join(root, dir));
    if (left.length) throw new Error(`${dir} still holds ${left.join(', ')}`);
    rmSync(join(root, dir), { recursive: true });
  }
}

console.log(`moved ${MOVES.size} files, rewrote ${rewritten} specifiers`);
for (const dir of ['src/app', 'src/core', 'src/shared/ui', 'src/features']) {
  console.log(dir + ' -> ' + readdirSync(join(root, dir)).join(', '));
}
