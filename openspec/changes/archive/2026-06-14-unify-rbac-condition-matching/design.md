## Context

OpenRegister's RBAC has three enforcement points, all reading the same authorization JSON (resolved via `PermissionHandler::resolveAuthorization()`) but interpreting conditional `match` clauses with different grammars:

```
                 rule JSON (from Schema.authorization[$action])
                                      │
        ┌─────────────────────────────┼────────────────────────────┐
        ▼                             ▼                            ▼
  row-level (SQL)                schema-level (PHP)        property-level (PHP)
  MagicRbacHandler               PermissionHandler         PropertyRbacHandler
  .applyRbacFilters              .hasPermission            .canReadProperty etc.
  → buildMatchConditions         → hasGroupPermission      → ConditionMatcher ✅
    → buildPropertyCondition       → evaluateMatchConditions  (already delegates)
    → $eq/$ne/$gt/$gte/$lt/$lte      ❌ equality only
      $in/$nin/$exists ✅              ❌ only $organisation
    → $organisation/$userId/$now ✅      variable
      (via resolveDynamicValue)
```

The `rbac-scopes` spec already documents the full grammar — scenarios like "Time-based conditional access via `$now` variable" and "User-scoped access via `$userId` variable" are listed as implemented — but the implementation of these scenarios only reliably passes when the row-level SQL path is the one evaluating the rule. For single-object reads (`GET /.../{id}`, attachments, relation expansion, any code path that resolves through `ObjectService::find()`), the evaluation goes through `PermissionHandler::checkPermission()` → `hasPermission()` → `hasGroupPermission()` → `evaluateMatchConditions()` (line 588 of `PermissionHandler.php`), which is a local 35-line reimplementation that:

1. Uses strict `!==` equality. An operator object like `{ "$lte": "$now" }` compared against the object's `publishDate` string is *never* equal, so any rule with operators is always rejected.
2. Resolves only `$organisation`. `$userId` and `$now` pass through as literal strings, so `{ "assignedTo": "$userId" }` will only match an object whose `assignedTo` is the literal string `"$userId"` — never a real user id.
3. Takes `$objectOrganisation` as a separate parameter and only uses it for the special `_organisation` field; other `@self` properties cannot be referenced.

The exact same authorization JSON, evaluated by `MagicRbacHandler::buildMatchConditions()`, produces SQL with full operator and variable support. So the list endpoint cheerfully returns objects that the subsequent find / attachments call then rejects. OpenCatalogi's `PublicationsController` is the visible case.

Meanwhile, `ConditionMatcher` (`openregister/lib/Service/ConditionMatcher.php`) already exists as a shared service that handles the complete grammar: operators via `OperatorEvaluator`, dynamic variables for `$organisation`, `$userId`, `$now`, and `_`-prefixed field lookups through `@self` via `getObjectValue()`. `PropertyRbacHandler` is already wired to it (per the `rbac-scopes` spec: "conditional rule evaluation via `ConditionMatcher`"). Only `PermissionHandler` kept its own partial reimplementation.

`MagicRbacHandler` contains a *second* duplicate: its private `objectMatchesConditions()` / `valueMatchesOperator()` helpers (lines 774–947) form a full PHP-side condition matcher that nobody in the main code path calls — the SQL path is all `applyRbacFilters`, the only consumer of these private helpers is `MagicRbacHandler::hasPermission()` (line 640), which itself is not reached by `ObjectService::find()` (that path uses `PermissionHandler::checkPermission`). These helpers duplicate `ConditionMatcher` + `OperatorEvaluator` verbatim and must also be collapsed, or the next refactor will have three competing implementations again.

Stakeholders:
- **OpenCatalogi** — `PublicationsController::attachments` hits this on any publication-bearing schema that uses `public`-with-match rules. This is the originating bug report.
- **Every app that calls `ObjectService::find()` with `_rbac: true`** — procest, pipelinq, docudesk, softwarecatalog. Any of their schemas that use operator-based or `$userId` / `$now`-based rules silently has the same bug.
- **OAS consumers** — the generated OpenAPI already advertises the operator grammar (via `OasService`); external clients who request the scopes are currently hitting inconsistent enforcement.

## Reuse Analysis

Per ADR-011, this change is *entirely* a deduplication:

