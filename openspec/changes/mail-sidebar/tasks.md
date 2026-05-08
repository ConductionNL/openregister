# Tasks: Mail Sidebar

## Backend API

- [ ] Add `findByMessageId(int $accountId, int $messageId)` method to EmailService that queries openregister_email_links and resolves object/register/schema metadata
- [ ] Add `findObjectsBySender(string $sender)` method to EmailService with GROUP BY object_uuid and COUNT for linkedEmailCount
- [ ] Add `quickLink(array $params)` method to EmailService that creates an email link from the sidebar's email-side perspective
- [ ] Add `byMessage(int $accountId, int $messageId)` endpoint to EmailsController returning linked objects with register/schema titles
- [ ] Add `bySender(string $sender)` endpoint to EmailsController returning discovered objects with email counts
- [ ] Add `quickLink()` POST endpoint to EmailsController for sidebar-initiated linking
- [ ] Add reverse-lookup and quick-link routes to appinfo/routes.php
- [ ] Add input validation for accountId, messageId (numeric), and sender (email format) parameters

## Script Injection

- [ ] Create MailAppScriptListener.php that listens for BeforeTemplateRenderedEvent from the Mail app
- [ ] Implement conditional injection: check Mail app enabled AND user has OpenRegister access
- [ ] Register MailAppScriptListener in Application.php with IEventDispatcher
- [ ] Add openregister-mail-sidebar script registration via OCP\Util::addScript()

## Webpack Build

- [ ] Add mail-sidebar entry point to webpack.config.js pointing to src/mail-sidebar.js
- [ ] Create src/mail-sidebar.js entry point that mounts the sidebar Vue component
- [ ] Configure webpack externals to use Nextcloud's shared Vue and axios
- [ ] Verify separate bundle output (js/openregister-mail-sidebar.js) does not include main app code

## Frontend - Core Components

- [ ] Create src/mail-sidebar/MailSidebar.vue root component with collapsible panel layout
- [ ] Create src/mail-sidebar/components/LinkedObjectsList.vue for explicitly linked objects
- [ ] Create src/mail-sidebar/components/SuggestedObjectsList.vue for sender-based discovery results
- [ ] Create src/mail-sidebar/components/ObjectCard.vue with title, schema, register, deep link, and unlink button
- [ ] Create src/mail-sidebar/components/LinkObjectDialog.vue modal with search input and results list

## Frontend - Composables and API

- [ ] Create src/mail-sidebar/composables/useMailObserver.js to observe Mail app URL changes and extract accountId/messageId
- [ ] Implement URL parsing for all Mail app route patterns (inbox, sent, drafts, custom folders, compose, settings)
- [ ] Implement 300ms debounce on URL change detection
- [ ] Implement per-messageId result caching with background refresh
- [ ] Create src/mail-sidebar/composables/useEmailLinks.js for API state management (loading, error, results)
- [ ] Create src/mail-sidebar/api/emailLinks.js with axios wrappers for by-message, by-sender, and quick-link endpoints

## Frontend - UX

- [ ] Implement collapse/expand toggle with animation and localStorage persistence
- [ ] Implement client-side filtering to exclude already-linked objects from the suggested list
- [ ] Implement link confirmation flow: search dialog -> select object -> POST quick-link -> refresh sidebar
- [ ] Implement unlink confirmation dialog with bilingual text
- [ ] Implement toast notifications for link/unlink success and error states
- [ ] Implement empty state displays for both sections (linked and suggested)
- [ ] Implement loading spinners during API calls
- [ ] Implement error states with retry buttons for API failures and timeouts

## Styling

- [ ] Create css/mail-sidebar.css with NL Design System compatible styles using Nextcloud CSS variables
- [ ] Implement responsive layout: 320px panel on desktop, overlay on <1024px viewports
- [ ] Ensure dark theme compatibility (no hardcoded colors)
- [ ] Verify WCAG AA contrast ratios for all text elements

## Accessibility

- [ ] Add role="complementary" and aria-label to sidebar container
- [ ] Add aria-labels to all interactive elements (toggle, cards, buttons)
- [ ] Implement keyboard navigation (Tab order through all interactive elements)
- [ ] Add visible focus indicators using --color-primary outline
- [ ] Test with screen reader (object card announcements, button labels)

## Internationalization

- [ ] Add all translatable strings to l10n source files (en and nl)
- [ ] Use t('openregister', ...) for all user-facing text in Vue components
- [ ] Verify Dutch translations render correctly in sidebar

## Error Handling and Resilience

- [ ] Handle API 500 errors with inline error message and retry button
- [ ] Implement 10-second request timeout with abort controller
- [ ] Handle missing mount point gracefully (log warning, skip injection, no exceptions)
- [ ] Handle OpenRegister app disabled/uninstalled (catch errors, hide sidebar)
- [ ] Ensure Mail app continues functioning normally when sidebar encounters any error

## Testing

- [ ] Unit tests for EmailService reverse-lookup methods (findByMessageId, findObjectsBySender)
- [ ] Unit tests for EmailsController new endpoints (byMessage, bySender, quickLink)
- [ ] Unit tests for MailAppScriptListener conditional injection logic
- [ ] Unit tests for URL parser (all Mail app route patterns)
- [ ] Unit tests for result caching and deduplication logic
- [ ] Integration test: link email from sidebar, verify appears in object's email tab
- [ ] Integration test: unlink email from sidebar, verify removed from object's email tab
- [ ] Integration test: Mail app functions normally with sidebar script injected
