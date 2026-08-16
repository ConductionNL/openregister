---
status: done
retrofit_extensions:
  - REQ-001
  - REQ-002
  - REQ-003
  - REQ-004
  - REQ-005
---

# Mail Sidebar

## Purpose

@e2e exclude Nextcloud Mail IMailProvider backend — covered by PHPUnit

Provide a sidebar panel inside the Nextcloud Mail app that displays OpenRegister objects related to the currently viewed email. This enables case handlers to see at a glance which cases, applications, or records are associated with an email -- and to create new associations -- without leaving the Mail app. The integration builds on the `openregister_email_links` table and `EmailService` established by the nextcloud-entity-relations spec.

**Standards**: Nextcloud App Framework (script injection via `OCP\Util::addScript()`), REST API conventions (JSON responses, standard HTTP status codes), WCAG AA accessibility
**Cross-references**: [nextcloud-entity-relations](../../../specs/nextcloud-entity-relations/spec.md), [object-interactions](../../../specs/object-interactions/spec.md), [deep-link-registry](../../../specs/deep-link-registry/spec.md)

---

## Requirements

### Requirement: Reverse-lookup API to find objects by mail message ID

The system SHALL provide a REST endpoint that accepts a Nextcloud Mail account ID and message ID, queries the `openregister_email_links` table, and returns all OpenRegister objects linked to that specific email message. For each linked object, the response MUST include the object's UUID, register ID, schema ID, title (derived from the object's data using the schema's title property), and the link metadata (who linked it and when).

#### Rationale

The existing `EmailsController` provides forward lookups (object -> emails). The sidebar needs the reverse: email -> objects. This endpoint is the primary data source for the sidebar's "Linked Objects" section.

#### Scenario: Find objects linked to a specific email
- **GIVEN** email with account ID 1 and message ID 42 is linked to objects `abc-123` and `def-456` in the `openregister_email_links` table
- **WHEN** a GET request is sent to `/api/emails/by-message/1/42`
- **THEN** the response MUST return HTTP 200 with JSON:
  ```json
  {
    "results": [
      {
        "linkId": 1,
        "objectUuid": "abc-123",
        "registerId": 1,
        "registerTitle": "Vergunningen",
        "schemaId": 3,
        "schemaTitle": "Omgevingsvergunning",
        "objectTitle": "OV-2026-0042",
        "linkedBy": "behandelaar-1",
        "linkedAt": "2026-03-20T14:30:00+00:00"
      },
      {
        "linkId": 2,
        "objectUuid": "def-456",
        "registerId": 1,
        "registerTitle": "Vergunningen",
        "schemaId": 3,
        "schemaTitle": "Omgevingsvergunning",
        "objectTitle": "OV-2026-0043",
        "linkedBy": "admin",
        "linkedAt": "2026-03-21T09:15:00+00:00"
      }
    ],
    "total": 2
  }
  ```
- **AND** each result MUST include `registerTitle` and `schemaTitle` resolved from the Register and Schema entities

#### Scenario: No objects linked to this email
- **GIVEN** email with account ID 1 and message ID 99 has no entries in `openregister_email_links`
- **WHEN** a GET request is sent to `/api/emails/by-message/1/99`
- **THEN** the response MUST return HTTP 200 with `{"results": [], "total": 0}`

#### Scenario: Invalid account ID or message ID
- **GIVEN** a GET request with non-numeric account or message ID
- **WHEN** the request is processed
- **THEN** the response MUST return HTTP 400 with `{"error": "Invalid account ID or message ID"}`

---

### Requirement: Sender-based object discovery API

The system SHALL provide a REST endpoint that accepts a sender email address and returns all OpenRegister objects that have ANY linked email from that sender. This enables the sidebar's "Other cases from this sender" discovery section. The results MUST be distinct by object UUID (no duplicates if multiple emails from the same sender are linked to the same object) and MUST include a count of how many emails from that sender are linked to each object.

#### Rationale

Case handlers need context beyond the current email. Knowing that the sender has 3 other open cases helps prioritize and cross-reference. This query leverages the `sender` column in `openregister_email_links`.

