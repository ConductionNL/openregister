# Design: Mail Sidebar

## Approach

Inject an OpenRegister sidebar panel into the Nextcloud Mail app that displays linked objects for the currently viewed email. The implementation follows a three-layer architecture:

1. **Backend**: New reverse-lookup API endpoints on `EmailsController` + a sender-based object discovery endpoint
2. **Script injection**: Register an additional script via `OCP\Util::addScript()` that loads when the Mail app is active
3. **Frontend**: A standalone Vue micro-app that renders a sidebar panel, communicates with OpenRegister API, and observes Mail app DOM/URL changes to detect which email is being viewed

## Architecture Decisions

### AD-1: Script Injection via OCP\Util::addScript vs. IFrame

**Decision**: Use `OCP\Util::addScript()` to inject a JavaScript bundle into the Mail app page.

**Why**: `OCP\Util::addScript()` is the supported Nextcloud mechanism for cross-app script loading. It loads synchronously with the page, has access to the same DOM and Nextcloud JS APIs (OC, OCA), and can use Nextcloud's axios instance for authenticated API calls. An IFrame would require separate authentication, CORS configuration, and would not integrate visually.

**Trade-off**: The injected script depends on the Mail app's DOM structure, which may change between versions. We mitigate this by observing URL hash changes rather than DOM mutations where possible.

### AD-2: Email Detection via URL Observation

**Decision**: Detect the currently viewed email by observing the Mail app's URL hash/route changes rather than intercepting Mail app internal events.

**Why**: The Mail app's Vue router encodes the current mailbox and message ID in the URL (e.g., `#/accounts/1/folders/INBOX/messages/42`). Observing URL changes is non-invasive, does not depend on Mail app internal APIs, and survives Mail app updates as long as the URL structure remains stable. The URL format has been stable since Nextcloud Mail 1.x.

**Fallback**: If URL parsing fails, the sidebar shows a "Select an email to see linked objects" placeholder rather than erroring.

### AD-3: Dual Query Strategy (Explicit Links + Sender Discovery)

**Decision**: The sidebar performs two queries per email: (1) explicit links from `openregister_email_links` for the current message ID, and (2) a sender-based discovery query that finds objects linked to ANY email from the same sender.

**Why**: Explicit links give precise results. Sender discovery provides context -- "this person has 3 other cases" -- which is valuable for case handlers who need to see the full picture. The two result sets are displayed in separate sections to avoid confusion.

**Trade-off**: Two API calls per email view. Mitigated by debouncing (wait 300ms after URL change) and caching results per message ID for the session.

### AD-4: Sidebar Position -- Right Panel Injection

**Decision**: Inject the sidebar as a right-side panel that appears alongside (not replacing) the Mail app's existing message detail view.

**Why**: The Mail app uses `NcAppContentDetails` for the message body on the right side. We inject a collapsible panel at the far right of the content area, similar to how Files app shows file details. This avoids conflicting with the Mail app's own layout.

**Implementation**: The injected script creates a container div, appends it to the Mail app's content area, and mounts a Vue instance into it. CSS ensures proper width and responsive behavior.

### AD-5: Graceful Degradation When Mail App Not Present

**Decision**: The script injection is conditional -- only registered when the Mail app is installed and enabled.

**Why**: OpenRegister must work without the Mail app. The `Application::register()` method checks `IAppManager::isEnabledForUser('mail')` before calling `Util::addScript()`.

### AD-6: API Reuse -- Extend Existing EmailsController

**Decision**: Add reverse-lookup endpoints to the existing `EmailsController` rather than creating a new controller.

**Why**: The `EmailsController` already owns the `/api/emails/*` route namespace (from nextcloud-entity-relations). Adding `GET /api/emails/by-message/{accountId}/{messageId}` and `GET /api/emails/by-sender` follows RESTful conventions and avoids route duplication.

## Files Affected

### New Files (Backend)

| File | Purpose |
|------|---------|
| `lib/Listener/MailAppScriptListener.php` | Listens for `BeforeTemplateRenderedEvent` from the Mail app and injects the sidebar script |

### Modified Files (Backend)

