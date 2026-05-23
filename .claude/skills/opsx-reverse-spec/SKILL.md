---
name: opsx-reverse-spec
description: Reverse-engineer a spec from observed code — one Bucket 2 cluster per run, creates a ghost change with spec delta + tasks + annotations (Experimental)
metadata:
  category: Retrofit
  tags: [retrofit, reverse-spec, experimental]
---

**Check the active model** from your system context.

- **On Haiku**: stop immediately:
  > "This command requires Sonnet or Opus — drafting REQs from observed code behavior is the highest-reasoning step in the retrofit flow. Please switch to Sonnet or Opus and re-run."
- **On Sonnet or Opus**: proceed normally.

---

Drafts a retrofit spec from observed code for **one Bucket 2 entry at a time** (never batch — one cluster per PR, one review cycle per cluster). Bias toward `--extend` — minting a new capability is a design decision.

Part of the [retrofit playbook](../../../../.github/docs/claude/retrofit.md). Run `/opsx-coverage-scan` first and `/opsx-annotate` for Bucket 1 before tackling Bucket 2.

## How ghost changes work here

Same model as `/opsx-annotate`, but with a spec delta:

- Name: `retrofit-{YYYY-MM-DD}-{capability-or-cluster}`
- `proposal.md`: rationale + scope
- `specs/` delta: **either** new REQs appended to an existing capability (`--extend`) **or** a whole new capability (`--cluster`)
- `tasks.md`: one task per new REQ (all `[x]` because code already exists)
- Annotations on the cluster's methods point at these tasks

**Input**:
- `{app}` — app slug (required)
- Exactly one of:
  - `--extend <capability>` — add REQs to an existing capability spec
  - `--cluster <name>` — create a brand-new capability spec

If neither flag is given, list the Bucket 2 clusters from the coverage report and use AskUserQuestion to pick one + flag.

**Steps**

1. **Validate inputs**

   - Coverage report exists (`{app}/openspec/coverage-report.json`) and is < 24h old. Stale → tell user to re-scan.
   - **`.opsx-ignore` is honored transitively** — this skill reads bucket lists from the coverage report; `{app}/.opsx-ignore` filtering happens upstream in `/opsx-coverage-scan`. Adding patterns to `.opsx-ignore` requires a re-scan to take effect.
   - For `--extend <capability>`: `{app}/openspec/specs/{capability}/spec.md` must exist. If missing, user meant `--cluster`.
   - For `--cluster <name>`: `{app}/openspec/specs/{name}/spec.md` must NOT already exist. If it does, user meant `--extend`.
   - Working tree is clean.
   - **Dangling `@spec` check**: grep for `@spec openspec/changes/` paths in `lib/**/*.php` and `src/**/*.js`. For any referenced change directory that does not exist on disk, list the dangling paths and ask the user: (a) fill in the referenced change (populate the missing scaffold), (b) proceed and mint `retrofit-DATE-X` alongside (ignore dangling refs), or (c) retarget the tags to an existing change. Do not auto-resolve — the right answer differs per case.

2. **Checkout the right branch**

   Read `coverage-report.json.branch` — use the same branch the scan ran on (not necessarily `development`; decidesk keeps specs on `beta`).

   ```bash
   git checkout {branch-from-report} && git pull
   git checkout -b retrofit/reverse-spec-{app}-{flag-value}-{YYYY-MM-DD}
   ```

3. **Load the cluster**

   From `coverage-report.json.buckets.bucket_2a[capability]` (for `--extend`) or `buckets.bucket_2b[cluster]` (for `--cluster`): extract the list of methods with their `observed_behavior` notes.

   **Cap the cluster at 5 REQs per run.** If the cluster has > 5 distinct behaviors worth separate REQs, split it: this run drafts the first 5, you run again for the next 5. Bias toward fewer REQs — one REQ can cover multiple methods implementing the same observable behavior.

4. **Read the code to understand observed behavior**

   For each method in the cluster, read the file and take notes on:
   - Inputs (parameters, class state it reads)
   - Outputs (return value, side effects — writes, events, HTTP)
   - Preconditions (what it assumes)
   - Postconditions (what it guarantees)
   - Failure modes (exceptions, error paths)

   **Goal: describe observed behavior, not design intent.** If the code looks buggy or inconsistent, flag it in notes — don't silently "fix" it via the spec.

5. **Load architectural context**

   Silently read:
   - Org-wide ADRs: `hydra/openspec/architecture/adr-*.md` (especially ADR-003 for `@spec` convention, ADR-011 for schema standards)
   - App's repo-specific ADRs: `{app}/openspec/architecture/adr-*.md` (if present)
   - Sibling specs in `{app}/openspec/specs/*/spec.md` — match existing voice and granularity (but NOT heading format — always use numbered REQs regardless of sibling style; see Step 6)
   - Spec writing guide: `~/.github/docs/claude/writing-specs.md`
   - Canonical spec template: `hydra/openspec/schemas/conduction/templates/spec.md` (relative to workspace root)

