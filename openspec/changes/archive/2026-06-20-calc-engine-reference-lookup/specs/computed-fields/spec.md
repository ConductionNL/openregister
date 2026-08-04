## ADDED Requirements

### Requirement: Declarative cross-object reference annotation
A schema MAY declare an `x-openregister-references` annotation: a map of named references, each resolving to at most one OTHER object whose fields the schema's calculations MAY read via `@ref.<name>.<field>`. Each reference SHALL declare a target `schema` and a `mode` of either `relatedObject` (resolve by a local foreign-key `field` holding a uuid/id) or `lookup` (resolve by a `filters` criteria map). A `lookup` reference MAY declare an optional `effectiveDate` selector to pick the most-recent row valid as-of a date. References are resolved by `CalculationOnSaveListener` (and `RematerialiseCalculationsCommand`) BEFORE any calculation is evaluated, and injected into the evaluation payload under `@ref.<name>`; the pure `CalculationEvaluator` SHALL remain free of I/O and resolve `@ref.<name>.<field>` only through its existing dotted-path `prop` mechanism. Reference resolution MUST run under the saving user's existing RBAC and multitenancy scope, MUST NOT recursively re-trigger the resolved object's own calculations, and MUST NOT fail the save when a reference is unresolvable.

#### Scenario: Resolve a reference by foreign key
- **GIVEN** a schema `DepreciationSchedule` declaring `x-openregister-references.asset` with `mode: relatedObject`, `schema: FixedAsset`, and `field: fixedAssetId`
- **AND** an object whose `fixedAssetId` holds the uuid of a `FixedAsset` with `acquisitionCost = 10000`
- **WHEN** the object is saved and a calculation reads `{ "prop": "@ref.asset.acquisitionCost" }`
- **THEN** the listener MUST resolve the `FixedAsset` via `ObjectService::find()`
- **AND** inject its data under `@ref.asset` so the calculation reads `10000`

#### Scenario: Resolve a reference by effective-dated criteria
- **GIVEN** a schema `MileageEntry` declaring `x-openregister-references.rate` with `mode: lookup`, `schema: MileageRate`, and `filters` keyed by `@self`-derived values (e.g. `{ "fiscalYear": { "year": "@self.journeyDate" }, "vehicleType": "@self.vehicleType", "country": "@self.country" }`)
- **AND** a `MileageRate` master row matching those criteria with `ratePerKm = 0.21`
- **WHEN** a `MileageEntry` is saved and a calculation reads `{ "prop": "@ref.rate.ratePerKm" }`
- **THEN** the listener MUST resolve the row via `ObjectService::findAll(['filters'=>…])` parameterised by the object's values
- **AND** inject it under `@ref.rate` so the calculation reads `0.21`

#### Scenario: An unresolvable reference injects null and never fails the save
- **GIVEN** a `lookup` reference whose criteria match no master row, OR a `relatedObject` reference whose `field` is empty or points to a missing object
- **WHEN** the object is saved
- **THEN** the listener MUST inject `@ref.<name>` as `null`
- **AND** a calculation reading `{ "prop": "@ref.<name>.<field>" }` MUST yield `null`
- **AND** the save MUST complete successfully (a warning MAY be logged)

#### Scenario: Reference resolution respects RBAC and tenant scope
- **GIVEN** a saving user without read permission on the target schema, or a referenced object owned by another tenant
- **WHEN** a reference is resolved during save
- **THEN** the resolution MUST use `ObjectService` with its default `_rbac: true` and `_multitenancy: true` (never bypassed)
- **AND** the unreadable/cross-tenant object MUST resolve to `null`, NOT leak its data

#### Scenario: Resolving a reference does not recursively re-trigger calculations
- **GIVEN** the resolved object's schema also declares materialised calculations
- **WHEN** a reference to it is resolved during another object's save
- **THEN** resolution MUST use a read path (`find()` / `findAll()`) that does NOT dispatch creating/updating events
- **AND** the resolved object's own calculations MUST NOT re-run as a side effect

#### Scenario: Materialised reference values are save-time snapshots refreshed by rematerialise
- **GIVEN** a `MileageEntry` whose `ratePerKm` was materialised from a `MileageRate` row at save time
- **WHEN** that `MileageRate` row is later edited
- **THEN** the previously saved `MileageEntry.ratePerKm` MUST remain unchanged (a snapshot) until the entry is re-saved
- **AND** running `openregister:rematerialise-calculations <register> <schema>` MUST re-resolve the reference and refresh the materialised value
