- Licence: EUPL-1.2 (European Union Public Licence).
- `appinfo/info.xml`: MUST use `<licence>agpl</licence>` — Nextcloud app store does not recognise EUPL.
- This is intentional dual-tagging, NOT a conflict. Do NOT change info.xml to eupl. Do NOT flag as review finding.

## PHP files — PHPDoc tags only

License and copyright metadata on PHP files lives **only** in the main file docblock as PHPDoc tags:

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
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/{change-name}/tasks.md#task-N
 */

declare(strict_types=1);
```

**Required tags on every PHP file:** `@author`, `@copyright`, `@license`, `@link`, `@spec`. File-level `@spec` links back to the OpenSpec change that created or last modified the file (ADR-003). Classes and public methods also carry their own `@spec` tag.

**Do NOT add:**
- `SPDX-FileCopyrightText: ...` lines in the docblock — that duplicates `@copyright`.
- `SPDX-License-Identifier: ...` lines in the docblock — that duplicates `@license`.
- `// SPDX-*` line comments before or after the docblock.

## Vue / JS / CSS files

These file types don't carry PHPDoc. Use SPDX header as the first line:

- Vue: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
- JS / TS: `// SPDX-License-Identifier: EUPL-1.2`
- CSS / SCSS: `/* SPDX-License-Identifier: EUPL-1.2 */`

## Repo-level REUSE compliance

Every app repo SHOULD carry a `REUSE.toml` at its root declaring license + copyright for every file pattern. This is the authoritative source for REUSE compliance — `reuse lint` reads it instead of requiring per-file SPDX headers for PHP files:

```toml
version = 1

[[annotations]]
path = "**/*.php"
SPDX-FileCopyrightText = "2026 Conduction B.V. <info@conduction.nl>"
SPDX-License-Identifier = "EUPL-1.2"
```

## Hydra quality gate

`scripts/run-quality.sh`'s `spdx-headers` gate enforces: every `lib/**/*.php` file has both `@license` and `@copyright` PHPDoc tags. Missing either fails the gate.