6. **Draft the REQ(s)**

   Each REQ carries:
   - **REQ-NNN** — for `--extend`, next free number in the capability. For `--cluster`, start at REQ-001.
   - **Title** — imperative, observable behavior ("The system SHALL archive meetings older than N days")
   - **Description** — 2-4 sentences of context
   - **Scenarios** — at least one `WHEN … THEN …` pair per REQ, matching the app's existing spec voice
   - **Notes** — ambiguity, observed-but-maybe-buggy behavior, TODO items for future tightening

   Granularity rule of thumb: one REQ per observable behavior. Don't inflate with sub-REQs; don't collapse distinct behaviors into one REQ.

   Keep a map: `{req_id → [file::method, ...]}` — you'll use it for tasks and annotations.

7. **Create the ghost change scaffold**

   Prefer the existing skill:
   ```
   /opsx-new retrofit-{YYYY-MM-DD}-{capability-or-cluster}
   ```

   Fallback: create the directory manually under `{app}/openspec/changes/retrofit-{YYYY-MM-DD}-{capability-or-cluster}/` with proposal.md, specs/, tasks.md.

8. **Write the spec delta**

   **For `--extend <capability>`** — write `openspec/changes/retrofit-.../specs/{capability}/spec.md` containing ONLY the new REQs (OpenSpec delta convention — archive merges them into the main spec).

   Also add a retrofit marker to the target spec's frontmatter so Specter dashboards can filter. The archive step will merge this in too. In the delta:

   **Always use numbered REQ-ID form** (`### REQ-NNN: <title>` headings), regardless of the sibling spec's existing heading style. If the sibling spec uses the old `### Requirement: <name>` format, the new REQs still get numbers — do not match the old style.

   **Frontmatter format — always use block YAML (multi-line dash list)** for `retrofit_extensions`, never inline `[…]`. Block scales better as the list grows, supports per-item comments, and matches the convention used by Hugo/Jekyll/Kubernetes manifests. The values are bare REQ-IDs (no quotes, no full requirement text):

   ```yaml
   ---
   retrofit_extensions:
     - REQ-NNN
     - REQ-NNN+1
   ---
   ```

   **For `--cluster <name>`** — write `openspec/changes/retrofit-.../specs/{name}/spec.md` as a full new spec, with:
   ```yaml
   ---
   retrofit: true
   ---
   ```

9. **Write tasks.md**

   One task per REQ, all `[x]`:
   ```markdown
   # Tasks

   - [x] task-1: {capability}#REQ-NNN — {REQ title} (retroactive annotation)
   - [x] task-2: {capability}#REQ-NNN+1 — ...
   ```

   Record the `req_id → task_N` map.

10. **Fill in proposal.md**

    ```markdown
    # Retrofit — {capability-or-cluster}

    Describes observed behavior of {N} methods under `{capability-or-cluster}` as {M} new REQs. Code already exists — this change retroactively specifies it.

    ## Affected code units
    - lib/Service/...::archive()
    - ...

    ## Approach
    - For each method: describe observed inputs, outputs, pre/postconditions, failure modes
    - Draft REQs that match behavior (not aspirational)
    - Notes section surfaces any observed-but-suspicious behavior

    Source: openspec/coverage-report.md generated {YYYY-MM-DD}. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
    ```

11. **Fast-forward the design.md**

    ```
    /opsx-ff retrofit-{YYYY-MM-DD}-{capability-or-cluster}
    ```

    The change already has proposal.md + specs/ + tasks.md — `/opsx-ff` fills in design.md. Annotate design.md clearly: "Retrofit change. Tasks describe retroactive annotation, not new implementation work."

