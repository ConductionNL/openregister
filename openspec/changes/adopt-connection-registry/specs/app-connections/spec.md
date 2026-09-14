# app-connections Specification Delta

## ADDED Requirements

### Requirement: OpenRegister declares its outside connections in one static file (REQ-OR-CONN-001)

OpenRegister SHALL declare its outside connections in `lib/Settings/connections.json`
following hydra connection-registry design D2 and D12. The file MUST validate
against integriq's `connections.schema.json`, its `app` MUST equal the id in
`appinfo/info.xml`, and every key MUST be unique. A `settingsUrl` SHALL point
only at an element id that exists in `src/` or `templates/`. Per-record
connections (webhooks, OAuth2 connections, federated object sources) SHALL NOT
be declared.

#### Scenario: the declaration lists the sixteen connections
@e2e tests/e2e/connections-page.spec.ts

- **GIVEN** openregister and integriq are installed and integriq has synced
- **WHEN** an admin opens the Connections page
- **THEN** the page SHALL list the sixteen declared connections in declared order
- **AND** every row SHALL have `app` equal to `openregister`

#### Scenario: a settings link lands on a section that exists
@e2e tests/e2e/connections-page.spec.ts

- **GIVEN** the LLM, anonymiser, GitHub and GitLab rows carry a `settingsUrl`
- **WHEN** the admin follows one
- **THEN** the admin settings page SHALL hold an element with that id

#### Scenario: a seam nothing calls reads Not available
@e2e tests/e2e/connections-page.spec.ts

- **GIVEN** `NullNcOfficeConverter` is bound and nothing calls the converter
- **WHEN** the admin reads the office converter row
- **THEN** it SHALL read Not available with a message saying nothing calls it

#### Scenario: an unset LLM does not read Simulated
@e2e exclude The claim is a property of the declaration and the save report; tests/Unit/Settings/ConnectionsDeclarationTest.php asserts the llm entry has no adapter block and tests/Unit/Controller/Settings/LlmSettingsConnectionReportTest.php asserts the unconfigured report.

- **GIVEN** no chat and no embedding provider is chosen
- **WHEN** the admin saves the LLM settings
- **THEN** OpenRegister SHALL report `llm` as `unconfigured`
- **AND** the declaration SHALL NOT mark the LLM row as simulated

### Requirement: The Connections page lists OpenRegister's rows from integriq (REQ-OR-CONN-002)

OpenRegister SHALL render a Connections page as an `index` page over
`integriq/app_connection` at `/settings/connections`, admin only, with
`requiresApp` integriq. Its menu entry SHALL carry `query: {app: openregister}`
and `visibleIf.appInstalled: integriq`, and SHALL sit in the settings foldout so
the main menu does not grow. The page SHALL NOT offer the generic Add button. Its
Add integration header action SHALL open
`/apps/integriq/connections?app=openregister&link=1`. The existing Integrations
page (`integrationsView`) SHALL keep its id, route and component.

#### Scenario: the page opens on OpenRegister's own rows
@e2e tests/e2e/connections-page.spec.ts

- **GIVEN** integriq holds rows for openregister and other apps
- **WHEN** an admin opens Connections from the settings foldout
- **THEN** only rows with `app` equal to `openregister` SHALL be listed
- **AND** each status SHALL render as Configured, Limited, Not configured, Simulated, Not available or Error

#### Scenario: Add integration goes to integriq
@e2e tests/e2e/connections-page.spec.ts

- **GIVEN** the Connections page
- **WHEN** the admin chooses Add integration
- **THEN** the browser SHALL open integriq's Connections overview with `app=openregister` and `link=1`
- **AND** no generic Add button SHALL be offered

#### Scenario: without integriq the page says what is missing
@e2e exclude The CI instance installs integriq, so no browser flow reaches an openregister without it; src/tests/connections-page.spec.js asserts the requiresApp and visibleIf declarations.

- **GIVEN** integriq is not installed
- **WHEN** an admin opens `/settings/connections` by URL
- **THEN** the missing-app screen SHALL name Integriq
- **AND** the settings foldout SHALL NOT list Connections

