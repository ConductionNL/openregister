---
kind: reverse-spec
---

## Why

Four already-shipped OpenRegister service clusters carry substantial behaviour that
no spec describes. This reverse-spec run documents the **observed** behaviour of
those classes and annotates their methods with `@spec` pointers so the code is
brought under the ADR-008 annotation convention. No behaviour changes — this is a
documentation/annotation pass extending four existing capabilities.

The four clusters:

1. **JSON-AST calculation engine** (`lib/Service/Calculation/*`). The
   `computed-fields` spec describes a *Twig-based* `ComputedFieldHandler`. The
   `Calculation` namespace is a **second, distinct** derivation engine: a pure-function
   evaluator over a single-key JSON expression AST (`{ "<op>": [args] }`) plus a
   schema-save validator for the `x-openregister-calculations` annotation. Neither the
   AST vocabulary, the save-time evaluation contract, nor the validator's cycle
   detection is captured anywhere. Extends `computed-fields`.

2. **Self-service profile actions backend** (`lib/Service/UserService.php`). The
   `profile-actions` spec describes the controller surface as "Not implemented", yet
   `UserService` already implements the service-layer logic for password change, avatar
   CRUD, GDPR export, notification preferences, activity history, API-token lifecycle,
   and account deactivation. The spec's scenarios target controllers; this run documents
   the service-layer contract that backs them, including two security-relevant
   observations (SHA-256 token-at-rest hashing; malformed-`expiresIn` rejection). Extends
   `profile-actions`.

3. **Organisation membership & multi-tenancy resolution** (`lib/Service/OrganisationService.php`).
   The `tenant-lifecycle` spec covers *provisioning* state machines. This service is the
   *runtime resolution* layer: per-user organisation membership, default-org bootstrap,
   active-org session caching/reconstruction, join/leave guards, and parent-chain
   resolution for hierarchical multi-tenancy. Distinct from provisioning and from
   tenant-isolation enforcement. Extends `tenant-lifecycle`.

4. **Built-in dashboard aggregations** (`lib/Service/DashboardService.php`). The OR-canned
   register/schema statistics, orphaned-item detection, size recalculation, and chart-data
   assembly (objects-by-register / -schema / -size, audit action distribution). Pure
   read-side aggregation. Extends `built-in-dashboards`.

## What Changes

- Document observed behaviour of the four clusters as spec deltas (new `### Requirement`
  blocks where uncovered; the existing specs gain ADDED requirements only — no scenarios
  are rewritten).
- Annotate the implementing methods with `@spec` pointers to this change's `tasks.md`.

### Dropped scanner groupings

- `lib/Service/ConditionMatcher.php` and `lib/Service/OperatorEvaluator.php` were bundled
  under `computed-fields` by the scanner. They are **RBAC condition matching** (MongoDB-style
  `$in/$gt/$lt/$exists` operators evaluated against authorization `match` rules), not
  computed-field derivation. They belong to `rbac-scopes`. `ConditionMatcher` is already
  annotated to `retrofit-2026-05-24-annotate-openregister`. Both are dropped from this bundle
  to avoid mis-attributing RBAC behaviour to the computed-fields capability. See the Notes in
  the computed-fields delta for the RBAC parity observation worth a future rbac-scopes run.

## Capabilities

### New Capabilities

None — all four are extensions of existing capabilities.

### Modified Capabilities

- `computed-fields`: ADDED requirements for the JSON-AST calculation engine and its
  schema-save validator.
- `profile-actions`: ADDED requirement for the `UserService` self-service backend contract.
- `tenant-lifecycle`: ADDED requirements for organisation membership resolution and active-org
  session caching.
- `built-in-dashboards`: ADDED requirement for the OR-canned dashboard aggregation contract.
  (Note: the local `built-in-dashboards` spec is a redirect stub pointing to the root openspec;
  this delta documents the OR-side service implementation only.)

## Impact

- **Code.** Docblock-only edits (`@spec` annotations) on:
  `lib/Service/Calculation/CalculationEvaluator.php`,
  `lib/Service/Calculation/CalculationAnnotationValidator.php`,
  `lib/Service/UserService.php`,
  `lib/Service/OrganisationService.php`,
  `lib/Service/DashboardService.php`.
- **No runtime behaviour change.** No new endpoints, no schema/DB migration.
- **Security observations surfaced** (not changed) in the profile-actions delta Notes.
