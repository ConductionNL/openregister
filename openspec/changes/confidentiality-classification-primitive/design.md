## Context

decidesk classifies confidentiality in five independent places (evidence gathered live,
2026-08-19, decidesk Back to Six programme): `AgendaItem.confidentiality` (ad hoc enum),
`Decision.isPublished` (boolean, no legal grounding), a hand-maintained public-publication
deny-list, `commissievergaderingen`'s `besloten-onderdeel` flag, and the `Geheimhouding` register
(grounds + `bekrachtiging`/`opheffing` per Gemeentewet art. 25/55/86). None of the five share a
property name, an enforcement path, or a tier vocabulary. This is the same drift ADR-045/047 exist
to prevent, one abstraction earlier: a data-classification capability every register touching
sensitive data eventually needs, currently reinvented per app.

OpenRegister already ships the two building blocks a classification primitive needs:

- **`row-field-level-security`**: `MagicRbacHandler` (SQL) and `ConditionMatcher`/
  `PermissionHandler` (PHP) already evaluate conditional per-object rules with MongoDB-style
  operators, including `{"vertrouwelijkheid": {"$lte": N}}`-shaped comparisons, owner bypass, admin
  bypass, and `public`/`authenticated` pseudo-groups (`openspec/specs/row-field-level-security/`).
- **`deprecate-published-metadata`**: replaced the old `published`/`depublished` object columns
  with a `$now` dynamic variable resolved identically by `MagicRbacHandler::resolveDynamicValue()`
  (SQL datetime) and `ConditionMatcher::resolveDynamicValue()` (ISO 8601), so a rule like
  `{"publicatieDatum": {"$lte": "$now"}}` is a working timed-release gate with zero new
  infrastructure (`openspec/specs/deprecate-published-metadata/`).

Neither building block is *wrong* for confidentiality — decidesk's `Geheimhouding` grounds and tier
could, in principle, already be expressed as a hand-authored RLS rule plus a `$now` release
condition. What is missing is the **standard shape**: a schema-declarative annotation that
generates the rule from schema metadata (as `x-openregister-quality`/`-dedup`/`-survivorship`
already do for scoring/dedup/golden-record), so every consuming app gets the same tier vocabulary
convention, the same legal-ground field convention, and the same timed-release behavior for free,
instead of hand-rolling match conditions per schema and drifting on property names.

Stakeholders: the OR team (owns the annotation + validator + derived-rule contract), decidesk
(first and motivating consumer — Gemeentewet workflow stays app-side, declares against this
primitive), procest/zaakafhandelapp (plausible future consumers for zaaktype vertrouwelijkheid,
not scoped here).

## Goals / Non-Goals

**Goals:**
- A schema-declarative `x-openregister-confidentiality` annotation naming: an ordered tier
  vocabulary, the property holding an object's tier, an optional property holding a legal-ground
  reference, an optional property holding a release date/time, and a clearance map from tier to
  required RBAC group.
- Save-time shape validation (`ConfidentialityAnnotationValidator`), following the existing
  `SchemaMapper::validate*Annotation()` warn-not-reject pattern for consistency with quality/dedup/
  survivorship/lifecycle/aggregations/calculations/merge.
- A derived read-enforcement rule, generated from the annotation and injected into the same
  evaluation chain authored RLS rules already use, requiring tier clearance OR a met release
  condition (via the existing `$now` mechanism).
- Render-time exposure of the resolved classification under `@self.confidentiality` so consuming
  apps and their UIs can display "why is this hidden / when does it open" without re-deriving the
  rule.
- A visible, non-silent failure path when the annotation is malformed (see "Loud discard" below) —
  the aggregation-dialect-repair change (same wave) established that a warning that only reaches
  `nextcloud.log` is not a sufficient signal for a schema author; this primitive should not repeat
  that mistake on day one.

**Non-Goals:**
- The Gemeentewet workflow itself: grounds vocabulary, `bekrachtiging`/`opheffing` approval,
  council-decision-triggered declassification. Stays in decidesk, declared against this primitive.
