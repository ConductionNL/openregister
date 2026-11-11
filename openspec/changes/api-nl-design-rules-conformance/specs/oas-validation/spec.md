# oas-validation

## ADDED Requirements

### Requirement: Every rule of the NLGov API design rules is reported

Open Register SHALL keep a catalogue of all 34 rules of NLGov REST API Design Rules 2.2.1, each with its id, title and kind (`document`, `response`, `functional` or `module`). The conformance report SHALL list every rule in the catalogue with exactly one result: `pass`, `fail`, `deviation`, `manual` or `not-probed`. A rule SHALL read `pass` only when a check ran and found nothing. A functional or module rule SHALL read `manual`. A response rule that has not been probed SHALL read `not-probed`.

#### Scenario: an integrator reads the full report

- **GIVEN** a register `zaken` with two schemas
- **WHEN** an anonymous integrator calls `GET /api/registers/zaken/oas/conformance`
- **THEN** the response is 200 with `ruleset` `2.2.1` and 34 entries in `rules`
- **AND** every entry has one of the results `pass`, `fail`, `deviation`, `manual` or `not-probed`
- **AND** `counts` adds up to 34
- @e2e exclude {specified only; task 7.2 adds tests/e2e/ci/nl-api-design-rules.spec.ts}

#### Scenario: a rule nobody probed does not read as passed

- **GIVEN** no administrator has probed register `zaken`
- **WHEN** an integrator calls `GET /api/registers/zaken/oas/conformance`
- **THEN** `/core/version-header`, `/core/transport/security-headers` and `/core/date-time/timezone` read `not-probed`
- **AND** none of them is counted under `pass`
- @e2e exclude {specified only; task 7.2 adds tests/e2e/ci/nl-api-design-rules.spec.ts}

### Requirement: Document rules are checked on the generated document

The OAS generator SHALL check every `document` rule of the catalogue during validation and SHALL record each finding with the rule id it breaks, the JSON path it found, and a message. The existing issue codes in the validation report SHALL stay, so a consumer of `x-validation-summary` keeps working.

#### Scenario: a version that is not semantic fails the semver rule

- **GIVEN** a generated document whose `info.version` is `1.0`
- **WHEN** an integrator calls `GET /api/registers/zaken/oas/conformance`
- **THEN** `/core/semver` reads `fail`
- **AND** its finding names the path `info.version` and the value `1.0`
- @e2e exclude {specified only; task 7.2 adds tests/e2e/ci/nl-api-design-rules.spec.ts}

#### Scenario: a trailing slash is named with its path

- **GIVEN** a schema whose extended path is documented as `/objects/zaken/meldingen/`
- **WHEN** the document is generated with `GET /api/registers/zaken/oas?validate=true`
- **THEN** `x-validation-summary.issues` holds an issue with code `nlgov_rule`, rule `/core/no-trailing-slash` and path `paths./objects/zaken/meldingen/`
- @e2e exclude {specified only; task 7.2 adds tests/e2e/ci/nl-api-design-rules.spec.ts}

### Requirement: The generated document carries the NLGov marker

The generated OpenAPI document SHALL carry a root extension `x-nl-api-design-rules` with the ruleset version and the ids of the document and functional rules under `pass`, `fail`, `deviation` and `manual`. The marker SHALL agree with the report for every rule it lists. It SHALL NOT carry probe results, so the document's ETag changes only when the document changes.

#### Scenario: a tender assessor finds the marker in the document

- **GIVEN** a register `zaken`
- **WHEN** a tender assessor calls `GET /api/registers/zaken/oas`
- **THEN** the response is 200 and the body has `x-nl-api-design-rules.version` `2.2.1`
- **AND** `/core/http-methods` is listed under `pass`
- @e2e exclude {specified only; task 7.2 adds tests/e2e/ci/nl-api-design-rules.spec.ts}

### Requirement: A deliberate deviation is named and never passed

A rule that Open Register breaks on purpose SHALL be declared as a deviation in the rule catalogue with a reason. The report and the marker SHALL show it under `deviation` with that reason. No setting or API call SHALL add a deviation or turn a deviation into a pass.

#### Scenario: the missing version segment is a deviation with its reason

- **GIVEN** the server URL of the generated document has no `/v{major}` segment
- **WHEN** an integrator calls `GET /api/registers/zaken/oas/conformance`
- **THEN** `/core/uri-version` reads `deviation`
- **AND** its reason says the fleet URL pattern carries no version and the version is negotiated through the `API-Version` header
- @e2e exclude {specified only; task 7.2 adds tests/e2e/ci/nl-api-design-rules.spec.ts}

### Requirement: An administrator probes the response rules

A functional administrator SHALL be able to probe the response rules against the live instance through `POST /api/registers/{id}/oas/conformance/probe` or the occ command `openregister:api:conformance {register} --probe`. A probe SHALL send at most five requests, each with a 10 second timeout, as the calling administrator. The report SHALL show each probed rule's result with the time of the probe. A user who is not an administrator SHALL be refused.

#### Scenario: an administrator sees the version header rule fail on a major-only value

- **GIVEN** the instance answers with `API-Version: 1`
- **WHEN** a functional administrator opens the register list, chooses "Check Dutch API design rules" on `zaken` and presses "Probe responses"
- **THEN** the dialog shows `/core/version-header` as `fail` with the finding that `1` is not a full version
- **AND** a later `GET /api/registers/zaken/oas/conformance` returns the same result with the probe time
- @e2e exclude {specified only; task 7.2 adds tests/e2e/ci/nl-api-design-rules.spec.ts}

#### Scenario: a caseworker cannot run the probe

- **GIVEN** a signed-in caseworker who is not an administrator
- **WHEN** they call `POST /api/registers/zaken/oas/conformance/probe`
- **THEN** the response is 403 and no request is sent to the instance
- @e2e exclude {specified only; task 7.2 adds tests/e2e/ci/nl-api-design-rules.spec.ts}

### Requirement: CI lints the generated document with the official ruleset

The repository SHALL pin a copy of the official NLGov Spectral ruleset and SHALL lint a document generated by a running instance with it on every pull request to `development`. Declared deviations SHALL be the only rules turned off, each by name with a comment naming its reason.

#### Scenario: a developer adds a path with a trailing slash

- **GIVEN** a pull request that makes the generator emit `/objects/zaken/meldingen/`
- **WHEN** the `api-test-coverage` workflow runs
- **THEN** the Spectral lint step fails naming `/core/no-trailing-slash`
- @e2e exclude {a CI lint, not a page; task 6.1 adds the step to .github/workflows/api-test-coverage.yml}
