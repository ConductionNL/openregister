# Tasks — Retrofit fe-misc (2026-05-25)

Retrofit annotation pass over the `fe-misc` bundle (93 methods). Every task below is
`[x]` because the code already exists — annotating retroactively. Tasks 1–7 map methods
to EXISTING capability REQs; tasks 8–10 map methods to the three REQs minted by this
change's `frontend-app-bootstrap` spec delta; task 11 documents the exclusions. No new
implementation work and no code-logic changes.

## Annotated → existing capabilities

- [x] task-1: mail-sidebar#REQ-001 (three-tab sidebar layout) — method-level helpers in
      the Link/Objects/sidebar shell: `src/mail-sidebar/MailSidebar.vue`
      (`setup`, `created`, `sidebarTitle`, `switchTab`), `src/mail-sidebar/components/
      ActionsTab.vue` (`loadSchemas`, `loadInitialResults`, `showResults`,
      `debounceSearch`, `searchObjects`, `objectName`), `src/mail-sidebar/components/
      LinkObjectDialog.vue` (`visible`, `onSearchInput`, `doSearch`, `selectResult`,
      `confirmLink`, `resultAriaLabel`, `close`, `reset`), `src/mail-sidebar/components/
      ObjectCard.vue` (`objectTitle`, `deepLink`, `cardAriaLabel`),
      `src/mail-sidebar/components/ObjectsTab.vue` (`loadObjects`, `objectUrl`,
      `messageId`, `unlinkObject`) (retroactive annotation)
- [x] task-2: mail-sidebar#REQ-003 (drag-and-drop email attachments onto linked objects)
      — `src/mail-sidebar/components/ObjectsTab.vue` (`onAttachmentDragOver`,
      `onAttachmentDrop`, `uploadAttachmentToObject`) (retroactive annotation)
- [x] task-3: mail-sidebar#REQ-004 (email-body entity extraction tab) —
      `src/mail-sidebar/components/EntitiesTab.vue` (`loadEntities`, `groupedEntities`,
      `formatType`, `messageId`) (retroactive annotation)
- [x] task-4: mail-sidebar#REQ-002 (path-based message-view detection) —
      `src/mail-sidebar/MailSidebar.vue::sidebarSubname` (renders the
      "Mail Integration" subname only on a message view) (retroactive annotation)
- [x] task-5: notificatie-engine#Requirement:Users MUST be able to manage their
      notification preferences / per-register and per-schema channel subscriptions —
      `src/services/notificationSubscriptions.js` (`listSubscriptions`, `subscribe`,
      `unsubscribe`, `hasSubscription`): the GET/POST/DELETE client over
      `/api/notification-subscriptions` plus the local subscription-match predicate
      (retroactive annotation)
- [x] task-6: mail-smart-picker#Requirement:A custom Vue widget MUST render the rich
      object preview inline — `src/reference/ObjectReferenceWidget.vue` (`title`,
      `objectUrl`, `iconUrl`, `schemaTitle`, `registerTitle`, `properties`,
      `formattedDate`): the Smart Picker reference card's title / link / icon /
      schema-register subtitle / property list / formatted-update-date members
      (retroactive annotation)
- [x] task-7: avg-verwerkingsregister#Requirement:The system MUST maintain a
      verwerkingsactiviteiten register as an OpenRegister schema — create/edit form
      contract in `src/dialogs/avg/EditActivityDialog.vue` (`makeForm` seeds the form
      from an existing activity or Art-30 defaults; `buildPayload` strips empty optional
      fields before write; `onSave` dispatches create vs update against `avgStore`)
      (retroactive annotation)

## Annotated → new frontend-app-bootstrap capability (spec delta this change)

- [x] task-8: frontend-app-bootstrap#REQ-001 (application data MUST be hot-loaded once at
      startup) — `src/services/AppInitializationService.js` (`initializeAppData`
      parallel-loads the eight core stores once, `reloadAppData` force-refreshes them,
      `isAppDataLoaded` reports readiness) and `src/App.vue` (`mounted` kicks off the
      hot-load + dashboard watchers; `provide` exposes the shared object-sidebar state)
- [x] task-9: frontend-app-bootstrap#REQ-002 (the app MUST expose a cached Nextcloud
      app install/uninstall client) — `src/services/appInstallService.js` (`constructor`
      captures the request token, `init`/`invalidateCache`/`reloadCacheList` manage the
      cached app list, `isAppInstalled`/`getAppData` query it, `installApp`/
      `forceInstallApp`/`uninstallApp` drive the `/settings/apps/*` endpoints and refresh
      the cache, surfacing the 403 password-confirmation contract)
- [x] task-10: frontend-app-bootstrap#REQ-003 (object file metadata MUST be editable via
      a typed client) — `src/services/fileMetadata.js` (`updateFileLabels` PUTs
      `{labels}` to `/files/{fileId}/labels`; `updateFileMetadata` PUTs the partial
      description/category/labels payload to `/files/{fileId}`, skipping null fields)

## Excluded (30 methods — see proposal.md for full reasons)

- [x] task-11: EXCLUDE bucket — every method below carries `@spec exclude <reason>`:
  - 13 entity constructors (`entities/*/*.ts`) — model field-copy boilerplate, no
    standalone contract.
  - `composables/UseFileSelection.js::useFileSelection` — upstream-derived VueUse
    dropzone/file-dialog wrapper, generic picker plumbing.
  - `dialogs/Dialogs.vue` (`onConfigSetCreated`, `onConfigSetDeleted`) — `$root`
    event re-emit plumbing.
  - `dialogs/avg/EditActivityDialog.vue` (`dialogTitle`, `rechtsgrondOptions`,
    `statusOptions`, `get`, `set`, `t`) — title/option/textarea presentation glue.
  - `navigation/MainMenu.vue` (`activeOrganisationName`, `handleNavigate`, `openLink`)
    — app-navigation plumbing.
  - `services/dateUtils.js` (`stringToDate`, `dateToString`), `services/formatBytes.js`
    (`formatBytes`), `services/getTheme.js` (`getTheme`),
    `services/getValidISOstring.js` (`getValidISOstring`) — stateless presentation /
    format helpers, no domain contract.
