# Container Setup Guide

Reference for Step 6 of `opsx-apply-loop`. Covers log folder creation (6.1), prior run history scan (6.2), version checks and image build (6.3), network creation (6.4), container startup with prompt construction (6.5), monitoring loop (6.6), and container exit handling with all 5 scenarios (6.7).

**Important:** In the Step 6.5 startup prompt, the container is instructed to read `references/apply-verify-loop-protocol.md` (not SKILL.md directly) for the apply→verify loop instructions.

---

### 6.1 Create the log folder for this run

Before building or starting the container, create the dedicated log folder. All log files for this run go here — change name is in the folder, not the file names.

**Important:** Logs are stored in the `hydra` repo's `.claude/logs/` directory (gitignored). From an app directory, `hydra` is a sibling directory inside apps-extra.

```bash
HYDRA_ROOT="$(cd ../hydra && pwd)"

LOG_SUBDIR="$(date +%Y-%m-%d)-{APP}-{CHANGE_NAME}"
LOG_DIR="${HYDRA_ROOT}/.claude/logs/${LOG_SUBDIR}"
CONTAINER_LOG_DIR="/workspace/.claude-logs/${LOG_SUBDIR}"
mkdir -p "${LOG_DIR}"
```

> `LOG_DIR` is the absolute host-side path; `CONTAINER_LOG_DIR` is the same folder as seen inside the container (via `hydra/.claude/logs` → `/workspace/.claude-logs` volume mount).

Log: `📁 Log folder: ${LOG_DIR}`

### 6.2 Scan for prior run history (host — do NOT delegate to container or test skills)

**You** (the host apply-loop orchestrator) scan the log folder now, before building the container. This step runs entirely in the host session — do not delegate to the container, to opsx-apply, to opsx-verify, or to any test skill.

```bash
ls "${LOG_DIR}" 2>/dev/null
```

If the folder is empty or does not yet contain any log files, set `{PRIOR_RUN_CONTEXT}` = `""` and continue.

If files exist, read each one yourself (use the Read tool) and build a plain-text summary. Files to look for and how to interpret them:

| File pattern | What it contains | How to use it |
|---|---|---|
| `apply-loop-*-result.log` | Final status of a previous container run (`STATUS=verify-clean`, `exhausted`, `blocked`) | Record status + iteration count |
| `apply-loop-*-live.log` | Per-minute monitoring status lines (file changes, CPU/mem, docker output) | Useful to see what the container was doing at each point in time |
| `apply-loop-*-container.log` | Full container output (captured on exit) | Skim for CRITICAL issues reported by verify, apply errors, and quality check failures — extract unresolved ones |
| `apply-loop-*-test-failures-*.log` | Host-side test failures written by Step 9b | Extract all FAILED test commands and their failure descriptions |
| `prompt.txt` | The startup prompt used for the last container run | Read to understand what context was already given |

**Build `{PRIOR_RUN_CONTEXT}`** as a structured plain-text block covering:

