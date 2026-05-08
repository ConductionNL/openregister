# Design — approval-workflow

> **Retrofit change.** Tasks describe retroactive annotation, not new implementation work. All code already exists.

## Overview

The approval-workflow capability introduces multi-step, role-gated approval chains for OpenRegister objects. Administrators configure named chains with ordered steps, each bound to a Nextcloud group. When an object enters a chain, one `ApprovalStep` record per chain step is created — the first step starts as `pending`, all others as `waiting`. Authorised users approve or reject the pending step; on approval the next waiting step is automatically advanced to `pending`.

## Data Model

### ApprovalChain
- `id` — integer primary key
- `name` — string, required
- `steps` — JSON array of step definitions, each with `role` (Nextcloud group ID) and optional `order` integer

### ApprovalStep
- `id` — integer primary key
- `chainId` — FK to ApprovalChain
- `objectUuid` — UUID of the object in the chain
- `stepOrder` — integer, determines execution sequence
- `role` — Nextcloud group ID (copied from chain step definition at initialization)
- `status` — enum: `pending` | `waiting` | `approved` | `rejected`
- `decidedBy` — Nextcloud user ID (nullable)
- `decidedAt` — timestamp (nullable)
- `comment` — string (nullable)

## Component Breakdown

### ApprovalController
REST controller exposing CRUD for `ApprovalChain` + action endpoints for `ApprovalStep`:
- Standard CRUD: `index`, `show`, `create`, `update`, `destroy` → delegates to mapper
- `objects(int $id)` — groups `ApprovalStep` records by `objectUuid` for a given chain, computes `approved` count and `total` count per object
- `steps()` — lists `ApprovalStep` records with optional query param filters (`status`, `role`, `chainId`, `objectUuid`)
- `approve(int $id)` / `reject(int $id)` — reads `comment` from request body, delegates to `ApprovalService`, maps service exceptions to HTTP 403/400

### ApprovalService
Business logic:
- `initializeChain` — iterates chain steps sorted by `order`; creates step records setting first as `pending`, subsequent as `waiting`
- `approveStep` — verifies role via `IGroupManager`, sets status=`approved` + metadata, finds next `waiting` step (lowest order > current), advances it to `pending`, persists workflow execution history
- `rejectStep` — same role verification, sets status=`rejected` + metadata, does NOT advance the next step

## Known Observations

- `statusOnApprove` / `statusOnReject` fields exist in chain step definitions and are returned in the service result, but the controller does not act on them to update object state — effectively dead config at this time.
- `calculateDelay` in the related `ActionRetryJob` is not part of this cluster.
- Rejection leaves the chain blocked — no auto-advance, no notification mechanism observed.

## Seed Data

Not applicable — retrofit change, no seed data tasks required.