| Existing OpenRegister service | How this change leverages it |
|---|---|
| `OCA\OpenRegister\Service\ConditionMatcher` | Becomes the single PHP-side conditional-match evaluator for RBAC. Already used by `PropertyRbacHandler`. Public API `objectMatchesConditions(array $object, array $match): bool` is exactly the shape needed. |
| `OCA\OpenRegister\Service\OperatorEvaluator` | Unchanged. `ConditionMatcher` already delegates to it for `$eq/$ne/$gt/$gte/$lt/$lte/$in/$nin/$exists`. |
| `PermissionHandler::resolveAuthorization()` | Unchanged. Already the single source of authorization JSON, consulted by both SQL and PHP paths (MagicRbac delegates to it at line 1525). |
| `OrganisationService::getActiveOrganisation()` | Unchanged. Both `ConditionMatcher` and `MagicRbacHandler` already cache this per-request. |

No new utility is introduced. No logic is ported into a new location. Two existing implementations (`PermissionHandler::evaluateMatchConditions` and the private helpers inside `MagicRbacHandler`) are deleted in favour of the already-canonical one.

## Goals / Non-Goals

**Goals:**

- One shared PHP-side condition-match evaluator (`ConditionMatcher`) used by every RBAC enforcement point that runs a rule against a loaded object.
- Full operator set (`$eq/$ne/$gt/$gte/$lt/$lte/$in/$nin/$exists`) and full variable set (`$organisation/$userId/$now`) honoured by schema-level RBAC on the find / show / attachments path.
- Verdict equivalence: for any principal + rule + object, the list endpoint includes the object if and only if the find endpoint permits it.
- `_organisation` and any other `_`-prefixed match field resolved via `@self` through `ConditionMatcher::getObjectValue()`, without schema-level callers needing to know the envelope layout.

**Non-Goals:**

- Changing the SQL-emission path (`MagicRbacHandler::applyRbacFilters`, `buildMatchConditions`, etc.). That stays — it is genuinely a different emitter and handles the same grammar correctly.
- Redesigning the `_rbac: bool` parameter surface. That is a structural issue (leaky opt-in across service boundaries) but is outside this change's scope.
- Fixing OpenCatalogi's `PublicationsController::show` flag (`_rbac: false`) or the double-find in `attachments`. Those are consumer-side PRs — see the out-of-scope list in the proposal.
- Extending RBAC to cover file retrieval (`FileService::getFiles` currently has no RBAC awareness). Separate, larger change.
- Adding new operators, new dynamic variables, or new rule forms. This is a parity fix, not a capability expansion.
- Changing the exception wording (e.g. "User 'Anonymous' does not have permission") — cosmetic.

## Decisions

### Decision 1 — Delegate `PermissionHandler::evaluateMatchConditions` to `ConditionMatcher`

`PermissionHandler::evaluateMatchConditions()` at line 588 is replaced. The replacement is one of two forms:

**Option A (keep the method as a thin shim):**

```php
public function evaluateMatchConditions(
    array $conditions,
    ?array $objectData,
    ?string $objectOrganisation,
    ?string $activeOrganisation
): bool {
    // Fold the separately-passed organisation into the envelope so
    // ConditionMatcher::getObjectValue() can resolve _organisation via @self.
    $envelope = ($objectData ?? []);
    if ($objectOrganisation !== null) {
        $envelope['@self'] = ($envelope['@self'] ?? []) + ['organisation' => $objectOrganisation];
    }
    return $this->conditionMatcher->objectMatchesConditions($envelope, $conditions);
}
```

**Option B (remove `evaluateMatchConditions` entirely, call `ConditionMatcher` from `hasGroupPermission` directly):**

Inline the fold at the single call site in `hasGroupPermission()` (around line 556) and delete `evaluateMatchConditions`. Slightly cleaner; slightly more churn in the call site.

**Chosen: Option B.** `evaluateMatchConditions` was only ever called from `hasGroupPermission`; keeping it as a shim adds a layer with no useful abstraction. The `activeOrganisation` parameter also becomes dead — `ConditionMatcher` resolves `$organisation` itself via `OrganisationService`. Fewer arguments at the `hasGroupPermission` boundary is a bonus.

**Alternatives considered:**

- **Backfill operator support into `evaluateMatchConditions` in place.** Would work, but ships a *third* implementation of the same logic — the opposite of what ADR-011 says. Rejected.
- **Keep `evaluateMatchConditions` as an `@deprecated` wrapper.** No external callers — it is private to the RBAC chain. No migration period needed. Rejected.

### Decision 2 — Pass the full object envelope into `ConditionMatcher`

`ConditionMatcher::getObjectValue()` already handles `_organisation` → `@self.organisation` by stripping a leading underscore for any property prefixed with `_`. The current `hasGroupPermission` signature splits `objectData` and `objectOrganisation` into two parameters — likely a historical artefact of `evaluateMatchConditions`'s limited grammar.

