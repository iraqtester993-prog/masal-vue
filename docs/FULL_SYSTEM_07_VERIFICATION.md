# Full system 07 — production verification

> Historical release record. Superseded by backend `20261007-parity-08b` and frontend `20261007-parity-08c`; current evidence, backups and phone-recovery limitations are recorded in [the comparison report](ORIGINAL_REFERENCE_REVIEW_20261007.md) and [the plan](../MIGRATION_PLAN.md).

> Subsequent original-source review on 2026-10-07 confirmed seven implementation parity gaps (prices filters/permissions, quick actions, login recovery, location sharing controls, dynamic translations and first-price difference display). The recorded test/deployment evidence below remains valid for its scope and does not establish complete original feature parity. See [the source comparison](ORIGINAL_REFERENCE_REVIEW_20261007.md).

Status on 2026-10-07 (Baghdad): **backend `20261007-full-system-07` and portal frontend `20261007-full-system-07-ui01` are ACTIVE on the final HTTPS hosts.** The public company entry is also published. This is the verified source/deployment record; it does not claim a live provider purchase, a physical printer test or every possible browser interaction.

## Published artifacts and acceptance

| Evidence | Actual result | Retained evidence and limits |
| --- | --- | --- |
| Backend | **321 files**, bundle SHA-256 `f18d0f12a5d26367d1e4dc0220382ac77f80a6cada31d3157a08beb8d3742329` | Immutable native-tested source archive SHA-256 `34f31f816b71b09a14e50a27b25fc2a6d1482d6393680d3c1ddcbf6731ae86ed`. Source comparison after Pint: 321 matches, zero differences. |
| Final portal frontend UI01 | **279 files**, bundle SHA-256 `b657bb0ab8d1ad2be8c0cb5354ba78c6166f89ed7f2a1302ce33efe91583bec3` | CSS fixes restore the original Digital common rules and contain long Security text on phones. No API, credential, provider request or financial logic changed. |
| Public company entry | **9 files**, bundle SHA-256 `1cc762efb376ddce3e307fbd12c03ad4faa6fec242e6dbceb8fb7e2354e7c0ee` | Original company defaults published through the audited owner PUT, version 2; no invented contacts, pictures or galleries. |
| Local PHP | **561 cases: 557 PASS, 4 SKIP, 5,055 assertions; zero failures/errors** | `deployment/.local/full-system-sqlite-final-07d.xml`. |
| Native PHP/MariaDB | **561 cases: 557 PASS, 4 SKIP, 5,057 assertions; zero failures/errors** | `deployment/.local/full-system-native-final-07d.xml`; PHP 8.5.11, disposable `dananiriq_masalverify`, 08:43.565 elapsed. |
| Vue and builds | **154 PASS; all four builds PASS**, rerun after UI01 CSS fixes | `deployment/.local/frontend-final-07-ui01.log`; model/API-contract/actual SSR checks plus admin, agents, POS and public builds. |
| Digital focused acceptance | **49 PASS / 903 assertions; 24 frontend PASS** | [Digital verification](DIGITAL_SERVICES_VERIFICATION.md); full suites above include these regressions. Provider HTTP is simulated with actual wire formats. |
| Direct cPanel shadow | **1 PASS / 34 assertions, no skips** | `deployment/.local/native-direct-final-07d.xml`: actual scratch creation/grants, signed import, fresh cached boot, environment switch and second-job rollback. |
| Full cPanel worker shadow | **1 PASS / 68 assertions, no skips** | `deployment/.local/native-worker-final-07d.xml`: actual `backups:work`, source/target completed controls, safety copy, preserved source and session/presence invalidation. |
| Production switch | **609 package files match; 59 original business tables preserved** | `deployment/.local/full-system-release-live-07.json`; original-column row hashes/counts, original environment values, APP_KEY and private files preserved. |
| Final UI01 files | **609 package files match: 321 backend + 279 portal + 9 public** | `deployment/.local/full-system-07-ui01-live.json`; backend and public content remain unchanged by UI01. |
| Final-host HTTP | **1,746 PASS, zero failures** | `deployment/.local/full-system-http-report.json`: three portals, main/www public entry, TLS, private API denial, host-only sessions, actual System reads, all 52 report definitions, money DTOs and FPM restore capabilities. No provider request or business write in this run. |
| Post-UI01 HTTP | **Four HTML entries and 17 entry assets byte-identical to the published bundle** | `deployment/.local/post-ui01-http-report.json`; all three old `*-test` login routes redirect to their final host with 302, and their old `auth/me` APIs return 410. Read-only; no business write or provider request. |
| Scheduler | Existing minute `flock` cron resolves the current release; `backups:work` is scheduled every minute | Actual `schedule:list` checked after activation; no production restore queued. |
| Real application backup | **Completed**, 0.4 MB, ID `52657f19-815e-422b-9d37-d037cc9d00e8` | Created from the live owner UI after publication, with signed encrypted private contents. See `C:/Users/PRO/Desktop/masal-backups/full-system-07-proof/backup-live.png`. The 423,988-byte encrypted snapshot was also downloaded to the private local prepublication backup folder; server/local SHA-256 `46c0257946f5029a8a6e9ad3bbe561a7180f11f05cc01d65c14ffc1bc1ac50d0` matches. This is a backup creation/download, not a production restore. |

