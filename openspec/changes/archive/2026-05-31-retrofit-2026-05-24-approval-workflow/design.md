# Design — Retrofit approval-workflow (frontend surface)

> Retrofit change. Tasks describe retroactive annotation, not new implementation work.

## Context

The `approval-workflow` spec covered the backend HTTP surface (REQ-001..REQ-005) and was missing any description of the Vue surface that drives it. This retrofit captures the **observed** frontend behavior — what the panels actually do today — as REQ-006 and REQ-007.

## What is being annotated, not built

- `ApprovalChainPanel.vue` mounts under the schema-detail workflow tab and is the canonical UI for listing/creating approval-chain configurations for a single schema.
- `ApprovalStepList.vue` is mounted on object-detail surfaces to show the step ledger and expose decide controls.

Both components were authored well before the retrofit playbook existed; they are now reverse-spec'd so the dashboard reports them as covered.

## Drift observations (carried forward)

The coverage batch suggested 27 methods belonged to this capability. On inspection, only 2 of those 27 are actually approval-workflow behavior; the other 25 belong to `workflow-engine-abstraction`, `workflow-integration`, and `schema-hooks` (already specified). The proposal lists each off-capability method explicitly so they can be picked up by the correct retrofit run rather than re-surfaced as "uncovered" in the next scan.

## Decisions

- **REQs added: 2** (well under the 5-REQ cap).
- **No new capability minted** — this is a `--extend` of `approval-workflow`.
- **No code changes** — only annotation tags applied to the two methods listed.
- Suspicious behaviors (always-true `canDecide`, console-only error handling, client-side filtering instead of server query) are flagged in REQ Notes as observed-but-suspicious; they are NOT silently "fixed" by the spec language.