#### Scenario: Discover objects by sender email
- **GIVEN** sender `burger@test.local` has emails linked to objects `abc-123` (2 emails), `ghi-789` (1 email)
- **WHEN** a GET request is sent to `/api/emails/by-sender?sender=burger@test.local`
- **THEN** the response MUST return HTTP 200 with:
  ```json
  {
    "results": [
      {
        "objectUuid": "abc-123",
        "registerId": 1,
        "registerTitle": "Vergunningen",
        "schemaId": 3,
        "schemaTitle": "Omgevingsvergunning",
        "objectTitle": "OV-2026-0042",
        "linkedEmailCount": 2
      },
      {
        "objectUuid": "ghi-789",
        "registerId": 2,
        "registerTitle": "Meldingen",
        "schemaId": 5,
        "schemaTitle": "Melding",
        "objectTitle": "ML-2026-0015",
        "linkedEmailCount": 1
      }
    ],
    "total": 2
  }
  ```
- **AND** results MUST be ordered by `linkedEmailCount` descending (most-linked first)

#### Scenario: No objects found for sender
- **GIVEN** sender `unknown@example.com` has no linked emails in any object
- **WHEN** a GET request is sent to `/api/emails/by-sender?sender=unknown@example.com`
- **THEN** the response MUST return HTTP 200 with `{"results": [], "total": 0}`

#### Scenario: Missing sender parameter
- **GIVEN** a GET request to `/api/emails/by-sender` without the `sender` query parameter
- **WHEN** the request is processed
- **THEN** the response MUST return HTTP 400 with `{"error": "The sender parameter is required"}`

#### Scenario: Sender discovery excludes current email's linked objects
- **GIVEN** the sidebar makes both a by-message and by-sender call
- **WHEN** the frontend renders the results
- **THEN** objects already shown in the "Linked Objects" section (from by-message) MUST be excluded from the "Other cases from this sender" section
- **AND** this filtering happens client-side to keep the API stateless

---

### Requirement: Quick-link endpoint for sidebar use

The system SHALL provide a POST endpoint that creates an email-object link with minimal input, designed for use from the Mail sidebar where the mail context (account ID, message ID, subject, sender, date) is already known. The endpoint MUST accept all required fields in one call and return the created link with resolved object metadata.

#### Rationale

The existing `POST /api/objects/{register}/{schema}/{id}/emails` endpoint requires knowing the register, schema, and object ID upfront and navigates from the object side. The sidebar needs to link from the email side -- the user sees the email and picks an object to link. The quick-link endpoint inverts the flow.

#### Scenario: Quick-link an email to an object from the sidebar
- **GIVEN** an authenticated user viewing email (accountId: 1, messageId: 42, subject: "Aanvraag vergunning", sender: "burger@test.local", date: "2026-03-20T10:00:00Z")
- **WHEN** a POST request is sent to `/api/emails/quick-link` with body:
  ```json
  {
    "mailAccountId": 1,
    "mailMessageId": 42,
    "mailMessageUid": "1234",
    "subject": "Aanvraag vergunning",
    "sender": "burger@test.local",
    "date": "2026-03-20T10:00:00Z",
    "objectUuid": "abc-123",
    "registerId": 1
  }
  ```
- **THEN** a record MUST be created in `openregister_email_links`
- **AND** the `linkedBy` field MUST be set to the current authenticated user
- **AND** the response MUST return HTTP 201 with the created link including resolved `objectTitle`, `registerTitle`, `schemaTitle`

#### Scenario: Quick-link with non-existent object
- **GIVEN** a POST with `objectUuid: "nonexistent-uuid"`
- **WHEN** the system validates the object
- **THEN** the response MUST return HTTP 404 with `{"error": "Object not found"}`

#### Scenario: Quick-link duplicate prevention
- **GIVEN** email (accountId: 1, messageId: 42) is already linked to object `abc-123`
- **WHEN** a POST request tries to create the same link
- **THEN** the response MUST return HTTP 409 with `{"error": "Email already linked to this object"}`

---

### Requirement: Mail app script injection via event listener

The system SHALL register a PHP event listener that injects the OpenRegister mail sidebar JavaScript bundle into the Nextcloud Mail app page. The injection MUST only occur when: (1) the Mail app is installed and enabled for the current user, (2) the user has access to at least one OpenRegister register, and (3) the current page is the Mail app. The script MUST be loaded as a separate webpack entry point to avoid bloating the main OpenRegister bundle.