The initial frontend package before the visual fix had SHA-256 `ef46f7d555186f62ada9f890b9633e6c5016919b866964cb507126d06a0edf37`. UI01 retains previous entry files privately and keeps earlier hashed assets for safe existing-page transitions.

The native suite's four skips are explicit: the two real cPanel cases run separately in the guarded private shadow; SQLite's attached-database boundary runs only on SQLite; the 50,000-card stress case requires its dedicated memory/resource flag and has earlier native/FPM evidence in [finance/stock verification](FINANCE_STOCK_VERIFICATION.md). Skips are not counted as passes. The local suite has its own runtime-specific skip list in its XML.

The first final worker run completed the actual switch but its test compared randomized encrypted ciphertext. The test now compares hashes of decrypted control data without printing secrets. Before clearing the disposable source gate, the root verified completed jobs, private rollback-state/environment hashes, matching decrypted controls, safety snapshot signatures and **every current source row/count against the signed safety snapshot**, plus fresh cached target state. Only the owning completed test job/version was released; the guarded disposable source was then rebuilt and the complete 68-assertion worker run passed. Application backend bytes did not change. Revised worker-test SHA-256: `9c2dc6953b809127bd6d1bb74aba761e1b568db9db322fa8c8fbe070fd80cc02`.

## Backups and deployment boundaries

The verified prepublication server backup is `/home/dananiriq/masal-backups/live-after-final-domains-20261006-214237`. All five archives were downloaded outside the repository to `C:/Users/PRO/Desktop/masal-backups/before-publication-20261007-004237-Baghdad` and their manifest hashes matched:

| Archive | SHA-256 |
| --- | --- |
| `database.sql.gz` | `1b4831893fb2d696366f6155996afa527248f96eda8b5f99cc9c20e0ddd7832e` |
| `backend-release.tar.gz` | `23c49456bcffec0332918f8183cdaecc991898f823caad94b99c02f04f860e48` |
| `portals.tar.gz` | `38eda7d92ee76cff61a032f82aebbca0ae4a973c80731d3b2e263ca8478f1d26` |
| `storage.tar.gz` | `829f79e4113f43d5950fbf4c6743a0ce6b01c0c0a34680f6538cc76250334e65` |
| `public_html.tar.gz` | `6f9d013086fd17d33ca73f9cdd942ad940a4e4c03efc6414d35688cad7975f21` |

The shared environment remains private, mode 0600, outside web roots; no loose `.env` or private deployment key is checked into source. Initial full-source backup is `C:/Users/PRO/Desktop/masal-backups/masal-before-full-system-20261006-141338-UTC.tar.gz`, 1,187 files, SHA-256 `42b2c350816a1c81ba2c1f9753ea4a30c9e1a070a9f4540cabc7ef6f4ff80402`.

The subsequent signed application snapshot created through the production backup UI is also retained privately outside source at `C:/Users/PRO/Desktop/masal-backups/before-publication-20261007-004237-Baghdad/application-backup-after-publication-52657f19-815e-422b-9d37-d037cc9d00e8.json`. Its exact size is 423,988 bytes; local and server SHA-256 both equal `46c0257946f5029a8a6e9ad3bbe561a7180f11f05cc01d65c14ffc1bc1ac50d0`. No contents or private keys were printed or checked into source.

Activation used private deployment and scheduler locks, maintenance, drained old FPM processes, verified fresh production DB/environment/APP_KEY before migrations, ran only forward migrations and the idempotent permission seeder, then checked preservation before reopening. Production uses **94 InnoDB tables**, **2 real accounts/2 users**, PHP **8.5.11**, Laravel **13.34.0**, MariaDB **10.11.19**, `APP_DEBUG=false`, Secure HttpOnly host-only cookies. No demonstration account, wallet credit or fabricated provider balance was created.

## Financial values, scope and privacy

