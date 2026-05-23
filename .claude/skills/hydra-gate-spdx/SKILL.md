---
name: hydra-gate-spdx
description: Enforce @license + @copyright PHPDoc tags on every PHP file under lib/. Invoked by the builder before push, by the reviewer as the first Mandatory Step, and by the fixer during a retry cycle. Mirrors the orchestrator's `spdx-headers` quality gate exactly.
metadata:
  category: Hydra pipeline gate
  tags: [hydra, mechanical-gate, license, eupl]
---

## Purpose

Every PHP file under `lib/` on a Conduction app must carry `@license` + `@copyright` PHPDoc tags in the main file docblock (ADR-014). The orchestrator's `run-quality.sh` has a `spdx-headers` stage (name kept for JSON-report compat) that fails the recheck if any file is missing either tag. Missing this gate is one of the most common reasons a PR bounces back to `needs-input`.

Do **NOT** add `SPDX-FileCopyrightText` or `SPDX-License-Identifier` lines to PHP files — they duplicate the @-tags. Repo-level REUSE compliance lives in `REUSE.toml` at the repo root, not per-file.

## Check

```bash
# Find PHP files missing @license or @copyright PHPDoc tag.
# Docblock-anchored grep: the tag must appear as ` * @license ` in a docblock,
# not inside a string literal.
MISSING_LICENSE=$(grep -rLE '^[[:space:]]*\*[[:space:]]*@license[[:space:]]' lib/ --include='*.php' 2>/dev/null)
MISSING_COPYRIGHT=$(grep -rLE '^[[:space:]]*\*[[:space:]]*@copyright[[:space:]]' lib/ --include='*.php' 2>/dev/null)
if [ -n "$MISSING_LICENSE" ] || [ -n "$MISSING_COPYRIGHT" ]; then
    echo "FAIL spdx-headers"
    [ -n "$MISSING_LICENSE" ] && { echo "  Missing @license:"; echo "$MISSING_LICENSE" | sed 's/^/    /'; }
    [ -n "$MISSING_COPYRIGHT" ] && { echo "  Missing @copyright:"; echo "$MISSING_COPYRIGHT" | sed 's/^/    /'; }
fi
```

## Required header format (PHP files)

```php
<?php

/**
 * Short Description
 *
 * Longer description.
 *
 * @category Controller
 * @package  OCA\{AppName}\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright <year> Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/{change-name}/tasks.md#task-N
 */

declare(strict_types=1);
```

Required tags on every PHP file: `@author`, `@copyright`, `@license`, `@link`, `@spec`. Public classes and public methods get their own `@spec` tag (ADR-003). Tests under `tests/` are scoped out of the gate — the check runs against `lib/` only.

## Non-PHP files (still use SPDX)

Vue / JS / TS / CSS / shell scripts don't carry PHPDoc. Use SPDX header as the first line:

- `.vue`: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
- `.js` / `.ts`: `// SPDX-License-Identifier: EUPL-1.2`
- `.css` / `.scss`: `/* SPDX-License-Identifier: EUPL-1.2 */`
- `.sh`: `# SPDX-License-Identifier: EUPL-1.2`

The gate is PHP-only — it doesn't check these. But keep the convention for REUSE compliance.

## Fix action

For each PHP file missing `@license` or `@copyright`:

1. Read the file
2. Locate the main file docblock (the first `/** ... */` block after `<?php`)
3. If the docblock exists but lacks the tag(s), insert them in the @-tag section (between `@package`/`@author` and `@link`)
4. If the docblock does not exist, add one immediately after the `<?php` opening, before `declare(strict_types=1);`
5. Re-run the Check to confirm green

This is a **bounded, mechanical fix** — always in-scope for the builder and reviewer. Never emit a verdict with `FAIL spdx-headers` still present; the orchestrator's recheck will reject the push.

## Known pitfalls

- `declare(strict_types=1);` comes AFTER the docblock, not before.
- Old files may have `// SPDX-FileCopyrightText` / `// SPDX-License-Identifier` line comments outside the docblock — those duplicate the @tags and should be **removed** when you touch the file.
- The gate name is `spdx-headers` for JSON-report compat, but it enforces PHPDoc @-tags, not SPDX lines. The naming will migrate once all legacy tooling is updated.

## Repo-level REUSE

For full REUSE compliance (`reuse lint` at the repo root), the repo should carry `REUSE.toml` with a rule mapping `**/*.php` → `EUPL-1.2`. No per-file SPDX headers are needed on PHP files when `REUSE.toml` is present.

## Verification

Sample gate output is in [examples/](examples/):
- [examples/pass.log](examples/pass.log) — what stdout looks like when this gate is green on the diff
- [examples/fail.log](examples/fail.log) — what stdout looks like when this gate finds a violation; per-finding detail is in `/tmp/hydra-gate-spdx.log` so the builder/reviewer can read line-by-line and apply Fix actions deterministically. Confirm the gate is green by running `./scripts/run-hydra-gates.sh --scope-to-diff` from the app dir before pushing.