The fold: merge `objectOrganisation` into `$objectData['@self']['organisation']` and pass the merged envelope to `ConditionMatcher`. Callers of `hasGroupPermission` already have both pieces (they come from `$object->getObject()` and `$object->getOrganisation()` in `hasPermission`), so the merge happens once at that single call site.

**Side effect:** any future `_`-prefixed match field (`_created`, `_published`, `_owner`, etc.) that callers put into `@self` will start working without further changes. Currently only `_organisation` is supported by `evaluateMatchConditions`; the SQL path already supports more. This change closes that gap for free.

**Alternatives considered:**

- **Add a second signature on `ConditionMatcher` that accepts `(objectData, objectOrganisation)` separately.** Leaks the historical shape into the shared service. Rejected.
- **Let `ConditionMatcher` look up the current user's organisation via `OrganisationService` on every `_organisation` reference regardless of the object's own organisation.** Would break the semantics: the rule `_organisation = $organisation` is meant to compare the *object's* org to the *user's* active org. `ConditionMatcher` already does exactly this when given the full envelope. Rejected.

### Decision 3 — Collapse `MagicRbacHandler`'s private PHP-side matcher

`MagicRbacHandler` has two evaluators inside:

1. `applyRbacFilters()` / `buildMatchConditions()` / `buildPropertyCondition()` / `buildSingleOperatorCondition()` — the SQL emitter. **Canonical row-level path. Stays.**
2. `hasPermission()` / `objectMatchesConditions()` / `objectPropertyMatchesCondition()` / `valueMatchesOperator()` / `singleOperatorMatches()` / `comparisonOperatorMatches()` / `arrayOperatorMatches()` / `existsOperatorMatches()` / `resolveDynamicValue()` — a PHP-side matcher (lines 640–947) that duplicates `ConditionMatcher` + `OperatorEvaluator` verbatim.

