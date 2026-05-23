---
name: opsx-coverage-scan
description: Audit a legacy app for spec ↔ code coverage — produces a 6-bucket report before retrofit annotation (Experimental)
metadata:
  category: Retrofit
  tags: [retrofit, audit, experimental]
---

**Check the active model** from your system context.

- **On Haiku**: stop immediately:
  > "This command requires Sonnet or Opus — classifying methods against spec REQs needs stronger reasoning than Haiku can reliably provide. Please switch to Sonnet or Opus and re-run."
- **On Sonnet or Opus**: proceed normally.

---

Audit an app for how its code lines up with its OpenSpec specs. Writes `{app}/openspec/coverage-report.md` plus `coverage-report.json` (parseable sidecar). Buckets every code unit into one of six categories. **Read-only** — this skill never touches code.

Part of the [retrofit playbook](../../../../.github/docs/claude/retrofit.md). Runs before `/opsx-annotate` and `/opsx-reverse-spec`.

## The annotation convention (read this first)

Per [ADR-003 §Spec traceability](../../../openspec/architecture/adr-003-backend.md) and the builder's [hydra-gate-spdx](../hydra-gate-spdx/SKILL.md):

```php
/**
 * ...
 * @spec openspec/changes/{change-name}/tasks.md#task-N
 */
```

Tag points at a **task in a change**, not a REQ in a spec. File headers carry one tag per task that touches the file; methods carry the tag(s) for the task(s) they implement.

Retrofit uses **ghost changes** to make this work for legacy code:

- `/opsx-annotate` creates one ghost change per run (`retrofit-{YYYY-MM-DD}-annotate-{app}`) whose tasks.md lists every Bucket 1 REQ. Annotations point at those tasks.
- `/opsx-reverse-spec` creates one ghost change per cluster (`retrofit-{YYYY-MM-DD}-{capability-or-cluster}`) with a spec delta (new REQs) + tasks + annotations in one PR.

The scanner's job is just to produce the plan. The actual ghost-change creation happens in the annotate / reverse-spec skills.

**Input**: `{app}` — app slug (e.g. `procest`, `pipelinq`). If omitted, prompt via AskUserQuestion with a list of apps that have `openspec/specs/` populated.

**Steps**

1. **Resolve app path and verify prereqs**

   - App exists at `<workspace>/{app}/` and has `openspec/specs/**/spec.md`. If specs are empty, stop and suggest `/app-explore` + `/app-design` first.
   - Report which branch the app is on. Some apps keep specs on `beta` rather than `development` (e.g. decidesk) — if the current branch has no specs but another branch does, stop and ask which branch to scan against.
   - Working tree can be dirty — this scan writes only to `openspec/coverage-report.md` + `openspec/coverage-report.json` and never modifies code.

2. **Check for existing coverage report**

   If `openspec/coverage-report.json` exists and is < 24h old, ask via AskUserQuestion:
   - **Re-scan** (recommended if code changed)
   - **Use existing** (skip to Step 10 — just print the summary)
   - **Cancel**

3. **Build the REQ inventory**

   For every `openspec/specs/*/spec.md`:
   - Read the file. **Check frontmatter first**: if the file has a `status: redirect`, `status: deprecated`, or `status: moved` field, skip REQ extraction entirely — the spec legitimately has 0 REQs because it delegates to another app. Emit a Bucket 3b entry with a `status:redirect` (or equivalent) note. Do NOT log it as "parser missed REQs."
   - Extract `capability` (dir name) and each REQ block with its heading text and scenarios.
   - **Heading parser**: match `^#{3,4}\s` (both `###` and `####` are used in production specs). REQ ID pattern: `[A-Z]{2,4}-[0-9]+[a-z]*` — this covers numeric (`REQ-001`), prefixed (`ZRC-007`), suffixed (`ZRC-005b`), and compound (`ZRC-016/018`) forms. Do not split compound IDs — keep as a single REQ entry.
   - Also scan `openspec/changes/*/specs/*/spec.md` for in-flight deltas (drafts count).

   Record `{capability, req_id, req_title, req_body, scenarios[], keywords[]}`. `keywords` is 2-5 distinctive tokens drawn from the REQ title + scenario nouns — used for matching.

