---
status: done
---

# shared-ui-components Specification

## Purpose
Provides reusable OpenRegister frontend components with well-defined contracts: a pagination component that clamps page-change requests to the valid range, a ConfigurationCard that detects already-imported discovered configurations via a backend lookup on mount, a collapsible settings card that emits its toggle state, and a settings section that escapes HTML in detailed descriptions to prevent XSS.

## Requirements
### Requirement: REQ-001 — Pagination component MUST clamp page-change requests to the valid range @e2e exclude isolated Vue component contract (PaginationComponent props-in / page-changed-event-out gating logic) — covered by Vitest component unit test, not a browser-observable app surface

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

### Requirement: REQ-002 — ConfigurationCard MUST detect already-imported discovered configurations via backend lookup @e2e exclude isolated Vue component contract (ConfigurationCard on-mount backend lookup + presentation switch) — covered by Vitest component unit test with mocked fetch; the configurations list surface itself is driven via manifest-shell.spec.ts

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

### Requirement: REQ-003 — Collapsible settings card MUST toggle on header click and emit a toggle event @e2e exclude isolated Vue component contract (SettingsCard collapsible state + toggle event) — covered by Vitest component unit test

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

### Requirement: REQ-004 — SettingsSection MUST escape HTML in detailed descriptions before rendering @e2e exclude isolated Vue component contract (SettingsSection sanitizeHtml escaping of v-html input) — XSS-escaping unit invariant covered by Vitest component unit test

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

