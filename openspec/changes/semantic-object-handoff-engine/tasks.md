# Tasks — semantic-object-handoff-engine (kind: code, depends_on: semantic-object-handoff)

OR-side implementation of the hydra ADR-051 contract. Verify every claim against HEAD before
building — do not restate resolver behaviour from memory (`git show origin/development:lib/Service/SemanticTypeResolver.php`).

## 0. Unblock the dependency (pre-existing defect)

- [ ] 0.1 Resolve the committed merge-conflict markers in `lib/Service/SemanticTypeResolver.php` (lines 61–69, 155–159, 193–290 on HEAD 6b0534094; `php -l` fails at line 155) keeping the `origin/development` side of each hunk (the `implementedTypesWithAncestors` / `ancestorTypesForRef` path + the PHPMD suppression docblock), and the matching markers in `tests/Unit/Service/SemanticTypeResolverTest.php`; `php -l` + the resolver unit tests must pass before any task below starts.

## 1. Dialect + validators

- [ ] 1.1 Add the seed kind-contract map (data, keyed by kind URI) for `ns#Case`, `ns#Quote`, `ns#Contract`, `ns#Invoice` with mandatory/optional field sets exactly as the hydra contract specs define them (`handoff-contract-case`, `handoff-contract-order-chain`); one source consulted by both validators and the engine.
- [ ] 1.2 Add `HandoffAnnotationValidator` (source-schema side) mirroring `PropertyReferenceTypeValidator`'s error style: unique ids, absolute `targetSemanticType` (`handoff-bad-target-type`), trigger `manual`/`lifecycle:<state>` (state must exist in the schema's lifecycle), mapping keys ⊆ contract fields with all mandatory fields mapped, expression kinds ⊆ `[from, const, template, semanticRef, provenance]` (`handoff-bad-mapping-expression`), `whenUnavailable` ∈ {hide, queue}, `onSuccess.set` keys exist on the source schema (`handoff-bad-success-update`).
- [ ] 1.3 Add `HandoffContractBindingValidator` (provider side): a schema whose implemented types include a contract-carrying kind and that declares `handoffContract` must bind every mandatory contract field to an existing own property (`handoff-contract-incomplete` listing the missing fields); no binding block ⇒ not a handoff provider (ADR-048 reference resolution unaffected).
- [ ] 1.4 Wire both validators into the schema-save path exactly where `NotificationAnnotationValidator` / `CalculationAnnotationValidator` run (`SchemaMapper`).

## 2. HandoffService

- [ ] 2.1 Add `lib/Service/Handoff/HandoffService`: load source via `ObjectService` under the caller's RBAC; read the declared entry (`handoff-not-declared` when absent); resolve the provider via `SemanticTypeResolver::resolveSchemaByImplements` filtered to schemas with a complete `handoffContract` binding.
- [ ] 2.2 Add the mapping evaluator: `from` copy, `const`, `template` (`{{prop}}` interpolation, HTML-escaped, existing convention), `semanticRef` (carry the reference UUID — never dereference or copy referenced data), `provenance` (engine-filled source pointer); translate contract fields to provider properties exclusively through the `handoffContract` binding.
- [ ] 2.3 Make steps create→relations→audit→`onSuccess.set` atomic in one `IDBConnection` transaction: target create through the `ObjectService` write path, `handoff` typed relation on both objects (`handed-off-to` / `originated-from`), one immutable audit row per side (actor, kind, handoff id, correlationId, resolved schema, deferred flag) via `AuditTrailMapper`, source update through the lifecycle-aware write path; any failure rolls everything back and surfaces the underlying error.
- [ ] 2.4 Enforce RBAC without escalation: write on the source AND create on the resolved target schema, both as the calling user; permission denial → typed 403 before any write.
- [ ] 2.5 Support `trigger: lifecycle:<state>` by invoking the same `execute()` from a lifecycle-transition listener with the transitioning user as actor.

## 3. Degradation — hide + queue

- [ ] 3.1 `hide` mode: availability omits the action; direct execute returns the machine-readable `handoff-provider-unavailable` response (409-class, never 5xx).
- [ ] 3.2 Add `HandoffQueueEntry` entity + mapper + migration (`oc_openregister_handoff_queue`: source ref, handoff id, kind URI, requesting user, correlationId, attempt counter, status parked/executed/failed-permission/failed-validation/cancelled, timestamps, last error) — append-only attempt semantics per the `WebhookLog` pattern.
- [ ] 3.3 `queue` mode: park + audit `handoff.queued` + return 202; drain on (a) schema-save when the saved schema's implemented types (incl. `allOf` ancestors) cover a parked kind, (b) app-enabled events, (c) a fallback `TimedJob` sweep (300 s cadence class, `WebhookRetryJob` pattern) for paths that bypass the listeners (e.g. register import).
- [ ] 3.4 Deferred execution runs as the recorded requesting user with RBAC re-evaluated at drain time; lost permission → `failed-permission` + requester notification; audit rows record `deferred: true` with park/drain timestamps.

## 4. Events

- [ ] 4.1 Add `HandoffExecutedEvent` (provenance: sourceApp + source register/schema/uuid + subject label + correlationId; target kind + resolved register/schema/uuid; handoff id; deferred flag), dispatched post-commit only; terminal-state feedback reuses the existing ADR-041 conclusion-event pattern via the echoed correlationId; the integration registry is NOT used as transport (gate-27).

## 5. REST surface

- [ ] 5.1 Add `HandoffController` with GET `/api/objects/{register}/{schema}/{id}/handoffs` (availability incl. `queued` state and resolver-reason codes for the "provider not installed" UI copy) and POST `/api/objects/{register}/{schema}/{id}/handoffs/{handoffId}` (execute/park).
- [ ] 5.2 Register both in `appinfo/routes.php`; `#[NoAdminRequired]` with a per-object authorization guard in each method body (read for availability, write for execute); no `#[PublicPage]`, CSRF enabled; verify route↔method reachability both ways (ADR-005/016/029).

## 6. Tests

- [ ] 6.1 PHPUnit (CI way: php:8.3-cli + OCP stubs) for: both validators (accept/reject matrices incl. the four contract error codes), mapping evaluator (five kinds, HTML-escaped template, semanticRef carries UUID only), atomic rollback on target-create failure (no relation/audit/source mutation), RBAC refusal without escalation, hide-mode response shape, queue park/drain incl. failed-permission on drain, and event emission fields.
- [ ] 6.2 Newman collection for the two endpoints (available/unavailable/queued/not-declared/forbidden cases); Playwright e2e for the spec's @e2e flow (handoff action offered → executed → provenance visible both ways → degraded explained state with the provider app disabled).
