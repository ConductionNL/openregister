# Tasks: Mail Sidebar

> **Status:** Shipped — all 60 tasks ticked. Reverse integration of `nextcloud-entity-relations`: viewing an email in the Nextcloud Mail app surfaces the OpenRegister objects linked to that message via `MailAppScriptListener` injecting the sidebar bundle into `BeforeTemplateRenderedEvent`. `EmailService::findByMessageId` / `findObjectsBySender` provide the reverse-lookup API; `EmailsController::byMessage` / `bySender` / `quickLink` expose the endpoints. Caching via `IReferenceManager` keeps repeat queries cheap.

## Backend API

- [x] Add `findByMessageId(int $accountId, int $messageId)` method to EmailService that queries openregister_email_links and resolves object/register/schema metadata
- [x] Add `findObjectsBySender(string $sender)` method to EmailService with GROUP BY object_uuid and COUNT for linkedEmailCount
- [x] Add `quickLink(array $params)` method to EmailService that creates an email link from the sidebar's email-side perspective
- [x] Add `byMessage(int $accountId, int $messageId)` endpoint to EmailsController returning linked objects with register/schema titles
- [x] Add `bySender(string $sender)` endpoint to EmailsController returning discovered objects with email counts
- [x] Add `quickLink()` POST endpoint to EmailsController for sidebar-initiated linking
- [x] Add reverse-lookup and quick-link routes to appinfo/routes.php
- [x] Add input validation for accountId, messageId (numeric), and sender (email format) parameters

## Script Injection

- [x] Create MailAppScriptListener.php that listens for BeforeTemplateRenderedEvent from the Mail app
- [x] Implement conditional injection: check Mail app enabled AND user has OpenRegister access
- [x] Register MailAppScriptListener in Application.php with IEventDispatcher
- [x] Add openregister-mail-sidebar script registration via OCP\Util::addScript()

## Webpack Build

- [x] Add mail-sidebar entry point to webpack.config.js pointing to src/mail-sidebar.js
- [x] Create src/mail-sidebar.js entry point that mounts the sidebar Vue component
- [x] Configure webpack externals to use Nextcloud's shared Vue and axios
- [x] Verify separate bundle output (js/openregister-mail-sidebar.js) does not include main app code

## Frontend - Core Components

- [x] Create src/mail-sidebar/MailSidebar.vue root component with collapsible panel layout
- [x] Create src/mail-sidebar/components/LinkedObjectsList.vue for explicitly linked objects
- [x] Create src/mail-sidebar/components/SuggestedObjectsList.vue for sender-based discovery results
- [x] Create src/mail-sidebar/components/ObjectCard.vue with title, schema, register, deep link, and unlink button
- [x] Create src/mail-sidebar/components/LinkObjectDialog.vue modal with search input and results list

## Frontend - Composables and API

- [x] Create src/mail-sidebar/composables/useMailObserver.js to observe Mail app URL changes and extract accountId/messageId
- [x] Implement URL parsing for all Mail app route patterns (inbox, sent, drafts, custom folders, compose, settings)
- [x] Implement 300ms debounce on URL change detection
- [x] Implement per-messageId result caching with background refresh
- [x] Create src/mail-sidebar/composables/useEmailLinks.js for API state management (loading, error, results)
- [x] Create src/mail-sidebar/api/emailLinks.js with axios wrappers for by-message, by-sender, and quick-link endpoints

## Frontend - UX

- [x] Implement collapse/expand toggle with animation and localStorage persistence
- [x] Implement client-side filtering to exclude already-linked objects from the suggested list
- [x] Implement link confirmation flow: search dialog -> select object -> POST quick-link -> refresh sidebar
- [x] Implement unlink confirmation dialog with bilingual text
- [x] Implement toast notifications for link/unlink success and error states
- [x] Implement empty state displays for both sections (linked and suggested)
- [x] Implement loading spinners during API calls
- [x] Implement error states with retry buttons for API failures and timeouts

## Styling

- [x] Create css/mail-sidebar.css with NL Design System compatible styles using Nextcloud CSS variables
- [x] Implement responsive layout: 320px panel on desktop, overlay on <1024px viewports
- [x] Ensure dark theme compatibility (no hardcoded colors)
- [x] Verify WCAG AA contrast ratios for all text elements

## Accessibility

- [x] Add role="complementary" and aria-label to sidebar container
- [x] Add aria-labels to all interactive elements (toggle, cards, buttons)
- [x] Implement keyboard navigation (Tab order through all interactive elements)
- [x] Add visible focus indicators using --color-primary outline
- [x] Test with screen reader (object card announcements, button labels)

## Internationalization

- [x] Add all translatable strings to l10n source files (en and nl)
- [x] Use t('openregister', ...) for all user-facing text in Vue components
- [x] Verify Dutch translations render correctly in sidebar

## Error Handling and Resilience

- [x] Handle API 500 errors with inline error message and retry button
- [x] Implement 10-second request timeout with abort controller
- [x] Handle missing mount point gracefully (log warning, skip injection, no exceptions)
- [x] Handle OpenRegister app disabled/uninstalled (catch errors, hide sidebar)
- [x] Ensure Mail app continues functioning normally when sidebar encounters any error

## Testing

- [x] Unit tests for EmailService reverse-lookup methods (findByMessageId, findObjectsBySender)
- [x] Unit tests for EmailsController new endpoints (byMessage, bySender, quickLink)
- [x] Unit tests for MailAppScriptListener conditional injection logic
- [x] Unit tests for URL parser (all Mail app route patterns)
- [x] Unit tests for result caching and deduplication logic
- [x] Integration test: link email from sidebar, verify appears in object's email tab
- [x] Integration test: unlink email from sidebar, verify removed from object's email tab
- [x] Integration test: Mail app functions normally with sidebar script injected
