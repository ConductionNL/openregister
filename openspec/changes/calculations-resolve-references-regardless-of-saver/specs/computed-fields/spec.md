## MODIFIED Requirements

### Requirement: Declarative cross-object reference annotation
A schema MAY declare an `x-openregister-references` annotation: a map of named references, each resolving to at most one OTHER object whose fields the schema's calculations MAY read via `@ref.<name>.<field>`. Each reference SHALL declare a target `schema` and a `mode` of either `relatedObject` (resolve by a local foreign-key `field` holding a uuid/id) or `lookup` (resolve by a `filters` criteria map). A `lookup` reference MAY declare an optional `effectiveDate` selector to pick the most-recent row valid as-of a date. References are resolved by `CalculationOnSaveListener` (and `RematerialiseCalculationsCommand`) BEFORE any calculation is evaluated, and injected into the evaluation payload under `@ref.<name>`; the pure `CalculationEvaluator` SHALL remain free of I/O and resolve `@ref.<name>.<field>` only through its existing dotted-path `prop` mechanism. Reference resolution MUST NOT depend on who saves: it MUST read with RBAC and multitenancy off, and MUST admit a referenced object only when it is inside the saving object's tenant scope (see the tenant boundary requirement). Resolution MUST NOT recursively re-trigger the resolved object's own calculations, and MUST NOT fail the save when a reference is unresolvable.

#### Scenario: Resolve a reference by foreign key
- **GIVEN** a schema `DepreciationSchedule` declaring `x-openregister-references.asset` with `mode: relatedObject`, `schema: FixedAsset`, and `field: fixedAssetId`
- **AND** an object whose `fixedAssetId` holds the uuid of a `FixedAsset` with `acquisitionCost = 10000`
- **WHEN** the object is saved and a calculation reads `{ "prop": "@ref.asset.acquisitionCost" }`
- **THEN** the listener MUST resolve the `FixedAsset` via `ObjectService::find()`
- **AND** inject its data under `@ref.asset` so the calculation reads `10000`

#### Scenario: Resolve a reference by effective-dated criteria
- **GIVEN** a schema `MileageEntry` declaring `x-openregister-references.rate` with `mode: lookup`, `schema: MileageRate`, and `filters` keyed by `@self`-derived values
- **AND** a `MileageRate` master row matching those criteria with `ratePerKm = 0.21`
- **WHEN** a `MileageEntry` is saved and a calculation reads `{ "prop": "@ref.rate.ratePerKm" }`
- **THEN** the listener MUST resolve the row via `ObjectService::findAll(['filters'=>…])` parameterised by the object's values
- **AND** inject it under `@ref.rate` so the calculation reads `0.21`

#### Scenario: An empty reference injects null and never fails the save
- **GIVEN** a `lookup` reference whose criteria match no master row, OR a `relatedObject` reference whose `field` is empty
- **WHEN** the object is saved
- **THEN** the listener MUST inject `@ref.<name>` as `null`
- **AND** a calculation reading `{ "prop": "@ref.<name>.<field>" }` MUST yield `null`
- **AND** the save MUST complete successfully

#### Scenario: An anonymous save resolves the same reference an administrator's save does
- **GIVEN** a dossiq case whose `caseType` points at a case type in the case's own organisation
- **WHEN** the case is saved by an anonymous web request (a resident on the portal)
- **THEN** the resolver MUST call `ObjectService` with `_rbac: false` and `_multitenancy: false`
- **AND** `@ref.caseType` MUST hold the case type, so `deadline` and `statutoryTerm` compute as they do for an administrator

#### Scenario: Resolving a reference does not recursively re-trigger calculations
- **GIVEN** the resolved object's schema also declares materialised calculations
- **WHEN** a reference to it is resolved during another object's save
- **THEN** resolution MUST use a read path (`find()` / `findAll()`) that does NOT dispatch creating/updating events
- **AND** the resolved object's own calculations MUST NOT re-run as a side effect

#### Scenario: Materialised reference values are save-time snapshots refreshed by rematerialise
- **GIVEN** a `MileageEntry` whose `ratePerKm` was materialised from a `MileageRate` row at save time
- **WHEN** that `MileageRate` row is later edited
- **THEN** the previously saved `MileageEntry.ratePerKm` MUST remain unchanged until the entry is re-saved
- **AND** running `openregister:rematerialise-calculations <register> <schema>` MUST re-resolve the reference and refresh the materialised value

## ADDED Requirements

### Requirement: A calculation reference stays inside the saving object's tenant
Because references are read without the session's scope, `ReferenceTenantGuard` SHALL decide which referenced objects may feed a calculation. A referenced object MUST be admitted when it has no organisation, when it is in the saving object's organisation or a parent of it, or when its register or schema is shared master data held by its organisation and shared with the saving organisation. Every other referenced object MUST resolve empty. A saving object with no organisation MUST reach only org-less objects.

#### Scenario: A reference to another tenant's object is refused
- **GIVEN** a case in organisation A whose `caseType` points at a case type in organisation B
- **AND** B is neither a parent of A nor a holder of shared master data for A
- **WHEN** the case is saved
- **THEN** `@ref.caseType` MUST be `null`
- **AND** none of the case type's data MUST reach the case

#### Scenario: A parent organisation's object is admitted
- **GIVEN** a case in organisation A whose case type sits in A's parent organisation
- **WHEN** the case is saved
- **THEN** `@ref.caseType` MUST hold the case type

#### Scenario: Shared master data is admitted
- **GIVEN** a case type in organisation H, on a schema H shares with organisation A
- **WHEN** a case in A that points at it is saved
- **THEN** `@ref.caseType` MUST hold the case type

#### Scenario: An org-less object is admitted from any tenant
- **GIVEN** a referenced object with no organisation
- **WHEN** an object in any organisation that points at it is saved
- **THEN** the reference MUST resolve

### Requirement: A calculation never writes null over a stored value because a reference could not be resolved
A reference is unresolved when it had something to resolve (a filled foreign key, or a lookup that matched rows) and still resolved empty: the target is missing, outside the tenant, or the read failed. A materialised calculation that reads an unresolved reference MUST NOT be evaluated on that save. The stored value MUST stay, and the rule run log MUST record an `error` verdict naming the reference. Calculations that do not read the unresolved reference MUST run as usual. The temporal sweep MUST NOT count such a calculation as changed.

#### Scenario: An unresolved reference keeps the stored value and says why
- **GIVEN** a case with `statutoryTerm = "P8W"` stored, whose `caseType` points at a case type that cannot be resolved
- **WHEN** the case is saved
- **THEN** `statutoryTerm` MUST still be `"P8W"` after the save
- **AND** the rule run log MUST hold an `error` verdict for `calculation:case:statutoryTerm` whose message names `caseType`
- **AND** a calculation on the same schema that reads no reference MUST still record a `fired` verdict

#### Scenario: A cleared foreign key is not unresolved
- **GIVEN** a case whose `result` foreign key is empty
- **WHEN** the case is saved
- **THEN** `@ref.result` MUST be `null` and calculations reading it MUST be evaluated as usual
