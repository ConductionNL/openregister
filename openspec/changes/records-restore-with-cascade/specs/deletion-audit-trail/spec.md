# deletion-audit-trail

## ADDED Requirements

### Requirement: A restore brings back what the delete cascaded, as one act

Restoring a soft-deleted object through `POST /api/deleted/{id}/restore` SHALL,
unless the caller sends `cascade: false`, also restore every object that the
same delete soft-deleted by cascade and that is still soft-deleted from that
delete, and SHALL put back references the delete cleared or defaulted on
surviving objects where the field still holds what the delete wrote. It SHALL
run as one transaction and SHALL record one restore entry for the root and one
per restored dependant naming the root.

#### Scenario: a lead comes back with its activities

- **GIVEN** a sales manager who deleted lead "Acme renewal", which cascaded to two activity objects and cleared the `lead` reference on one quote
- **WHEN** the manager calls `POST /api/deleted/{leadUuid}/restore` inside the recovery window
- **THEN** the response is 200 with `restored` 3 and `relinked` 1
- **AND** the lead, both activities and the quote's `lead` reference are visible again through the normal object API
- @e2e exclude {specified only; task 3.2 adds tests/e2e/ci/restore-with-cascade.spec.ts}

#### Scenario: a later edit is not overwritten

- **GIVEN** the same delete, after which a colleague set the second quote's `lead` to another lead
- **WHEN** the manager restores "Acme renewal"
- **THEN** the second quote keeps the colleague's value and the response lists it under `skipped.changed`
- @e2e exclude {specified only; task 3.2 adds tests/e2e/ci/restore-with-cascade.spec.ts}

#### Scenario: one object the caller may not restore stops the act

- **GIVEN** a cascade that took an activity in a schema where the manager has no `update`
- **WHEN** the manager restores the lead
- **THEN** the response is 403 naming one object in that schema, and nothing is restored
- @e2e exclude {specified only; task 2.3 adds DeletedControllerTest, task 3.2 adds tests/e2e/ci/restore-with-cascade.spec.ts}

### Requirement: The restore can be previewed

`GET /api/deleted/{id}/restore-preview` SHALL return, without writing, the
objects that would be restored, the references that would be put back, and the
items that would not, each with its reason: changed since, destroyed or out of
window, already restored, or not permitted.

#### Scenario: the manager sees what will come back

- **GIVEN** the deleted lead from above
- **WHEN** the manager opens "Restore with related records" on the Deleted page
- **THEN** the dialog lists two activities to restore, one quote to re-link and one quote changed since, before anything is written
- @e2e exclude {specified only; task 3.2 adds tests/e2e/ci/restore-with-cascade.spec.ts}

### Requirement: The cascade evidence is findable and outlives nothing it serves

Every audit entry written for a cascade delete, a cleared reference or a
defaulted reference SHALL carry the triggering object's uuid in an indexed
field, and entries for cleared or defaulted references SHALL NOT expire before
the triggering object's recovery window ends.

#### Scenario: a reference cleared on day one can be restored on day 60

- **GIVEN** a schema with a 90-day recovery window and a delete that cleared a reference 60 days ago
- **WHEN** the object is restored
- **THEN** the reference is put back, because its `set_null` audit entry has not expired
- @e2e exclude {specified only; task 1.2 adds the ReferentialIntegrityServiceTest case, task 3.2 adds tests/e2e/ci/restore-with-cascade.spec.ts}