4. **Enumerate code units**

   Use Glob to list:
   - **PHP**: `lib/**/*.php` — skip `lib/Migration/` and `lib/Db/` entity boilerplate (getters/setters). Include `lib/BackgroundJob/`, `lib/Controller/`, `lib/Service/`, `lib/Listener/`, `lib/Command/`, `lib/Repair/`, `lib/Cron/`.
   - **Vue/JS/TS**: `src/**/*.{vue,ts,js}` — skip `**/*.spec.{ts,js}`, `**/*.test.{ts,js,vue}`, `**/__tests__/`, `src/main.js`, `src/bootstrap.js`.
   - **Python** (ExApp wrappers only): stop — Python variant deferred.

   For each file, record:
   - Class / component name
   - Each method/function: name, visibility, parameter list, first-line docblock description
   - Whether the file docblock already carries any `@spec openspec/changes/...` tag
   - Whether each method's docblock already carries `@spec`

4.5. **Large-app guard**

   Count the total PHP files enumerated in Step 4. If > 500, stop and ask via AskUserQuestion before proceeding:
   - **Proceed inline** — classify everything in one pass (may be context-heavy)
   - **Chunk by directory** — run Pass A one top-level `lib/` subdirectory at a time, writing partial results after each chunk
   - **Cancel**

   Do not skip this check — silently attempting a 700-file classification in one context window is the failure mode that produced the `large-app-asks-confirmation` eval zero.

5. **Classify every method — two passes**

   **Pass A — bucket everything except private helpers.** Walk each method linearly:

   - **Already annotated** (`@spec openspec/changes/` present in docblock) → record as `annotated`. Note which task(s) it points at. Skip bucketing.
   - **Framework plumbing** — empty constructors, `__call`/`__get`/`__set`, listener `handle()` bodies that only dispatch to a service, single-line controllers that just call a service method with no logic → record as `plumbing`. Plumbing never carries `@spec`.
   - **Bucket 1 — match to a REQ** — score every REQ against method name + docblock + file path + class name. Top match ≥ 0.85 → Bucket 1. 0.70–0.85 → Bucket 1 with `NEEDS-REVIEW` flag. Below 0.70 → fall through.
   - **Bucket 2a — existing capability, no REQ** — file path clearly belongs to a capability (e.g. `lib/Controller/MeetingController.php` → `meeting-management`) but no REQ matched, OR the match confidence fell below 0.70. Cluster by the owning capability.

     **Large-cluster sanity check**: before emitting a Bucket 2a cluster with > 50 methods, read 5–10 random method names from the cluster and compare them against the owning capability's REQ scenario text. If the method names do not match the capability's domain vocabulary, the cluster is likely a scanner misclassification (wrong capability keyword matched by directory name). Emit a warning:
     > ⚠️ Bucket 2a cluster `{capability}` has {N} methods but sampled method names (`{method1}`, `{method2}`, ...) do not match this capability's REQ scenarios. Likely misclassification — verify before running `/opsx-reverse-spec`.
   - **Bucket 2b — no capability owner** — no capability matches the file path. Cluster by a human-readable label derived from the directory (e.g. `lib/Service/Integration/*` → `integrations`).

     **Namespace-word warning**: after emitting Bucket 2b clusters, flag any whose label matches a filesystem namespace word: `service`, `controller`, `background`, `job`, `event`, `tool`, `appinfo`, `middleware`, `exception`, `listener`, `command`, `repair`. These labels describe component types, not observable behaviors — they are NOT usable as `/opsx-reverse-spec --cluster` targets without a human pre-split. Emit a warning in the report:
     > ⚠️ Cluster `{label}` is a namespace word, not a behavioral name. Pre-split required before running `/opsx-reverse-spec`.

   **Pass B — resolve private helpers.** After Pass A, walk every `private`/`protected` method:

   - Find its caller(s) inside the same class. If all callers are in Bucket 1 pointing at the same REQ → inherit that REQ into Bucket 1.
   - If callers span multiple REQs → inherit all of them (multi-REQ helper).
   - If no caller is bucketed (all callers are plumbing or themselves private) → follow the call chain upward until you find a bucketed method.
   - If the helper is unreachable from any bucketed caller → keep in whichever bucket Pass A assigned.

   Confidence scoring is judgment. Consider: does the REQ title's verb+noun appear in the method name? Does the REQ's scenario text reference the same domain concepts (`meeting`, `motion`, `vote`) as the file/class? Is the file path aligned with the capability directory? Record the signal(s) used in the report so a human can audit.

