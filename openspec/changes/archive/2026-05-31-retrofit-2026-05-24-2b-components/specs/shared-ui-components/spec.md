---
retrofit: true
---

# Shared UI Components Specification

**Status**: in-progress
**Scope**: openregister

## Purpose

Specifies the observable behaviour of OpenRegister's shared, reusable Vue components that are not tied to a specific register-data feature (search, i18n, audit, etc.). These components are consumed across many views — pagination controls on every list view, settings cards/sections across all settings panels, and the universal configuration card used by both imported and discovered configurations. This spec captures behaviour that already exists in `src/components/` so the coverage matcher can resolve `@spec` annotations on those methods. Future enhancements to these components — additional events, accessibility tightening, HTML-allowlist sanitisation — extend this capability via new REQs rather than reinventing parallel widgets.

## ADDED Requirements

### Requirement: REQ-001 — Pagination component MUST clamp page-change requests to the valid range

The reusable pagination component (`src/components/PaginationComponent.vue`) MUST validate every page-change request before emitting it to the parent. The component MUST NOT emit a `page-changed` event when the requested page equals the current page, is less than 1, or is greater than the total page count. Page-change requests originate from First/Previous/Next/Last buttons, ellipsis-aware numbered buttons, and any external caller that mounts the component.

#### Scenario: Out-of-range page request is rejected

- **GIVEN** a `PaginationComponent` mounted with `currentPage = 3` and `totalPages = 10`
- **WHEN** `changePage(0)` is called (e.g. underflow from a hand-crafted prop)
- **THEN** no `page-changed` event SHALL be emitted
- **AND** the parent's `currentPage` SHALL remain unchanged

#### Scenario: Same-page request is a no-op

- **GIVEN** the same component with `currentPage = 3`
- **WHEN** the user clicks the numbered button for page 3 (the active page)
- **THEN** no `page-changed` event SHALL be emitted
- **AND** the parent SHALL NOT receive a redundant request

#### Scenario: Valid page-change emits the new page number

- **GIVEN** the same component with `currentPage = 3` and `totalPages = 10`
- **WHEN** the user clicks "Next" (request for page 4)
- **THEN** the component SHALL emit `page-changed` with payload `4`
- **AND** the parent SHALL update its `currentPage` prop, re-rendering the component on page 4

### Requirement: REQ-002 — ConfigurationCard MUST detect already-imported discovered configurations via backend lookup

The universal `ConfigurationCard` (`src/components/cards/ConfigurationCard.vue`) renders both locally-imported configurations and remotely-discovered ones (from `ImportConfiguration` flows). For discovered configurations (those with a `config.app` field), the component MUST query the backend on mount to check whether a configuration with the same `app` identifier already exists locally. On a positive match, the card MUST switch from the "Discovered" presentation (Import button) to the "Imported" presentation (View/Edit/Export/Delete actions, status badges) without requiring the user to refresh the page or re-open the discover dialog.

#### Scenario: Discovered configuration is already imported

- **GIVEN** a discovered configuration with `config.app = "decidesk"` and a local configuration already exists with `app = "decidesk"`
- **WHEN** the card is mounted
- **THEN** the component SHALL call `GET /index.php/apps/openregister/api/configurations?app=decidesk`
- **AND** when the response contains a non-empty `results` array, the component SHALL store the first result's `id` in `importedConfigId`
- **AND** the rendered card SHALL show the "Local" or "External" badge (per `isLocalConfiguration`) instead of "Discovered"
- **AND** the action menu SHALL show View/Edit/Export/Delete instead of Import

#### Scenario: Discovered configuration is not imported

- **GIVEN** a discovered configuration whose `config.app` has no matching local row
- **WHEN** the card is mounted
- **THEN** the backend call returns an empty `results` array
- **AND** `importedConfigId` SHALL remain `null`
- **AND** the card SHALL render the "Discovered" badge and the Import action

#### Scenario: Backend lookup fails

- **GIVEN** a discovered configuration whose backend lookup throws (network error, 5xx response, malformed JSON)
- **WHEN** the card is mounted
- **THEN** the thrown error SHALL be caught
- **AND** `importedConfigId` SHALL be set to `null` (assume "not imported")
- **AND** the card SHALL render as Discovered with an Import action

### Requirement: REQ-003 — Collapsible settings card MUST toggle on header click and emit a toggle event

