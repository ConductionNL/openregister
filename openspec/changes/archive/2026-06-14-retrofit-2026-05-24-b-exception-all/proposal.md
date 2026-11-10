# retrofit-2026-05-24-b-exception-all

## Why

The `lib/Exception/` directory contains a family of exception classes whose
constructor/getter plumbing is already implicitly covered by host-capability
scenarios, but a subset of them carry a *structured payload contract* that
downstream consumers (UI error rendering, API clients, audit-log clients)
depend on. Those payload shapes are not pinned by any current spec.

This is a reverse-spec retrofit (observed behavior, no code changes beyond
`@spec` annotations). It bundles four sub-clusters over 8 files / 21 methods
into ONE ghost change: three `--extend` clusters that pin payload contracts
onto existing host capabilities, and one explicit DROP cluster.

The bias is to **extend** the host capability rather than create a parallel
"exception" sub-capability — the exception's behavior *is* the host spec's
diagnostic/envelope requirement, so the new REQs only pin the concrete key
shape where the host spec is silent on it.

## What Changes

### Cluster 1 — `exception-reference-validation` → extend `reference-existence-validation`

`CircularReferenceException` and `ReferenceValidationException` both subclass
`ValidationException` and expose structured getters plus a `toArray()`
diagnostic payload. The host spec already requires "structured diagnostic
information" (l.219) and "circular reference chains MUST be detected" (l.248),
and it pins the *HTTP 422 `details` envelope* shape (`property`,
`referenceUuid`, `targetSchema`, `targetRegister`, `validationType`).

It does **not** pin the *exception-level* `toArray()` keys, which use a
distinct internal naming (`propertyName`, `referencedUuid`, `targetSchemaSlug`,
`targetRegister`, `message`, `code`) and — for the circular variant — a `cycle`
array. Those are the keys the controller maps from, so they are a real
contract. ONE new REQ pins both `toArray()` shapes and the structured getters.

Annotated: `CircularReferenceException` (__construct, getReferencedUuid,
getTargetSchemaSlug, getCycle, toArray) + `ReferenceValidationException`
(__construct, getPropertyName, getReferencedUuid, getTargetSchemaSlug,
getTargetRegister, toArray) — 11 methods.

### Cluster 2 — `exception-provider-unavailable` → extend `generic-integrations`

`ProviderUnavailableException` carries the AD-23 actionable-error contract: the
four `CAUSE_*` classification constants drive the UI's "Reconfigure connector"
vs "Service offline" rendering, and `getDetails()` returns the `{cause: ...}`
payload the frontend consumes. The host spec's "Graceful Degradation"
requirement specifies the *trigger* (subsystem unreachable → degraded health)
but not the cause vocabulary or the `details.cause` payload. ONE new REQ pins
the four permitted cause values and the getCause()/getDetails() shape.

Annotated: `ProviderUnavailableException` (__construct, getCause, getDetails) —
3 methods.

Note: the active `pluggable-integration-registry` change is still pre-archive
and the class docblock already points at its task-4. The bundle guidance was to
route this delta there if it were the canonical home; however this retrofit is
scoped as ONE ghost change against the merged host capability
(`generic-integrations`, created by archiving `integration-shares`). The new
REQ is additive and does not conflict with the active change.

### Cluster 3 — `exception-append-only` → extend `audit-trail-immutable`

`AppendOnlyException::toResponseBody()` returns the structured HTTP 405 body
`{error: "SCHEMA_APPEND_ONLY", message, schema, operation}` raised when an
UPDATE/DELETE is attempted on an `appendOnly: true` schema. The host spec's
"Audit trail entries MUST NOT be deletable" requirement pins a *different*
405 envelope (`{"error": "Audit trail entries cannot be deleted"}`, prose-only)
for the audit-trail *API endpoints*. The append-only *schema-write* refusal is
a separate, machine-readable code-based envelope that audit-log clients use to
distinguish "append-only refusal" from "lock" from "not-found". ONE new REQ
pins the four envelope keys plus the canonical `SCHEMA_APPEND_ONLY` error code.

Annotated: `AppendOnlyException` (__construct, getSchemaIdentifier,
getOperation, toResponseBody) — 4 methods.

### Cluster 4 — `exception-plumbing-drop` → DROP (no spec, no annotation)

These five methods across four files are pure signaling exceptions:
constructor formats a message + sets an HTTP code; the single getter (where
present) exposes the ctor arg. There is no testable behavior independent of the
host spec that *throws* them.

- `LockedException::__construct` — "write on a locked object yields 423" belongs
  to the lock/concurrency requirement in `object-lifecycle`, not the exception.
- `RegisterNotFoundException::__construct` / `SchemaNotFoundException::__construct`
  — the "missing register/schema yields 404" behavior is covered by
  `object-lifecycle` lookup scenarios.
- `HookStoppedException::__construct` / `HookStoppedException::getErrors` — the
  "a hook may stop the operation and surface its errors" behavior is covered by
  the `schema-hooks` capability in scenario form.

Annotating these would add `@spec` noise without bucketing any new contract.
They are dropped explicitly per the architect decision.

## Impact

- Specs: `reference-existence-validation`, `generic-integrations`,
  `audit-trail-immutable` each gain ONE new ADDED requirement (3 new REQs total).
- Code: `@spec` annotations only on the 18 non-dropped methods across 4 files
  (`CircularReferenceException`, `ReferenceValidationException`,
  `ProviderUnavailableException`, `AppendOnlyException`). No behavior changes.
- Dropped: 5 plumbing methods across 4 files
  (`LockedException`, `RegisterNotFoundException`, `SchemaNotFoundException`,
  `HookStoppedException`) — documented above, not annotated.
