---
status: done
---

# integration-email Specification

## Purpose
Links existing Nextcloud Mail messages to OpenRegister objects, listing them by date in a sidebar tab and caching subject, sender, and date at link time so dashboards render without live Mail API calls. The integration links and unlinks only — it never composes, sends, or modifies the underlying message. Access inherits from object RBAC plus the user's own Mail account access.
## Requirements
### Requirement: Email Provider Registration

The system SHALL ship `EmailProvider` registered as DI-tagged `IntegrationProvider` with id `email`, group `comms`, requiredApp `mail`, storage `link-table`.

#### Scenario: Provider present when Mail is installed

- **GIVEN** NC Mail app installed
- **WHEN** `IntegrationRegistry::getEnabled()` is called
- **THEN** the result MUST include provider with `id='email'`, `group='comms'`, `storageStrategy='link-table'`

#### Scenario: Provider hidden when Mail is missing

- **GIVEN** NC Mail app not installed
- **WHEN** `IntegrationRegistry::getEnabled()` is called
- **THEN** the result MUST NOT include the email provider

---

### Requirement: Sidebar Tab — List and Link

The tab SHALL list linked emails by date descending and SHALL provide a "Link existing email" affordance. The tab SHALL NOT provide compose/send.

#### Scenario: Link an existing email via picker

- **GIVEN** the tab is open and the user has Mail accounts configured
- **WHEN** the user clicks "Link existing email", selects an account and folder, and picks a message
- **THEN** the system MUST POST to `/api/objects/{register}/{schema}/{id}/email` with `{mailAccountId, mailMessageId}`
- **AND** cached subject/sender/date MUST be populated from the Mail API at link time
- **AND** the email MUST appear in the tab list without reload

#### Scenario: Unlink preserves the email

- **WHEN** the user unlinks a message
- **THEN** the link record MUST be deleted
- **AND** the Mail message MUST NOT be touched

---

### Requirement: Widget Across Surfaces

`CnEmailCard` SHALL render on all four surfaces. Dashboard surfaces MUST use cached columns (no per-render Mail API call); detail/single-entity surfaces MAY fetch live.

#### Scenario: Dashboard surface uses cached fields

- **GIVEN** 50 linked emails on user dashboard
- **WHEN** `CnEmailCard` renders with `surface='user-dashboard'`
- **THEN** NO Mail API calls MUST be made — only link-table data used

#### Scenario: Single-entity surface renders chip

- **GIVEN** `entityId=<mailMessageId>`
- **WHEN** `CnEmailCard` renders with `surface='single-entity'`
- **THEN** a chip with subject + sender + date MUST be rendered

---

### Requirement: Reference-Property Auto-Rendering

Schema property `referenceType: 'email'` SHALL render `CnEmailCard` at `surface='single-entity'`.

#### Scenario: Detail grid renders email reference inline

- **GIVEN** schema `{originalRequest: { type: 'string', referenceType: 'email' }}` and object with `originalRequest: 'msg-id-42'`
- **WHEN** `CnDetailGrid` renders
- **THEN** the `originalRequest` row MUST contain `CnEmailCard` with `entityId='msg-id-42'`

---

### Requirement: Permission Inheritance

`EmailProvider::requiresPermission()` SHALL return `null`. Access inherits from object RBAC + Mail app access (user sees only emails in accounts they control).

#### Scenario: Provider declares no extra permission

- **WHEN** `EmailProvider::requiresPermission()` is called
- **THEN** the system MUST return `null` so access inherits from object RBAC and Mail app access

