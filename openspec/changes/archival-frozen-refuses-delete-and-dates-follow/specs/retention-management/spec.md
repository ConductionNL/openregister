# retention-management

## ADDED Requirements

### Requirement: A frozen record cannot be deleted

OpenRegister SHALL refuse to delete an object that carries the `@self.frozen`
marker, on every delete path that dispatches the object deleting event,
including bulk and cascade deletes, with a message naming who froze it and
when. Unfreezing it SHALL make it deletable again.

#### Scenario: an account manager's locked client stays

- **GIVEN** an account manager who froze client "Bakkerij Jansen" through `POST /api/objects/pipelinq/client/{id}/freeze`
- **WHEN** a colleague calls `DELETE /api/objects/pipelinq/client/{id}`
- **THEN** the delete is refused with a message naming the account manager and the date
- **AND** after `DELETE .../{id}/freeze`, the same delete succeeds
- @e2e exclude {specified only; task 3.1 adds the Newman case}

### Requirement: A schema teardown refuses kept rows unless forced on the command line

`SchemaDeletionService::cascadeDeleteSchema()`, reached by
`DELETE /api/schemas/{id}?deleteObjects=true` and
`occ openregister:schemas:prune-retired`, SHALL read every row of the schema's
magic tables first and SHALL refuse before any row is touched when a row is
frozen, archived or locked, whoever holds the lock. A magic table that outlives
its register and still holds rows SHALL refuse too, because nothing can say its
rows are not frozen. The HTTP route SHALL answer 409 for a frozen or archived
row and for such a table, and 423 for a locked row, and SHALL offer no override.

An admin SHALL lift the refusal only with
`occ openregister:schemas:prune-retired --force-kept-rows`, which also needs
`--force` and `--apply`. `--force` and `--force-archival` SHALL NOT lift it. The
command SHALL report kept rows before it unlinks the schema from a register.

A forced teardown that removes kept rows, or the objects of an archival schema,
SHALL write one `schema.teardown_forced` audit entry inside the teardown's
transaction, naming the schema, the number of kept rows and whether the schema
is archival, with `occ` as the actor. The per-object `schema.cascade_delete`
entries SHALL stay as they are.

#### Scenario: a frozen row stops the HTTP teardown

- **GIVEN** schema `client` with three rows, one of them frozen by an account manager
- **WHEN** an admin calls `DELETE /api/schemas/{id}?deleteObjects=true`
- **THEN** the response is 409 naming the account manager and the date
- **AND** no row, table or audit entry changes, and the schema stays
- @e2e exclude {needs a schema teardown on a live instance; covered by SchemaTeardownKeptRowsTest and SchemasDestroySafetyTest}

#### Scenario: the prune command skips a schema holding kept rows

- **GIVEN** retired schema `document` of app `filinq` with a locked row
- **WHEN** an admin runs `occ openregister:schemas:prune-retired --app filinq --slug document --apply --force`
- **THEN** the command skips it and names `--force-kept-rows`
- **AND** every register still lists the schema
- @e2e exclude {occ command; covered by PruneRetiredSchemasArchivalTest}

#### Scenario: a forced prune leaves an audit entry

- **WHEN** the admin runs the same command with `--force-kept-rows`
- **THEN** the schema and its rows are removed
- **AND** the audit trail holds one `schema.teardown_forced` entry with `keptRows: 1` and actor `occ`
- @e2e exclude {occ command; covered by SchemaTeardownKeptRowsTest}

### Requirement: The destruction date follows its source date under every method

On every save, OpenRegister SHALL recalculate the destruction date of an
object whose schema archives it when the source of the date changed: the
`sourceDateProperty` under `ander_datumkenmerk`, the related record under the
relation methods, and the existing sources under the other methods. A date
that appears after creation SHALL set the destruction date, and a date that is
cleared SHALL clear it. When a record's date that other records read through a
relation changes, those records SHALL be recalculated.

#### Scenario: a client becomes inactive after it was created

- **GIVEN** schema `client` with an `archive` block using `afleidingswijze: ander_datumkenmerk`, `sourceDateProperty: relationshipEndedAt` and `defaultBewaartermijn: P2Y`, and a client created without `relationshipEndedAt`
- **WHEN** an account manager sets `relationshipEndedAt` to 2026-10-01
- **THEN** the client's destruction date is 2028-10-01
- **AND** when the date is cleared again, the destruction date is removed and the audit trail says so
- @e2e exclude {specified only; task 3.2 adds the Newman case}

#### Scenario: a contact person follows its client

- **GIVEN** schema `contact` using a relation method with `sourceRelation: client` and `sourceRelationProperty: relationshipEndedAt`, and two contacts of the client above
- **WHEN** the account manager sets the client's `relationshipEndedAt`
- **THEN** both contacts get the same destruction date after the background job runs
- @e2e exclude {specified only; covered by RelatedRetentionRecalculationJobTest in task 2.2}
