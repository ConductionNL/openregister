---
kind: chore
depends_on: []
---

## Why

Spec/code process hygiene: several capabilities have outgrown or drifted from
their canonical spec home, and completed changes were never archived. This makes
the spec catalog untrustworthy as a picture of what is implemented — reviewers
and `/opsx-coverage-scan` see gaps that are actually just un-synced work.

Verified drift at HEAD:

- **`ai-mcp` REQ-006 unsynced.** `lib/Service/Mcp/ToolRegistryFacade.php` (239
  lines, all tasks in `openspec/changes/or-tool-registry-facade/tasks.md`
  checked) implements REQ-006 (`listTools()`/`invokeTool()`, whitelist,
  no-impersonation), but `openspec/specs/ai-mcp/spec.md` only carries
  REQ-001…005 — REQ-006 was never folded in.
- **DSAR case-engine work not in the canonical spec.**
  `openspec/specs/gdpr-data-subject-rights/spec.md` has 5 requirements covering
  only the original request model, while ~44 PHP files implement cases,
  escalation tiers, DPIA detection, policy packs, evidence harvest, redaction,
  and retention sweeps. Six `openspec/changes/dsar-*` folders remain unarchived
  (their code is merged to development; `dsar-escalation-and-dpia/tasks.md` shows
  0/10 ticked while its code exists — branch skew, not fiction).
- **`credential-broker` has no canonical spec home.** Three fully-implemented
  change folders (`credential-broker`, `credential-doriath-leaf`,
  `credential-provider-doffin`) live only under `openspec/changes/`; there is no
  `openspec/specs/credential-broker/`.
- **Event/listener counts stale in `event-driven-architecture`.** Spec prose says
  "8 listeners, 39+ events"; actual is 32 listeners in `lib/Listener/` and 60 in
  `lib/Event/` — the spec undercounts (outgrown), not fiction.

## What Changes

- Sync `ai-mcp` REQ-006 from `or-tool-registry-facade` into
  `openspec/specs/ai-mcp/spec.md` (`/opsx-sync`), then archive that change.
- Promote the credential-broker chain to `openspec/specs/credential-broker/`
  (canonical home per ADR-004), then archive the three change folders.
- Fold the DSAR case-engine/escalation/DPIA/policy-pack requirements into
  `openspec/specs/gdpr-data-subject-rights/spec.md`; reconcile the stale
  `dsar-*/tasks.md` checkboxes against actually-merged code; archive the six
  completed DSAR changes.
- Correct the stale listener/event counts in the `event-driven-architecture`
  spec to match HEAD.

## Impact

- Affected: `openspec/specs/` (ai-mcp, gdpr-data-subject-rights, new
  credential-broker, event-driven-architecture), `openspec/changes/` (archive the
  completed folders).
- No code change; documentation/traceability only.
- Risk: none functional; ensure archiving matches the merged state on
  `development`, not a stale branch (verify with `git log` before archiving).
