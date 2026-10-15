## ADDED Requirements

### Requirement: BRP count of residents at an address object

`BrpPersonProvider::countResidentsAtAddressObject(string $addressObjectId)` SHALL answer how many people the BRP registers at an address object, as a number only. It SHALL ask Haal Centraal `/personen` with a `ZoekMetAdresseerbaarObjectIdentificatie` body carrying the 16-digit `adresseerbaarObjectIdentificatie` and a `fields` list of one field, and answer `{ total, meta }` where `meta` is the Wet-BRP audit metadata `{ correlationId, durationMs, status }`.

The provider SHALL ask only when the `brp-haalcentraal` source carries `configuration.residentCount` set to the boolean `true`. Otherwise it SHALL answer `{ unavailable: true, cause: 'resident-count-not-enabled' }` without calling the registry.

The call SHALL reach the connector with `withholdBodies`, so the call log keeps no request or response body. A connector that cannot withhold bodies SHALL NOT be called, and the answer is `{ unavailable: true, cause: 'upstream-service-down' }`.

The answer SHALL never hold a name, a BSN, a person object or the address object id, and the provider SHALL never log the address object id. A malformed id SHALL answer `{ unavailable: true, cause: 'invalid-identifier' }` without a call. A missing or unreachable source SHALL degrade to `{ unavailable: true, cause }` as `lookupByBsn` does.

#### Scenario: the count of residents at an address object

- GIVEN the `brp-haalcentraal` source carries `configuration.residentCount: true`
- AND the BRP registers three people at address object `0518010000123456`
- WHEN portaliq asks `countResidentsAtAddressObject('0518010000123456')`
- THEN the provider posts `type='ZoekMetAdresseerbaarObjectIdentificatie'`, the id in `adresseerbaarObjectIdentificatie` and one field, with `withholdBodies`
- AND it answers `{ total: 3, meta }` and nothing about the three people
- @e2e exclude Backend provider method, verified by PHPUnit (router mocked); portaliq's Mijn gegevens is the live pass.

#### Scenario: the setting is off

- GIVEN the source carries no `configuration.residentCount`, or a value other than `true`
- WHEN the count is asked
- THEN the answer is `{ unavailable: true, cause: 'resident-count-not-enabled' }` and the registry is not called
- @e2e exclude Backend refusal path, verified by PHPUnit.

#### Scenario: no number and no address in the answer or the log

- GIVEN any count, answered or degraded
- WHEN the provider answers and logs
- THEN the answer holds only `total` and `meta`, or `unavailable` and `cause`
- AND no log line holds the address object id or a burgerservicenummer
- @e2e exclude Backend privacy invariant, verified by PHPUnit (logger asserted).

### Requirement: a call may be recorded without its bodies

`ExternalIntegrationRouter` SHALL honour a `withholdBodies: true` call option by passing `withholdBodies: true` to the connector's `CallService::call`, and SHALL never pass that option on as a request option. When the connector's `call` has no `withholdBodies` parameter, the router SHALL NOT call it and SHALL fail with cause `upstream-service-down`. `sourceConfiguration(provider)` SHALL answer the provider's source `configuration` array and fail like `call()` when the connector or the source is missing.

#### Scenario: the connector records the call without bodies

- GIVEN integriq's CallService, whose `call` takes `withholdBodies`
- WHEN a provider calls with `withholdBodies: true`
- THEN the CallService receives `withholdBodies: true` and the request options do not carry it
- @e2e exclude Backend transport option, verified by PHPUnit (CallService stand-in).

#### Scenario: an older connector is not called

- GIVEN a CallService whose `call` has no `withholdBodies` parameter
- WHEN a provider calls with `withholdBodies: true`
- THEN the CallService is not called and the router fails with `upstream-service-down`
- @e2e exclude Backend refusal path, verified by PHPUnit.
