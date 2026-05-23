---
name: local-run
description: Run the full Hydra pipeline locally (builder → reviewer → security)
metadata:
  category: Pipeline
  tags: [pipeline, test, local]
---

Run the full Hydra pipeline locally against a disposable test repo.

**Input**: Flags are optional. Example: `/local-run --spec-dir /tmp/todo-mvp-spec --repo-url https://github.com/algorithm-conduction/todo-app`

**Defaults** (if no flags given):
- `--spec-dir`: ask the user
- `--repo-url`: ask the user
- `--github-user`: derived from repo-url, or `algorithm-conduction`
- `--repo-name`: derived from repo-url, or `todo-app`

**Steps** — execute all steps without interruption. Only stop if something fails.

1. **Parse flags** from the user's input. If `--spec-dir` or `--repo-url` are missing, ask once.

2. **Setup test repo**
   - Load `secrets/.env` (source it, never read/display contents)
   - Rename existing repo to `<name>-<timestamp>` via GitHub API (museum pattern, ignore if 404)
   - Create fresh repo with `auto_init: true` via GitHub API
   - Upload all files from the spec dir as `openspec/changes/<spec-basename>/` via GitHub Contents API

3. **Build images** from repo root:
   ```bash
   podman build -f images/builder/Dockerfile -t localhost/hydra-builder:test . -q
   podman build -f images/reviewer/Dockerfile -t localhost/hydra-reviewer:test . -q
   podman build -f images/security/Dockerfile -t localhost/hydra-security:test . -q
   ```
   Use `${CONTAINER_RUNTIME}` if set, otherwise auto-detect podman/docker.

4. **Run Builder**
   ```bash
   bash scripts/dev-run.sh <spec-dir> builder --repo-url <repo-url>
   ```
   Extract result from output (turns, cost, error status).

5. **Detect PR URL** from GitHub API — find the open PR on the repo.

6. **Run Reviewer + Security in parallel** (use background Bash tasks):
   ```bash
   bash scripts/dev-run.sh - reviewer --repo-url <repo-url> --pr-url <pr-url>
   bash scripts/dev-run.sh - security --repo-url <repo-url> --pr-url <pr-url>
   ```

7. **Report summary** — print a table:
   - Per agent: turns, cost, verdict (pass/fail)
   - Total cost
   - PR URL
   - Log file paths

**Constraints**:
- Never display secrets/.env contents
- Never ask permission between steps — the user pre-approved by invoking /local-run
- If builder fails, stop and report — don't run reviewer/security
- Parse JSONL logs for result extraction (type=result → is_error, num_turns, total_cost_usd)
