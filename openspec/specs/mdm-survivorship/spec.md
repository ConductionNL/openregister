# mdm-survivorship Specification

## Purpose
TBD - created by archiving change mdm-survivorship-engine. Update Purpose after archive.
## Requirements
### Requirement: Declarative survivorship annotation and vocabulary
OpenRegister SHALL support a schema annotation `x-openregister-survivorship` that
declares how a master object's golden record is resolved from its linked source
records. The annotation key SHALL be registered in `Schema::ANNOTATION_VOCABULARY`
and SHALL be shape-validated at schema import by a `SurvivorshipAnnotationValidator`;
a malformed annotation MUST degrade to a non-fatal warning (the schema still stores
objects, the golden record simply is not materialised). The annotation SHALL declare
at least: `sourceLinkField` (the field holding the linked source records),
`goldenRecordField` (default `goldenRecord`), `provenanceField` (default
`attributeProvenance`), `tierOrder` (an ordered list of tier names weakest→strongest,
default `["discard","bronze","silver","gold"]`), `defaultTier` (default `bronze`),
`discardTier` (default `discard`), `freshnessAnchorField` (the per-source date used
for freshness and tie-break), and `tieBreak` (default `mostRecentUpdate`). A
`trustLookup.keys` array (default `["entityType","attribute","sourceSystem"]`) SHALL
declare the tuple used to look up a trust tier.

#### Scenario: Annotation is registered and recognised
- **WHEN** a schema declares an `x-openregister-survivorship` block on import
- **THEN** the annotation MUST be retained on the schema's configuration (not dropped as an unknown `x-openregister-*` key)

#### Scenario: Malformed annotation is non-fatal
- **WHEN** a schema is imported with an `x-openregister-survivorship` annotation missing `sourceLinkField`, or with a non-array `tierOrder`, or with a `defaultTier`/`discardTier` absent from `tierOrder`
- **THEN** the schema import MUST still succeed
- **AND** the invalid annotation MUST be ignored with a logged warning, and no golden record materialised

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

### Requirement: Freshness decay and date-correct tie-break
When resolving a candidate's tier, the resolver SHALL apply freshness decay: if the
elapsed time since the source's `freshnessAnchorField` date exceeds the trust row's
`freshnessDecayDays`, the candidate's tier SHALL be lowered exactly one level on
`tierOrder` (a `discardTier` result is then excluded). When two candidates resolve to
the same tier, the tie SHALL be broken in favour of the most recently updated source,
where "most recent" is determined by **parsing the `freshnessAnchorField` values as
dates and comparing chronologically** — NOT by lexical string comparison. Unparseable
or absent anchor dates MUST sort as oldest (they never win a tie by freshness) and MUST
NOT throw.

#### Scenario: Stale source decays one tier
- **WHEN** a `gold` trust row declares `freshnessDecayDays: 90` and the winning source's anchor date is 120 days old
- **THEN** that candidate MUST be resolved as `silver` (one level down), and MUST lose to any genuine `gold` candidate for the same attribute

#### Scenario: Tie broken by the more recent source, compared as dates
- **WHEN** two candidates resolve to the same tier, one with anchor `2026-01-09` and one with anchor `2026-01-10`
- **THEN** the `2026-01-10` candidate MUST win — and this MUST hold even where a naive lexical comparison of differently-formatted timestamps would disagree

### Requirement: Generic queryable trust configuration
OpenRegister SHALL own a generic `trustConfiguration` register/schema whose rows
declare a trust tier for a `(entityType, attribute, sourceSystem)` tuple, with optional
`freshnessDecayDays` and an `effectiveFrom` date. A `TrustTierResolver` SHALL resolve
the effective tier for a tuple as of a given date: among matching rows, the most recent
row whose `effectiveFrom` is on or before the as-of date wins; when no row matches, the
resolver SHALL return null so the caller falls back to `defaultTier`. Trust rows are
data (RBAC-scoped, auditable, queryable), NOT frozen inside the schema annotation.

#### Scenario: Most recent effective row wins
- **WHEN** two trust rows match a tuple, one `effectiveFrom: 2025-01-01` (silver) and one `effectiveFrom: 2026-01-01` (gold), resolved as of `2026-06-01`
- **THEN** the resolver MUST return `gold`

#### Scenario: Future-dated row is ignored
- **WHEN** the only matching row has `effectiveFrom: 2027-01-01`, resolved as of `2026-06-01`
- **THEN** the resolver MUST return null, and the caller MUST fall back to `defaultTier`

#### Scenario: No matching row falls back to default
- **WHEN** no trust row matches a tuple
- **THEN** the resolver MUST return null

### Requirement: On-save golden-record materialisation
OpenRegister SHALL materialise the golden record on object create and update via a
`SurvivorshipRecomputeListener` subscribed to `ObjectCreatingEvent` and
`ObjectUpdatingEvent`, registered in `lib/AppInfo/Application.php`. When the object's
schema declares `x-openregister-survivorship`, the listener SHALL load the linked
source records from `sourceLinkField`, invoke the `SurvivorshipResolver`, and write the
resolved golden record and provenance into `goldenRecordField` and `provenanceField`
before persistence — only when the computed values differ from the stored ones. The
listener MUST be fail-soft: on any error it logs a warning and returns without aborting
the save, exactly like `QualityScoreOnSaveListener`. Schemas without the annotation MUST
be untouched.

#### Scenario: Golden record materialised on save
- **WHEN** an object of a schema declaring `x-openregister-survivorship` is created with linked source records
- **THEN** the persisted object MUST carry a `goldenRecord` resolved from those sources and an `attributeProvenance` map keyed by attribute

#### Scenario: Resolution failure never aborts the save
- **WHEN** the resolver raises an error mid-computation (e.g. a malformed source payload)
- **THEN** the object MUST still be persisted, and a warning MUST be logged

#### Scenario: Schema without the annotation is untouched
- **WHEN** an object of a schema that does NOT declare `x-openregister-survivorship` is saved
- **THEN** the listener MUST make no change to the object payload

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

