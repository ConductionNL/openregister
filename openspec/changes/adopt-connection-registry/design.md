# Design: adopt-connection-registry

The contract is hydra `openspec/changes/connection-registry/design.md`. This
file records how OpenRegister meets it and where it does not fit.

## D1. The declaration

Sixteen connections, each verified against the code on `development`
(2026-09-14).

| Key | What it is | How its status is known |
|---|---|---|
| `llm` | Chat and embedding provider in the `llm` JSON blob | Report on LLM settings save |
| `anonymiser` | OpenAnonymiser ExApp through AppAPI | Report from its connection test |
| `translation` | `TranslationProviderInterface`, bound to `IdentityTranslationProvider` | `reportedOnly`, report from the seam job |
| `dsar-identity` | `IdentityVerifyRegistry`, default `NullIdentityVerifyProvider` | `reportedOnly`, report from the seam job |
| `dsar-regulator` | `RegulatorEscalateRegistry`, default `NullRegulatorEscalateProvider` | `reportedOnly`, report from the seam job |
| `edepot` | e-Depot transfer over REST, SFTP or Integriq | Report from its connection test |
| `github` | Configuration import and publish on GitHub | `requiredConfig` `github_api_token`, report from the token test |
| `gitlab` | Configuration import on GitLab | `requiredConfig` `gitlab_api_token`, report from the token test |
| `brp` | `BrpPersonProvider`, source `brp-haalcentraal` | Integriq probe of a linked source |
| `kvk` | `KvkProvider`, source `kvk` | Integriq probe |
| `opencorporates` | `OpenCorporatesProvider`, source `opencorporates` | Integriq probe |
| `openproject` | `OpenProjectProvider`, source `openproject` | Integriq probe |
| `xwiki` | `XwikiProvider`, source `xwiki` | Integriq probe |
| `message-dispatch` | `MessageDispatchProvider`, SMS and WhatsApp sources | Integriq probe |
| `pdok` | `PdokGeocoder`, the public Locatieserver through Integriq's CallService | Not checked; the message says so |
| `office-converter` | `NcOfficeConverterInterface`, bound to `NullNcOfficeConverter` | `available: false` |

- `sourceTemplate` is set where integriq ships `register.d/<slug>-source.json`
  on `development`: `brp-haalcentraal`, `kvk`, `opencorporates`, `xwiki` and
  `whatsapp-cloud-api`. OpenProject has no template, so the field is omitted.
- `settingsUrl` points only at an element that exists. `#api-tokens` already
  existed. `#section-llm` and `#section-text-extraction` are added to the two
  section roots by this change. The provider rows carry no link: their setting
  is the source in integriq, which Add integration opens.
- `message-dispatch`, not `whatsapp`. The provider sends through five sources,
  three of them SMS, so the key follows the provider id.

## D2. The LLM row does not use `jsonPath`

The audit suggested `adapter.configKey: llm`, `jsonPath: chatProvider` and
`simulatedValues` for the unset value. That would be false. With no chat
provider chosen, `ResponseGenerationHandler` throws a 503 and
`ChatHealthController` answers `no_provider`. No mock answers, so the row must
not read Simulated.

The contract has no declaration that reads a JSON path and yields
`unconfigured`. So the row carries no adapter block, and
`LlmSettingsController::updateLLMSettings` reports what it saved:

- no chat and no embedding provider: `unconfigured`, saying chat answers 503;
- one of the two: `limited`, naming the one chosen and the one missing;
- both: `configured`, naming both providers and saying it is not tested.

Until the first save the row reads its `unconfiguredMessage`.

## D3. The DI seams report from a job

A DI binding is read by resolving the service. Doing that at boot would cost
every request (ADR-076), and a repair step runs before integriq has synced the
rows, so its report would be refused as undeclared.

`ConnectionSeamReportJob` is a `TimedJob` every six hours. It resolves the
three seams and sends one report each:

- `translation`: `simulated` while the provider identifier is `identity`,
  otherwise `configured` naming the identifier.
- `dsar-identity` and `dsar-regulator`: `simulated` while the registry holds
  only its fail-closed default, with a message that the default refuses.
  Otherwise `configured`, naming the registered ids and saying a policy pack
  chooses which one runs.

A resolve that throws becomes an `error` report for that seam only.

## D4. Reports and refreshes

`OCA\OpenRegister\Service\Connection\ConnectionReporter` mirrors dossiq's
`IntegrationStatusService`:

- `STATUS_EVENT` and `REFRESH_EVENT` are string constants, resolved with
  `class_exists` (ADR-041). Without integriq nothing is sent and nothing is
  logged.
- `report()` refuses a key outside `KEYS` or a status outside `STATUSES` with a
  warning. `STATUSES` includes `limited`.
- `refreshFromSave()` sends a refresh for each connection whose declared config
  keys the save carried. `REFRESH_KEYS` equals the declared `requiredConfig`
  plus `adapter.configKey`, which a unit test holds.
- A listener that throws is caught and logged. It never reaches the request.

Callers:

| Caller | Sends |
|---|---|
| `LlmSettingsController::updateLLMSettings` | report `llm` |
| `ApiTokenSettingsController::saveApiTokens` | refresh `github`, `gitlab` |
| `ApiTokenSettingsController::testGitHubToken` / `testGitLabToken` | report, only when the tested token is the saved one |
| `EdepotSettingsController::testEdepotConnection` and a save with `testConnection` | report `edepot` |
| `AnonymisationBackendService::testConnection` for `openanonymiser` | report `anonymiser` |
| `ConnectionSeamReportJob::run` | report `translation`, `dsar-identity`, `dsar-regulator` |

A token test with a token typed into the form but not saved says nothing about
the saved connection, so it sends no report.

## D5. The page

- Page id `connections`, route `/settings/connections`, title Connections,
  `type: index` over `integriq/app_connection`, `requiresApp` integriq,
  `showAdd: false`, the columns of contract D8 and a folder sidebar on status.
- Menu entry `Connections` under the Integration group with
  `query: {app: openregister}` and `visibleIf.appInstalled: integriq`.
  `menu-layout.json` lifts it into the settings foldout beside Sources and
  Endpoints. The main menu keeps its eight top-level entries (ADR-097 decision 1).
- `src/customComponents.js` exports `openIntegriqConnections`. `CnIndexPage`
  resolves a header action handler through `CnAppRoot`'s `customComponents`,
  which OpenRegister did not pass before; `App.vue` now does, and passes the
  formatters too.
- The page id and title differ from `integrationsView` (ADR-019), which stays.

## Risks

- **Integriq refuses the file until the D12 amendments merge.** Integriq's
  `connections.schema.json` on `development` has no `reportedOnly`, and its
  validator skips an invalid file whole. The declaration test validates
  against the amended schema from `feat/connection-registry-amendments`.
- **Stale error report.** Contract rule 4b ranks any report above rule 5, so a
  failed GitHub token test keeps the row red after a new token is saved, until
  the next test.
- **Existing instances.** The job is registered by
  `ReconcileDeclaredBackgroundJobs` on the next upgrade.
