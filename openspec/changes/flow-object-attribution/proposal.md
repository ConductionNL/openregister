---
kind: code
---

## Why

A flow run records exactly one object: `FlowRun.subjectUuid`, the thing that triggered it. Everything the run goes on to touch — the objects it creates, the ones its nodes update, the ones a leaf app writes on its behalf — is recorded nowhere. So neither of the two questions people actually ask can be answered today:

- **From the object:** "which run, and which node of it, last touched this case?"
- **From the run:** "what did this run actually change?"

`FlowRunStep` already answers *what happened* per node. It does not answer *what it happened to*. That gap is why a flow that spans a case, its tasks, its decisions and its documents is unauditable in practice: the history exists, and it points at nothing.

## What Changes

- Every audit-trail row written while a flow run is executing is stamped with the run uuid, the node id and the step sequence that caused it. The stamp is set from an **ambient run context** established by the step dispatcher, so it captures writes made by code that has never heard of flows — including leaf apps a node calls into. A node-level record would see only what the node itself knew it wrote.
- Two read directions: `GET /api/flow-runs/{uuid}/objects` (objects touched, grouped by node) and a `flowRun` filter plus the three new fields on the existing audit-trail query.
- **BREAKING (audit chain):** the three keys join the canonical JSON that the hash chain covers, so the stamp is tamper-evident. Per ADR-003 Rule 4 this is a migration event: the genesis seed moves to `v2` and the table is re-sealed by the existing `AuditHashService::rechainAll()`.
- The re-chain is **verify-then-rechain**. The migration first verifies the existing chain against a *frozen copy* of the v1 canonicaliser and persists that verdict, then re-seals under v2. Without the frozen copy the pre-check would canonicalise under v2, report every row broken, and the re-chain would bless it anyway — a check that cannot distinguish a healthy chain from a tampered one is not a check.
- UI: the objects a run touched on the run detail, and the runs that touched an object in its sidebar.

## Capabilities

### New Capabilities
- `flow-object-attribution`: what a flow run records about the objects it touches — the ambient run context and its lifecycle, what a stamped row means, and how the attribution is read back from either end.

### Modified Capabilities
- `audit-hash-chain`: the canonical JSON gains three flow keys and the genesis seed is versioned; adds the requirement that a seed change is a verify-then-rechain migration with the outgoing canonicaliser frozen for the verification.
- `flow-engine`: the step dispatcher establishes and unconditionally clears the run context around every step, and a run can report the objects it touched.

## Impact

| Area | Change |
|---|---|
| `lib/Db/AuditTrail.php` | three fields + `jsonSerialize()` keys (hash-covered) |
| `lib/Db/AuditTrailMapper.php` | both insert paths read the ambient context; `flowRun` query filter |
| `lib/Service/AuditHashService.php` | seed becomes `v2`; frozen `v1` canonicaliser retained for migration verification |
| `lib/Service/Flow/RegistryStepDispatcher.php` | establishes / clears the context per step |
| `lib/Service/Flow/FlowRunContext.php` | new — the ambient holder |
| `lib/Controller/FlowRunController.php` | `objects` endpoint |
| `lib/Migration/` | columns + index; verify-then-rechain repair step |
| Frontend | run detail "objects touched"; object sidebar "flow runs" |

**Risk owned by this change:** a context that is not cleared attributes later, unrelated writes to a finished run. It is silent — the rows look correct. The clear is `finally`-bound and asserted by a test that performs a non-flow write *after* a run and requires the columns to be null.

**Consumers:** dossiq is the first, via the companion `case-flow-human-steps` change. Nothing in this change is dossiq-specific.