- Any UI for authoring the annotation. Schemas remain JSON-edited, same as every other
  `x-openregister-*` annotation today.
- Migrating decidesk's five existing mechanisms onto the primitive. Tracked as decidesk-side
  follow-up, not part of this OR change.
- Changing `row-field-level-security`'s authored-rule contract, or `deprecate-published-metadata`'s
  `$now` resolution contract. Both are consumed unchanged.
- A generic "clearance level" system independent of confidentiality (e.g. arbitrary numeric ACL
  tiers for non-confidentiality purposes) — scoped strictly to the classification-with-legal-ground-
  and-timed-release shape decidesk's evidence describes.

## Decisions

### Reuse before build (ADR-011)

Searched `lib/Service/`, `lib/Db/MagicMapper/`, `lib/Service/Aggregation/`,
`openspec/specs/row-field-level-security/`, `openspec/specs/deprecate-published-metadata/` before
proposing anything new:

- **`MagicRbacHandler::resolveDynamicValue('$now')` / `ConditionMatcher::resolveDynamicValue('$now')`**
  — reused verbatim for the timed-release condition. No second `$now`/embargo implementation.
- **The conditional-rule shape `{"group": ..., "match": {...}}`** — the derived rule is expressed in
  exactly this shape so it flows through `MagicRbacHandler::applyRbacFilters()` /
  `PermissionHandler::hasPermission()` / `ConditionMatcher::objectMatchesConditions()` unchanged;
  those three enforcement points are not modified, only given an additional rule source.
  `MagicRbacHandler::propertyToColumnName()` (camelCase → snake_case) applies to the derived rule's
  property references exactly as it does to authored ones.
- **The `SchemaMapper::validate*Annotation()` / `logDroppedAnnotationKeys()` pattern** — the new
  validator plugs into the same private-method family as `validateQualityAnnotation()` /
  `validateDedupAnnotation()` / `validateSurvivorshipAnnotation()` (`lib/Db/SchemaMapper.php:1118-
  1214`), not a bespoke validation path.
- **`Schema::ANNOTATION_VOCABULARY`** — extended with the new key rather than left to fall through
  `logDroppedAnnotationKeys()`'s "unknown key" warning.
- **`@self.relations` / write-only mirror pattern** (`row-field-level-security` §"writeOnly stripping")
  — the `@self.confidentiality` render mirror follows the same "materialise at the render choke
  point, strip/compute unconditionally" precedent rather than inventing a new render hook.

### Annotation shape

```json
"x-openregister-confidentiality": {
  "tiers": ["public", "internal", "confidential", "secret"],
  "tierProperty": "confidentialityTier",
  "groundProperty": "confidentialityGround",
  "releaseAtProperty": "confidentialityReleaseAt",
  "clearance": {
    "confidential": "raadsleden",
    "secret": "college"
  }
}
```

- `tiers`: REQUIRED, non-empty, ordered least → most restrictive. The first tier is the implicit
  default requiring no clearance (equivalent to `public`/`authenticated` pseudo-groups already in
  RLS).
- `tierProperty`: REQUIRED, MUST name a declared schema property whose value MUST be one of `tiers`.
- `groundProperty`: OPTIONAL. When present MUST name a declared schema property. OR does not
  interpret its value — free text, enum, or `$ref` to a grounds-vocabulary register are all valid;
  this is deliberately where jurisdiction-specific wording (Gemeentewet, or any other statute) lives
  as data, per ADR-047's "jurisdiction is data, not code."
- `releaseAtProperty`: OPTIONAL. When present MUST name a declared schema property of `format:
  date-time`. When the object's value is set and `<= $now`, the derived rule treats the object as
  released regardless of tier (an embargo, not a permanent classification).
