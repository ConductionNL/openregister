# spec-anchor-repair — round 2 (2026-07-16)

Finishes the fleet `@spec` anchor repair. Round 1 shipped openregister (#457),
pipelinq (#404), shillinq. This round: the six remaining apps — **all merged and
verified on `origin/development`** — plus repair of two tool defects that round 1
had shipped.

## Per-app results (gate-46 `spec-anchor-existence`, measured repo-wide)

| app | before | repointed | after | anchor | file | dangling | apply PR | archive PR | issue |
|---|---|---|---|---|---|---|---|---|---|
| docudesk | 864 | 484 | **380** | 83 | 401 | 380 | #186 | #189 | #188 |
| decidesk | 1199 | 519 | **680** | 62 | 457 | 680 | #135 | #137 | #136 |
| opencatalogi | 1282 | 698 | **584** | 128 | 570 | 584 | #142 | #144 | #143 |
| softwarecatalog | 1336 | 1162 | **174** | 2 | 1160 | 174 | #95 | #97 | #96 |
| openconnector | 1809 | 1337 | **472** | 403 | 934 | 472 | #237 | #239 | #238 |
| procest | 2873 | 573 | **2300** | 79 | 494 | 2300 | #236 | #240 | #238 |
| **subtotal** | **9363** | **4773** | **4590** | 757 | 4016 | 4590 | | | |

Plus round-1 defect repair: **openregister 1411→1408** (PR #461, also lands the
fixed tool) and **pipelinq 2014→1965** (PR #407) — 52 anchors.

**Repointed this round: 4,825.** Running fleet total: **~9,179**
(4,354 round 1 + 4,825). Every app reconciles exactly: `before − repointed == after`.

## Category breakdown (residual dangling, flagged never guessed)

| cause | count |
|---|---|
| (d) non-tasks.md ref — decimal task / design.md / proposal.md / re-headed specs anchor | 1,910 |
| (d) no-fragment tasks.md ref, slug not a canonical capability | 1,087 |
| (d) change uses non-annotate tasks.md (no `task-N: cap#REQ` line) | 1,295 |
| (d) archived change dir not located | 254 |
| (c) capability archived/deleted — requirement genuinely gone | 44 |

## Comment-only proof (all 8 apps)

`git diff --numstat origin/development`: **0 non-`@spec` changed lines out of
9,650**; **0 files with asymmetric insertions/deletions** (1:1 line rewrite ⇒ no
statement added or removed); **0 files touched outside `lib/`/`src/`** except the
OpenSpec change docs. **0 repoint candidates rejected** by the gate-46
post-condition check.

## Three tool defects found — round 1 shipped two of them

1. **CRLF normalisation** (procest). Python's universal-newline read + default
   write rewrote CRLF files to LF: **2,462 non-`@spec` diff lines**, a whitespace
   reformat wearing an anchor-repair hat. The comment-only assertion caught it —
   it would have passed a "looks like comments" review. Also switched to
   `errors='strict'`+skip; `errors='replace'` would burn U+FFFD into source.
2. **Raw-fragment leak** (decidesk). The `@spec` regex swallows a sentence-ending
   `.` into the fragment. The resolver matched on `slugify(frag)` but emitted the
   **raw** frag → it "repaired" anchors into *still-broken* anchors, and its own
   post-condition check was blind because `is_broken()` used the same lenient
   compare. **Shipped in round 1: residue openregister 3, pipelinq 49, shillinq 0
   — all repaired here.** OR's reported 1,408 was aspirational; it had actually
   landed 1,411.
3. **No self-heal path** for defect 2's output (already-canonical `specs/…`
   targets were an unrecognised shape → stuck DANGLING). Added an unambiguous
   fragment-normalisation shape.

All three carry regression tests **verified to fail against the old tool**. The
fixed tool is at `openregister/openspec/changes/spec-anchor-repair/tool/`.

**What made defect 2 visible: the reconciliation identity `before − repointed ==
after`.** A 2-anchor drift on decidesk and 12 on openconnector was the only
symptom. Trusting the tool's own count would have hidden it — the tool and the
gate must be compared, not assumed equal. Now asserted per app.

## Follow-ups

- The 4,590 residual anchors are filed per app (issues above), never force-removed:
  guessing would make traceability confidently wrong instead of visibly broken —
  worse, because gate-46 would then go green over a lie.
- Still unswept (round-1 audit): openbuild 1373, hermiq 1160, zaakafhandelapp 697,
  doriath 521, hrmq 514, launchpad 427, larpingapp 316, scholiq 292, nldesign 241,
  planix 200, portaliq 173, petstore 55, nextcloud-app-template 27, nextcloud-vue 9.
- zaakafhandelapp / portaliq / nextcloud-vue are ~0% auto — dominated by re-headed
  anchors in *existing* specs; needs a fuzzy-heading resolver a human confirms.
- Tool's canonical home should move to `hydra/scripts/` for fleet reuse.
