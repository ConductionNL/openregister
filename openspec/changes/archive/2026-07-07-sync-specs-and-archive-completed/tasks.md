## 1. ai-mcp REQ-006

- [ ] 1.1 `/opsx-sync` the `or-tool-registry-facade` delta into `openspec/specs/ai-mcp/spec.md` (add REQ-006 listTools/invokeTool/whitelist/no-impersonation).
- [ ] 1.2 Archive `openspec/changes/or-tool-registry-facade` via `/opsx-archive`.

## 2. credential-broker canonical home

- [ ] 2.1 Create `openspec/specs/credential-broker/` from the three change folders' deltas (`credential-broker`, `credential-doriath-leaf`, `credential-provider-doffin`).
- [ ] 2.2 Archive the three change folders once synced.

## 3. DSAR

- [ ] 3.1 Verify (via `git log` on development) which `dsar-*` changes are actually merged; reconcile `dsar-escalation-and-dpia/tasks.md` (shows 0/10 but code exists).
- [ ] 3.2 Fold case-engine/escalation/DPIA/policy-pack requirements into `openspec/specs/gdpr-data-subject-rights/spec.md`.
- [ ] 3.3 Archive the six completed `dsar-*` changes.

## 4. event-driven-architecture counts

- [ ] 4.1 Update the spec prose to the real counts (32 listeners in `lib/Listener/`, 60 events in `lib/Event/`) and note the newer lifecycle listeners.

## 5. Verification

- [ ] 5.1 `/opsx-coverage-scan` (or the coverage tooling) shows the synced capabilities as covered, not gapped.
- [ ] 5.2 No completed change remains under `openspec/changes/` unarchived after this pass.

## Acceptance criteria

- ai-mcp REQ-006 and the credential-broker + DSAR case-engine requirements live
  in `openspec/specs/`.
- The six DSAR changes, `or-tool-registry-facade`, and the credential-broker
  chain are archived, matching merged state on `development`.
- `event-driven-architecture` counts match HEAD.