| File | Change |
|------|--------|
| `lib/Service/EmailService.php` | Add `findByMessageId()`, `findBySender()`, `findObjectsByMessageId()`, `findObjectsBySender()` methods |
| `lib/Controller/EmailsController.php` | Add `byMessage()` and `bySender()` endpoints |
| `appinfo/routes.php` | Add routes for reverse-lookup endpoints |
| `lib/AppInfo/Application.php` | Register `MailAppScriptListener` and conditional script injection |

### New Files (Frontend)

| File | Purpose |
|------|---------|
| `src/mail-sidebar.js` | Entry point for the Mail sidebar micro-app (webpack additional entry) |
| `src/mail-sidebar/MailSidebar.vue` | Root component for the sidebar panel |
| `src/mail-sidebar/components/LinkedObjectsList.vue` | Displays explicitly linked objects |
| `src/mail-sidebar/components/SuggestedObjectsList.vue` | Displays sender-based discovery results |
| `src/mail-sidebar/components/ObjectCard.vue` | Card component for a single object with metadata |
| `src/mail-sidebar/components/LinkObjectDialog.vue` | Modal dialog for searching and linking objects |
| `src/mail-sidebar/composables/useMailObserver.js` | Composable that observes Mail app URL changes and extracts account/message IDs |
| `src/mail-sidebar/composables/useEmailLinks.js` | Composable for API calls to email link endpoints |
| `src/mail-sidebar/api/emailLinks.js` | Axios API wrapper for email link endpoints |
| `css/mail-sidebar.css` | Styles for the sidebar panel (NL Design System compatible) |

### Modified Files (Frontend)

| File | Change |
|------|--------|
| `webpack.config.js` | Add `mail-sidebar` as additional entry point |

## API Routes (to add to routes.php)

```php
// Reverse-lookup: find objects linked to a specific email message
['name' => 'emails#byMessage', 'url' => '/api/emails/by-message/{accountId}/{messageId}', 'verb' => 'GET', 'requirements' => ['accountId' => '\d+', 'messageId' => '\d+']],

// Discovery: find objects linked to emails from a specific sender
['name' => 'emails#bySender', 'url' => '/api/emails/by-sender', 'verb' => 'GET'],

// Quick link: link current email to an object (used from sidebar)
['name' => 'emails#quickLink', 'url' => '/api/emails/quick-link', 'verb' => 'POST'],
```

## Sequence Diagram

```
User opens email in Mail app
       |
       v
MailSidebar.vue (injected script)
       |
       +--> useMailObserver detects URL change
       |    extracts accountId=1, messageId=42
       |
       +--> GET /api/emails/by-message/1/42
       |    Returns: [{objectUuid, register, schema, title, ...}]
       |    --> Renders LinkedObjectsList
       |
       +--> GET /api/emails/by-sender?sender=burger@test.local
       |    Returns: [{objectUuid, register, schema, title, linkedEmailCount, ...}]
       |    --> Renders SuggestedObjectsList (filtered to exclude already-linked)
       |
User clicks "Link to Object"
       |
       +--> LinkObjectDialog opens
       |    User searches for object by title/UUID
       |    GET /api/objects/search?q=vergunning+123
       |
       +--> User selects object, confirms
       |    POST /api/emails/quick-link
       |    {accountId: 1, messageId: 42, objectUuid: "abc-123", register: 1, schema: 2}
       |
       +--> Sidebar refreshes, shows new link in LinkedObjectsList
```

## CSS/Styling Strategy

The sidebar panel uses Nextcloud's standard CSS variables (`--color-primary`, `--color-background-dark`, etc.) and NL Design System tokens where available. The panel width is 320px on desktop, collapses to a toggleable overlay on narrow viewports (<1024px). The toggle button is a small tab anchored to the right edge of the content area.

## Dependency on nextcloud-entity-relations

This change REQUIRES the nextcloud-entity-relations spec to be implemented first, specifically:
- `openregister_email_links` database table
- `EmailService` with link/unlink/list methods
- `EmailLinkMapper` for database queries
- `EmailsController` with base CRUD endpoints

This change EXTENDS that foundation with reverse-lookup capabilities and the Mail app UI integration.
