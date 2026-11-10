# enhanced-audit-trail (delta)

## ADDED Requirements

### Requirement: The audit trail page browses, filters and exports entries (REQ-ADS-001)

OpenRegister SHALL offer an audit trail page listing entries newest first with the action, the object, the register and schema, the user and the time, paginated server-side. A sidebar SHALL filter the list by register, schema, action, user and a date range, and SHALL offer to show only entries with changes. An export action SHALL download the entries that match the current filters, in CSV, JSON, XML or TXT, through `GET /api/audit-trails/export`. Opening an entry SHALL show its full record including the changed values before and after. The page and the export SHALL return only entries the caller may read.

#### Scenario: An administrator filters on one object's changes
- **GIVEN** the audit trail page with entries for several schemas
- **WHEN** the administrator picks schema `melding`, action update and a date range in the sidebar
- **THEN** only update entries for `melding` in that range are listed
- @e2e exclude {task 2.1 adds tests/e2e/ci/audit-trail-page.spec.ts}

#### Scenario: The export follows the filters
- **GIVEN** the same filters set
- **WHEN** the administrator exports as CSV
- **THEN** the file holds exactly the filtered entries
- @e2e exclude {task 2.1}

#### Scenario: An entry shows what changed
- **GIVEN** an update entry where `status` went from `nieuw` to `in behandeling`
- **WHEN** the administrator opens the entry
- **THEN** the old and the new value of `status` are shown
- @e2e exclude {task 2.1}
