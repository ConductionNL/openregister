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
