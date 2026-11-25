# Tasks — confidentiality-classification-primitive (kind: code)

A generic per-object confidentiality tier + legal ground + timed release, declared via
`x-openregister-confidentiality` and enforced through the existing RLS/RBAC evaluation chain.
Reuses `MagicRbacHandler`/`ConditionMatcher`'s `$now` dynamic variable
(`deprecate-published-metadata`) and the `SchemaMapper::validate*Annotation()` pattern
(`quality`/`dedup`/`survivorship`). No new OR schema.

## 1. Annotation + validation

- [x] 1.1 Add `x-openregister-confidentiality` to `Schema::ANNOTATION_VOCABULARY` (`lib/Db/Schema.php`).
- [x] 1.2 Add `ConfidentialityAnnotationValidator` (`lib/Service/Confidentiality/`) validating: non-empty ordered `tiers`; `tierProperty` exists in schema `properties`; every `clearance` key is a member of `tiers`; `groundProperty` (if present) exists in `properties`; `releaseAtProperty` (if present) exists in `properties` and is `format: date-time`.
- [x] 1.3 Add `SchemaMapper::validateConfidentialityAnnotation()` wired the same way as `validateQualityAnnotation()` (`lib/Db/SchemaMapper.php:1118-1144`), but per the design's "Loud discard" decision: log at `error` (not `warning`) level and mark the schema so the derived-rule generator treats it as fail-closed rather than "no rule" (see task 2.4).

## 2. Derived read-enforcement

- [x] 2.1 Add a derived-rule generator that reads a schema's `x-openregister-confidentiality` and produces a conditional rule in the existing `{"group": ..., "match": {...}}` shape `MagicRbacHandler`/`ConditionMatcher` already consume, walking `clearance` inheritance from the object's tier down to the first tier that declares a requirement.
- [x] 2.2 Wire the derived rule into `MagicRbacHandler::applyRbacFilters()` as an additional OR-combined rule source for schemas declaring the annotation (SQL-level, list/search/export/facet paths) — no post-fetch PHP filtering.
  > `MagicRbacHandler::confidentialityTermSql()` compiles `ConfidentialityRule::readRules()` with the authored-rule compiler (`processAuthorizationRuleSql()`, so `$now` and the column mapping are the ones authored rules get), OR-ed with the caller's owner admits. `applyRbacFilters()` ANDs that term ahead of the authorization block; `buildRbacConditionsSql()` (and with it the row and alias predicates the aggregation and UNION paths use) ANDs it into every condition. The derived conditions are OR-ed with each other; the whole term is AND-ed with the schema's own rules. OR-ing it beside authored rules would let the derived "an untiered object is open to everyone" condition reopen objects an authored rule keeps from the caller, which the spec's worked example ("readable by anyone with ordinary schema-level access") also rules out. Only `read` is narrowed.
- [x] 2.3 Wire the same derived rule into `ConditionMatcher::objectMatchesConditions()` / `PermissionHandler::hasPermission()` for the single-object read path, reusing `$now` resolution for `releaseAtProperty` exactly as `deprecate-published-metadata` resolves it for `publicatieDatum`-style rules.
  > `Service/Confidentiality/ConfidentialityReadGate` evaluates the same derived conditions through `ConditionMatcher::objectMatchesConditions()` on the object's match envelope. `PermissionHandler::evaluatePermission()` asks it after the deny pass and before any grant, as a final refusal, only for a read of one object on a schema that declares the block.
- [x] 2.4 Implement the fail-closed behavior for a schema whose annotation failed validation (task 1.3): every object with a non-empty `tierProperty` value is denied read to non-owner/non-admin callers until the annotation is corrected.
  > A faulty block's rule admits only untiered objects on both paths (no `tierProperty` at all: nothing but the owner's rows), and `ConfidentialityReadGate::logFailClosed()` logs it at `error` level, once per schema and request, naming the schema slug and the defects.
- [x] 2.5 Confirm owner bypass and admin bypass apply to the derived rule identically to authored RLS rules (no new bypass logic — reuse the existing bypass evaluated in the same handler).
  > List path: the administrator and trusted-system bypass run before the term, and the term ORs in `ownerAdmitConditionsSql()`, the owner admits authored rules get. Find path: the `admin` group and the object's owner pass, as `bypassesGroupRules()` lets them.

## 3. Render mirror

- [x] 3.1 Add `@self.confidentiality` materialisation (`tier`, `ground`, `releaseAt`, `released`) at the `RenderObject` choke point for schemas declaring the annotation, following the same "materialise unconditionally at the render boundary" precedent as the `@self.relations` mirror.

## 4. Tests

- [x] 4.1 Add PHPUnit unit tests for `ConfidentialityAnnotationValidator` covering: valid annotation, undeclared `tierProperty`, unknown `clearance` tier, non-date-time `releaseAtProperty`, clearance-inheritance resolution.
- [x] 4.2 Add PHPUnit tests for the derived-rule generator + `MagicRbacHandler`/`ConditionMatcher` integration covering every scenario in `specs/confidentiality-classification/spec.md`: denied below clearance, allowed with clearance, allowed once released, future release does not grant access, owner/admin bypass, pagination count reflects only accessible objects, fail-closed on invalid annotation, unaffected schema without the annotation.
  > `ConfidentialityReadGateTest` (real `ConditionMatcher`), `PermissionHandlerConfidentialityTest` (find path through `hasPermission()`), `MagicRbacConfidentialityTest` (the handler's predicate run as SQL over rows in SQLite: of ten confidential rows, the three released ones are listed and counted).
- [x] 4.3 Add a render test asserting `@self.confidentiality` content for an unreleased and a released object.

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
