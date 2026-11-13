# objects-crud

## ADDED Requirements

### Requirement: A write can create or update by a declared key in one call

`POST /api/objects/{register}/{schema}` SHALL accept `_upsertOn=<constraint>` naming a uniqueness constraint the schema declares with action `refuse`, including the legacy `unique` key named by its properties joined with `+`. Open Register SHALL read that constraint's property values from the body and match them under the caller's RBAC and organisation. With no match it SHALL create the record and answer 201. With one match it SHALL update that record with the body and answer 200. With more than one match it SHALL write nothing and answer 409 listing the matches.

#### Scenario: an integration creates then updates a case by its number

- **GIVEN** schema `zaak` in register `zaken` declares the `refuse` constraint `zaaksleutel` over `gemeentecode` and `zaaknummer`, and holds no record with `0363` and `Z-2026-0042`
- **WHEN** a signed-in integration posts `{"gemeentecode": "0363", "zaaknummer": "Z-2026-0042", "omschrijving": "Kapvergunning"}` to `POST /api/objects/zaken/zaak?_upsertOn=zaaksleutel`
- **THEN** the response is 201 with the new record
- **AND WHEN** it posts the same key with `"omschrijving": "Kapvergunning Dorpsstraat"`
- **THEN** the response is 200, the same uuid is returned, and the record carries the new `omschrijving`
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/upsert-on-key.spec.ts}

#### Scenario: a key that matches two records writes nothing

- **GIVEN** two records in `zaak` that both carry `0363` and `Z-2026-0001`, left from before the constraint was declared
- **WHEN** a signed-in integration posts that key to `POST /api/objects/zaken/zaak?_upsertOn=zaaksleutel`
- **THEN** the response is 409 listing both uuids
- **AND** neither record changes
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/upsert-on-key.spec.ts}

### Requirement: Only a declared refuse constraint can be the key

Open Register SHALL answer 400 when `_upsertOn` names no declared constraint, names a constraint with action `report`, or the body lacks a value for one of the constraint's properties. The answer SHALL name the problem and list the schema's `refuse` constraints. `_upsertOn` together with `_failIfExists` SHALL answer 400.

#### Scenario: an integration names a field instead of a constraint

- **GIVEN** schema `zaak` declares only the `refuse` constraint `zaaksleutel`
- **WHEN** a signed-in integration calls `POST /api/objects/zaken/zaak?_upsertOn=status`
- **THEN** the response is 400 with `refuseConstraints` `["zaaksleutel"]`
- **AND** no record is created or changed
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/upsert-on-key.spec.ts}

#### Scenario: a missing key value is named

- **GIVEN** the same schema
- **WHEN** a signed-in integration posts a body without `zaaknummer` to `POST /api/objects/zaken/zaak?_upsertOn=zaaksleutel`
- **THEN** the response is 400 naming `zaaknummer`
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/upsert-on-key.spec.ts}

### Requirement: An upsert never reveals or changes a record the caller cannot see

Open Register SHALL answer 401 to `_upsertOn` on an anonymous request. When the key is held by a record the caller cannot read, it SHALL answer 409 naming the constraint and SHALL NOT include that record's uuid. When the caller can read the matched record but may not change it, it SHALL answer 403. When the lookup cannot run, it SHALL answer 503 and write nothing.

#### Scenario: an anonymous form cannot overwrite a case by guessing its key

- **GIVEN** a public form that posts anonymously to `POST /api/objects/zaken/zaak`
- **WHEN** the request adds `?_upsertOn=zaaksleutel`
- **THEN** the response is 401 and no record is created or changed
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/upsert-on-key.spec.ts}

#### Scenario: a key held in another organisation is not named

- **GIVEN** a record with key `0363` and `Z-2026-0042` that belongs to an organisation the caller is not a member of
- **WHEN** a signed-in integration of another organisation posts that key with `_upsertOn=zaaksleutel`
- **THEN** the response is 409 naming the constraint `zaaksleutel`
- **AND** the body carries no uuid
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/upsert-on-key.spec.ts}

### Requirement: Concurrent upserts on one key produce one record

Open Register SHALL serialise upserts that carry the same register, schema, constraint and key values, so concurrent calls with one key create at most one record and update it for the rest. Upserts with different keys SHALL NOT wait on each other. A call SHALL wait only a short, bounded time for the key; a call that cannot have the key in that time SHALL answer 503 with a `Retry-After` header and SHALL write nothing, so a burst never holds a worker for long and a client that retries gets 200.

#### Scenario: twelve synchronisation workers send the same new case

- **GIVEN** no record carries key `0363` and `Z-2026-0099`
- **WHEN** twelve signed-in workers post that key with `_upsertOn=zaaksleutel` at the same moment
- **THEN** one response is 201 and every other response is 200, or 503 with a `Retry-After` header
- **AND** `zaak` holds exactly one record with that key

#### Scenario: a worker that cannot have the key in time is told when to retry

- **GIVEN** another call holds the key `0363` and `Z-2026-0099` for longer than the wait
- **WHEN** a signed-in worker posts that key with `_upsertOn=zaaksleutel`
- **THEN** the response is 503 with a `Retry-After` header
- **AND** nothing is written
- @e2e exclude {lock contention, not a page; covered by ObjectsControllerUpsertOnKeyTest::testAKeyAnotherCallHoldsIs503WithRetryAfter}
- @e2e exclude {concurrency, not a page; task 2.2 adds tests/Integration/UpsertOnKeyConcurrencyTest.php}
