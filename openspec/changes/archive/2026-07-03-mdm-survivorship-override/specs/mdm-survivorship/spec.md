## MODIFIED Requirements

### Requirement: Pure entity-type-agnostic survivorship resolution
OpenRegister SHALL provide a pure `SurvivorshipResolver` service that, given the
linked source records, the survivorship config, a trust-tier lookup, and an
optional per-object attribute-override map, computes a golden record and
per-attribute provenance. The resolver MUST be entity-type-agnostic: it takes the
entity type as a parameter and never hardcodes attribute or entity names. For each
attribute present across the non-withdrawn source records, the resolver SHALL
resolve each candidate source's effective trust tier, select the winner by a
running maximum on the tier's rank in `tierOrder`, drop `discardTier` candidates,
and emit the winning value into the golden record plus a provenance entry
recording the value, source system, resolved tier, and update timestamp. **When
the override map holds a value for an attribute, that value SHALL short-circuit
tier selection and win unconditionally — even when no source supplies it — and
its provenance entry SHALL be marked as a manual override (recording the actor
and, when supplied, a rationale) rather than a trust tier.** A source whose value
for an attribute is null or empty MUST NOT compete for that attribute. Sources
marked withdrawn MUST be excluded entirely. The resolver MUST be null-safe and
MUST NOT throw on malformed source records or a malformed override map — a
malformed record or override entry is skipped.

#### Scenario: Higher tier wins the attribute
- **WHEN** two non-withdrawn sources supply a value for the same attribute, one resolving to `gold` and one to `silver`
- **THEN** the golden record for that attribute MUST hold the `gold` source's value
- **AND** the provenance entry for that attribute MUST record `trustTier: gold` and that source's system + update timestamp

#### Scenario: Discard-tier value is never selected
- **WHEN** the only source supplying an attribute resolves to the configured `discardTier`
- **THEN** that attribute MUST be absent from the golden record

#### Scenario: Uncontested source populates with the default tier
- **WHEN** a single source supplies an attribute and no trust row matches its tuple
- **THEN** the resolver MUST treat it as `defaultTier` and populate the golden record with its value (a lone source still yields a golden record)

#### Scenario: Empty and withdrawn sources are excluded
- **WHEN** a source is marked withdrawn, or its value for an attribute is null or an empty string
- **THEN** that source MUST NOT contribute that attribute to the golden record

#### Scenario: Per-object override wins over the tier-selected value
- **WHEN** the override map holds a value for an attribute that a `gold` source would otherwise win
- **THEN** the golden record for that attribute MUST hold the override value
- **AND** the provenance entry MUST be marked a manual override recording the actor (and rationale when supplied), not a trust tier

#### Scenario: Override populates an attribute no source supplies
- **WHEN** the override map holds a value for an attribute that no linked source supplies
- **THEN** the golden record MUST hold the override value for that attribute

#### Scenario: Malformed override entry is skipped
- **WHEN** the override map is non-array, or an entry is malformed
- **THEN** the resolver MUST ignore the malformed override without throwing and resolve the remaining attributes by tier

## ADDED Requirements

### Requirement: Per-object attribute overrides are materialised and preserved
The survivorship config SHALL support an `overridesField` key (default
`attributeOverrides`) naming the object field that holds the per-object override
map. The `SurvivorshipRecomputeListener` SHALL read the override map from that
field, thread it into `SurvivorshipResolver`, and **preserve the override map
across recomputes** — a save that does not change the overrides MUST NOT drop or
reset them. Overrides MUST be scoped to the single master object; setting or
clearing an override on one object MUST NOT affect any other object.

#### Scenario: Override survives an unrelated recompute
- **WHEN** an object with a set attribute override is saved again for an unrelated reason
- **THEN** the override map MUST still be present after the save
- **AND** the golden record MUST still reflect the override

#### Scenario: Overrides are isolated to their object
- **WHEN** an override is set on one master object
- **THEN** other master objects of the same schema MUST recompute unchanged from their trust tiers

### Requirement: Survivorship override endpoint sets and clears an attribute override
OpenRegister SHALL expose `POST /api/objects/survivorship/{id}/override` that
sets (with a value) or clears (with a null/absent value) one attribute override
on a master object, records the acting user and an optional rationale, triggers a
golden-record recompute, and returns the recomputed object. The endpoint SHALL be
RBAC/tenant scoped through `ObjectService` (the same posture as the merge
endpoints): a caller who cannot write the target object MUST receive a
forbidden/not-found response rather than a successful override. The controller
method MUST declare its auth posture via Nextcloud attributes and MUST be
registered in `appinfo/routes.php`.

#### Scenario: Setting an override recomputes the golden record
- **WHEN** an authorised steward posts an attribute + value to the override endpoint for a master object
- **THEN** the override MUST be persisted onto the object's override map
- **AND** the response MUST contain the recomputed object whose golden record reflects the override

#### Scenario: Clearing an override falls back to trust resolution
- **WHEN** the steward posts the endpoint for an attribute with a null/absent value
- **THEN** that attribute's override MUST be removed
- **AND** the recomputed golden record MUST fall back to the tier-selected value for that attribute

#### Scenario: Unauthorised caller is rejected
- **WHEN** a caller who cannot write the target object posts to the override endpoint
- **THEN** the response MUST be a forbidden/not-found error and no override MUST be written