1. **Previous runs summary** — for each prior container run found: the run time, status, and number of iterations
2. **Unresolved CRITICAL issues** — list every CRITICAL issue reported by opsx-verify in prior runs that does NOT appear to have been fixed (i.e., it appeared in a result or log file and the final STATUS was not `verify-clean`, or it appeared in the last clean result's WARNING list). Be specific: file name, issue description, severity.
3. **Unresolved test failures** — list every test failure from `test-failures-*` files that was not subsequently resolved (i.e., the test was re-run after the failure was written and still failed, or no re-run was found). Include the failed command, affected area, and failure description.
4. **What was already attempted** — briefly note what apply tried to fix in prior iterations so the container does not repeat the same approach if it failed.

If no unresolved issues are found (e.g., the only prior run has `STATUS=verify-clean`), note that and set `{PRIOR_RUN_CONTEXT}` to a short "Previous run: verify-clean — no unresolved issues." string.

Log: `📋 Prior run history: <N result file(s), M test-failure file(s) found — <brief one-line summary>>`

### 6.3 Check versions and build/rebuild the container image

The Dockerfile lives at [assets/apply-loop.Dockerfile](../assets/apply-loop.Dockerfile). **Always** check host versions against the Dockerfile — if versions have drifted, update the Dockerfile and rebuild.

**Step 1 — Read host versions:**
```bash
# Claude CLI version — must match the pinned version in the Dockerfile
claude --version
# → e.g. 2.1.83

# openspec version — must match the pinned version in the Dockerfile
openspec --version
# → e.g. 1.2.0
```

**Step 2 — Compare with Dockerfile and update if needed:**

Read `assets/apply-loop.Dockerfile` and check the `claude-code@X.X.X` and `@fission-ai/openspec@X.X.X` version pins. If either differs from the host version:
- Update the version in the Dockerfile in-place (edit `assets/apply-loop.Dockerfile` directly)
- Log: `📦 Updated Dockerfile: claude-code@<old> → <new>` (and/or openspec)
- Force a rebuild (even if the image already exists)

**Step 3 — Build if needed:**
```bash
# Check if image exists
docker image inspect apply-loop:latest >/dev/null 2>&1 && echo "exists" || echo "build needed"
```

Build (or rebuild after version update):
```bash
docker build -t apply-loop:latest -f hydra/.claude/skills/opsx-apply-loop/assets/apply-loop.Dockerfile .
```

> **Container user**: The image creates a non-root user `claude` with `HOME=/home/claude`. The `/home/claude/.claude/` directory is pre-created with correct ownership so that volume-mounting the credentials file does not cause permission issues with the CLI's `session-env/` directory.

Skip the build only if the image exists AND versions match.

### 6.4 Create the restricted Docker network (first time only)

```bash
docker network inspect apply-loop-net >/dev/null 2>&1 || \
  docker network create apply-loop-net
```

> **Note on full network isolation**: The network above still allows general outbound internet. To restrict it to the Claude API only (`api.anthropic.com`) you need iptables rules on the host — see the **Container Limitations** section at the bottom of this skill.

### 6.5 Start the container

Do **not** pass `--rm` — the container must be kept alive after exit so logs can be captured before removal.

Three volumes are mounted:
- The **app directory** → `/workspace` (read-write, contains code + openspec changes)
- The **hydra `.claude/` directory** → `/workspace/.claude` (read-only, provides skill files to the container's Claude session)
- The **`hydra/.claude/logs/` directory** → `/workspace/.claude-logs` (read-write, for result and test-failure files — gitignored in hydra repo)

If this is a **test-failure re-entry** (Step 9c), use the re-entry prompt variant in Step 6.5 below.

Set the run time prefix for all log files created by this container run:
```bash
RUN_TIME=$(date +%H:%M)
```

Run the container in **detached mode** (`-d`) so the host session can monitor it while it runs.

**First, write the startup prompt to a file** to avoid shell quoting issues:

```bash
cat > "${LOG_DIR}/prompt.txt" << PROMPT_EOF
You are running inside an isolated Docker container for Nextcloud app {APP}, change {CHANGE_NAME}.
You have no git, no gh CLI, and no GitHub access. Do not attempt git or GitHub operations.
Archive is handled on the host after you exit — do NOT run opsx-archive.

Working directory is /workspace (= the {APP}/ app directory).
Skill files are at /workspace/.claude/skills/ (read-only mount of the shared .claude/).

Execute the apply→verify loop protocol from /workspace/.claude/skills/opsx-apply-loop/references/apply-verify-loop-protocol.md.
Read that file first to get the full instructions.

App: {APP}
Change name: {CHANGE_NAME}
Max iterations: 5
Log directory: ${CONTAINER_LOG_DIR}
Log file prefix: apply-loop-${RUN_TIME}
Result file: ${CONTAINER_LOG_DIR}/apply-loop-${RUN_TIME}-result.log
PROMPT_EOF
```

**Always append the prior run context** (even if empty — write the header so the container always sees a consistent block):

```bash
cat >> "${LOG_DIR}/prompt.txt" << PRIOR_EOF

## Prior run history for today (same app + change)

{PRIOR_RUN_CONTEXT}

If any unresolved CRITICAL issues or test failures are listed above, treat them as known bugs that your apply pass MUST address — do not skip them even if the task list does not explicitly mention them. If the prior run history says "no unresolved issues", proceed normally from the task list.
PRIOR_EOF
```

**If this is a test-failure re-entry**, additionally append:
```bash
echo "Test-failure re-entry: Also read ${CONTAINER_LOG_DIR}/apply-loop-{FAIL_TIME}-test-failures-{TEST_ITERATION} for the latest host-side test failures — use it alongside the prior run history above to guide what apply should fix in this iteration." >> "${LOG_DIR}/prompt.txt"
```

Then start the container:

```bash
docker run -d \
  --name "apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION}" \
  -v "$(pwd)/{APP}:/workspace" \
  -v "$(pwd)/hydra/.claude:/workspace/.claude:ro" \
  -v "${HYDRA_ROOT}/.claude/logs:/workspace/.claude-logs" \
  $(if [ -f "$HOME/.claude/.credentials.json" ]; then echo "-v $HOME/.claude/.credentials.json:/home/claude/.claude/.credentials.json:ro"; elif [ -n "${ANTHROPIC_API_KEY}" ]; then echo "-e ANTHROPIC_API_KEY=${ANTHROPIC_API_KEY}"; fi) \
  -w /workspace \
  -e "CONTAINER_LOG_DIR=${CONTAINER_LOG_DIR}" \
  -e "RUN_TIME=${RUN_TIME}" \
  --network apply-loop-net \
  apply-loop:latest \
  sh -c 'claude --dangerously-skip-permissions --print "$(cat ${CONTAINER_LOG_DIR}/prompt.txt)"'
```

```bash
echo "$(date '+%H:%M:%S') — 🚀 Container started (detached). Monitoring begins..." >> "${LOG_DIR}/apply-loop-${RUN_TIME}-live.log"
```

Log: `🚀 Container started (detached). Monitoring begins...`

### 6.6 Monitor the container (host)

The monitoring script runs **one check per invocation** (~60s), then exits. Claude re-runs it in a loop and posts a brief status update to the user after each invocation — giving you a live progress line in chat approximately every minute.

**Setup** (one-time):
```bash
chmod +x hydra/.claude/skills/opsx-apply-loop/scripts/apply-loop-check.sh
```

**Monitoring loop** — run this command repeatedly (timeout: 120000ms per invocation):
```bash
hydra/.claude/skills/opsx-apply-loop/scripts/apply-loop-check.sh "apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION}" "$(pwd)/{APP}" "${LOG_DIR}/apply-loop-${RUN_TIME}-live.log"
```

After **each invocation**, immediately:
1. **Show the output to the user** as a brief inline status — e.g. `⚙️ 14:32 — active | CPU: 85% MEM: 420MiB | changed: src/views/Foo.vue, src/store/bar.js`
2. Check the exit code and act:

| Exit code | Meaning | Action |
|-----------|---------|--------|
| **0** | Container still running | Re-run immediately (no new user approval needed) |
| **1** | Container stopped | Proceed to Step 6.7 |
| **2** | Stuck (5+ min no activity) | Use **AskUserQuestion**: "⚠️ Container appears stuck — no file changes or output for 5+ minutes." Options: **Keep waiting** (re-run the monitor — same command, already approved), **Show logs** (`docker logs apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION} --tail=50`), **Kill and retry** (stop container, restart from Step 6.5), **Kill and stop** (stop container, report failure) |

The `CONTAINER_STOPPED` marker in the output confirms the container exited and includes the docker exit code.

### 6.7 Handle container exit

When the container stops (for any reason), immediately capture its full logs before doing anything else:

```bash
docker logs "apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION}" 2>&1 | tee "${LOG_DIR}/apply-loop-${RUN_TIME}-container.log"
# These files served their purpose during the run — clean them up now
rm -f "${LOG_DIR}/prompt.txt"
rm -f "${LOG_DIR}/apply-loop-${RUN_TIME}-live.log"
# Fallback: also catch any live.log not matched by RUN_TIME (e.g. resumed sessions)
find "${LOG_DIR}" -name "*-live.log" -delete 2>/dev/null || true
```

`container.log` is written to `${LOG_DIR}` (gitignored) and survives on the host regardless of what happens to the container next.

Determine the exit scenario:

```bash
EXIT_CODE=$(docker inspect "apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION}" --format '{{.State.ExitCode}}')
RESULT=$(cat "${LOG_DIR}/apply-loop-${RUN_TIME}-result.log" 2>/dev/null || echo "STATUS=unknown")
```

**Scenario A — Verify clean** (`EXIT_CODE=0`, `STATUS=verify-clean`):
- Remove the container automatically:
  ```bash
  docker rm "apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION}"
  ```
- **Sync GitHub issue checkboxes immediately** — read current `{APP}/openspec/changes/{CHANGE_NAME}/tasks.md` (pre-archive location) and check off every task marked `[x]` in issue #`{ISSUE_NUMBER}`:
  - **MCP (preferred):** `get_issue` → patch all `- [ ]` → `- [x]` for done tasks → `update_issue`
  - **CLI (fallback):** `gh issue view {ISSUE_NUMBER} --repo <owner/{APP}> --json body` → update checkboxes → `gh issue edit ...`
  - Log: `✅ GitHub issue #<N> checkboxes synced (post-container)`
- **Update apply-loop status comment** — post or update a single comment starting with `## Apply-Loop Status` on issue #`{ISSUE_NUMBER}`. Search existing comments for one with that header; update via PATCH if found, create if not:
  ```markdown
  ## Apply-Loop Status

  | Stage | Status | Details |
  |-------|--------|---------|
  | Implementation | ✓ Complete | All tasks done |
  | Quality Checks | ✓ Pass | |
  | Verification | ✓ Pass | verify-clean |
  | Host Tests | pending | |
  | Archive | pending | |

  *Updated: YYYY-MM-DD HH:MM UTC*
  ```
  - **MCP (preferred):** `list_issue_comments` → find comment → `update_issue_comment` or `add_issue_comment`
  - **CLI (fallback):** `gh api repos/{owner}/{repo}/issues/{n}/comments` → PATCH or POST
- Proceed to Step 9 (host test loop)

**Scenario B — Loop exhausted** (`EXIT_CODE=0`, `STATUS=exhausted`):
- Show the remaining CRITICAL issues from `${LOG_DIR}/apply-loop-${RUN_TIME}-result.log` and the tail of the log
- Use **AskUserQuestion** to ask:
  > "Loop exhausted. Logs saved to `${LOG_DIR}`. Inspect the container before removing?"
  - **No, remove it** → `docker rm "apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION}"` → go to the Loop exhausted section
  - **Yes, keep it for now** → print `docker exec -it apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION} bash` → do NOT remove → go to Loop exhausted section

**Scenario C — Apply blocked** (`EXIT_CODE=0`, `STATUS=blocked`):
- Show the blocker details from `${LOG_DIR}/apply-loop-${RUN_TIME}-result.log` and the tail of the log
- Use **AskUserQuestion** to ask:
  > "Apply was blocked. Logs saved to `${LOG_DIR}`. Inspect the container before removing?"
  - **No, remove it** → `docker rm "apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION}"` → stop and wait for user to resolve the blocker
  - **Yes, keep it for now** → print the `docker exec` command → do NOT remove → stop and wait

**Scenario D — Container crashed** (`EXIT_CODE≠0`):
- Show the full log output
- Use **AskUserQuestion** to ask:
  > "The container exited unexpectedly (exit code `{EXIT_CODE}`). Logs saved to `${LOG_DIR}`. Keep the container for debugging?"
  - **No, remove it** → `docker rm "apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION}"` → stop, report the crash
  - **Yes, keep it for debugging** → print `docker exec -it apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION} bash` → do NOT remove → stop, report the crash

**Scenario E — User interrupted (SIGTERM/Ctrl+C)**:
- Capture logs (already done above), then use **AskUserQuestion** to ask:
  > "The loop was interrupted. Logs saved to `${LOG_DIR}`. Some files may be partially written. Inspect the container before removing?"
  - **No, remove it** → `docker rm "apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION}"` → stop
  - **Yes, keep it for now** → print the `docker exec` command → do NOT remove → stop
