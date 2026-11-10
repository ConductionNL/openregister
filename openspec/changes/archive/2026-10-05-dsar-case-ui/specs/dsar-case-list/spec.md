## ADDED Requirements

### Requirement: Case list surface on the existing AVG view
OpenRegister SHALL present a data-subject-request case LIST as an additional tab on the existing `src/views/avg/AvgIndex.vue` surface, not as a separate app or page.
The tab MUST be added to the existing `tabs`/`activeTab` structure and reuse the app's existing view/table styling and `NcEmptyContent` empty-state, so a handler reaches the case list from the same AVG entry point as the activities/verantwoording/dsar/compliance tabs. The list MUST render tracked cases from the Phase-1 case API (`/api/gdpr/cases`) via the extended `avg` store, MUST NOT introduce a bespoke store, and MUST NOT rewrite or remove the existing stateless DSAR tab.

#### Scenario: Handler opens the case list from the AVG surface
- **WHEN** a handler opens the AVG view and selects the Cases tab
- **THEN** the tracked data-subject-request cases MUST be listed in a table with per-case status, handler, and deadline state
- **AND** the existing activities/verantwoording/dsar/compliance tabs MUST remain available and unchanged

#### Scenario: Empty case list shows an empty-state, not an error
- **WHEN** no cases exist for the caller
- **THEN** the list MUST show an empty-state (not an error or blank table)

@e2e A handler opens the AVG view, selects the Cases tab, and sees the tracked cases listed (or a friendly empty-state when there are none), with the other AVG tabs still present.

### Requirement: Case list columns and wording resolve from the active policy pack
OpenRegister SHALL resolve the case-list column labels, status wording, and escalation-tier wording from the active `dsarPolicyPack`, not from strings hard-coded in the Vue component.
The list MUST read status/tier labels from the pack for the tenant (with an optional per-case jurisdiction override) so that changing a label in the pack changes the list wording without a component change. No jurisdiction-specific label string MUST be inlined in the view.

#### Scenario: Status and tier labels come from the pack
- **WHEN** the active policy pack defines status and escalation-tier labels
- **THEN** the case list MUST display those pack-supplied labels
- **AND** changing a label in the pack MUST change the list wording without a code change

#### Scenario: No jurisdiction wording is hard-coded in the view
- **WHEN** the case-list component is inspected
- **THEN** it MUST resolve status/ground/tier labels from the active pack
- **AND** it MUST NOT contain inlined jurisdiction-specific label strings

@e2e A steward changes a status label in the active policy pack and sees the case-list column wording reflect the new label without a redeploy.

### Requirement: Case list is filterable by status, handler, and overdue
OpenRegister SHALL let a handler filter the case list by status, by handler, and by an overdue toggle, driven by the Phase-1 deadline-tracking state.
Filtering by an overdue toggle MUST use the case's `isOverdue`/`escalationTier` deadline state, and the list MUST reflect the RBAC- and tenant-scoped case set — a case the caller cannot read MUST NOT appear. Every filter control that is a select MUST carry an accessible input label (WCAG AA).

#### Scenario: Overdue filter narrows to breached/overdue cases
- **WHEN** a handler enables the overdue filter
- **THEN** the list MUST show only cases whose effective deadline has passed (overdue/breached)
- **AND** clearing the filter MUST restore the full authorised set

#### Scenario: Status and handler filters narrow the list
- **WHEN** a handler selects a status and/or a handler filter
- **THEN** the list MUST show only cases matching the selected status/handler within the caller's authorised set

@e2e A handler filters the case list by an overdue toggle and by status, and the visible rows narrow to the matching authorised cases; clearing the filters restores the full list.
