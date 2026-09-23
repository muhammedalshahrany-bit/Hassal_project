# HASSAL — حصّال

Yemen-first B2B marketplace for commercial opportunities, inventory, and food surplus. The Laravel API in
[`backend/`](backend/) is the system of record; the TypeScript application at this root is its web client and
gateway. No production demo accounts, seeded passwords or external payment credentials are included.

Setup steps live in [backend/LOCAL_SETUP.md](backend/LOCAL_SETUP.md). This page is only the map: where things
are, and where a change belongs.

## Layout

```
src/App.tsx              view switching, session state, and which doors a session may open
src/components/          one file per screen or panel (Header, MarketplaceView, AdminConsoleView, …)
src/lib/api.ts           every HTTP call the client makes — nothing fetches from a component
src/lib/navigation.ts    the tab vocabulary: ids, labels, grouping, what lives behind the account chip
src/lib/config.ts        the bootstrap payload read as settings, never as constants
src/lib/i18n.ts          locale type, UI strings, and reading a bilingual row whichever column it filled
src/lib/theme.ts         the dark/light choice, remembered under `hassal_theme`
src/lib/googleIdentity.ts Google Identity Services loaded on demand, with the per-attempt nonce
src/types.ts             the shapes the API answers with
src/index.css            Tailwind v4 tokens, the app-wide dark remap, and the small-screen sweep
src/components/BrandMark.tsx the HASSAL mark, drawn once for every screen that shows it
public/brand/            the mark and app icons at their real pixel sizes, plus public/manifest.webmanifest
index.html               the document head (icons, manifest, theme-colour) and the CSS-only opening that paints itself from `public/brand/icon-512.png` while the bundle loads
server.ts                the :3000 gateway: serves the built client and proxies /api to the Laravel API
backend/                 Laravel 13 + MySQL: routes, controllers, services, migrations, tests
```

## Where a change belongs

| To do this | Change here |
| :--- | :--- |
| Add, rename or move a tab | `src/lib/navigation.ts`, then the gate for it in `canOpenView` in `src/App.tsx` |
| Call an endpoint | its route in `backend/routes/api.php`, then a method in `src/lib/api.ts` and its shape in `src/types.ts` |
| Add something an operator can set | `backend/app/Support/SettingCatalog.php` and the `system_settings` row — never a literal in a component |
| Require a permission | the `permission:` middleware on the route; a hidden button is presentation, not enforcement |
| Restyle dark mode or phones | the sweeps in `src/index.css`, which are deliberately unlayered so they outrank the utilities |
| Change the logo or an app icon | replace the file in `public/brand/` at its real pixel size and update `index.html` / `src/components/BrandMark.tsx`; a size never declared differently from the file's actual dimensions |
| Change the opening, its wording or its timing | the `#hassal-boot` block in `index.html` — CSS only, no script drives it, and it clears itself whether or not animations run |
| Change the schema | a new additive migration — `hassal_production` holds real rows and is never rebuilt |

## Run

```
cd backend && php artisan serve            # API on http://127.0.0.1:8000/api/v1
npm install && npm run dev                 # client + gateway on http://localhost:3000
```

## Check

```
npm run lint      # tsc --noEmit, the client's type contract
npm run build     # the production bundle
cd backend && vendor/bin/phpunit
```

## The documents

| File | What it holds |
| :--- | :--- |
| `PROJECT_SCOPE.md` | what the platform is for, and what it refuses to claim |
| `DOMAIN_MODEL.md` | the business flow: Offer → Purchase Request → seller review → Reservation → Deal |
| `ARCHITECTURE.md` | the layers, and where each responsibility sits |
| `DATABASE.md` | the live schema, generated from `information_schema`, not from a design report |
| `API_CONTRACT.md` | the integration contract in Arabic, written for any client — including the mobile client that does not exist yet |
| `backend/API_CONTRACT.md` | the same contract measured from the code that answers today: bootstrap shape and webhook payloads |
| `IMPLEMENTATION_PLAN.md` | the phases, and what each one settled |
| `backend/README.md` and `backend/*.md` | the API's own setup, configuration, security, testing and deployment notes |

Two files describe the API contract: the root one is the specification an integrator reads, `backend/` holds
what the running code proves. Where they disagree, the code and `backend/API_CONTRACT.md` are current.
