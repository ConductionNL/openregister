# Tasks: brp-residents-at-address-count

Kind: code. Size S. Row: portaliq `cmp-id-residents-at-address`.

## 1. Router

- [x] 1.1 `ExternalIntegrationRouter::sourceConfiguration()` answers the source's `configuration`, and fails like `call()` when the connector or the source is missing. Verify: `ExternalIntegrationRouterTest`.
- [x] 1.2 A `withholdBodies` call option reaches integriq's `CallService::call(withholdBodies: true)`, never Guzzle; a CallService without that parameter is not called. Verify: `ExternalIntegrationRouterTest`.

## 2. Provider

- [x] 2.1 `BrpPersonProvider::countResidentsAtAddressObject()` posts `ZoekMetAdresseerbaarObjectIdentificatie` with one field, behind `configuration.residentCount: true`, and answers `{ total, meta }` or `{ unavailable, cause }`. Verify: `BrpPersonProviderResidentCountTest` covers the count, the setting off, a malformed id, the degraded paths, and that no number or address id reaches the result or the log.

The administrator-facing note (turn on `residentCount` on the `brp-haalcentraal` source) lives on portaliq's registered-details docs page, next to the sources it already names.