#### Rationale

Nextcloud's `OCP\Util::addScript()` is the standard mechanism for cross-app script injection. By listening to the Mail app's template rendering event, we ensure the script is only loaded when relevant.

#### Scenario: Script is injected when Mail app is active
- **GIVEN** a user with OpenRegister access opens the Nextcloud Mail app
- **WHEN** the Mail app's `BeforeTemplateRenderedEvent` fires
- **THEN** `OCP\Util::addScript('openregister', 'openregister-mail-sidebar')` MUST be called
- **AND** the script MUST create a container element and mount the Vue sidebar component
- **AND** the script MUST NOT interfere with the Mail app's existing functionality

#### Scenario: Script is NOT injected when Mail app is not installed
- **GIVEN** the Nextcloud Mail app is not installed
- **WHEN** the user navigates to any page
- **THEN** no mail sidebar script MUST be registered or loaded
- **AND** no errors MUST appear in the server log related to the mail sidebar

#### Scenario: Script is NOT injected for users without OpenRegister access
- **GIVEN** a user who has no access to any OpenRegister registers
- **WHEN** the user opens the Mail app
- **THEN** the mail sidebar script MUST NOT be injected
- **AND** no OpenRegister UI elements MUST appear in the Mail app

---

### Requirement: Sidebar panel UI with linked objects display

The system SHALL render a collapsible sidebar panel on the right side of the Mail app's message detail view. The panel MUST display two sections: (1) "Linked Objects" showing objects explicitly linked to the current email, and (2) "Related Cases" showing objects discovered via sender email address. Each object MUST be displayed as a card with the object title, schema name, register name, and a deep link to the object in OpenRegister.

#### Rationale

Case handlers need quick, scannable access to case context while reading emails. A sidebar panel is the least disruptive UI pattern -- it does not obscure the email content and can be collapsed when not needed.

#### Scenario: Sidebar shows linked objects for current email
- **GIVEN** the user is viewing email (accountId: 1, messageId: 42) which is linked to 2 objects
- **WHEN** the sidebar loads
- **THEN** the "Linked Objects" section MUST display 2 object cards
- **AND** each card MUST show: object title, schema name (e.g., "Omgevingsvergunning"), register name (e.g., "Vergunningen")
- **AND** each card MUST have a clickable link that navigates to `/apps/openregister/registers/{registerId}/{schemaId}/{objectUuid}` in a new tab

#### Scenario: Sidebar shows related cases from same sender
- **GIVEN** the current email is from `burger@test.local` who has emails linked to 3 objects (1 of which is already linked to the current email)
- **WHEN** the sidebar loads
- **THEN** the "Related Cases" section MUST display 2 object cards (excluding the one already shown in "Linked Objects")
- **AND** each card MUST show: object title, schema name, register name, and a badge showing "N emails" (how many emails from this sender are linked)

#### Scenario: Sidebar is collapsible
- **GIVEN** the sidebar panel is visible
- **WHEN** the user clicks the collapse toggle button
- **THEN** the panel MUST animate to a narrow tab (40px wide) showing only the OpenRegister icon
- **AND** clicking the tab MUST re-expand the panel
- **AND** the collapsed/expanded state MUST persist in `localStorage` across page reloads

#### Scenario: Sidebar shows empty state when no links exist
- **GIVEN** the current email has no linked objects and the sender has no linked emails anywhere
- **WHEN** the sidebar loads
- **THEN** the "Linked Objects" section MUST show: "No objects linked to this email"
- **AND** the "Related Cases" section MUST show: "No related cases found for this sender"
- **AND** a prominent "Link to Object" button MUST be visible

#### Scenario: Sidebar handles email navigation
- **GIVEN** the sidebar is showing objects for email (messageId: 42)
- **WHEN** the user clicks on a different email (messageId: 43) in the Mail app
- **THEN** the sidebar MUST detect the URL change within 300ms
- **AND** the sidebar MUST show a loading state while fetching new data
- **AND** the sidebar MUST display objects linked to the new email (messageId: 43)
- **AND** the previous results MUST be cached so returning to email 42 is instant

