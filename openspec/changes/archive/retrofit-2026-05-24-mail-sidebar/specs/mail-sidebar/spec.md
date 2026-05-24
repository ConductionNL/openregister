---
retrofit_extensions:
  - REQ-001
  - REQ-002
  - REQ-003
  - REQ-004
  - REQ-005
---

# Mail Sidebar — Retrofit Delta (2026-05-24)

The following REQs describe behavior already shipping in production but not previously specified. They retroactively cover the three-tab sidebar layout, path-based Mail-app routing, attachment drag-drop, entity extraction, and the bootstrap retry loop.

## ADDED Requirements

### REQ-001: Three-tab sidebar layout (Objects / Link / Entities)

The sidebar panel SHALL render its content inside an `NcAppSidebar` with three `NcAppSidebarTab` children, in this order: (1) **Objects** — the linked-objects list previously specified, (2) **Link** — the search-and-link affordance (replaces the inline "Link to Object" button), (3) **Entities** — the entity-extraction view (see REQ-004). The active tab SHALL be controlled via `active.sync` and the Objects tab SHALL be the default on first render.

#### Rationale

The original spec described "Linked Objects" + "Related Cases" sections flowing one above the other inside a single panel. Production replaced this with three orthogonal tabs to keep the panel compact in narrow Mail-app layouts and to host the new entity surface (REQ-004) without doubling the Objects-tab scroll length. The Link tab also lets the sidebar emit a `linked` event back to `MailSidebar.vue` so the Objects tab refreshes on success without prop drilling.

#### Scenario: Default tab on sidebar mount
- **GIVEN** the sidebar mounts on a Mail-app message view
- **WHEN** `NcAppSidebar` renders
- **THEN** `activeTab` MUST be `'objects'`
- **AND** the Objects tab MUST display the `ObjectsTab` component bound to the current `accountId` + `messageId`

#### Scenario: Switching tabs
- **GIVEN** the user is on the Objects tab
- **WHEN** the user clicks the Link tab header
- **THEN** the Link tab content MUST render (`ActionsTab` component) and the Objects tab content MUST be hidden
- **AND** the `accountId` + `messageId` props MUST pass through identically to the Link tab

#### Scenario: Link flow emits refresh to Objects tab
- **GIVEN** the Link tab successfully creates an object link
- **WHEN** the `ActionsTab` emits its `linked` event
- **THEN** `MailSidebar.vue`'s `onLinked()` handler MUST call `this.$refs.objectsTab.loadObjects()`
- **AND** the Objects tab MUST refresh its linked-object list without a full reload

#### Scenario: Collapsed state hides all tabs
- **GIVEN** the user has previously collapsed the sidebar (`COLLAPSED_STORAGE_KEY = 'openregister-mail-sidebar-collapsed' = 'true'` in `localStorage`)
- **WHEN** the sidebar mounts
- **THEN** `NcAppSidebar` MUST NOT render
- **AND** a narrow toggle button MUST render in its place
- **AND** clicking the toggle MUST flip `collapsed` to `false` and persist the new value to `localStorage`

#### Notes
- **Spec consolidation**: this REQ supersedes the "Linked Objects + Related Cases" two-section layout described in the existing "Sidebar panel UI with linked objects display" requirement. The Related-Cases content was not implemented as its own tab — sender-based suggestions remain inside the Objects tab via `useEmailLinks.suggestedObjects`. Future work: rename / split the existing REQ to reflect the tab refactor; flagged as a follow-up.

---

### REQ-002: Path-based Mail-app URL routing (Mail 5.x+)

The URL observer SHALL parse both legacy hash-based Mail-app URLs (`#/accounts/{accountId}/folders/{folder}/messages/{messageId}`) and path-based Mail 5.x URLs (`/apps/mail/box/{boxId}/thread/{threadId}` and `/apps/mail/box/{boxId}`). For path-based URLs the `boxId` segment MAY be numeric (mailbox id) or alphabetic (e.g. `priority`, `starred`). The observer SHALL extract `accountId`, `messageId`, and an `isMessageView` flag and SHALL trigger a debounced refresh on change.

#### Rationale

The original "Email URL observation for automatic context switching" REQ covered only hash-based routing. Nextcloud Mail 5.x switched to Vue Router history mode with pushState — neither `hashchange` nor `popstate` fires on pushState navigation, so the observer also runs a 500ms polling interval. The path-based pattern is the only one used by current Mail releases.

#### Scenario: Parse path-based URL with numeric mailbox
- **GIVEN** the URL is `/apps/mail/box/2/thread/42`
- **WHEN** `parseMailUrl()` runs
- **THEN** it MUST return `{ accountId: 1, messageId: 42, sender: null }`
- **AND** `isMessageView` MUST be `true`

