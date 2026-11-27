## Purpose

A schema-declarative confidentiality classification primitive: a per-object tier, an optional
legal-ground reference, and an optional timed release, enforced through OpenRegister's existing
row-level-security evaluation chain instead of app-hand-rolled access checks.

## ADDED Requirements

### Requirement: Schemas MUST be able to declare a confidentiality classification via `x-openregister-confidentiality`
A schema MUST be able to declare `x-openregister-confidentiality` with a non-empty ordered `tiers`
list (least to most restrictive), a `tierProperty` naming a declared schema property, and a
`clearance` map from tier to required RBAC group whose every key is in `tiers`. A tier absent from
`clearance` MUST inherit the requirement of the nearest less restrictive tier that declares one; the
least restrictive tier, when absent, requires none.

#### Scenario: Valid annotation is accepted
- **GIVEN** a schema `besluit` with property `confidentialityTier` (string) and
  `x-openregister-confidentiality: {"tiers": ["public", "confidential"], "tierProperty":
  "confidentialityTier", "clearance": {"confidential": "raadsleden"}}`
- **WHEN** the schema is saved
- **THEN** the annotation MUST be accepted with no validation error

#### Scenario: tierProperty must reference a declared property
- **GIVEN** a schema whose `x-openregister-confidentiality.tierProperty` names a property not
  present in the schema's `properties`
- **WHEN** the schema is saved
- **THEN** the annotation MUST be rejected with an error naming the undeclared property

#### Scenario: clearance tier must be a member of tiers
- **GIVEN** a schema whose `clearance` map contains a key not present in `tiers`
- **WHEN** the schema is saved
- **THEN** the annotation MUST be rejected with an error naming the unknown tier

#### Scenario: A tier absent from clearance inherits the next-less-restrictive requirement
- **GIVEN** `tiers: ["public", "internal", "confidential"]` and `clearance: {"confidential":
  "raadsleden"}` (no entry for `internal`)
- **WHEN** the derived rule is evaluated for an object at tier `internal`
- **THEN** the effective clearance requirement for `internal` MUST be the same as `public`'s (no
  clearance required), because `internal` has no explicit entry and inherits from the
  next-less-restrictive tier