6. **Reverse pass — find unimplemented REQs**

   **Build the removed-lines cache first (one-time, before the per-REQ loop):**

   ```bash
   git log --all -p -- lib/ src/ | grep "^-[^-]" > /tmp/removed-lines.txt
   ```

   This scans the full git history once and collects every removed line. Expected cost: 5–15 seconds on typical repos (~5–10k commits). If it exceeds 30 seconds, abort the cache build and classify all unimplemented REQs as **Bucket 3b** — note "git history too large for reverse pass" in the report. Do **not** fall back to grepping the current codebase; that has entirely different semantics ("keyword in current code but unmatched" vs "implementation evidence in removed code").

   **For every unimplemented REQ** (no method landed in Bucket 1):

   ```bash
   grep -ic "<keyword1>\|<keyword2>" /tmp/removed-lines.txt
   ```

   - ≥ 2 distinct keywords from the REQ appear in removed lines → **Bucket 3a** (implementation evidence found in git history, possibly broken or removed).
   - Fewer than 2 matches → **Bucket 3b** (never implemented).

   Don't over-invest in 3a classification — if the match is weak (single keyword, generic term like "id" or "name"), collapse to 3b with a note. Bucket 3 is surfaced for humans to triage, not auto-fixed.

7. **ADR conformance sweep (Bucket 4)**

   Quick grep pass (non-blocking, surface only):
   - Missing `@license` / `@copyright` / `@spec` in file docblocks (per ADR-014 + ADR-003)
   - `var_dump` / `dd(` / `die(` / `print_r(` / `error_log(` outside tests (per hydra-gate-forbidden-patterns — use word-boundary grep; `->add(` is not `dd(`)
   - Hardcoded user-facing strings not wrapped in `t(` (Vue) or `$this->l10n->t(` (PHP)
   - Direct SQL (`$this->db->query(`, `prepare(`) — should use OpenRegister per ADR-001

   One finding per file, grouped by rule.

8. **Honor `.opsx-ignore`**

   If `{app}/.opsx-ignore` exists, read glob patterns (one per line, `#` for comments). Drop any matching entries from buckets 1, 2a, 2b, and 4 (not 3 — REQ-level, useful regardless). Report how many entries were suppressed.

9. **Write the report**

   Write two files.

   **`openspec/coverage-report.json`** — parseable sidecar (consumed by `/opsx-annotate`):

   ```json
   {
     "generated_at": "2026-04-20T14:30:00Z",
     "app": "procest",
     "branch": "development",
     "scanner_version": "1",
     "buckets": {
       "annotated": [{"file": "...", "method": "...", "spec_tag": "openspec/changes/.../tasks.md#task-3"}],
       "plumbing": [{"file": "...", "method": "...", "reason": "listener-dispatch-only"}],
       "bucket_1": [{"file": "...", "method": "...", "capability": "meeting-management", "req_id": "REQ-001", "confidence": 0.92, "needs_review": false, "signal": "path+name+scenario noun match", "inherits_from": null}],
       "bucket_2a": {
         "meeting-management": [{"file": "...", "method": "...", "observed_behavior": "..."}]
       },
       "bucket_2b": {
         "integrations": [{"file": "...", "method": "...", "observed_behavior": "..."}]
       },
       "bucket_3a": [{"req": "catalogs#REQ-042", "evidence": "removed-lines cache matched 3 keywords: handleCatalog, catalogId, registerCatalog"}],
       "bucket_3b": [{"req": "federation#REQ-089"}],
       "bucket_4": {
         "missing-spec-in-file-docblock": [{"file": "lib/Service/..."}]
       }
     },
     "ignored": 0,
     "notes": ["optional free-text notes for human reviewers"]
   }
   ```

   **`openspec/coverage-report.md`** — human-readable companion. Structure:

   ```markdown
   # Coverage Report — {app}

   Generated: YYYY-MM-DD HH:MM UTC
   Branch: {branch}
   Scanner: opsx-coverage-scan v1

   ## Summary

   | Bucket | Count | Next action |
   |---|---|---|
   | annotated | N | — (already tagged) |
   | plumbing | N | — (never tagged) |
   | 1 — REQ matched | N | `/opsx-annotate {app}` |
   | 2a — existing capability, no REQ | N (M clusters) | `/opsx-reverse-spec {app} --extend <cap>` |
   | 2b — no capability owner | N (M clusters) | `/opsx-reverse-spec {app} --cluster <name>` |
   | 3a — REQ broken (code removed) | N | Separate fix PR |
   | 3b — REQ never implemented | N | Mark deferred or remove |
   | 4 — ADR conformance | N findings across M rules | Follow-up issue |

   ## Bucket 1 — Ready to annotate (via ghost change `retrofit-{date}-annotate-{app}`)

   (grouped by capability, then file)

   ### capability: meeting-management → task-1

   | File | Method | REQ | Confidence | Signal |
   |---|---|---|---|---|
   | lib/Controller/MeetingController.php | index() | REQ-001 | 0.92 | path+name+scenario-noun |

   ## Bucket 2a — Existing capability, no REQ (reverse-spec --extend)

   ### cluster: meeting-management (3 methods)
   - lib/Service/MeetingService.php::archive() — observed: moves meeting to archive register, writes audit entry. No REQ covers archival.

   ## Bucket 2b — No capability owner (reverse-spec --cluster)

   ### cluster: integrations (5 methods)
   - ...

   ## Bucket 3 — Surfaced for human triage

   ### 3a — possibly broken
   - catalogs#REQ-042 — removed-lines cache matched keywords: handleCatalog, catalogId, registerCatalog. Verify.

   ### 3b — never implemented
   - federation#REQ-089 — no git history reference. Consider deferring.

   ## Bucket 4 — ADR conformance findings

   ### missing-spec-in-file-docblock (8 files)
   - lib/Service/...

   ## Notes for the human reviewer

   - (free-text — surprises, low-confidence calls, ambiguous REQs)
   ```

