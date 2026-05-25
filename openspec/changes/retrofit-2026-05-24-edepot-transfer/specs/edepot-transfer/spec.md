---
retrofit_extensions:
  - REQ-009
  - REQ-010
---

### REQ-009: The system MUST resolve the e-Depot transport implementation from app config with `rest_api` as the default fallback

Both the `TransferExecutionJob` (background path) and the `EdepotSettingsController` (admin connection-test path) MUST resolve the active transport implementation by reading the `edepot_transport` app-config value from the `openregister` namespace. Recognised values are `sftp`, `openconnector`, and `rest_api`. Any unset, empty, or unrecognised value MUST silently fall back to the `rest_api` transport — the resolver MUST NOT throw on unknown transport types.

#### Scenario: Resolver returns SFTP transport when configured
- **GIVEN** the app config `openregister.edepot_transport` is set to `sftp`
- **WHEN** `TransferExecutionJob::resolveTransport` runs
- **THEN** it MUST return the `SftpTransport` instance injected via the constructor

#### Scenario: Resolver returns OpenConnector transport when configured
- **GIVEN** the app config `openregister.edepot_transport` is set to `openconnector`
- **WHEN** the resolver runs
- **THEN** it MUST return the `OpenConnectorTransport` instance

#### Scenario: Resolver returns REST transport when configured
- **GIVEN** the app config `openregister.edepot_transport` is set to `rest_api`
- **WHEN** the resolver runs
- **THEN** it MUST return the `RestApiTransport` instance

#### Scenario: Resolver falls back to REST when app config is missing or unknown
- **GIVEN** the app config `openregister.edepot_transport` is unset, empty, or set to an unrecognised value (e.g. `s3`, `ftp`, `null`)
- **WHEN** the resolver runs
- **THEN** it MUST return the `RestApiTransport` instance without throwing
- **AND** the fallback MUST NOT log an error — REST is the documented default

#### Scenario: Settings controller resolver accepts an explicit type override
- **GIVEN** an admin posts a connection test with body `{"transport": "openconnector"}`
- **WHEN** `EdepotSettingsController::resolveTransport($type)` runs with `$type = "openconnector"`
- **THEN** it MUST return the `OpenConnectorTransport` instance regardless of the stored `edepot_transport` config value
- **AND** an unknown `$type` value MUST fall back to `RestApiTransport`

### REQ-010: The TransportResult value object MUST expose overall success, partial-success classification, per-object accept/reject results, an optional error message, and the e-Depot transfer reference

Every transport implementation (`SftpTransport`, `RestApiTransport`, `OpenConnectorTransport`) MUST return a `TransportResult` from its `send()` method. The result MUST be constructible with `success`, `objectResults`, `errorMessage`, and `transferReference` parameters and MUST expose the following query methods used by `EdepotTransferService` to update `retention.archiefstatus`, `retention.eDepotReferentie`, and `retention.transferErrors[]`.

#### Scenario: TransportResult exposes the transfer reference set by the transport
- **GIVEN** `OpenConnectorTransport::send()` succeeds and the OpenConnector API responds with `{"callLogId": "ocl-abc-123"}`
- **WHEN** the transport returns a `TransportResult` constructed with `transferReference: "ocl-abc-123"`
- **THEN** `TransportResult::getTransferReference()` MUST return the string `"ocl-abc-123"`
- **AND** when no reference is supplied the method MUST return `null`
- **AND** callers MUST use this value to populate `retention.eDepotReferentie` on accepted objects

#### Scenario: TransportResult classifies a partial success
- **GIVEN** a `TransportResult` is constructed with `success: false` and `objectResults` containing 3 entries where 2 have `accepted: true` and 1 has `accepted: false`
- **WHEN** `isPartialSuccess()` is called
- **THEN** it MUST return `true`
- **AND** `getAcceptedUuids()` MUST return the 2 accepted UUIDs
- **AND** `getRejectedUuids()` MUST return the 1 rejected UUID

#### Scenario: TransportResult reports overall failure with an error message
- **GIVEN** a transport catches a network exception and returns `new TransportResult(success: false, errorMessage: "Connection refused")`
- **WHEN** the caller queries the result
- **THEN** `isSuccess()` MUST return `false`
- **AND** `isPartialSuccess()` MUST return `false` (no per-object results)
- **AND** `getErrorMessage()` MUST return `"Connection refused"`
- **AND** `toArray()` MUST include all four fields: `success`, `objectResults`, `errorMessage`, `transferReference`

#### Scenario: TransportResult overall-success short-circuits partial-success
- **GIVEN** a `TransportResult` is constructed with `success: true`
- **WHEN** `isPartialSuccess()` is called
- **THEN** it MUST return `false` regardless of the contents of `objectResults` — overall success is mutually exclusive with partial success