### Requirement: OpenRegister reports what only it can observe (REQ-OR-CONN-003)

OpenRegister SHALL send `OCA\Integriq\Event\ConnectionStatusReportedEvent` for
a connection whose state it observes, and
`OCA\Integriq\Event\ConnectionRefreshRequestedEvent` when a settings save
writes a declared config key. Both classes SHALL be named by string and sent
only when the class exists. A report SHALL NOT change the response of the
request that sent it, whether integriq is absent or its listener fails. No
report SHALL be sent on every request (ADR-076).

#### Scenario: a failed e-Depot test reaches the row
@e2e exclude An e-Depot endpoint cannot be stood up from the browser suite; tests/Unit/Service/Connection/ConnectionReporterTest.php asserts the event and tests/Unit/Controller/Settings/EdepotSettingsConnectionReportTest.php asserts the controller sends it.

- **GIVEN** the e-Depot transport points at an endpoint that does not answer
- **WHEN** the admin runs the e-Depot connection test
- **THEN** OpenRegister SHALL report `edepot` as `error` naming the transport

#### Scenario: saving a GitHub token asks integriq to look again
@e2e tests/e2e/connections-page.spec.ts

- **GIVEN** the GitHub row reads Not configured
- **WHEN** the admin saves a GitHub token
- **THEN** OpenRegister SHALL send a refresh for `github` and no status of its own
- **AND** the GitHub row SHALL read Configured on the next page load

#### Scenario: without integriq nothing is sent
@e2e exclude The CI instance installs integriq; tests/Unit/Service/Connection/ConnectionReporterTest.php asserts nothing is dispatched or logged when the event class is absent.

- **GIVEN** integriq is not installed
- **WHEN** the admin saves the LLM settings
- **THEN** no event SHALL be sent and no warning SHALL be logged
- **AND** the save response SHALL be unchanged

#### Scenario: a failing listener never reaches the request
@e2e exclude A throwing listener cannot be installed from a browser; tests/Unit/Service/Connection/ConnectionReporterTest.php asserts the exception is caught and logged.

- **GIVEN** integriq's report listener throws
- **WHEN** the admin runs the OpenAnonymiser connection test
- **THEN** the test SHALL answer as it would without the report
- **AND** the failure SHALL be logged as a warning naming the connection

### Requirement: The DI seams report what is bound, off the request path (REQ-OR-CONN-004)

A background job SHALL report the translation provider, the DSAR identity
seam and the DSAR regulator seam at most every six hours. A seam bound to its
stand-in SHALL be reported as `simulated` with a message that says what the
stand-in does. The DSAR stand-ins SHALL be described as refusing, never as
pretending to succeed.

#### Scenario: the identity translation provider reads Simulated
@e2e exclude The job runs from cron, which the browser suite does not drive; tests/Unit/BackgroundJob/ConnectionSeamReportJobTest.php asserts the report.

- **GIVEN** `IdentityTranslationProvider` is bound
- **WHEN** the seam job runs
- **THEN** OpenRegister SHALL report `translation` as `simulated`
- **AND** the message SHALL say translations return the source text unchanged

#### Scenario: the DSAR stand-ins read Simulated and say they refuse
@e2e exclude The job runs from cron; tests/Unit/BackgroundJob/ConnectionSeamReportJobTest.php asserts both reports.

- **GIVEN** only the fail-closed default providers are registered
- **WHEN** the seam job runs
- **THEN** `dsar-identity` and `dsar-regulator` SHALL be reported as `simulated`
- **AND** each message SHALL say the default refuses

#### Scenario: a registered DSAR provider reads Configured
@e2e exclude The job runs from cron; tests/Unit/BackgroundJob/ConnectionSeamReportJobTest.php asserts the report.

- **GIVEN** a leaf app registered an identity provider beside the default
- **WHEN** the seam job runs
- **THEN** `dsar-identity` SHALL be reported as `configured` naming the registered provider
