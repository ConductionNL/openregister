# Apply → Verify Loop Protocol

Reference for Steps 7–8 of `opsx-apply-loop`. These steps execute **inside the container** (not on the host). The container's Claude CLI session reads this file and executes the loop.

---

## Step 7: Initialise the loop log (inside container)

Create an in-memory iteration log. Append to it after each apply and verify pass. Use **plain-text status lines** (not a markdown pipe table) — pipe tables look misaligned in terminal output and raw log files when column content widths vary. The formatted summary table is produced once in Step 15.

```
## apply-loop log — {APP}/{CHANGE_NAME}
```

Set `iteration = 1`. Set `max_iterations = 5`.

The log directory and log file prefix for this run were passed in your startup prompt. Use them for all output files:
- `<log-dir>` = the log directory (e.g. `/workspace/.claude-logs/YYYY-MM-DD-{APP}-{CHANGE_NAME}`)
- `{RUN_TIME_PREFIX}` = the log file prefix (e.g. `apply-loop-11:26`) — use this as the prefix for result files: `<log-dir>/{RUN_TIME_PREFIX}-result`

Working directory is `/workspace` (= the app directory). All openspec paths are relative to this: `openspec/changes/{CHANGE_NAME}/`.

---

## Step 8: Apply → Verify loop (inside container)

Repeat the following until verify is clean or `iteration > max_iterations`.

### 8a. Run apply (via opsx-apply)

Log: `⚙️ Iteration <N> — running apply`

**Invoke the `opsx-apply` skill** for `{CHANGE_NAME}`. Pre-answer its interactive prompts to keep the loop automated:

| Prompt from opsx-apply | Answer |
|------------------------|--------|
| "Ready to implement N remaining tasks?" | **Start implementing** |
| "Which task number?" (if shown) | Not applicable — start from first pending |
| "What would you like to do next?" (end of apply) | Do NOT answer — return control to apply-loop |

**Note**: The apply skill will attempt GitHub issue updates — these will silently fail inside the container (no gh CLI/GitHub access). This is expected. GitHub sync is handled after the container exits (Step 12).

**Seed data (ADR-001)**: When apply implements tasks that introduce or modify OpenRegister schemas, the seed data entries in `lib/Settings/{app}_register.json` must also be created/updated. The apply skill handles this — ensure it does not skip this step even inside the container.

**If this is a test-failure re-entry**: read the test-failures file specified in your startup prompt (e.g. `<log-dir>/apply-loop-HH:MM-test-failures-N`) for context on what the host-side tests reported. Use those failures to guide which code areas to focus on during apply.

Quality checks run directly in the container (PHP and Composer are installed in this image). The `docker compose exec` approach is not available — run directly:
```bash
cd /workspace && composer check:strict 2>&1
# If check:strict not available:
composer phpcs 2>&1 && composer phpmd 2>&1 && composer psalm 2>&1
```

For auto-fixable issues:
```bash
cd /workspace && composer phpcs:fix 2>&1
# OR: composer cs:fix
```

Frontend quality checks (if `package.json` exists with lint scripts):
```bash
cd /workspace && npm run lint 2>&1
npm run stylelint 2>&1
```

**If apply is blocked** (missing artifacts, design issues, unclear requirements): stop the loop, report the blocker, write the result file to `<log-dir>`, and exit cleanly:
```bash
echo "STATUS=blocked" > <log-dir>/apply-loop-{RUN_TIME_PREFIX}-result
echo "REASON=<blocker description>" >> <log-dir>/apply-loop-{RUN_TIME_PREFIX}-result
```
The host (Step 6.7 Scenario C) handles user prompting and container removal.

Append to iteration log:
`[Iter <N> | apply ] <N> tasks implemented — Quality: ✓ pass / N issues fixed / N issues remain`

### 8b. Run verify (via opsx-verify)

Log: `🔍 Iteration <N> — running verify`

