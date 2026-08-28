---
kind: code
---

## Why

decidesk classifies confidentiality in **five separate places** because OpenRegister has no
primitive for it: `AgendaItem.confidentiality` (an ad hoc enum), `Decision.isPublished` (a boolean
with no legal grounding), a hand-maintained public-publication deny-list, `commissievergaderingen`'s
`besloten-onderdeel` flag, and the `Geheimhouding` register (grounds + `bekrachtiging`/`opheffing`
per Gemeentewet art. 25/55/86). Each reinvents the same shape — a tier, a legal basis, and a
possible timed release — with its own property names, its own enforcement code, and no shared
guarantee that "confidential" means the same thing twice in the same app.

This is the ADR-022 drift pattern one layer up from ADR-045 (MDM surface) and ADR-047 (AVG/DSAR
workflow): a data-classification capability that every register touching government or otherwise
sensitive data will eventually need (decidesk today; procest, zaakafhandelapp, opencatalogi are the
next obvious candidates for zaaktype vertrouwelijkheid). Left app-side, every consumer grows its own
copy and drifts, exactly as pipelinq's MDM and AVG surfaces did before ADR-045/047.

OpenRegister already has half the enforcement machinery: `row-field-level-security` (RLS) supports
conditional per-object matching including a `vertrouwelijkheid`-style int comparison
(`{"$lte": N}`), and `deprecate-published-metadata` established the canonical timed-release pattern
— a `$now` dynamic variable resolved by both `MagicRbacHandler` (SQL) and `ConditionMatcher` (PHP)
so a match condition like `{"publicatieDatum": {"$lte": "$now"}}` becomes a working embargo with no
new column, no cron job, and no service. What is missing is not a new enforcement engine — it is a
**standard, schema-declarative shape** for "this object has a confidentiality tier, a legal ground,
and an optional release condition", generated once per schema instead of hand-authored per app, the
same way `x-openregister-quality`/`x-openregister-dedup` turned bespoke scoring/dedup code into
config.

## What Changes

- **New schema annotation `x-openregister-confidentiality`** (validated at schema-save time,
  mirroring the `x-openregister-quality`/`-dedup`/`-survivorship` pattern): declares the ordered
  tier vocabulary (least → most restrictive), which property holds the object's tier, which
  property (if any) holds a legal-ground reference, which property (if any) holds a release
  date/time, and which RBAC group is required to read at-or-above each tier.
- **A `ConfidentialityAnnotationValidator`** (`lib/Service/Confidentiality/`) validating the
  annotation shape at schema save — tier vocabulary is a non-empty ordered list, the declared
  tier/ground/release properties exist on the schema, and every tier named in the clearance map is
  in the tier vocabulary. Malformed annotations degrade to a non-fatal warning at save time
  (matching the existing quality/dedup/survivorship pattern) — but see the loud-failure requirement
  below.
- **Derived read-enforcement**, additive to the existing RLS/RBAC evaluation chain
  (`MagicRbacHandler` for SQL, `ConditionMatcher`/`PermissionHandler` for PHP-level checks): for any
  schema declaring the annotation, a rule is derived at evaluation time (not authored by hand) that
  denies read unless (a) the caller's group clears the object's tier per the annotation's clearance
  map, **or** (b) a declared release property is present and its value is `<= $now` (reusing the
  exact `$now` resolution path `deprecate-published-metadata` already ships) — an embargoed object
  becomes readable the instant its release condition is met, with no write, no job, and no
  materialisation step required.
- **A legal-ground reference field convention**: the annotation's `groundProperty` names an
  ordinary schema property (string or `$ref`); OpenRegister does not interpret its content — the
  declaring schema is free to use a free-text ground, an enum, or a `$ref` to a grounds-vocabulary
  register (e.g. decidesk's own Gemeentewet articles). This keeps jurisdiction-specific legal
  wording out of OR core, matching ADR-047's "jurisdiction is data, not code" pattern.
- **Read-time visibility of the resolved classification**: the object's effective tier, ground, and
  release status are exposed under `@self.confidentiality` on render (mirroring the
  `@self.relations` mirror pattern), so a UI can show "Confidential until 2027-01-01 (Gemeentewet
  art. 25)" without re-deriving the logic client-side.

**Explicitly out of scope:**
- The Gemeentewet-specific workflow (grounds vocabulary, `bekrachtiging`/`opheffing` approval flow,
  council-decision-triggered declassification) stays in decidesk. This change ships the primitive
  the workflow will declare against, not the workflow itself — the same split ADR-047 drew between
  OR's case-engine spine and pipelinq's NL policy pack.
- No UI. No admin management screen for authoring the annotation (schemas are still edited as JSON,
  same as quality/dedup/survivorship today).
- No migration of decidesk's five existing mechanisms — that is separate, decidesk-side follow-up
  work once this primitive lands (tracked in decidesk's Back to Six programme).
- No change to `row-field-level-security`'s authored-rule contract — the derived rule is a new
  input into the same evaluation chain, not a change to how hand-authored RLS rules behave.

## Capabilities

### New Capabilities
- `confidentiality-classification`: the `x-openregister-confidentiality` schema annotation, its
  save-time validator, the derived read-enforcement rule (tier clearance + timed release via
  `$now`), and the `@self.confidentiality` render mirror.

### Modified Capabilities
<!-- None. This change adds a new rule SOURCE that plugs into the existing RLS/RBAC evaluation
     chain (MagicRbacHandler / ConditionMatcher / PermissionHandler) and reuses the `$now`
     dynamic-variable resolution `deprecate-published-metadata` already ships. It does not alter
     the behavior or contract of row-field-level-security's hand-authored conditional rules, and it
     does not touch deprecate-published-metadata's requirements — both are consumed unchanged. -->

## Impact

- **New code**: `ConfidentialityAnnotationValidator`; a derived-rule generator invoked from the same
  seam `MagicRbacHandler::applyRbacFilters()` / `PermissionHandler::hasPermission()` /
  `ConditionMatcher::objectMatchesConditions()` already use for authored RLS rules; a render-time
  mirror writer for `@self.confidentiality` alongside the existing `RenderObject` choke point.
  `Schema::ANNOTATION_VOCABULARY` gains `x-openregister-confidentiality`.
- **Consumes (unchanged)**: `MagicRbacHandler` SQL-level filtering + `$now`/`$organisation`/`$userId`
  dynamic-variable resolution, `ConditionMatcher` PHP-level evaluation, `PermissionHandler` schema-
  level checks, the admin/system-context bypass conventions already governing `_rbac`/
  `SystemOperationContext`, and the `SchemaMapper::validate*Annotation()` warn-not-reject pattern.
- **APIs**: no new endpoints. Existing REST/GraphQL/search/export read paths gain the derived
  enforcement automatically wherever the annotation is declared, the same way declaring
  `x-openregister-quality` today materialises a score with no new endpoint.
- **No breaking change**: a schema that does not declare `x-openregister-confidentiality` is
  unaffected. Existing `vertrouvelijkheid`/`vertrouwelijkheid`-style hand-authored RLS rules (see
  `row-field-level-security`'s own scenarios) continue to work unchanged; this annotation is an
  alternative to hand-authoring that class of rule, not a replacement requirement.
- **Downstream**: decidesk's `Geheimhouding`/`AgendaItem.confidentiality`/`Decision.isPublished`/
  `besloten-onderdeel`/deny-list consolidation is separate, tracked follow-up work once this
  primitive is available (decidesk Back to Six programme). procest/zaakafhandelapp zaaktype
  vertrouwelijkheid is a plausible second consumer, not scoped here.
