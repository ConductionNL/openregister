# tenant-lifecycle Delta

## ADDED Requirements

### Requirement: An organisation's lifecycle is on the audit trail

Every change of an organisation's status through TenantLifecycleService (suspend, reactivate, deprovision, retain,
archive) SHALL write one sealed audit-trail entry with action `organisation.status_changed`, `object_uuid` the
organisation's uuid, the acting user, and `changed.status` holding the old and new status. An edit through
`PUT/PATCH /api/organisations/{uuid}` that changes the name, description or slug SHALL write one entry with action
`organisation.updated` holding the changed fields. A refused transition SHALL write nothing, and a failure to write the
entry SHALL NOT fail the change.

#### Scenario: Suspend an organisation

- **GIVEN** an active organisation
- **WHEN** an administrator suspends it
- **THEN** `GET /api/audit-trails?object_uuid={uuid}` lists an `organisation.status_changed` entry from active to
  suspended, with the administrator as its user