**Invoke the `opsx-verify` skill** for `{CHANGE_NAME}`. Pre-answer its interactive prompts:

| Prompt from opsx-verify | Answer |
|-------------------------|--------|
| "Would you also like to run API and/or browser tests?" | **Skip testing** — no network access to Nextcloud in this container |
| "Found issues. Would you like me to fix them?" | **No, leave as-is** — the next apply iteration handles fixes |
| "Ready to archive this change?" | **No, not yet** — archive is handled on the host |
| "Archive with warnings?" | **No, not yet** — archive is handled on the host |

**Note**: The verify skill will attempt GitHub issue sync — this will silently fail inside the container. Expected. Sync happens after container exits (Step 12).

**Classify all findings** as CRITICAL, WARNING, or SUGGESTION (as reported by opsx-verify).

**Append to iteration log**, e.g.:
`[Iter <N> | verify] ✓ Clean` or `[Iter <N> | verify] X CRITICAL, Y WARNING — <brief summary>`

### 8c. Evaluate and decide

**If CRITICAL issues found (with or without WARNINGs) and `iteration < max_iterations`**:
- Display the findings clearly
- Log: `[Iter <N> | verify] X CRITICAL — continuing loop`
- Increment `iteration`
- Go back to **Step 8a**

**If only WARNING (or SUGGESTION) issues found and `iteration < max_iterations`**:
- Assess whether each warning is **actionable** (a further apply pass could plausibly fix it) or **non-actionable** (e.g., unverifiable inside this container, requires a live runtime environment, depends on external data, or is by design):
  - **All actionable** → continue loop:
    Log: `[Iter <N> | verify] Y WARNING(s) — actionable, continuing loop`
    Increment `iteration`, go back to **Step 8a**
  - **All non-actionable** (or no actionable ones remain) → proceed without restarting:
    Log: `[Iter <N> | verify] Y WARNING(s) — loop not restarted: <reason for each non-actionable warning>`
    Write result file with `WARNINGS_ONLY=true` and exit (same path as the max-iterations warnings case below)

**If CRITICAL issues found and `iteration == max_iterations`**:
- Stop — CRITICAL issues cannot be carried to archive
- Write `<log-dir>/apply-loop-{RUN_TIME_PREFIX}-result` with `STATUS=exhausted` and the list of remaining issues
- Exit the container

**If only WARNING (or SUGGESTION) issues remain and `iteration == max_iterations`**:
- Log: `[Iter <N> | verify] Y WARNING(s) — max iterations reached, proceeding`
- Write the result file and exit:
  ```bash
  echo "STATUS=verify-clean" > <log-dir>/apply-loop-{RUN_TIME_PREFIX}-result
  echo "APP={APP}" >> <log-dir>/apply-loop-{RUN_TIME_PREFIX}-result
  echo "CHANGE={CHANGE_NAME}" >> <log-dir>/apply-loop-{RUN_TIME_PREFIX}-result
  echo "ITERATIONS=<N>" >> <log-dir>/apply-loop-{RUN_TIME_PREFIX}-result
  echo "WARNINGS_ONLY=true" >> <log-dir>/apply-loop-{RUN_TIME_PREFIX}-result
  ```

**If no CRITICAL and no WARNING issues**:
- Log: `[Iter <N> | verify] ✓ Clean`
- Write the result file and exit:
  ```bash
  echo "STATUS=verify-clean" > <log-dir>/apply-loop-{RUN_TIME_PREFIX}-result
  echo "APP={APP}" >> <log-dir>/apply-loop-{RUN_TIME_PREFIX}-result
  echo "CHANGE={CHANGE_NAME}" >> <log-dir>/apply-loop-{RUN_TIME_PREFIX}-result
  echo "ITERATIONS=<N>" >> <log-dir>/apply-loop-{RUN_TIME_PREFIX}-result
  ```

Container exits cleanly. **Do NOT run opsx-archive — that is handled on the host.**
