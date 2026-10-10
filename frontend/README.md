# Masal Vue frontend

As of 2026-10-07, backend **`20261007-full-system-07`** and portal frontend **`20261007-full-system-07-ui01`** are live on the final HTTPS hosts, with a separate published public company entry. Native acceptance and all 609 deployment file checks passed; production HTTP QA passed **1746/1746 checks**. The Digital form was reviewed on desktop in light/dark modes and at a 376 px phone width. This is focused browser acceptance, not a claim that every original interaction, external provider or printer has been tested. See [the migration plan](../MIGRATION_PLAN.md) and [the final verification record](../docs/FULL_SYSTEM_07_VERIFICATION.md).

## Entries and outputs

Vue 3 uses one shared source tree and four independent entries. Each authenticated portal has its own hostname and host-only session cookie. Cookies are not isolated by port.

| Entry | Production host | Local development URL | Output |
| --- | --- | --- | --- |
| `admin` | [admin.dananir-iq.com](https://admin.dananir-iq.com/login) | `http://admin.localhost:5173` | `dist/admin/` |
| `agents` | [agents.dananir-iq.com](https://agents.dananir-iq.com/login) | `http://agents.localhost:5174` | `dist/agents/` |
| `pos` | [pos.dananir-iq.com](https://pos.dananir-iq.com/login) | `http://pos.localhost:5175` | `dist/pos/` |
| `public` | [dananir-iq.com](https://dananir-iq.com) and `www.dananir-iq.com`, live | `http://public.localhost:5177` | `dist/public/` |

`portals/` contains the entry HTML and initialization. `src/modules/` groups domain pages, API clients and state. `src/shared/` supplies common components, styles and file readers; `src/layouts/` implements the original grouped navigation and shell. `src/router/index.js` registers authenticated pages with lazy loading and permission/account/membership metadata. The public entry has its own application and does not load the authenticated shell.

The implemented source includes accounts/staff/permissions, catalog/reference, finance, stock/import/export, sales/printing, support/notifications, reports/dashboard, digital services, maps/presence, security/time policies/archive, company management/public enquiries, backups and preferences. Availability depends on server actor permissions and actual data. A registered route is not an authorization boundary or proof of complete browser acceptance.

## Development

Node.js **22.12+** is required. Start the independently configured Laravel application on port 8000, then run these commands from the repository root in separate terminals:

```powershell
npm --prefix frontend ci
npm run backend:serve
npm run dev:admin
npm run dev:agents
npm run dev:pos
npm run dev:public
```

The Vite proxy targets `http://127.0.0.1:8000` by default; `MASAL_BACKEND_URL` in a private local environment can change it. The proxy supplies `X-Masal-Portal` only for local/testing resolution. Production Laravel resolves exact permitted hostnames. Browser login does not select a role or account scope through a header.

Use the `*.localhost` hostnames above and verify they resolve to loopback. Simultaneous sessions on `127.0.0.1` with different ports share cookies and do not reproduce production isolation.

## Validation and builds

From the repository root:

```powershell
npm run frontend:test
npm run build              # Four outputs: admin, agents, pos, public
```

From `frontend/`, `npm run build` builds the three authenticated portals. Add `npm run build:public` for the public entry. Individual scripts are `build:admin`, `build:agents`, `build:pos`, and `build:public`. `npm test` runs the frontend tests. Production needs built files, not a permanent Vite or Node server.

The final source passed **154 frontend tests and four builds**. Backend verification passed 557 of 561 cases with four genuine skips: 5055 assertions on SQLite and 5057 on native MariaDB. Separately gated cPanel direct restore and full-worker shadow acceptance passed with 34 and 68 assertions respectively. The root verification record retains artifact hashes and browser acceptance boundaries. SSR tests and compilation do not establish every button, printer flow, responsive layout or provider outcome. GitHub Actions validates source; it does not publish to hosting.

## Sessions, preferences and data

Login identity and transient UI state stay in memory. Laravel's session cookie and CSRF mechanism authenticate requests. Account data and Arabic/English/Kurdish language and light/dark preferences are persisted by server APIs; preferences use versioned writes. No application data, login tokens or preferences are persisted in localStorage, sessionStorage or IndexedDB.

Lists, filtering and exports use actual scoped API data. Financial values remain exact decimal strings with currencies kept separate. Unknown or protected values stay unavailable; they must not become fabricated zeros. Laravel enforces scope, action permissions, ancestor state and data privacy for every request. Vue navigation filtering controls presentation.

## Deployment and legacy reference

Authenticated outputs are served from `/home/dananiriq/public_html/masal/portals/{admin,agents,pos}`. Laravel stays outside the web root at `/home/dananiriq/masal-backend/current`, with environment and persistent storage under `masal-backend/shared/`. The cPanel bridge preserves the portal hostname and forwards same-origin API/CSRF requests to the private front controller.

The separate public build is live in `/home/dananiriq/public_html/`, the main-domain document root. The original company content was published through its audited server workflow without substituting demo content. Its bridge and Laravel middleware permit only public company content/assets, CSRF setup and enquiry submission. An administrative API must not become accessible through the public host. See [deployment instructions](../deployment/README.md) for the authoritative templates and activation checks.

`legacy/` preserves the original reference application and its local-storage/demo behavior. It is excluded from every current entry and deployment bundle. `npm run dev:legacy` starts it locally on port 5176; `npm run build:legacy` produces `dist/legacy/` for comparison only. Original colors, Cairo/JetBrains fonts, grouping and source content guide migration; complete visual parity requires recorded browser comparison. Historical cutover and UI evidence are linked in [the plan](../MIGRATION_PLAN.md), rather than defining the current feature scope.
