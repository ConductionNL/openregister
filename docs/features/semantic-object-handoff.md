# Semantic Object Handoff

Cross-app workflows hand objects over via **canonical semantic kinds** (`https://openregister.app/ns#Case`, `ns#Quote`, `ns#Contract`, `ns#Invoice`) — never via point-to-point bridges. OpenRegister owns the handoff/conversion engine (ADR-051, ADR-022 exclusivity): the emitting app never names a target app or schema slug; whichever installed schema *implements* the kind (ADR-048 markers) receives the object.

## Standards & architecture references

- ADR-051 — Cross-app workflows hand off via canonical semantic primitives (hydra)
- ADR-048 — Cross-app semantic references (the read direction; this feature is the write/convert direction)
- ADR-041 — Cross-app commands via events (the handoff-executed event shape)
- ADR-031 — Schema-declarative business logic (the `x-openregister-handoff` dialect)

## Overview

A **source schema** declares its handoffs in an `x-openregister-handoff` array (configuration block, ADR-031 dialect family). Each entry names a target kind URI, a trigger (`manual` or `lifecycle:<state>`), a mapping onto the kind's **contract fields** using five expression kinds (`from`, `const`, `template`, `semanticRef`, `provenance`), an optional degradation mode (`whenUnavailable: hide | queue`), and an optional `onSuccess.set` update applied to the source after a successful handoff.

An **implementing schema** claims a kind via its existing `implements[]` / `jsonld.type` / `x-schema-org` markers and binds each contract field to one of its own properties in a `handoffContract` block. Save-time validators reject malformed declarations (`handoff-bad-target-type`, `handoff-bad-mapping-expression`, `handoff-bad-success-update`) and incomplete bindings (`handoff-contract-incomplete`).

Executing a handoff (HandoffService):

1. Resolves the provider via `SemanticTypeResolver` (null-safe, disabled apps excluded, deterministic tie-break), filtered to schemas with a **complete** binding.
2. Creates the target object through the normal `ObjectService` write path **under the caller's RBAC** (create permission pre-checked — never escalated).
3. Links `handoff:<id>:handed-off-to` / `handoff:<id>:originated-from` provenance relations on both objects.
4. Writes one immutable audit row per side (`handoff.executed`, correlation id).
5. Applies `onSuccess.set` through the lifecycle-aware write path.
6. Dispatches a typed `HandoffExecutedEvent` (ADR-041 provenance + correlation id) so the providing app runs its intake logic in its own DI context.

Failure at any step leaves no partial state (compensation removes the created target and restores the source).

**Degradation** when no installed schema implements the kind: `hide` returns the machine-readable `handoff-provider-unavailable` response (409, never a 5xx) and the UI omits the action; `queue` parks the request durably in `oc_openregister_handoff_queue` and drains it automatically when a provider appears (schema-save / app-enable listeners + a 5-minute fallback job), re-evaluating the original requester's RBAC at drain time.

## API

| Method | Path | Behaviour |
|---|---|---|
| GET | `/api/objects/{register}/{schema}/{id}/handoffs` | Availability: every declared handoff with state `available` (naming the resolved provider schema), `unavailable` (+ machine-readable reason), or `queued` (+ queue entry). |
| POST | `/api/objects/{register}/{schema}/{id}/handoffs/{handoffId}` | Execute: `200 {status: executed, target: {register, schema, uuid}, correlationId}`, `202 {status: parked, queueEntry}` (queue mode), `404 handoff-not-declared`, `409 handoff-provider-unavailable`, `403` on RBAC refusal, `400` on target validation failure. |

Both endpoints require authentication and enforce per-object RBAC (read for availability; write-on-source + create-on-target for execute).

## Key capabilities

- Declarative handoff dialect on the source schema — pure data, validated at save, versioned with the register
- Kind-contract binding on the provider — emitters never name a concrete target property
- Seed kind contracts: `ns#Case` (title, summary, channel, source + requester?, priority?) and the order chain `ns#Quote` / `ns#Contract` / `ns#Invoice`
- Semantic references carried as references (UUID), never copied blobs
- Engine-filled provenance pointer + bidirectional provenance relations + immutable audit
- Durable queue-mode deferral with drain-time RBAC re-evaluation and requester notifications
- Lifecycle-triggered handoffs for real actors (no system-user privilege lane)
