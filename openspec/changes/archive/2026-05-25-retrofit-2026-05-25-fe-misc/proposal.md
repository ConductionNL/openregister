# Retrofit — frontend coverage: misc (services / entities / dialogs / nav) (2026-05-25)

## Why

Retrofit triage over the `fe-misc` bundle: 93 uncovered frontend methods across the
remaining `src/` directories that no earlier frontend pass claimed — `mail-sidebar/`,
`services/`, `entities/`, `dialogs/`, `reference/`, `navigation/`, `composables/`, and
`App.vue`. The bundle is a mixed bag: API-client wrappers and a couple of dialog/widget
contracts carry real behaviour, while the bulk of `entities/`, `dialogs/`, `navigation/`
and the generic format helpers are UI/model plumbing. Until every method is either
annotated to a capability REQ or carries a reasoned `@spec exclude`, the coverage scanner
keeps flagging the bundle as a gap under ADR-003.

## What Changes

Every method ends tagged: either annotated to an existing-or-newly-minted capability REQ
via this change's `tasks.md`, or carried as an `@spec exclude <reason>` with a required
reason. Three new REQs are minted in a new `frontend-app-bootstrap` capability for the
genuine frontend contracts that have no live capability home (app-startup hot-loading,
the Nextcloud app install/uninstall client, the object file-metadata client). This is a
retroactive specification of behaviour that already exists; no code logic changes.

## Counts

- **63 methods annotated** (spec'd) — mapped to live or here-minted capability REQs.
- **30 methods excluded** — UI plumbing, model field-copy constructors, and stateless
  presentation/format helpers with no standalone behavioural contract.
- **3 new REQs minted** in a new `frontend-app-bootstrap` capability (app-startup
  hot-loading, Nextcloud app install/uninstall client, object file-metadata client).

## Annotated → existing capabilities (47 methods)

- **mail-sidebar** (REQ-001 layout, REQ-002 message-view subname, REQ-003 attachment
  drop, REQ-004 entities tab) — the method-level helpers inside the already file-level
  annotated three-tab sidebar components: `MailSidebar.vue` (5), `ActionsTab.vue` (6),
  `EntitiesTab.vue` (4), `LinkObjectDialog.vue` (8), `ObjectCard.vue` (3),
  `ObjectsTab.vue` (7) = **33**.
- **notificatie-engine** ("Users MUST be able to manage their notification preferences"
  / "per-register and per-schema channel subscriptions") — `notificationSubscriptions.js`
  client (`listSubscriptions`, `subscribe`, `unsubscribe`, `hasSubscription`) = **4**.
- **mail-smart-picker** ("A custom Vue widget MUST render the rich object preview inline")
  — `ObjectReferenceWidget.vue` (all 7 computed/method members rendering the Smart Picker
  reference card) = **7**.
- **avg-verwerkingsregister** ("maintain a verwerkingsactiviteiten register") —
  `EditActivityDialog.vue` create/edit form contract (`makeForm`, `buildPayload`,
  `onSave`) = **3**.

## Annotated → new `frontend-app-bootstrap` capability (16 methods)

These are genuine frontend contracts with no live capability home:

- **REQ-001 (startup hot-load)** — `services/AppInitializationService.js`
  (`initializeAppData`, `reloadAppData`, `isAppDataLoaded`) + `App.vue` (`mounted`,
  `provide`) = **5**.
- **REQ-002 (app install/uninstall client)** — `services/appInstallService.js`
  (`constructor`, `init`, `invalidateCache`, `reloadCacheList`, `isAppInstalled`,
  `getAppData`, `installApp`, `forceInstallApp`, `uninstallApp`) = **9**.
- **REQ-003 (object file-metadata client)** — `services/fileMetadata.js`
  (`updateFileLabels`, `updateFileMetadata`) = **2**. These are listed as
  future-pass DROPs in `retrofit-2026-05-24-file-actions` (no live REQ yet), so they get
  their own contract here rather than a dangling annotation.

## Excluded (30 methods) — reasons required, recorded per method

- **13 entity constructors** (`entities/{agent,application,auditTrail,configuration,
  conversation,database,message,object,organisation,register,schema,source,view}.ts`) —
  model field-copy boilerplate: copy typed fields off the input with `|| default`, plus a
  passthrough for extra keys. No standalone behavioural contract; the `validate()`
  methods (already covered or out of batch) carry the real schema contract.
- **`composables/UseFileSelection.js::useFileSelection`** — third-party-derived VueUse
  dropzone/file-dialog wrapper (attributed to an upstream GitHub author), generic
  file-picker plumbing not tied to a register-data contract.
- **`dialogs/Dialogs.vue`** (`onConfigSetCreated`, `onConfigSetDeleted`) — container that
  re-emits a `configset-updated` event on `$root`; pure event-bus plumbing.
- **`dialogs/avg/EditActivityDialog.vue`** UI members (`dialogTitle`, `rechtsgrondOptions`,
  `statusOptions`, `get`/`set` text-area adapters, `t`) — title/option/textarea
  presentation glue around the form contract that IS spec'd (`makeForm`/`buildPayload`/
  `onSave`).
- **`navigation/MainMenu.vue`** (`activeOrganisationName`, `handleNavigate`, `openLink`) —
  app-navigation plumbing: read active org name, `$router.push`, `window.open`.
- **Stateless format/presentation helpers** — `services/dateUtils.js`
  (`stringToDate`/`dateToString`, schema-format date round-trip for the native picker),
  `services/formatBytes.js`, `services/getTheme.js`, `services/getValidISOstring.js`.
  Pure pure-function utilities with no domain contract.

## Impact

- **New capability**: `frontend-app-bootstrap` (3 REQs) — the canonical home for
  app-bootstrap and non-feature-specific client service contracts.
- **Specs touched**: `specs/frontend-app-bootstrap/spec.md` (ADDED only).
- **Code**: none — annotation-only retrofit. Existing `src/services/`, `src/App.vue`,
  `src/mail-sidebar/`, `src/reference/`, and `src/dialogs/avg/` methods gain `@spec`
  pointers or reasoned `@spec exclude` tags.
- **Cross-references**: methods mapped to existing capabilities (`mail-sidebar`,
  `notificatie-engine`, `mail-smart-picker`, `avg-verwerkingsregister`) gain pointers
  to those owners; no requirement text in those capabilities changes.

## Future reverse-spec passes

None required out of this bundle. `dateUtils.js` is adjacent to the in-progress
`datetime-input-handling` capability (a backend `DateTimeNormalizer` placeholder); if that
spec later grows a frontend section the date round-trip helper can be re-homed there, but
it carries no normative gap today.

Source: `/tmp/or-scan/fw-misc.json` (bundle `fe-misc`, 93 methods).