### Requirement: Schemas MUST be able to declare an optional legal-ground reference and timed release
`x-openregister-confidentiality` MAY declare `groundProperty` and `releaseAtProperty`, each naming a
declared schema property. `groundProperty`'s value MUST NOT be interpreted or validated by
OpenRegister beyond existence of the property — its content (free text, enum, or `$ref`) is the
declaring schema's concern. `releaseAtProperty`, when declared, MUST name a property of `format:
date-time`.

#### Scenario: groundProperty content is opaque to OpenRegister
- **GIVEN** schema `besluit` declares `groundProperty: "confidentialityGround"` and an object has
  `confidentialityGround: "Gemeentewet art. 25"`
- **WHEN** the object is validated and saved
- **THEN** OpenRegister MUST accept the value without interpreting or constraining its content

#### Scenario: releaseAtProperty must be a date-time property
- **GIVEN** a schema declares `releaseAtProperty: "confidentialityReleaseAt"` naming a property of
  type `string` with no `date-time` format
- **WHEN** the schema is saved
- **THEN** the annotation MUST be rejected with an error naming the property and the required format

### Requirement: Read access to a classified object MUST require tier clearance or a met release condition
For a schema declaring `x-openregister-confidentiality`, a read of an object MUST be denied unless
(a) the caller's group meets the effective clearance for the object's tier, (b) a declared
`releaseAtProperty` holds a value at or before now, or (c) the caller is the owner or an admin
(unchanged bypass). Lists and searches MUST evaluate this in the SQL query, and it MUST be ORed with
the schema's authored `authorization.read` rules.

#### Scenario: An unset or unknown tier reads as the least restrictive tier
- **GIVEN** a `besluit` object whose `tierProperty` value is unset, or is not one of `tiers`
- **WHEN** a caller reads it
- **THEN** its tier MUST be the least restrictive tier in `tiers`

#### Scenario: Read denied below clearance and before release
- **GIVEN** schema `besluit` as in the valid-annotation scenario, and object `besluit-1` with
  `confidentialityTier: "confidential"` and no `confidentialityReleaseUntil` set
- **AND** user `pieter` is NOT in group `raadsleden`
- **WHEN** `pieter` reads or lists `besluit-1`
- **THEN** `besluit-1` MUST NOT be visible to `pieter`

#### Scenario: Read allowed with sufficient clearance
- **GIVEN** the same object as above
- **AND** user `jan` IS in group `raadsleden`
- **WHEN** `jan` reads or lists `besluit-1`
- **THEN** `besluit-1` MUST be visible to `jan`

#### Scenario: Read allowed once the release condition is met, regardless of clearance
- **GIVEN** schema `besluit` additionally declares `releaseAtProperty: "confidentialityReleaseUntil"`
- **AND** object `besluit-2` has `confidentialityTier: "confidential"` and
  `confidentialityReleaseUntil` set to a timestamp in the past
- **AND** user `pieter` is NOT in group `raadsleden`
- **WHEN** `pieter` reads or lists `besluit-2`
- **THEN** `besluit-2` MUST be visible to `pieter` (the release condition satisfied the rule
  regardless of clearance)

#### Scenario: An unmet future release condition does not grant access
- **GIVEN** object `besluit-3` has `confidentialityTier: "confidential"` and
  `confidentialityReleaseUntil` set to a timestamp in the future
- **AND** user `pieter` is NOT in group `raadsleden`
- **WHEN** `pieter` reads or lists `besluit-3`
- **THEN** `besluit-3` MUST NOT be visible to `pieter`

#### Scenario: Owner and admin bypass apply unchanged
- **GIVEN** object `besluit-1` (tier `confidential`, no release set)
- **WHEN** the object's owner, or a Nextcloud admin-group user, reads `besluit-1`
- **THEN** `besluit-1` MUST be visible regardless of the caller's group clearance

#### Scenario: List/search pagination reflects only classification-accessible objects
- **GIVEN** 10 `besluit` objects at tier `confidential`, of which 3 have a past `releaseAtProperty`
  value and 7 do not
- **AND** user `pieter` is NOT in group `raadsleden`
- **WHEN** `pieter` lists `besluit` objects
- **THEN** exactly the 3 released objects MUST appear
- **AND** the pagination `total` MUST reflect 3, not 10, and no post-fetch PHP filtering MUST be
  used to reach that count


#### Scenario: The query-level rule joins the authored read rules
- **GIVEN** the `besluit` schema also declares an authored `authorization.read` rule
- **WHEN** a caller lists `besluit` objects
- **THEN** the classification rule MUST be applied through the same query-level mechanism `row-field-level-security`'s conditional rules use
- **AND** an object MUST be listed when either the classification rule or an authored read rule admits it

### Requirement: A malformed confidentiality annotation MUST fail closed, not silently permit access
Unlike the other advisory `x-openregister-*` annotations, a schema whose
`x-openregister-confidentiality` fails validation MUST NOT be read as having no rule. Every object
with a non-empty `tierProperty` value MUST be read-denied until the annotation is corrected, and the
failure MUST be logged at `error` level, not `warning`, naming the schema and the defect.

#### Scenario: An invalid annotation denies read rather than granting it
- **GIVEN** a schema declares `x-openregister-confidentiality` with a `clearance` key not present in
  `tiers` (an invalid annotation)
- **WHEN** an object on that schema with a non-empty `tierProperty` value is read by a caller who is
  neither the owner nor an admin
- **THEN** the read MUST be denied
- **AND** an `error`-level log entry MUST record the schema slug and the specific validation defect

#### Scenario: A schema with no confidentiality annotation is unaffected
- **GIVEN** a schema that does not declare `x-openregister-confidentiality`
- **WHEN** any object on that schema is read
- **THEN** this capability MUST NOT alter the read outcome in any way

### Requirement: The resolved classification MUST be exposed on render as `@self.confidentiality`
When a schema declares `x-openregister-confidentiality`, a rendered object MUST include
`@self.confidentiality` with the resolved `tier`, the raw `ground` and `releaseAt` values of the
declared properties, and a `released` boolean (true when a declared release condition is met). The
mirror MUST be present for every caller who can see the object, with no restriction beyond the
object-level read gate.

#### Scenario: Render mirror reflects an unreleased classified object
- **GIVEN** object `besluit-1` (tier `confidential`, ground `"Gemeentewet art. 25"`, no release set)
- **WHEN** user `jan` (group `raadsleden`) reads `besluit-1`
- **THEN** the rendered response MUST include `@self.confidentiality: {"tier": "confidential",
  "ground": "Gemeentewet art. 25", "releaseAt": null, "released": false}`

#### Scenario: Render mirror reflects a released object
- **GIVEN** object `besluit-2` (tier `confidential`, `releaseAtProperty` in the past)
- **WHEN** any caller with read access renders `besluit-2`
- **THEN** `@self.confidentiality.released` MUST be `true`
