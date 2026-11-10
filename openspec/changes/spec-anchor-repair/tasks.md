# Tasks — spec-anchor-repair (OpenRegister)

- [x] task-1: Measure broken `@spec` anchors repo-wide with gate-46 resolution logic (5,051 on base).
- [x] task-2: Categorise broken anchors by cause (archived→canonical-recoverable vs genuinely-dangling).
- [x] task-3: Build deterministic resolver (`tool/resolver.py`) — archived-tasks.md lookup (incl. date-prefixed convention), `<cap>#<REQ>` + section-heading capability recovery, exact requirement-heading anchor match.
- [x] task-4: Build comment-only repointer (`tool/repoint.py`) — rewrite through the `@spec` tag regex, gate-46 post-condition re-check before writing, dangling triage list.
- [x] task-5: Write the repointer's unit test (`tool/test_repoint.py`) — repoints a moved anchor, leaves an ambiguous one, proves logic byte-identical. Passing.
- [x] task-6: Apply the repointer — 3,643 anchors repointed across 695 files (896 anchor-level, 2,747 file-level).
- [x] task-7: Comment-only proof — 0 non-`@spec` changed lines out of 7,030; 0 files with asymmetric insertions/deletions.
- [x] task-8: Gate-46 re-verify — broken 5,051 → 1,408 (all repointed anchors resolve).
- [x] task-9: File the 1,408 residual-dangling anchors for human triage (`residual-dangling.md` + umbrella issue).
- [ ] task-10: STALE-BASE GUARD before push — `git diff --numstat origin/development` is `@spec`-lines-only (no logic deletions).
- [ ] task-11: PR to `development`, admin-merge, archive change.
