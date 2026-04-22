# Design: Integration — Contacts

> Umbrella decisions apply.

## Approach

`ContactsProvider` wraps `ContactService`. Tab renders role-grouped (applicant, handler, advisor, other). `CnContactCard` at `single-entity` is the canonical "person chip" used across the whole app suite.

## Architecture Decisions

### AD-1: Role is a first-class field in the tab layout

**Decision**: Contacts in the tab are grouped by role with section headers ("Applicants", "Handlers", ...). Ungrouped "Other" for contacts without a role.

**Why**: Role is the primary question case handlers ask ("who is this person to this case?"). Grouping answers it at a glance; a flat list buries it.

**Trade-off**: More complex render. Payoff is high for case-management apps (Procest, ZaakAfhandelApp).

### AD-2: Single-entity widget is the canonical person chip

**Decision**: `CnContactCard` at `surface='single-entity'` is the preferred rendering for any schema property of kind "person reference" across ALL Conduction apps. The widget shows avatar + name + role context (if linked) + hover-expand for email/phone.

**Why**: Consistency across apps. Every app that has a "handler", "applicant", "advisor" property gets the same rich chip via `referenceType: 'contacts'` without implementing its own.

**Trade-off**: This widget will be high-traffic — must be performant and well-tested. Worth the investment.

### AD-3: Reverse lookup exposed in the tab

**Decision**: Clicking a contact in the tab opens a flyout showing all OR objects linked to that contact (across all schemas the user can see).

**Why**: The killer feature of the contacts integration. "Show me everything Jan de Vries is involved in" is the #1 asked-for case-management query.

**Trade-off**: Cross-schema query is non-trivial. Existing `ContactService::findObjectsForContact()` already solves it.

## Files Affected

### New files — Backend

| File | Purpose |
|---|---|
| `lib/Service/Integration/Providers/ContactsProvider.php` | Wraps `ContactService` |
| Unit test |

### Modified — Backend

| File | Change |
|---|---|
| `lib/AppInfo/Application.php` | DI-tag |

### New files — Frontend

| File | Purpose |
|---|---|
| `CnContactsTab/CnContactsTab.vue` | Role-grouped list + link/create + reverse-lookup flyout |
| `CnContactCard/CnContactCard.vue` | 4 surfaces, canonical person chip on `single-entity` |
| `src/integrations/builtin/contacts.js` | Registration |
| Barrels + tests |

## Risks

| Risk | Mitigation |
|---|---|
| Performance of `single-entity` widget at scale (detail grids with many person refs) | Shared reactive cache keyed by vCard UUID; one fetch per unique contact per page render |
| Role vocabulary drift across apps (procest calls it "behandelaar", Pipelinq "owner") | `role` field stays free-text; apps map to local vocabulary in display; tab grouping uses lowercase normalized roles |
| Contact without NC account | vCard supports external; widget shows avatar initials if no avatar URL |
