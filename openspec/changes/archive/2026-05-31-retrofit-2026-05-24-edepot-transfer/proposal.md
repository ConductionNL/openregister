# Retrofit — edepot-transfer

Describes observed behavior of 2 methods under `edepot-transfer` as 2 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units
- lib/BackgroundJob/TransferExecutionJob.php::resolveTransport (REQ-009)
- lib/Controller/Settings/EdepotSettingsController.php::resolveTransport (REQ-009)
- lib/Service/Edepot/Transport/TransportResult.php::getTransferReference (REQ-010)

## Additional methods annotated with existing REQs
- lib/Controller/Settings/EdepotSettingsController.php::testEdepotConnection — covered by existing "Test e-Depot connection" scenario under the configurable endpoint settings requirement
- lib/Service/Edepot/Transport/OpenConnectorTransport.php::__construct — covered by the existing OpenConnector transport requirement (transport dependency injection)
- lib/Service/Edepot/Transport/TransportResult.php::__construct — covered by REQ-010 (TransportResult value object contract)

## Approach
- Observed: Both `resolveTransport` implementations read `edepot_transport` from `IAppConfig` (with `rest_api` as default) and dispatch via a `switch` on `sftp` / `openconnector` / `rest_api` — `rest_api` is also the `default` arm, so any unknown/missing value silently falls back to REST.
- Observed: `TransportResult` is a constructor-only value object exposing the e-Depot's transfer reference (the call-log id for OpenConnector, the upload identifier for REST/SFTP) alongside per-object accept/reject results and an optional top-level error message. `OpenConnectorTransport::send()` returns it with `transferReference: $callLogId`.
- The existing spec already covers the high-level transport-protocol selection (SFTP, REST, OpenConnector) but does NOT specify (a) the default-fallback behavior when the configured transport type is unknown, nor (b) the TransportResult contract callers rely on to record `retention.eDepotReferentie`.
- REQ-009 captures the resolver default-fallback rule.
- REQ-010 captures the TransportResult value-object shape, in particular `getTransferReference()` and the partial-success classification.

Source: bucket 2a coverage scan. See retrofit playbook.
