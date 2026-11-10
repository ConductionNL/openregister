# objects-crud

## ADDED Requirements

### Requirement: A schema may declare one identity over a combination of fields

A named uniqueness constraint with action `refuse` MAY carry `identity: true`. A
schema SHALL have at most one identity constraint, and the system MUST refuse at
schema save an identity on a `report` constraint or a second identity.

#### Scenario: A functional administrator declares a composite key

- **GIVEN** a functional administrator editing the schema `zaken`
- **WHEN** they save the constraint `zaaksleutel` over `gemeentecode` and `zaaknummer` with action `refuse` and `identity: true`
- **THEN** `GET /api/schemas/{id}` returns the constraint with `identity: true`
- @e2e exclude {specified only; task 4.3 adds tests/e2e/object-by-key.spec.ts}

#### Scenario: A report constraint cannot be the identity

- **GIVEN** a schema with a constraint whose action is `report`
- **WHEN** an administrator sets `identity: true` on it and saves
- **THEN** the response is 400 and names the constraint
- @e2e exclude {specified only; task 1.1 adds the evaluator unit test}

### Requirement: An object can be addressed by its key

The system SHALL answer `GET`, `PUT`, `PATCH` and `DELETE` on
`/api/objects/{register}/{schema}/by-key` with the identity properties as query
parameters, acting on the one object whose identity values match. It MUST answer
400 when a property is missing, 404 when no readable object matches, and 409 with
the matching uuids when more than one does. RBAC and multitenancy MUST apply as on
`objects#show`.

#### Scenario: An outside system fetches a case by its number

- **GIVEN** a case in schema `zaken` with `gemeentecode` 0363 and `zaaknummer` Z-2026-0042
- **WHEN** a synchronisation client with read access requests `GET /api/objects/zaken-register/zaken/by-key?gemeentecode=0363&zaaknummer=Z-2026-0042`
- **THEN** the response is 200 with the same body `objects#show` returns for that case
- **AND** the body carries `@self.key` `0363:Z-2026-0042`
- @e2e exclude {specified only; task 4.3 adds tests/e2e/object-by-key.spec.ts}

#### Scenario: A caller who may not read the case gets not found

- **GIVEN** the same case and a user without read access to it
- **WHEN** the user requests the same by-key URL
- **THEN** the response is 404
- @e2e exclude {specified only; task 2.2 adds the API test}

#### Scenario: A key that matches two legacy records is refused

- **GIVEN** two objects from before the identity was declared with the same `gemeentecode` and `zaaknummer`
- **WHEN** a client sends `PATCH` to the by-key URL
- **THEN** the response is 409 and lists both uuids
- **AND** neither object is changed
- @e2e exclude {specified only; task 2.2 adds the API test}

### Requirement: Identity values do not change after creation

The system MUST refuse, with 422 naming the property, a write that changes an
identity property of an existing object. Only the audited schema migration path
SHALL rewrite identity values.

#### Scenario: A caseworker cannot renumber a case by editing it

- **GIVEN** an existing case with `zaaknummer` Z-2026-0042
- **WHEN** a caseworker sends `PATCH /api/objects/zaken-register/zaken/{id}` with `zaaknummer` Z-2026-0043
- **THEN** the response is 422 and names `zaaknummer`
- **AND** the case keeps Z-2026-0042
- @e2e exclude {specified only; task 3.2 adds the API test}