---

### Requirement: Link and unlink actions from the sidebar

> **SUPERSEDED by REQ-001 (Three-tab sidebar layout), below.** The heading and
> its scenarios are kept so existing `@spec` anchors continue to resolve, but the
> modal-dialog design described here is NOT what ships.
>
> REQ-001 states that the Link tab "replaces the inline 'Link to Object' button".
> That replacement happened in the code — the live affordance is the **Connect**
> tab (`ActionsTab.vue`), which searches per schema and links on selection — but
> this requirement was never marked superseded, so both designs read as current.
> The modal it describes (`LinkObjectDialog.vue`) existed, was specced, unit-
> tested and documented, and **was never mounted by anything**: it had zero
> imports in the entire repository. It has now been removed.
>
> The one capability the modal had that the Connect tab does not is search
> across *all* registers and schemas at once (`GET /api/objects?_search=`)
> rather than within one configured schema at a time
> (`GET /api/objects/{register}/{schema}?_search=`). That difference is a
> property of the superseded design, not a feature that was lost in use — no
> user could reach it. If cross-register linking is wanted, it belongs in the
> Connect tab as a new requirement, not in a resurrected modal.

The system SHALL provide UI actions in the sidebar to link and unlink objects from the current email. Linking opens a search dialog where the user can find objects by title, UUID, or schema. Unlinking removes the association after confirmation.

#### Rationale

The sidebar is the natural place to manage email-object associations. Without link/unlink actions, users would need to navigate to OpenRegister to manage links, defeating the purpose of the sidebar integration.

#### Scenario: Link an object to the current email via search
- **GIVEN** the user clicks "Link to Object" in the sidebar
- **WHEN** the link dialog opens
- **THEN** the dialog MUST show a search input with placeholder "Search by title or UUID..."
- **AND** as the user types, results MUST appear after 300ms debounce
- **AND** each result MUST show: object title, schema name, register name
- **AND** objects already linked to this email MUST be marked with a "Already linked" badge and be non-selectable

#### Scenario: Confirm linking an object
- **GIVEN** the user has selected object "OV-2026-0042" in the link dialog
- **WHEN** the user clicks "Link"
- **THEN** a POST request MUST be sent to `/api/emails/quick-link` with the current email's metadata and the selected object's UUID
- **AND** on success, the dialog MUST close and the linked object MUST appear in the "Linked Objects" section
- **AND** a Nextcloud toast notification MUST show "Object linked successfully" / "Object succesvol gekoppeld"

#### Scenario: Unlink an object from the current email
- **GIVEN** object "OV-2026-0042" is linked to the current email (linkId: 7)
- **WHEN** the user clicks the unlink (X) button on the object card
- **THEN** a confirmation dialog MUST appear: "Remove link between this email and OV-2026-0042?" / "Koppeling tussen deze e-mail en OV-2026-0042 verwijderen?"
- **AND** on confirmation, a DELETE request MUST be sent to `/api/objects/{register}/{schema}/{objectUuid}/emails/7`
- **AND** the object card MUST be removed from the "Linked Objects" section
- **AND** if the object has other emails from the same sender linked, it MUST appear in the "Related Cases" section

#### Scenario: Link dialog search returns no results
- **GIVEN** the user types "nonexistent-case-99" in the search input
- **WHEN** the debounced search completes
- **THEN** the dialog MUST show "No objects found" / "Geen objecten gevonden"
- **AND** a hint MUST appear: "Try searching by UUID or with different keywords" / "Probeer te zoeken op UUID of met andere zoektermen"

---

### Requirement: Email URL observation for automatic context switching

The system SHALL implement a URL observer that monitors the Nextcloud Mail app's route changes to detect when the user switches between emails. The observer MUST extract the mail account ID and message ID from the URL hash and trigger sidebar data refresh. The observer MUST handle all Mail app URL patterns including inbox, sent, drafts, and custom folders.

#### Rationale

The Mail app is a single-page application with client-side routing. The sidebar cannot rely on page reloads to detect navigation -- it must observe route changes programmatically. URL observation is more reliable and less invasive than DOM mutation observation or intercepting the Mail app's internal event bus.

