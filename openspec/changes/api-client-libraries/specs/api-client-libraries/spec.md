# api-client-libraries

## ADDED Requirements

### Requirement: Official client libraries exist for TypeScript and Python

Conduction SHALL publish an official Open Register client for TypeScript (`@conduction/openregister-client` on npm) and for Python (`openregister-client` on PyPI). Each SHALL cover the same platform surface: API versions and capabilities, object list, read, create, update, patch and delete, search, file list, upload and download on an object, and an object's audit log. Each SHALL be released from a tag with provenance and SHALL be licensed EUPL-1.2.

#### Scenario: a developer lists the objects of a schema

- **GIVEN** a developer with an app password for an instance that has register `zaken` and schema `zaak`
- **WHEN** they install `@conduction/openregister-client` and call `objects.list('zaken', 'zaak', { _limit: 10 })`
- **THEN** the client sends `GET /api/objects/zaken/zaak?_limit=10` with `API-Version: 1`
- **AND** it returns the objects with `total`, `page` and `pages`
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/api-client-libraries.spec.ts}

#### Scenario: the audit log can be read and never written

- **GIVEN** a developer using the Python client
- **WHEN** they call `client.audit.for_object('zaken', 'zaak', '00000000-0000-0000-0000-000000000000')`
- **THEN** the client sends `GET /api/objects/zaken/zaak/00000000-0000-0000-0000-000000000000/audit-trails`
- **AND** the library offers no method that updates or deletes an audit entry
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/api-client-libraries.spec.ts}

### Requirement: A library major speaks one contract version

Library major N SHALL send `API-Version: N` on every request. On first use it SHALL read `GET /api/versions` and SHALL refuse with a typed error naming the served versions when N is neither supported nor deprecated there. On a response carrying `Deprecation` it SHALL warn once per process with the `Sunset` date and the successor. On a 410 it SHALL raise a typed error carrying `successorVersion`.

#### Scenario: a developer is warned before the sunset

- **GIVEN** an instance that declares contract 1 deprecated with sunset 2027-06-30 and successor 2
- **WHEN** a developer's script on library 1.x makes three calls
- **THEN** each call succeeds
- **AND** the script receives one deprecation warning naming 2027-06-30 and version 2
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/api-client-libraries.spec.ts}

#### Scenario: a withdrawn contract names where to go

- **GIVEN** an instance that declares contract 1 withdrawn with successor 2
- **WHEN** a developer's service on library 1.x calls `objects.get('zaken', 'zaak', id)`
- **THEN** the library raises `ContractWithdrawn` with `successorVersion` `2`
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/api-client-libraries.spec.ts}

### Requirement: A developer generates typed models for one register

Each library SHALL offer a `generate` command that reads `GET /api/versions/{N}/oas?register={register}` and writes typed models for that register's schemas: TypeScript types, or pydantic models for Python. The generic object methods SHALL accept those types.

#### Scenario: a developer gets a compile error on a wrong field

- **GIVEN** a developer ran `npx @conduction/openregister-client generate --register zaken --out src/or-types.ts`
- **WHEN** they read `zaak.omschrijvingg` from the result of `objects.get<Zaak>('zaken', 'zaak', id)`
- **THEN** `tsc` reports that `omschrijvingg` does not exist on `Zaak`
- @e2e exclude {specified only; a compile-time check, task 2.2 adds the compile test in the library repository}

### Requirement: The caller record names the client library

Open Register SHALL record, in the caller record, the client library name and version when the request's `User-Agent` matches `^openregister-client-(ts|python)/\d+\.\d+\.\d+`, and SHALL record an empty value for any other `User-Agent`. An administrator SHALL read it through `GET /api/callers`.

#### Scenario: an administrator finds integrations on an old library

- **GIVEN** a leverancier's service calling with `User-Agent: openregister-client-ts/1.2.0`
- **WHEN** a functional administrator calls `GET /api/callers` for the last month
- **THEN** the response lists that principal with `client` `openregister-client-ts/1.2.0`, its routes and call counts
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/api-client-libraries.spec.ts}

#### Scenario: a browser does not become a client row

- **GIVEN** a caseworker whose browser sends a normal browser `User-Agent`
- **WHEN** their calls are recorded
- **THEN** the caller record stores an empty `client` for them
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/api-client-libraries.spec.ts}

### Requirement: Open Register's CI runs the published clients against every pull request

The `api-test-coverage` workflow SHALL install the latest published release of each official library for every contract major the booted instance serves and SHALL run the library's contract suite against it. A failing suite SHALL fail the workflow.

#### Scenario: a route rename breaks a published client

- **GIVEN** a pull request that renames `/api/objects/{register}/{schema}/{id}/audit-trails`
- **WHEN** the `api-test-coverage` workflow runs
- **THEN** the contract suite of `@conduction/openregister-client` fails on the audit call and the workflow is red
- @e2e exclude {a CI check, not a page; task 1.3 adds the step to .github/workflows/api-test-coverage.yml}
