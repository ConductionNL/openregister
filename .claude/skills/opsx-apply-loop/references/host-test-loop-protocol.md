# Host Test Loop Protocol

Reference for Step 9 of `opsx-apply-loop`. Executes on the **host** after the container exits. Only runs if `{TESTS_ENABLED}=true`.

---

## Step 9: Host test loop (conditional)

**Skip this step entirely if `{TESTS_ENABLED}=false`.** Proceed directly to Step 10.

**Check Nextcloud environment** — quick check before running tests:

```bash
DOCKER_DEV_ROOT="$(cd ../../../.. && pwd)"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/status.php 2>/dev/null)
```

**If `HTTP_CODE` is `200`**: Nextcloud is running. Verify the app is enabled:

```bash
docker compose -f "$DOCKER_DEV_ROOT/.github/docker-compose.yml" exec -T nextcloud php occ app:list --enabled 2>/dev/null | grep -q "^  - {APP}$" && echo "✅ {APP} enabled" || echo "⚠️ {APP} not found in enabled apps"
```

Log: `✅ Nextcloud ready, {APP} enabled`

**If `HTTP_CODE` is not `200`** (or app is not enabled): start containers and wait up to 60s:

```bash
docker compose -f "$DOCKER_DEV_ROOT/.github/docker-compose.yml" up -d nextcloud proxy
```

Poll `http://localhost:8080/status.php` every 5s. If still not `200` after 60s, show logs and stop:

```bash
docker compose -f "$DOCKER_DEV_ROOT/.github/docker-compose.yml" logs master-nextcloud-1 --tail=20
```

> "⛔ Nextcloud did not start in time. Fix the environment and re-run `/opsx-apply-loop`."

Set `test_iteration = 1`. Set `max_test_iterations = 3`.

### 9a. Run the in-loop test commands

Log: `🧪 Test iteration <T> — running: <TEST_COMMANDS_IN_LOOP>`

**Use the Agent tool (NOT the Skill tool)** to run each command in `{TEST_COMMANDS_IN_LOOP}` sequentially. The Agent tool runs as a subprocess and returns results directly to you — this is what allows the loop to continue after each test without getting stuck. Never use the Skill tool here; it loads the skill inline and the conversation will terminate instead of returning control.

For each command (e.g. `/test-functional` → skill name `test-functional`), determine the absolute path to the skill file. Skills live in the `hydra` repo, which is a sibling directory to `{APP}/` inside apps-extra. Construct the path as:

```
CLAUDE_SKILLS="$(cd {APP}/../hydra && pwd)/.claude/skills"
SKILL_FILE="${CLAUDE_SKILLS}/{skill-name}/SKILL.md"
```

Launch a **general-purpose Agent** with a prompt that includes:
1. "Read and follow the skill instructions at `{SKILL_FILE}`."
2. "App: `{APP}`. Change: `{CHANGE_NAME}`. Save all test output (screenshots, result files) inside `{APP}/test-results/` — never in the workspace root."
3. "You are in READ-ONLY mode — do NOT modify any code files. Your job is to test the current state, identify failures, and produce a structured report."
4. "End with the structured result line (e.g. `FUNCTIONAL_TEST_RESULT: PASS | FAIL  CRITICAL_COUNT: <n>  SUMMARY: <one-line summary>`). Output nothing after the result line."
5. Any relevant prior-run context (test failures from earlier iterations if this is a re-entry).

**CRITICAL: Test skills must NEVER make code changes.** They must only:
1. Run tests against the current code
2. Identify what is failing and why
3. Produce a report describing what needs to change (file, line, issue, suggested fix)

Each command automatically targets `{APP}` when invoked (or pass the app name as an argument if the skill supports it).

After each Agent call returns, read its result summary to extract pass/fail from the structured result line (e.g., `FUNCTIONAL_TEST_RESULT: PASS | FAIL`). A command **fails** if it reports FAIL or any CRITICAL/HIGH-level finding. If a test skill does not output a result line, treat its recommendation as: APPROVE/COMPLIANT/SECURE = PASS, anything else = FAIL.

Update the loop log:
```
| T<N> | test:<cmd> | ✓ Pass / X FAIL | <summary> |
```

After the Agent returns, **immediately continue to the next command or Step 9b** — do NOT pause, do NOT ask the user anything, do NOT wait for confirmation.

### 9b. Evaluate test results

**If all commands pass**:
- Log: `✅ Test iteration <T> — all tests pass`
- Proceed to **Step 10** (deferred tests)

**If any command fails and `test_iteration < max_test_iterations`**:
- Log: `⚠️ Test iteration <T> — failures found, re-entering apply→verify`
- Write test failures to a file for the container to read:
  ```bash
  FAIL_TIME=$(date +%H:%M)
  echo "TEST_ITERATION=<T>" > "${LOG_DIR}/apply-loop-${FAIL_TIME}-test-failures-${TEST_ITERATION}.log"
  echo "FAILED_COMMANDS=<list>" >> "${LOG_DIR}/apply-loop-${FAIL_TIME}-test-failures-${TEST_ITERATION}.log"
  # Append the full failure output from each failed test command
  ```
- Increment `test_iteration`
- Go to **Step 9c**

**If any command fails and `test_iteration == max_test_iterations`**:
- Log: `⛔ Test loop exhausted after 3 iterations — failures remain`
- Use **AskUserQuestion** to ask:
  > "Tests still failing after 3 iterations. How would you like to proceed?"
  - **Archive anyway** — proceed to Step 10 with a warning note
  - **Fix manually, then re-run** — stop here; user can run `/opsx-apply-loop {APP} {CHANGE_NAME}` again
  - **Skip tests and archive** — proceed to Step 10, skip remaining test steps
  - **Cancel** — stop here, do not archive

### 9c. Re-enter container for test-failure fixes

Start a new container run (reuse Step 6.5 with test-failure re-entry variant). The container will:
1. Read the test-failures file passed in the startup prompt (e.g. `${CONTAINER_LOG_DIR}/apply-loop-${FAIL_TIME}-test-failures-${TEST_ITERATION}.log`) for context
2. Run `opsx-apply` targeting the failing areas
3. Run `opsx-verify` to check the fix
4. Exit with `STATUS=verify-clean` or `STATUS=exhausted`

Wait for container exit (monitoring via Step 6.6). Handle exit scenarios per Step 6.7.

After a **verify-clean** exit from the re-entry container, sync GitHub issue checkboxes immediately (same as Scenario A in Step 6.7) — read current `{APP}/openspec/changes/{CHANGE_NAME}/tasks.md` and update issue #`{ISSUE_NUMBER}`.

Clean up the container after re-entry exits (keep all log and test-failure files):
```bash
docker rm "apply-loop-{APP}-{CHANGE_NAME}-{ITERATION}-t{TEST_ITERATION}"
```

Return to **Step 9a**.