#### Scenario: Detect email selection from inbox URL
- **GIVEN** the Mail app URL changes to `#/accounts/1/folders/INBOX/messages/42`
- **WHEN** the URL observer processes the change
- **THEN** it MUST extract `accountId: 1` and `messageId: 42`
- **AND** trigger a sidebar data refresh for that account/message combination
- **AND** the refresh MUST be debounced (300ms) to avoid rapid-fire requests during quick navigation

#### Scenario: Detect email selection from custom folder
- **GIVEN** the Mail app URL changes to `#/accounts/2/folders/Archief/messages/108`
- **WHEN** the URL observer processes the change
- **THEN** it MUST extract `accountId: 2` and `messageId: 108`
- **AND** trigger a sidebar data refresh

#### Scenario: Handle URL without message selection (folder view)
- **GIVEN** the Mail app URL changes to `#/accounts/1/folders/INBOX` (no message selected)
- **WHEN** the URL observer processes the change
- **THEN** the sidebar MUST clear the current results
- **AND** show a placeholder: "Select an email to see linked objects" / "Selecteer een e-mail om gekoppelde objecten te zien"

#### Scenario: Handle compose/settings URLs
- **GIVEN** the Mail app URL changes to `#/compose` or `#/settings`
- **WHEN** the URL observer processes the change
- **THEN** the sidebar MUST collapse or hide (no email context available)
- **AND** no API calls MUST be made

#### Scenario: Cache results for previously viewed emails
- **GIVEN** the user viewed email (messageId: 42) and then navigated to email (messageId: 43)
- **WHEN** the user navigates back to email (messageId: 42)
- **THEN** the sidebar MUST immediately display the cached results for messageId 42
- **AND** a background refresh MUST be triggered to check for updates
- **AND** if the background refresh returns different data, the UI MUST update seamlessly

---

### Requirement: Webpack entry point for mail sidebar bundle

The system SHALL build the mail sidebar as a separate webpack entry point (`mail-sidebar`) that produces an independent JavaScript bundle. This bundle MUST NOT import or depend on the main OpenRegister application bundle. It MUST only include the Vue components, composables, and API utilities needed for the sidebar panel.

#### Rationale

Loading the entire OpenRegister frontend bundle (with all views, stores, and dependencies) into the Mail app would be wasteful and could cause conflicts. A separate entry point ensures minimal bundle size and isolation.

#### Scenario: Separate webpack entry point
- **GIVEN** the webpack configuration has a `mail-sidebar` entry point at `src/mail-sidebar.js`
- **WHEN** `npm run build` is executed
- **THEN** a separate bundle `js/openregister-mail-sidebar.js` MUST be produced
- **AND** the bundle size MUST be less than 100KB gzipped (excluding Vue runtime shared with Nextcloud)
- **AND** the bundle MUST NOT include any OpenRegister store modules, router configuration, or view components from the main app

#### Scenario: Bundle uses Nextcloud's shared Vue instance
- **GIVEN** the Mail app page already has Vue loaded via Nextcloud's runtime
- **WHEN** the mail sidebar bundle loads
- **THEN** it MUST use the externalized Vue (from webpack externals) rather than bundling its own
- **AND** it MUST use Nextcloud's shared axios instance for API calls (`@nextcloud/axios`)

---

### Requirement: i18n support for Dutch and English

The system SHALL provide all user-facing strings in the sidebar in both Dutch (nl) and English (en), using Nextcloud's standard translation mechanism (`@nextcloud/l10n`). The sidebar MUST follow the user's Nextcloud language preference.

#### Rationale

All Conduction apps require Dutch and English as minimum languages (per i18n requirement in project.md). Government users in the Netherlands primarily use Dutch.

#### Key translatable strings