#### Scenario: Parse path-based URL with priority box
- **GIVEN** the URL is `/apps/mail/box/priority/thread/6`
- **WHEN** `parseMailUrl()` runs
- **THEN** it MUST return `{ accountId: 1, messageId: 6, sender: null }`
- **AND** `isMessageView` MUST be `true`

#### Scenario: Parse path-based URL without thread (folder view)
- **GIVEN** the URL is `/apps/mail/box/2`
- **WHEN** `parseMailUrl()` runs
- **THEN** it MUST return `{ accountId: 2, messageId: null, sender: null }`
- **AND** `isMessageView` MUST be `false`

#### Scenario: Parse legacy hash URL with message
- **GIVEN** the URL hash is `#/accounts/1/folders/INBOX/messages/42`
- **WHEN** `parseMailUrl()` runs
- **THEN** it MUST return `{ accountId: 1, messageId: 42, sender: null }`

#### Scenario: Parse unrecognised URL
- **GIVEN** the URL is `/apps/files/`
- **WHEN** `parseMailUrl()` runs
- **THEN** it MUST return `{ accountId: null, messageId: null, sender: null }`

#### Scenario: Detect pushState navigation via polling
- **GIVEN** the Mail app navigates from `/apps/mail/box/2/thread/42` to `/apps/mail/box/2/thread/43` via Vue Router pushState (no `hashchange`, no `popstate`)
- **WHEN** the next 500ms poll tick fires
- **THEN** the observer MUST detect the URL change
- **AND** debounce by 300ms before updating `accountId` / `messageId` refs
- **AND** invoke the `onChange` callback exactly once for the new URL

#### Notes
- **Observed bug — `accountId` fallback heuristic**: for path-based URLs the boxId is a mailbox id (or `priority` / `starred`), not an account id. The observer falls back to `accountId: 1` unconditionally. Setups with multiple Mail accounts will mis-attribute thread linkage. Tracked here as observed behavior; the right fix is to resolve mailbox → account via the Mail app's initial-state payload. Out of scope for this retrofit.

---

### REQ-003: Drag-and-drop email attachments onto linked objects

The sidebar SHALL make Mail attachment elements (DOM nodes matching `.attachment`) draggable and SHALL accept attachment drops on linked-object cards. When an attachment is dropped, the file content SHALL be fetched from the Mail download URL and uploaded to the target object via `POST /apps/openregister/api/objects/{register}/{schema}/{id}/filesMultipart`.

#### Rationale

Mail's `MessageAttachment.vue` (Mail 5.7.x and earlier) does not set `draggable=true` on its attachment elements. The sidebar runtime-patches those nodes to enable native HTML5 drag, writing a structured payload into `dataTransfer` under the MIME type `application/x-nc-mail-attachment` and also as `text/uri-list` so non-OR drop targets still work. The drop handler on the Objects-tab card then downloads the attachment with the user's session credentials and re-uploads as a multipart file. Upstream PR nextcloud/mail#10509 is expected to retire the runtime patching once native drag support lands.

#### Scenario: Patch attachment element on mount
- **GIVEN** the sidebar is mounted and the Mail app renders an `.attachment` element with `__vueParentComponent.props = { id: 'a1', fileName: 'aanvraag.pdf', mime: 'application/pdf', size: 1234, url: '...' }`
- **WHEN** `useAttachmentDrag()`'s `MutationObserver` scans the new node
- **THEN** the element MUST receive `draggable="true"` and a `__orAttachmentPatched` flag MUST be set on it
- **AND** the same element MUST NOT be patched twice on subsequent mutations

#### Scenario: Drag-start writes attachment payload
- **GIVEN** the user starts dragging a patched `.attachment` element and the current URL contains `/apps/mail/box/2/thread/42`
- **WHEN** the `dragstart` listener fires
- **THEN** `event.dataTransfer.effectAllowed` MUST be `'copy'`
- **AND** `event.dataTransfer.setData('application/x-nc-mail-attachment', …)` MUST be called with a JSON payload containing `{ messageId: 42, attachmentId, fileName, mime, size, downloadUrl }`
- **AND** the same payload's `downloadUrl` MUST also be written as `text/uri-list` and the `fileName` as `text/plain`

