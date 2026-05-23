# Check Tool Verification

Reference for Step 4d-verify of `create-pr`. Verifies that every tool binary required by the check steps actually exists before running any checks.

---

## Step 4d-verify: Verify Check Tools Are Available

After install steps complete, verify that **every tool binary** required by the check steps actually exists before running any checks. This prevents silent skips (e.g. composer scripts that fall back to `|| echo 'not installed, skipping...'`).

**1. Build a tool checklist** from the execution plan's check steps. For each check command, identify the binary it needs:

| Check command pattern | Binary to verify |
|---|---|
| `composer psalm` | `vendor/bin/psalm` |
| `composer phpstan` | `vendor/bin/phpstan` |
| `composer phpcs` | `vendor/bin/phpcs` |
| `composer phpmd` | `vendor/bin/phpmd` |
| `composer phpmetrics` | `vendor/bin/phpmetrics` |
| `composer lint` | `php` (always available) |
| `npx eslint` / `npm run lint` | `node_modules/.bin/eslint` |
| `npx stylelint` / `npm run stylelint` | `node_modules/.bin/stylelint` |
| Any `vendor/bin/{tool}` | that exact path |
| Any `node_modules/.bin/{tool}` | that exact path |

**2. Determine WHERE to check** — checks may run locally or inside the Docker container:

- For **local check steps**: verify the binary exists at `{CHECK_DIR}/{binary_path}`
- For **Docker check steps**: verify inside the container:
  ```bash
  docker exec "$NC_CONTAINER" test -f /var/www/html/apps-extra/{APP_DIR}/{binary_path} && echo "EXISTS" || echo "MISSING"
  ```

**3. Probe each tool** and collect results:

```bash
# Local example:
test -f {CHECK_DIR}/vendor/bin/psalm && echo "EXISTS" || echo "MISSING"

# Docker example:
docker exec "$NC_CONTAINER" test -f /var/www/html/apps-extra/{APP_DIR}/vendor/bin/psalm && echo "EXISTS" || echo "MISSING"
```

**4. If ALL tools are available:** proceed silently to Step 4e.

**5. If ANY tools are missing:** display a clear report:

```
Tool availability check:
  ✅ vendor/bin/phpcs          — available
  ✅ vendor/bin/phpstan        — available
  ❌ vendor/bin/psalm          — MISSING
  ❌ vendor/bin/phpmd          — MISSING
  ✅ node_modules/.bin/eslint  — available
```

Then determine the likely fix. Missing tools are almost always caused by a failed or incomplete dependency install:

- **Missing `vendor/bin/*` tools** → `composer install` likely failed or was never run. The fix is:
  - Locally: `composer install --ignore-platform-reqs` (in `{CHECK_DIR}`)
  - In Docker: `docker exec -w /var/www/html/apps-extra/{APP_DIR} "$NC_CONTAINER" composer install --ignore-platform-reqs`
- **Missing `node_modules/.bin/*` tools** → `npm ci` or `npm install` likely failed or was never run. The fix is:
  - Locally: `npm ci` (in `{CHECK_DIR}`)
  - In Docker: `docker exec -w /var/www/html/apps-extra/{APP_DIR} "$NC_CONTAINER" npm ci`

Ask the user using AskUserQuestion:

**"Some check tools are missing and checks would silently skip without them. Should I install the missing dependencies now?"**
- **Yes, install now** — run the appropriate install command(s), then re-verify all tools. If still missing after install, report the specific failures and stop.
- **Skip missing checks** — proceed to Step 4e, but mark any check whose tool is missing as `⏭️ SKIPPED (tool not installed)` in the results table instead of running it. Include this in the PR description.
- **Stop here** — let the user fix the environment manually and re-run the skill.