| English | Dutch |
|---------|-------|
| Linked Objects | Gekoppelde objecten |
| Related Cases | Gerelateerde zaken |
| No objects linked to this email | Geen objecten gekoppeld aan deze e-mail |
| No related cases found for this sender | Geen gerelateerde zaken gevonden voor deze afzender |
| Link to Object | Koppelen aan object |
| Search by title or UUID... | Zoeken op titel of UUID... |
| Already linked | Al gekoppeld |
| Link | Koppelen |
| Cancel | Annuleren |
| Object linked successfully | Object succesvol gekoppeld |
| Remove link? | Koppeling verwijderen? |
| Remove link between this email and {title}? | Koppeling tussen deze e-mail en {title} verwijderen? |
| Remove | Verwijderen |
| Select an email to see linked objects | Selecteer een e-mail om gekoppelde objecten te zien |
| N emails | N e-mails |
| Open in OpenRegister | Openen in OpenRegister |

#### Scenario: Sidebar renders in Dutch for Dutch user
- **GIVEN** a user whose Nextcloud language is set to `nl`
- **WHEN** the sidebar loads
- **THEN** all labels, buttons, placeholders, and messages MUST be displayed in Dutch
- **AND** the `t('openregister', ...)` function MUST be used for all translatable strings

#### Scenario: Sidebar renders in English for English user
- **GIVEN** a user whose Nextcloud language is set to `en`
- **WHEN** the sidebar loads
- **THEN** all labels, buttons, placeholders, and messages MUST be displayed in English

---

### Requirement: Accessibility compliance (WCAG AA)

The sidebar panel MUST meet WCAG AA accessibility standards. All interactive elements MUST be keyboard-navigable, have visible focus indicators, and include appropriate ARIA labels. Color contrast MUST meet 4.5:1 for normal text and 3:1 for large text.

#### Scenario: Keyboard navigation through sidebar
- **GIVEN** the sidebar is visible and has linked objects
- **WHEN** the user presses Tab
- **THEN** focus MUST move through: collapse toggle -> first object card link -> first object unlink button -> second object card link -> ... -> "Link to Object" button
- **AND** each focused element MUST have a visible focus ring (using `--color-primary` outline)

#### Scenario: Screen reader announces sidebar content
- **GIVEN** a screen reader user navigates to the sidebar
- **WHEN** the sidebar region is reached
- **THEN** it MUST be announced as "OpenRegister: Linked Objects sidebar" (via `role="complementary"` and `aria-label`)
- **AND** each object card MUST announce: "{title}, {schema} in {register}. Linked by {user} on {date}"
- **AND** the unlink button MUST announce: "Remove link to {title}"

#### Scenario: Color contrast in light and dark themes
- **GIVEN** the sidebar uses Nextcloud CSS variables for colors
- **WHEN** rendered in light theme or dark theme
- **THEN** all text MUST have at least 4.5:1 contrast ratio against its background
- **AND** the sidebar MUST NOT use hardcoded colors (CSS variables only, per NL Design System requirements)

---

### Requirement: Error handling and resilience

The sidebar MUST handle API errors, network failures, and unexpected states gracefully without breaking the Mail app experience. Errors MUST be displayed inline in the sidebar, not as modal dialogs or browser alerts.

#### Scenario: API returns 500 error
- **GIVEN** the reverse-lookup API returns HTTP 500
- **WHEN** the sidebar processes the response
- **THEN** the sidebar MUST display: "Could not load linked objects. Try again later." / "Gekoppelde objecten konden niet worden geladen. Probeer het later opnieuw."
- **AND** a "Retry" button MUST be shown
- **AND** the error MUST be logged to the browser console with the response details

#### Scenario: Network timeout
- **GIVEN** the API call takes longer than 10 seconds
- **WHEN** the timeout is reached
- **THEN** the sidebar MUST abort the request and show a timeout message
- **AND** a "Retry" button MUST be shown

#### Scenario: Mail app DOM structure changes (version mismatch)
- **GIVEN** the Mail app updates and the expected container element is not found
- **WHEN** the sidebar script attempts to mount
- **THEN** the script MUST log a warning: "Mail sidebar: could not find mount point, skipping injection"
- **AND** the script MUST NOT throw unhandled exceptions
- **AND** the Mail app MUST continue to function normally

#### Scenario: OpenRegister API is unreachable
- **GIVEN** the OpenRegister app is disabled or uninstalled while the Mail app is open
- **WHEN** the sidebar attempts an API call
- **THEN** the sidebar MUST catch the error and hide itself
- **AND** no error dialogs or broken UI elements MUST remain in the Mail app

---

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