#### Scenario: Drop attachment on linked-object card uploads file
- **GIVEN** the user drags an attachment payload over an object card on the Objects tab and the card has `register=1`, `schemaId=3`, `uuid='abc-123'`
- **WHEN** the `drop` event fires
- **THEN** the handler MUST fetch the attachment via `attachment.downloadUrl` with `credentials: 'same-origin'`
- **AND** wrap the response blob into a `File` named `attachment.fileName`
- **AND** POST a `multipart/form-data` body to `/apps/openregister/api/objects/1/3/abc-123/filesMultipart` with the file as `files[]`
- **AND** show a success toast `"Attachment added to {name}"` on success
- **AND** show an error toast `"Failed to add attachment to object"` on any thrown error

#### Scenario: Drop is a no-op when payload mime is missing
- **GIVEN** a drop event without `application/x-nc-mail-attachment` data
- **WHEN** the handler runs
- **THEN** it MUST early-return without making any HTTP call

#### Scenario: Cleanup on unmount
- **GIVEN** the sidebar component is being torn down
- **WHEN** `onBeforeUnmount` fires
- **THEN** the `MutationObserver` MUST `disconnect()` and the observer reference MUST be cleared

#### Notes
- **Brittle coupling**: the patch logic targets the `.attachment` CSS class and `__vueParentComponent.props` (Vue 3) / `__vue__.$props` (Vue 2) — both Mail-internal. Any Mail-side refactor of `MessageAttachment.vue` can silently break the drag handle without breaking the sidebar's other features. Tracked upstream as nextcloud/mail#10509.

---

### REQ-004: Email-body entity extraction tab

The Entities tab SHALL fetch entities related to the currently viewed email from `GET /apps/openregister/api/entities?emailId={messageId}&limit=50` and SHALL group them by entity `type`. Supported types SHALL include `PERSON`, `ORGANIZATION`, `EMAIL`, `PHONE`, `LOCATION`, `ADDRESS`, `DATE`, `IBAN`, with all other types collapsing under an `Other` group. Each entity SHALL render its `value` and, if present, a confidence percentage rounded to the nearest integer.

#### Rationale

Case handlers receiving citizen emails frequently need to copy out names, addresses, phone numbers, and bank account numbers (IBAN) to start a new case or annotate an existing one. The Entities tab surfaces those values pre-extracted so the handler does not have to read the email body to find them. The tab is data-only — it does not (yet) provide one-click linking from an entity to a case.

#### Scenario: Load entities on tab mount
- **GIVEN** the Entities tab is the active tab and `messageId=42`
- **WHEN** `EntitiesTab` is created
- **THEN** `axios.get('/apps/openregister/api/entities', { params: { emailId: 42, limit: 50 }, timeout: 10000 })` MUST be called
- **AND** `loading` MUST be `true` for the duration of the call
- **AND** `entities` MUST be populated from `response.data.data` or `response.data.results` (whichever is present)

#### Scenario: Group entities by type
- **GIVEN** `entities = [{ type: 'PERSON', value: 'Jan de Vries', confidence: 0.92 }, { type: 'IBAN', value: 'NL91…', confidence: 0.99 }, { type: 'PERSON', value: 'Sara Bakker', confidence: 0.81 }]`
- **WHEN** `groupedEntities` computes
- **THEN** the result MUST be `{ PERSON: [Jan, Sara], IBAN: [NL91…] }`
- **AND** the rendered group titles MUST be `t('openregister', 'Persons')` and `t('openregister', 'IBANs')` respectively

#### Scenario: Unknown type collapses to Other
- **GIVEN** `entities` contains an item with `type: 'PRODUCT_CODE'` (not in the labels map)
- **WHEN** `formatType('PRODUCT_CODE')` runs
- **THEN** it MUST return the literal `'PRODUCT_CODE'` (fallthrough)
- **AND** an item with `type: null` or missing MUST be grouped under the `'unknown'` key and rendered with the `Other` label

#### Scenario: Refetch on message change
- **GIVEN** the Entities tab is open and `messageId` changes from 42 to 43
- **WHEN** the watcher fires
- **THEN** `loadEntities()` MUST run again with the new id
- **AND** stale entities from message 42 MUST NOT remain visible after the new fetch completes

#### Scenario: API failure shows empty state
- **GIVEN** the entities endpoint returns HTTP 500 or the request times out
- **WHEN** the `catch` block runs
- **THEN** `entities` MUST be reset to `[]`
- **AND** the error MUST be logged to the browser console with a `[EntitiesTab]` prefix
- **AND** the tab MUST render the empty-state message `"No entities detected for this email."`

#### Scenario: No messageId selected
- **GIVEN** the Entities tab is open but the Mail app has no message selected (`messageId === null`)
- **WHEN** `loadEntities()` runs
- **THEN** it MUST early-return without an HTTP call
- **AND** `entities` MUST be set to `[]`