Group 2 is duplication with ADR-011 written on it. `hasPermission()` is kept (it is part of the class's public contract and a few paths want a PHP-side verdict without running SQL), but its body becomes: "resolve authorization, handle admin/owner bypass, iterate rules, for each conditional rule delegate to `ConditionMatcher::objectMatchesConditions()`". The private helpers in group 2 are removed.

**Alternatives considered:**

- **Leave `MagicRbacHandler::hasPermission` untouched since the bug report is about `PermissionHandler`.** Rejected. Leaves a dead-but-duplicated matcher that will drift from the canonical one. ADR-011 specifically calls this out: "deduplicate even if the current bug does not route through the duplicate".
- **Delete `MagicRbacHandler::hasPermission()` entirely.** Tempting — the main flow does not call it — but grepping is not enough of a safety guarantee, and the method is part of the spec's documented capability (`rbac-scopes` lists it under row-level RBAC). Better to collapse onto the shared matcher than to delete.
- **Move `hasPermission()` from `MagicRbacHandler` to `ConditionMatcher` or a new `RuleEvaluator`.** Interesting, but outside scope — it would move code between services without shrinking the surface. This change already hits the concrete bug; a larger re-architecture can happen on its own timeline.

### Decision 4 — No spec-requirement change, only scenario additions

The `rbac-scopes` spec already requires operator support and dynamic variable support at every enforcement point (see the "Conditional Scopes with Dynamic Variables" and "API Scope Enforcement Across All Access Methods" requirements, and the scenario list for `$userId`/`$now`/`$lte`). This change does not add a new *requirement* — it adds *scenarios* that explicitly prove parity between the list and find paths, and ties the normative language to the single-matcher contract (`ConditionMatcher` is the only PHP-side evaluator).

The delta is therefore in the `## MODIFIED Requirements` section of `specs/rbac-scopes/spec.md` and strengthens the wording of the existing "Conditional Scopes with Dynamic Variables" requirement with an explicit delegation clause.

**Alternatives considered:**

- **Introduce a new "Evaluator Unification" requirement.** Orthogonal to how the spec is organised. Rejected.
- **Put this in a new top-level spec (`rbac-evaluator-unification`).** Over-specifies — unification is an implementation concern subordinate to the `rbac-scopes` capability. Rejected.

## Risks / Trade-offs

- **Risk — `ConditionMatcher::objectMatchesConditions()` has a subtly different behaviour from `evaluateMatchConditions()` on null values or array values.** The old method returned `false` via strict `!==` for any mismatch. `ConditionMatcher`'s `singleConditionMatches()` has a more nuanced path: simple scalars do `===`, operator arrays go through `OperatorEvaluator`, a `null` resolved value means the condition cannot be met. **Mitigation:** the test matrix in `tasks.md` enumerates every operator/variable combination and every principal category (admin, owner, authenticated, anonymous). Any behavioural difference from the old implementation for plain equality rules should be caught there. If a real regression emerges, we have the old method in git history to diff.

- **Risk — `PropertyRbacHandler` (already using `ConditionMatcher`) might rely on the old `PermissionHandler::evaluateMatchConditions` for cross-layer consistency.** **Mitigation:** verified during exploration — `PropertyRbacHandler` uses `ConditionMatcher` directly; no call chain from `PropertyRbacHandler` into `evaluateMatchConditions`.

- **Risk — Callers of `MagicRbacHandler::hasPermission()` depend on side effects of the now-removed private helpers (logging, exception type).** **Mitigation:** search the codebase for `$this->logger` inside those private helpers — there are only `$this->logger->debug` entries during rule processing inside `applyRbacFilters` (which is untouched). The private PHP-side matchers (`objectMatchesConditions` etc.) do not log. No side effects to preserve.

- **Trade-off — `hasGroupPermission`'s signature becomes narrower** (no `objectOrganisation` or `activeOrganisation`). Any internal caller passing the old shape gets an argument-count mismatch at the call site, which the typechecker catches immediately.

- **Trade-off — `ConditionMatcher` becomes a hot-path dependency of `PermissionHandler`**, which is itself a hot path (every `ObjectService::find()` call hits it). The matcher already caches `$organisation` per request and is used by `PropertyRbacHandler` on the same call path, so the marginal cost is one extra service call per permission check. Acceptable.

- **Risk — Tests over-fit to the implementation rather than the spec.** **Mitigation:** tests are written in terms of verdicts (list includes X ⇔ find returns 200 for X), not in terms of which private helper got called.

## Migration Plan

1. No DB migration.
2. No API contract change — HTTP surface is unchanged; previously-broken endpoints start behaving correctly.
3. No consumer code change required. OpenCatalogi, softwarecatalog, procest, pipelinq, docudesk continue working unchanged and pick up the fix on deploy.
4. **Deploy order:** openregister first. Consumers see the fix immediately without redeploying.
5. **Rollback:** revert the PR. No persisted state to unwind. Any authorization JSON that was authored assuming the operator grammar worked correctly (and did work correctly on the list path) continues to work on the list path after rollback; only the schema-level PHP check reverts to its previous weaker form.

## Resolved Questions

- **`_organisation` resolution in the fold** — confirmed: `ConditionMatcher::getObjectValue()` at line 164 strips the leading `_` and looks up `@self[<stripped>]`. So merging `objectOrganisation` into `$envelope['@self']['organisation']` works out of the box for `_organisation` matches. Other `_`-prefixed match fields (`_created`, `_owner`, `_published`) are also supported for free once the envelope is passed whole.
- **`hasGroupPermission` call sites** — only `hasPermission` (line 169, 189, 211, 227) and `filterObjectsForPermissions` / `filterUuidsForPermissions` call it inside `PermissionHandler`. No external callers across `openregister/lib/`. Safe to narrow the signature.
- **`MagicRbacHandler::hasPermission` callers** — grep for `MagicRbacHandler` usage in `openregister/lib/` shows only `applyRbacFilters` and `buildRbacConditionsSql` are consumed externally. `hasPermission` is not currently consumed in the main code path, but is kept as an intentional part of the class's public surface per the `rbac-scopes` spec. Its body is collapsed onto `ConditionMatcher`.
- **Do we also remove `MagicRbacHandler::resolveDynamicValue` (PHP-side)?** Yes — it duplicates `ConditionMatcher::resolveDynamicValue` (private there, but the behaviour is identical). The SQL path does not reuse this helper; `buildPropertyCondition` / `buildSingleOperatorCondition` have their own value-resolution inline for SQL parameter binding. Two different concerns; the PHP-side one goes.
- **Behaviour on `$exists` with null value** — `ConditionMatcher`'s `valueMatchesOperator` delegates to `OperatorEvaluator`. Per the `rbac-scopes` spec, `$exists: true` requires non-null, `$exists: false` requires null. This matches `MagicRbacHandler::existsOperatorMatches()` (lines 936–947) verbatim, so removing the duplicate is a no-op.
- **`evaluateMatchConditions` was public on PermissionHandler — is it part of the external API?** Searching: `public function evaluateMatchConditions` has no calls outside `PermissionHandler::hasGroupPermission` itself. Safe to remove (it was public only because PHP has no `friend`-like modifier and a sibling helper needed access). No deprecation cycle needed.