- `clearance`: REQUIRED (MAY be empty only if `tiers` has exactly one entry — trivial case). Maps
  every tier named in `tiers` other than the first to an RBAC group name required to read at that
  tier or above. A tier absent from `clearance` inherits the next-less-restrictive tier's
  requirement (so a schema need only declare clearance at the tiers where it changes).

### Derived read-enforcement

At the same seam `MagicRbacHandler::applyRbacFilters()` (SQL) and
`ConditionMatcher::objectMatchesConditions()` / `PermissionHandler::hasPermission()` (PHP) already
consult authored `authorization.read` rules, a schema declaring
`x-openregister-confidentiality` contributes one additional derived rule, logically:

```
FOR the object's tier T (read from tierProperty, defaulting to tiers[0] if unset/invalid):
  ALLOW read IF caller's group satisfies clearance[T] (walking up from T to tiers[0])
  ALLOW read IF releaseAtProperty is declared AND object[releaseAtProperty] <= $now
  (owner bypass and admin bypass apply exactly as they do to every other RLS rule)
```

This is additive: it is evaluated with OR semantics against any authored rules the schema also
declares (mirroring "Multiple authorization rules evaluated with OR logic" in
`row-field-level-security`), so a schema author can still add narrower hand-authored rules on top.
SQL-level evaluation keeps the ADR "RLS MUST happen at the SQL query level" guarantee — the derived
rule generates a `WHERE` fragment via `MagicRbacHandler`, not a post-fetch PHP filter, so pagination
counts and facet counts stay correct for confidentiality-filtered lists exactly as they do for
authored RLS rules today.

### Loud discard (the fourth item from the same wave's aggregation-dialect-repair change)

The existing `x-openregister-quality`/`-dedup`/`-survivorship`/`-aggregations`/`-calculations`
pattern degrades a malformed annotation to a `logger->warning()` call and imports the schema anyway
(`lib/Db/SchemaMapper.php:1044-1249`). `aggregation-dialect-repair` (same wave, `openregister/
openspec/changes/aggregation-dialect-repair/`) diagnosed that this pattern is not loud enough in
practice: a `nextcloud.log` line with no surfacing in the save response reads, from the schema
author's side, as success. Because a malformed confidentiality annotation is a **security-relevant**
failure mode (a broken tier/clearance mapping silently means "enforcement rule not applied" — the
strictest possible failure direction is fail-closed, but the current pattern's failure direction for
*other* advisory annotations is fail-open-to-"feature simply doesn't run"), this change does NOT
reuse the warn-and-continue behavior unmodified:

- `ConfidentialityAnnotationValidator` errors are still collected the same way as the other
  validators (consistency, `SchemaMapper.php:1044-1070` shape).
- But `SchemaMapper::validateConfidentialityAnnotation()` (the new sibling method) additionally
  appends validation errors to the schema-save response's existing warnings surface (whatever
  `aggregation-dialect-repair` task 4 establishes as the shared "annotation validation warnings"
  response field — this change consumes that mechanism rather than inventing a second one; see
  `depends_on` note in Open Questions) so a schema author seeing "saved successfully" also sees
  "confidentiality annotation ignored: <reason>" in the same response, not only in a log file they
  may never read.
- Until that shared mechanism lands, this change's validator at minimum logs at `error` (not
  `warning`) level with a message naming the schema and the specific defect, and the derived
  enforcement rule for a schema with an invalid annotation MUST NOT be silently treated as "no
  rule" — it MUST be treated as "read denied by default for the declared tier property until fixed"
  (fail-closed), which is the opposite default from quality/dedup/survivorship (fail-open-to-
  "feature doesn't run") precisely because this feature's job is access control.

### Object / access-control patterns (ADR-001, ADR-005, ADR-023)

All classified objects remain ordinary OR objects; reads/writes flow through `ObjectService`
unchanged. The derived rule is enforced inside the existing RLS chain, not a new authorization
layer bolted on top — there is no second "is this object secret" check elsewhere in the request
path to keep in sync. Fail-closed on validator error (above) follows the same CWE-863 discipline
already normative for `row-field-level-security`'s "Unresolvable dynamic variable denies access
safely" and `PropertyRbacHandler`'s write-only strip.