12. **Annotate code (inline — don't delegate)**

    For each method in this cluster, apply the `@spec openspec/changes/retrofit-{YYYY-MM-DD}-{capability-or-cluster}/tasks.md#task-N` tag using the same per-file single-pass edit logic as [/opsx-annotate](../opsx-annotate/SKILL.md) Step 6. Do NOT invoke `/opsx-annotate` here — it would create a second ghost change. This skill handles its own cluster's annotations inline.

    Run the linter + `/hydra-gates` after.

13. **Sync the spec to Specter**

    ```bash
    python3 concurrentie-analyse/scripts/sync_spec_content.py {app}
    ```

    Reads `retrofit: true` / `retrofit_extensions: [...]` from spec.md frontmatter (once the change is archived) and populates `app_specs.retrofit` / `app_specs.retrofit_extensions`.

    Migration prereq — if the sync fails with a missing-column error:
    ```bash
    python3 concurrentie-analyse/scripts/migrate_app_specs_retrofit.py
    ```

    Confirm exit 0 + non-zero row count. On error, stop and report — don't proceed.

14. **Archive the ghost change**

    ```
    /opsx-archive retrofit-{YYYY-MM-DD}-{capability-or-cluster}
    ```

    Merges the delta: for `--extend`, new REQs land in `openspec/specs/{capability}/spec.md`. For `--cluster`, creates `openspec/specs/{name}/spec.md`. Re-run `sync_spec_content.py` after archive so Specter picks up the merged state.

15. **Commit structure**

    The scaffold + annotations + archive should be committed in two logical commits:
    1. `retrofit: draft {capability-or-cluster} spec + annotate {N} methods` — scaffold + spec delta + tasks + annotations
    2. `retrofit: archive {capability-or-cluster} change` — archive-merge into main specs

    Append the annotation commit's SHA to `.git-blame-ignore-revs`.

16. **Push and PR**

    ```bash
    git push -u origin retrofit/reverse-spec-{app}-{flag-value}-{YYYY-MM-DD}
    ```

    Invoke `/create-pr`. PR title:
    > `retrofit: reverse-spec {capability-or-cluster} ({M} REQs / {N} methods)`

    PR body:
    ```markdown
    ## Retrofit — Reverse-Spec

    Describes observed behavior of {N} methods under `{capability-or-cluster}` as {M} new REQs.

    Ghost change: `retrofit-{date}-{capability-or-cluster}` (archived).

    ### What this PR does
    - Drafts {M} REQs: `retrofit_extensions: [...]` for --extend, or `retrofit: true` for --cluster
    - Creates tasks.md with one task per REQ
    - Annotates {N} methods with `@spec openspec/changes/retrofit-.../tasks.md#task-N`
    - Archives the change (merges spec delta into main specs)
    - Specter sync: app_specs row flagged as retrofit cohort

    ### What this PR does NOT do
    - No code behavior changes — just annotations
    - Does not silently fix observed-but-buggy behavior — notes section flags ambiguity

    ### Review focus
    - REQ language matches observed behavior (not aspirational)
    - Scenarios are testable
    - One REQ per distinct observable behavior

    Source: `openspec/coverage-report.md` generated {YYYY-MM-DD} | Cluster: `{name}`
    ```

    Labels: `retrofit`, `reverse-spec`.

17. **Summary**

    ```
    ## Reverse-Spec Complete — {app} / {capability-or-cluster}

    Ghost change:   retrofit-{YYYY-MM-DD}-{capability-or-cluster} (archived)
    REQs drafted:   {M}
    Methods tagged: {N}
    Specter:        registered (retrofit cohort via sync_spec_content.py)
    Branch: retrofit/reverse-spec-{app}-{flag-value}-{YYYY-MM-DD}
    PR: {url}

    Remaining Bucket 2 clusters (from current report): {K}

    Next:
    - `/opsx-reverse-spec {app} --extend <next>` or `--cluster <next>` — one at a time
    - Or `/opsx-coverage-scan {app}` to refresh the report after this merges
    ```

**Guardrails**

- **One cluster per run.** Never batch. Each cluster is its own review cycle — REQ language is the review surface.
- **Cap at 5 REQs per run.** Larger clusters split into multiple runs.
- **Observed, not aspirational.** Bugs stay bugs; TODO notes surface them.
- **Bias toward `--extend`.** Only mint a new capability when the cluster is genuinely new territory.
- **Specter sync is mandatory.** If `sync_spec_content.py` fails, stop. Don't leave a spec in-tree but missing from Specter — the retrofit cohort dashboards will lie.
- **No loops.** If the scan after this merges still shows the cluster in Bucket 2, something's wrong with the matcher. Stop and investigate — don't draft more REQs to force a match.
- **Don't delegate annotation.** Step 12 does it inline. Calling `/opsx-annotate` would create a parallel ghost change.
- **Never sed/awk/python for code edits.** Project rule.

## Capture Learnings

After the run, append observations to [learnings.md](learnings.md):

- **Cluster sizing** — how many methods per cluster felt right (too big → hard to REQ; too small → REQ inflation)
- **Granularity calls** — REQs merged or split, and why
- **Specter friction** — `sync_spec_content.py` issues, retrofit cohort flagging
- **Re-scan precision** — did the freshly minted REQs reliably match their code units after archive? What broke the match if not?

One insight per bullet, with today's date.

> 💡 Switch models back with `/model <name>` when done.