The `SettingsCard` component (`src/components/shared/SettingsCard.vue`) MAY be configured as collapsible via the `collapsible` prop. When collapsible, clicks on the header MUST toggle the section's expanded/collapsed state and MUST emit a `toggle` event carrying the new collapsed state (`true` = now collapsed, `false` = now expanded). The default expanded/collapsed state on mount is controlled by the `defaultCollapsed` prop. When `collapsible` is `false`, header clicks SHALL have no effect and no `toggle` event SHALL be emitted.

#### Scenario: Toggling a collapsible section

- **GIVEN** a `SettingsCard` rendered with `collapsible = true` and `defaultCollapsed = false`
- **WHEN** the user clicks the section header
- **THEN** the section's content SHALL collapse (transition-driven `v-show="!isCollapsed"`)
- **AND** the component SHALL emit `toggle` with payload `true`
- **WHEN** the user clicks the header again
- **THEN** the content SHALL re-expand
- **AND** the component SHALL emit `toggle` with payload `false`

#### Scenario: Non-collapsible card ignores header clicks

- **GIVEN** a `SettingsCard` rendered with `collapsible = false`
- **WHEN** the user clicks the header
- **THEN** no state change SHALL occur and no `toggle` event SHALL be emitted

### Requirement: REQ-004 — SettingsSection MUST escape HTML in detailed descriptions before rendering

The `SettingsSection` wrapper (`src/components/shared/SettingsSection.vue`) accepts a `detailedDescription` prop that is rendered via `v-html` inside the main description box. To prevent XSS, the component MUST escape the supplied string before rendering. The `sanitizeHtml` method MUST guarantee that no HTML tags or attributes from the input are interpreted by the browser as markup — the visible output SHALL be the input rendered as plain text (special characters replaced by their HTML entity equivalents).

#### Scenario: Script tag is escaped

- **GIVEN** a `SettingsSection` rendered with `detailedDescription = "<script>alert(1)</script>"`
- **WHEN** the component renders the description box
- **THEN** the DOM SHALL contain the text `&lt;script&gt;alert(1)&lt;/script&gt;` (or equivalent encoding)
- **AND** no `script` element SHALL be present in the rendered tree
- **AND** no JavaScript from the input SHALL execute

#### Scenario: Plain text passes through visibly unchanged

- **GIVEN** a `SettingsSection` with `detailedDescription = "Quotas are enforced per organisation."`
- **WHEN** the component renders
- **THEN** the description box SHALL display "Quotas are enforced per organisation." verbatim
- **AND** no characters SHALL be added or removed beyond the entity-escape pass

## Non-Functional Requirements

- **Accessibility:** Collapsible headers (REQ-003) and pagination buttons (REQ-001) MUST remain keyboard-operable; pagination buttons MUST receive a discernible name (the page label or "First"/"Previous"/"Next"/"Last"). WCAG 2.1 AA SC 2.1.1 and 4.1.2.
- **Security:** REQ-004 closes one XSS vector. The current implementation is a `textContent` round-trip, not an HTML-allowlist; richer formatting (bold, links) is out of scope.
- **Internationalisation:** Visible strings inside these components are translated via `t('openregister', ...)`; component-level behaviour does not vary by locale (ADR-007).

## Acceptance Criteria

- [ ] `@spec` annotations exist on every method listed in this spec's REQ map, pointing at `openspec/changes/retrofit-2026-05-24-2b-components/tasks.md#task-N`.
- [ ] `npm run lint -- src/components/PaginationComponent.vue src/components/cards/ConfigurationCard.vue src/components/shared/SettingsCard.vue src/components/shared/SettingsSection.vue` passes.
- [ ] Coverage scan re-run after archive resolves each method to its REQ (no longer in Bucket 2b).

## Notes

- `SettingsSection.sanitizeHtml` is the project's current "quick and dirty" sanitiser (per the inline comment); a follow-up should replace it with an allowlist-based sanitiser such as DOMPurify if richer description content is ever needed. Tracked as a TODO inside the method body — this retrofit does not enlarge scope.
- `ConfigurationCard.checkIfImported` silently swallows fetch errors and falls back to "not imported". This is the observed behaviour and is captured as such; surfacing the error to the user is out of scope for this retrofit.
- The pagination component already has a JSDoc docblock (REQ-001's implementation is well-commented); the coverage matcher flagged it only because no `@spec` link existed. Annotation closes that gap.
