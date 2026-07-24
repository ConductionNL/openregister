# spec-anchor-repair — fleet @spec anchor traceability repair

Change name: `spec-anchor-repair`. Gate-46 (`spec-anchor-existence`) enforces that
every `@spec openspec/...` docblock tag resolves to a real file + `#requirement`
heading. A fleet audit found broken anchors on a mass scale — the traceability
layer was largely fiction.

## Root cause

`/opsx-annotate` retrofit runs tagged methods with
`@spec openspec/changes/<slug>/tasks.md#task-N` — pointing at the **change dir**,
not canonical `openspec/specs/`. When each change was archived
(`changes/ → changes/archive/<date>-<slug>/`) the target evaporated. The intended
requirement is recoverable: the archived `tasks.md` line encodes the capability
and requirement-heading text verbatim (`task-7: widget-registry#REQ-001 — The
system MUST …`).

## Fleet category breakdown (measured repo-wide with gate-46 resolution logic)

Total broken fleet-wide: **24,502** across 23 apps. Auto-repointable (canonical
target verifiably resolves): **12,405 (51%)**; residual-dangling for human
triage: **12,097**.

| app | broken | anchor | file | dangling | auto% |
|---|---|---|---|---|---|
| openregister | 5033 | 878 | 2580 | 1575 | 69% |
| shillinq | 2759 | 0 | 1121 | 1638 | 41% |
| pipelinq | 2676 | 206 | 511 | 1959 | 27% |
| procest | 2487 | 68 | 419 | 2000 | 20% |
| openbuild | 1373 | 544 | 625 | 204 | 85% |
| softwarecatalog | 1329 | 2 | 1159 | 168 | 87% |
| opencatalogi | 1237 | 99 | 574 | 564 | 54% |
| decidesk | 1199 | 62 | 457 | 680 | 43% |
| hermiq | 1160 | 333 | 49 | 778 | 33% |
| openconnector | 913 | 3 | 722 | 188 | 79% |
| docudesk | 864 | 83 | 401 | 380 | 56% |
| zaakafhandelapp | 697 | 0 | 0 | 697 | 0% |
| doriath | 521 | 130 | 65 | 326 | 37% |
| hrmq | 514 | 0 | 366 | 148 | 71% |
| launchpad | 427 | 0 | 142 | 285 | 33% |
| larpingapp | 316 | 0 | 297 | 19 | 94% |
| scholiq | 292 | 15 | 263 | 14 | 95% |
| nldesign | 241 | 0 | 177 | 64 | 73% |
| planix | 200 | 0 | 46 | 154 | 23% |
| portaliq | 173 | 0 | 0 | 173 | 0% |
| petstore | 55 | 0 | 2 | 53 | 4% |
| nextcloud-app-template | 27 | 0 | 6 | 21 | 22% |
| nextcloud-vue | 9 | 0 | 0 | 9 | 0% |
| **TOTAL** | **24502** | **2423** | **9982** | **12097** | **51%** |

(anchor = repointed to exact requirement heading; file = repointed to capability
spec file; dangling = flagged, never guessed. The 0%-auto apps —
zaakafhandelapp/portaliq/nextcloud-vue — are dominated by re-headed anchors in
*existing* specs, a harder ambiguous class that correctly stays in triage.)

## Mapping to the a/b/c/d cause taxonomy

- (a) file moved/renamed + (c) archived → canonical recoverable = the bulk of the
  auto-repointed 12,405 (`changes/<slug>/…` → `specs/<cap>/…`).
- (b) requirement re-headed → the 2,423 exact-heading anchor repoints, plus the
  bulk of the 0%-auto apps' dangling (re-headed in existing specs — deferred).
- (d) genuinely dangling → the residual 12,097 (non-annotate tasks.md, decimal
  task refs, design/proposal refs, capabilities genuinely deleted).

## The tool (deterministic, comment-only, gate-46-verified)

`tool/resolver.py` + `tool/repoint.py` (committed in the OR change dir; canonical
home should be `hydra/scripts/` for fleet reuse). Conservatism rules:
1. Recover capability **verbatim** from the archived `tasks.md` `task-N` line
   (`<cap>#<REQ>` token, or the enclosing `## <cap>` section heading).
2. Use requirement-level anchor **only** on an exact heading-text match; else drop
   to capability-level (honest downgrade, never a positional guess).
3. Re-check every proposed target with gate-46 logic **before** writing; reject
   if it would not resolve (0 rejects on OR).
4. Anything else → DANGLING, filed for human triage — never guessed.

Comment-only proof: rewrite runs through the `@spec` tag regex; `git diff` shows
every changed line contains `@spec` and every file has `insertions==deletions`
(1:1 line rewrite — no statement added/removed). `tool/test_repoint.py` proves
logic byte-identical + dangling anchors untouched. PASSING.

## Executed end-to-end

### openregister — DONE
- 5,051 broken on base `origin/development` → **3,643 repointed** across 695 files
  (896 anchor-level, 2,747 file-level), **1,408 dangling**.
- Comment-only proof: 0 non-`@spec` lines / 7,030; 0 asymmetric files.
- gate-46 re-verify: 5,051 → 1,408.
- **PR #457 (MERGED)** · residual-dangling umbrella **issue #458**.

(shillinq / pipelinq / procest — see status below.)

## Plan for remaining apps

The tool is generic and proven; each remaining app is: worktree from
origin/development → `python3 repoint.py <root> --apply` → stale-base guard →
change docs → PR → admin-merge → umbrella issue. Recommended order by real-repoint
yield: shillinq (1,121), openbuild (1,169), softwarecatalog (1,161), openconnector
(725), pipelinq (717), opencatalogi (673), decidesk (519), procest (487), docudesk
(484), hermiq (382), hrmq (366), larpingapp (297), scholiq (278), doriath (195),
nldesign (177). The 0%-auto apps (zaakafhandelapp, portaliq, nextcloud-vue) need a
re-headed-anchor resolver (fuzzy heading match against current specs) before they
yield — recommend a separate follow-up.