10. **Print the summary and suggest next command**

    ```
    ## Coverage Scan Complete — {app}

    Buckets: annotated={N} | plumbing={N} | 1={N} | 2a={N}/{M} clusters | 2b={N}/{M} clusters | 3a={N} | 3b={N} | 4={N}

    Report: {app}/openspec/coverage-report.md
    JSON:   {app}/openspec/coverage-report.json

    Next:
    1. Read the .md manually — confirm Bucket 1 matches before annotating
    2. `/opsx-annotate {app}` — creates one ghost change + applies Bucket 1 annotations in one PR
    3. `/opsx-reverse-spec {app} --extend <cap>` / `--cluster <name>` — one Bucket 2 entry at a time (bias toward --extend)
    ```

**Guardrails**

- **Read-only.** Writes exactly two files: `openspec/coverage-report.md` + `openspec/coverage-report.json`. Never modifies code, never commits.
- **Heuristic, not mechanical.** Bucket 1 confidence is judgment. Always `NEEDS-REVIEW` flag when 0.70–0.85; always record the signal used.
- **Python ExApps deferred.** Stop with a clear message; don't half-scan.
- **Chunking guidance.** If Bucket 1 exceeds ~150 methods across 50+ files, tell the user: "Large Bucket 1 — consider annotating one capability at a time by running `/opsx-annotate {app} --capability <cap>` when that flag lands." For now, the skill accepts the whole bucket — but surface the risk.
- **Don't guess REQ intent.** If a spec is ambiguous, say so in the Notes section. Bucket 2 exists for exactly this case.
- **Large app warning.** If the scan enumerates > 500 PHP files, pause and ask for confirmation before Pass A — the classification pass will consume significant context.
- **Reverse pass timeout.** The removed-lines cache (`git log --all -p | grep "^-[^-]"`) takes 5–15s on typical repos. If it exceeds 30s, skip the reverse pass and put all unimplemented REQs in Bucket 3b — never fall back to grepping the current codebase (different semantics).

## Capture Learnings

After the scan, route each new observation through the two-stage buffer:

1. **High-confidence observation** (directly confirmed this run, matches an existing pattern, or fixes a measured eval failure in `evals/grading.json`) → append directly to [learnings.md](learnings.md) under the appropriate section: **Patterns That Work**, **Mistakes to Avoid**, **Domain Knowledge**, or **Open Questions**.

2. **Unverified observation** (only seen once, feels useful but not yet confirmed) → append to [learning-candidates.md](learning-candidates.md). Entries are promoted once the promotion criteria are met (confirmed in 3+ executions, resolve a measured eval failure, or receive explicit user endorsement) and discarded after 30 days otherwise.

Categories to capture:

- **Classification signals that work** — what reliably put a method in the right bucket
- **Heuristic failures** — confidence scores that were wrong in either direction
- **App-specific conventions** — path layouts, naming patterns that help or hurt matching
- **REQ language gaps** — spec REQs that were hard to match because their language didn't reflect actual domain vocabulary

Each entry must start with today's date in `YYYY-MM-DD` format. One insight per bullet. Skip entirely if nothing new was learned.

**Consolidation trigger** — if `learnings.md` exceeds ~80–100 entries, run a consolidation pass: merge duplicates, remove outdated items, and promote any pattern confirmed in 3+ entries into the **Consolidated Principles** section. Principles there are candidates for promotion to SKILL.md Guardrails on the next edit.

> 💡 Switch models back with `/model <name>` when done.