## Risks / Trade-offs

- **[A derived rule with the wrong clearance mapping fails closed]** → Mitigation: deliberate design
  choice (see "Loud discard" above) — the safer failure direction for an access-control primitive is
  "nobody can read the confidential object" over "everybody can", even though this differs from the
  existing quality/dedup fail-open convention. Documented explicitly so a future maintainer does not
  "fix" it back to fail-open for consistency.
- **[Tier vocabulary drift across schemas]** → Each schema declares its own `tiers` list; OR does not
  enforce a single fleet-wide vocabulary. Mitigation: this is deliberate (a college/gemeenteraad
  tier set differs from a generic internal/external one), but is flagged as an Open Question below
  for whether a shared vocabulary convention doc is worth writing once 2+ apps adopt this.
- **[Performance: an extra WHERE clause on every read of a classified schema]** → Mitigation: SQL-
  level generation via the existing `MagicRbacHandler` fragment builder, same cost class as any
  other authored RLS rule; no N+1, no post-fetch filtering.
- **[`releaseAtProperty` embargo timing depends on server clock]** → Same trust boundary
  `deprecate-published-metadata`'s `$now` already accepts; not a new risk surface.
- **[A schema declares `groundProperty` pointing at a `$ref`]** → OR does not validate the
  referenced object exists at annotation-save time (mirrors how `x-openregister-references` targets
  are not existence-checked until read); a dangling ground reference degrades to "ground shown as
  raw UUID" in the `@self.confidentiality` mirror, not a hard failure.

## Migration Plan

Additive only: a new annotation key, a new validator class, a new derived-rule source consumed by
existing enforcement points, and a new `@self.confidentiality` render key. No new OR schema, no
database migration, no change to any schema that does not declare the annotation. Rollback is
removing the validator registration and the derived-rule generator; schemas that declared the
annotation would then fall through `logDroppedAnnotationKeys()`'s generic "unknown key" warning
(safe — the annotation was advisory metadata, not a storage requirement, except for the fail-closed
enforcement behavior specified above, which also simply stops applying on rollback).

## Seed Data

No new OR schema is introduced by this change, so there is no register-level seed-data task. The
capability is exercised entirely by PHPUnit unit/integration tests against the validator and the
derived-rule generator using inline schema fixtures (mirroring how
`row-field-level-security`/`deprecate-published-metadata` are tested — no live Nextcloud, php:8.3-cli
+ OCP stubs). A worked example belongs in this design for reviewer clarity:

- Schema `besluit` (municipal decision), `tiers: ["public", "confidential"]`,
  `tierProperty: "confidentialityTier"`, `groundProperty: "confidentialityGround"`,
  `releaseAtProperty: "confidentialityReleaseUntil"`, `clearance: {"confidential": "raadsleden"}`.
  Example object: `{"confidentialityTier": "confidential", "confidentialityGround":
  "Gemeentewet art. 25", "confidentialityReleaseUntil": "2027-01-01T00:00:00+00:00"}` — unreadable
  by a caller outside group `raadsleden` until 2027-01-01, after which it is readable by anyone with
  ordinary schema-level access regardless of group.

## Open Questions

- Which shared mechanism `aggregation-dialect-repair`'s task 4 (loud annotation-failure surfacing)
  actually lands as — a response `warnings` array, a distinct validation-errors endpoint, or
  something else — determines the exact wiring of this change's "Loud discard" section. Not a
  blocker (the fail-closed enforcement behavior and `error`-level logging stand on their own), but
  the response-surfacing wiring should be revisited once that change ships.
- Whether a fleet-wide shared confidentiality-tier vocabulary convention (beyond "each schema
  declares its own `tiers`") is worth documenting once a second consumer (procest/zaakafhandelapp)
  adopts this primitive — deferred until there is a second real consumer to generalise from.