- Fourth has an **open cumulative sales amount**, summing scoped **succeeded** sales across all dates. There is no initial allocation or remaining-wallet subtraction. Pending/review/refunded orders do not inflate this number. The live dashboard and its detail dialog state this explicitly.
- The manager selects the service and the intended main agent, then enters the incoming token. Local company metadata is optional; no company/product is invented when loading a catalog. Existing active IQD products are mapped only within both actor and agent category scope. The current provider-confirmed catalog remains tied to its credential version.
- Server hierarchy, canonical employee scope, portal, permission, category availability, account stops and versions govern every query/action/export. POS never receives provider costs, upstream balance, token or PIN list fields. A main-agent employee scoped only to a POS cannot view the main account's connection/balance metadata.
- Amounts use integer minor-unit snapshots and exact decimal strings; IQD/USD are separate. Unknown/protected values remain NULL with source explanations. Topup catalog cost is a quoted snapshot, not fabricated settlement evidence; Fourth balance is not inferred from sales totals.
- Actual provider purchases use guarded intents/attempts, reservation then purchase where required, encrypted immediate receipt storage and verification of the original request. Uncertain outcomes are reviewed without a second purchase; a confirmed external refund does not manufacture a local finance credit.
- Reports and exports exclude credentials, encrypted control payloads, subscriber/receipt/PIN contents, provider evidence and session identifiers. The full eligible filtered report is exported, with unavailable/protected source notes.

## Restore behavior and source isolation

Signed streaming snapshots include selected-schema business rows and referenced private files; they exclude `.env`, APP_KEY and session/cache/auth-device/control tables. Uploaded preview is limited to **15 MB** under the actual host's 16 MB upload limit; same-server snapshots can stream larger content.

Preview validates signature, schema, row references and business invariants in a new isolated database. Restore requires current password, owned preview and version. The worker takes an exclusive runtime gate, rejects unresolved provider purchases/active attempts, preserves a safety backup, imports with immutable financial triggers enabled, switches the private environment atomically and boots a fresh PHP process with config cache before reopening. Failure remains under owning rollback/maintenance controls. Restored sessions are invalidated and restored presence is offline/not sharing; historical coordinates are preserved.

Source aliases carry their explicit connection name, so completed control updates remain in the original database after the active DB changes. Matching completed controls are saved in both source and target. The successful production-capable platform and worker were exercised only under `/home/dananiriq/masal-verification/restore-platform-20261006-07`; **no production restore occurred**.

## Browser proof and remaining acceptance boundaries

The final desktop Digital form uses original Cairo, 44 px input height, original Cyan button/color/card rules, three service/agent/token fields and the original grouped sidebar. Actual checked actions include opening/collapsing sidebar groups, entering the add-connection form without saving a token, Fourth dashboard detail and its filtered log link, backup creation and public company rendering. Desktop light/dark modes were checked and the original light preference restored. The phone viewport measured 376 × 305 px: document client/scroll width both 361 px, three vertically stacked fields each 44 px high, Cairo font and no document horizontal overflow. The phone screenshot records the field region; it is not a full-page capture. Final screenshots are saved outside source in `C:/Users/PRO/Desktop/masal-backups/full-system-07-proof/`: `digital-desktop-light.png`, `digital-desktop-dark.png`, `digital-mobile.png`, `backup-live.png`, linked from the plan.

- The supplied Fourth key is **not saved, assigned or sent to a provider**. The manager chooses the intended agent in the live form; tests do not establish availability or stock on that external account. No live topup/card purchase was made.
- There were no supplied existing POS/agent credentials for authenticated final-host QA. Their authorization/hierarchy behavior is tested with independent fixtures on SQLite/MariaDB; HTTP proof separately checks anonymous portal separation and System-cookie isolation. No fake production account was introduced for coverage.
- `wallets-legacy`, `wallets-holds` and `prices-policies` are explicitly dormant historical report sources. Their counts are NULL/reasons shown; they are not populated with fabricated rows.
- The integrated browser did not deliver a download event within 15 seconds when the CSV button was tested. Export APIs and scoped source behavior are tested, but a completed user-browser download and physical printer output remain separate acceptance items.
- Original reference source is preserved and excluded from all published runtime/build bundles. New portals use server data/preferences and never read/write commercial localStorage/sessionStorage/IndexedDB. Existing old browser data was not inspected or purged; its removal is not falsely marked complete.
- Detailed OpenAPI schemas remain a partial baseline. [API_ROUTE_CATALOG.json](../contracts/API_ROUTE_CATALOG.json) records **214 method/path records / 180 URI paths**, SHA-256 `8208296d212b4c285906ebd7c98076afdbdd79e51ae4a66acade14fe5dd7b2f3`; current FormRequests/resources and routes define later modules.
- Arabic primary content/design is reviewed; this record does not assert every English/Kurdish string or every empty/nonempty/error state has undergone complete visual acceptance. SMTP delivery, external provider execution and expected real concurrency require their actual production inputs.

Final URLs: [administrator](https://admin.dananir-iq.com/login), [all agent levels](https://agents.dananir-iq.com/login), [POS](https://pos.dananir-iq.com/login), [public company](https://dananir-iq.com). [Migration plan](../MIGRATION_PLAN.md) is the checklist for continuing acceptance and maintenance.
