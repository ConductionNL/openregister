---
status: proposed
---

# Mail Sidebar

## Purpose

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