#### Notes
- **Confidence-rendering rule**: entities without a `confidence` field do not render a percentage badge — observed in code as `v-if="entity.confidence"`. Confidence values are expected to be floats in `[0,1]`; the tab applies `Math.round(confidence * 100)` without bounds-checking. Out-of-range values would render as ">100%" — flagged as a follow-up.
- **Source endpoint contract**: `/api/entities?emailId=…` is not specified in any current spec. This REQ describes only the tab's consumer behavior; the producer endpoint needs its own spec (likely under a future `entity-extraction` capability).

---

### REQ-005: Mail-app page detection and retry-mount bootstrap

The mail-sidebar entry script SHALL detect whether the current page is a Mail-app page by probing for `document.getElementById('initial-state-mail-accounts')`. On a positive detection it SHALL mount the sidebar's Vue root directly to `document.body` with the id `openregister-mail-sidebar`. On a negative detection it SHALL retry the probe at 1-second intervals up to `MOUNT_MAX_RETRIES = 30` times before giving up silently. The script SHALL also bootstrap the Integration Registry singleton (ADR-019) at module-load time to populate `useIntegrationRegistry()` for any sub-component on the bundle.

#### Rationale

The Mail app's Vue root destroys its DOM children during route changes. Mounting the sidebar inside `#content`, `#content-vue`, or `#app-content-vue` causes the sidebar to disappear whenever Mail navigates between message and folder views. Mounting on `document.body` survives Mail re-renders. The retry loop accommodates first-page-load races where the sidebar bundle executes before the Mail app has had a chance to print its initial-state element.

#### Scenario: Mount on Mail-app page detected immediately
- **GIVEN** the bundle loads and `#initial-state-mail-accounts` is already in the DOM
- **WHEN** `mountSidebar()` runs
- **THEN** a `new Vue({ render: h => h(MailSidebar) }).$mount()` MUST execute
- **AND** the resulting `$el` MUST be assigned `id="openregister-mail-sidebar"` and appended to `document.body`
- **AND** a `[OpenRegister] Mail sidebar mounted successfully` info log MUST be emitted

#### Scenario: Idempotent on re-entry
- **GIVEN** an element with `id="openregister-mail-sidebar"` already exists on the page
- **WHEN** `tryMount()` runs again
- **THEN** the existing mount MUST be left untouched
- **AND** no second Vue instance MUST be created
- **AND** a `[OpenRegister] Sidebar already mounted` debug log MUST be emitted

#### Scenario: Retry until initial-state appears
- **GIVEN** the bundle loads before the Mail app has rendered its initial-state element
- **WHEN** `tryMount()` checks `isMailAppPage()` and the element is absent
- **THEN** `setTimeout(tryMount, 1000)` MUST schedule a retry
- **AND** the retry counter MUST increment by 1
- **AND** mounting MUST occur on the first tick where the element is present

#### Scenario: Give up after MOUNT_MAX_RETRIES
- **GIVEN** the bundle loads on a non-Mail page (e.g. Files or Settings)
- **WHEN** 30 retries have elapsed without `#initial-state-mail-accounts` appearing
- **THEN** the script MUST stop retrying
- **AND** emit a `[OpenRegister] Not a Mail page, skipping sidebar injection` debug log
- **AND** no Vue instance MUST be created

#### Scenario: DOMContentLoaded gating
- **GIVEN** `document.readyState === 'loading'`
- **WHEN** the module top-level executes
- **THEN** `mountSidebar` MUST be attached to `DOMContentLoaded` instead of being invoked immediately
- **AND** otherwise `mountSidebar()` MUST run synchronously

#### Scenario: Integration registry singleton boot
- **GIVEN** the bundle loads on a Mail-app page where the host page never invoked `ensureIntegrationRegistry()`
- **WHEN** the module top-level executes
- **THEN** `ensureIntegrationRegistry()` MUST be called once
- **AND** `useIntegrationRegistry()` MUST return a populated singleton for any sub-component (ADR-019)

#### Notes
- **30-second cap**: the retry budget (30 × 1s = 30s) was sized empirically; a slow Mail app boot on a busy server (cold APCu, hot reload) can exceed 30s and miss the mount. Failure mode is silent — the user sees no sidebar and no console error. Flagged as a possible follow-up: bump to 60s or hook into a Mail-side ready event when one exists.
- **Bootstrap order**: `ensureIntegrationRegistry()` is intentionally invoked at module-load time (before `DOMContentLoaded`) so that `useIntegrationRegistry()` is safe for any sub-component that initialises during `setup()`. This is idempotent — calling it again from another bundle's bootstrap is a no-op.
