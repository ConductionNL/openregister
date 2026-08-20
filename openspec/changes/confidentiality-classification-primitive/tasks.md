# Tasks — confidentiality-classification-primitive (kind: code)

A generic per-object confidentiality tier + legal ground + timed release, declared via
`x-openregister-confidentiality` and enforced through the existing RLS/RBAC evaluation chain.
Reuses `MagicRbacHandler`/`ConditionMatcher`'s `$now` dynamic variable
(`deprecate-published-metadata`) and the `SchemaMapper::validate*Annotation()` pattern
(`quality`/`dedup`/`survivorship`). No new OR schema.

## 1. Annotation + validation

- [ ] 1.1 Add `x-openregister-confidentiality` to `Schema::ANNOTATION_VOCABULARY` (`lib/Db/Schema.php`).
- [ ] 1.2 Add `ConfidentialityAnnotationValidator` (`lib/Service/Confidentiality/`) validating: non-empty ordered `tiers`; `tierProperty` exists in schema `properties`; every `clearance` key is a member of `tiers`; `groundProperty` (if present) exists in `properties`; `releaseAtProperty` (if present) exists in `properties` and is `format: date-time`.
- [ ] 1.3 Add `SchemaMapper::validateConfidentialityAnnotation()` wired the same way as `validateQualityAnnotation()` (`lib/Db/SchemaMapper.php:1118-1144`), but per the design's "Loud discard" decision: log at `error` (not `warning`) level and mark the schema so the derived-rule generator treats it as fail-closed rather than "no rule" (see task 2.4).

## 2. Derived read-enforcement

- [ ] 2.1 Add a derived-rule generator that reads a schema's `x-openregister-confidentiality` and produces a conditional rule in the existing `{"group": ..., "match": {...}}` shape `MagicRbacHandler`/`ConditionMatcher` already consume, walking `clearance` inheritance from the object's tier down to the first tier that declares a requirement.
- [ ] 2.2 Wire the derived rule into `MagicRbacHandler::applyRbacFilters()` as an additional OR-combined rule source for schemas declaring the annotation (SQL-level, list/search/export/facet paths) — no post-fetch PHP filtering.
- [ ] 2.3 Wire the same derived rule into `ConditionMatcher::objectMatchesConditions()` / `PermissionHandler::hasPermission()` for the single-object read path, reusing `$now` resolution for `releaseAtProperty` exactly as `deprecate-published-metadata` resolves it for `publicatieDatum`-style rules.
- [ ] 2.4 Implement the fail-closed behavior for a schema whose annotation failed validation (task 1.3): every object with a non-empty `tierProperty` value is denied read to non-owner/non-admin callers until the annotation is corrected.
- [ ] 2.5 Confirm owner bypass and admin bypass apply to the derived rule identically to authored RLS rules (no new bypass logic — reuse the existing bypass evaluated in the same handler).

## 3. Render mirror

- [ ] 3.1 Add `@self.confidentiality` materialisation (`tier`, `ground`, `releaseAt`, `released`) at the `RenderObject` choke point for schemas declaring the annotation, following the same "materialise unconditionally at the render boundary" precedent as the `@self.relations` mirror.

## 4. Tests

- [ ] 4.1 Add PHPUnit unit tests for `ConfidentialityAnnotationValidator` covering: valid annotation, undeclared `tierProperty`, unknown `clearance` tier, non-date-time `releaseAtProperty`, clearance-inheritance resolution.
- [ ] 4.2 Add PHPUnit tests for the derived-rule generator + `MagicRbacHandler`/`ConditionMatcher` integration covering every scenario in `specs/confidentiality-classification/spec.md`: denied below clearance, allowed with clearance, allowed once released, future release does not grant access, owner/admin bypass, pagination count reflects only accessible objects, fail-closed on invalid annotation, unaffected schema without the annotation.
- [ ] 4.3 Add a render test asserting `@self.confidentiality` content for an unreleased and a released object.

## 5. Verification

- [ ] 5.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and the Hydra mechanical gates (spdx-headers, unsafe-auth-resolver, orphan-auth); fix any pre-existing issues touched.
- [ ] 5.2 Run `openspec validate --change confidentiality-classification-primitive --strict`; resolve any errors.

## Acceptance Criteria

- A schema declaring a valid `x-openregister-confidentiality` gets tier-clearance + timed-release
  read enforcement with zero additional app code, evaluated at the SQL level for list/search.
- An invalid annotation fails closed (denies read) rather than silently granting access, and logs at
  `error` level.
- A schema without the annotation is provably unaffected (existing RLS/RBAC test suite stays green).
- `@self.confidentiality` reflects the resolved tier/ground/release state for every caller who can
  read the object.
- No change to `row-field-level-security`'s or `deprecate-published-metadata`'s existing contracts —
  their own test suites stay green unmodified.

## Quality Checklist

- Reused `MagicRbacHandler`/`ConditionMatcher`'s `$now` resolution and the authored-rule shape rather
  than building a parallel enforcement engine (ADR-011).
- SPDX + `@license`/`@copyright` docblock headers on every new PHP file (EUPL-1.2).
- Fail-closed behavior on validator error is deliberate and documented in design.md — do not
  "simplify" it to match the quality/dedup/survivorship fail-open convention.
- No jurisdiction-specific wording (Gemeentewet or otherwise) added to OR core; `groundProperty`
  content stays opaque to OpenRegister.
