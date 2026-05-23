---
name: hydra-gate-composer-audit
description: Run `composer audit` to check composer.lock dependencies for known CVEs. Invoked by the builder before push and the reviewer's mandatory block. Mirrors the orchestrator's `composer-audit` quality gate.
metadata:
  category: Hydra pipeline gate
  tags: [hydra, mechanical-gate, composer, security, cve]
---

## Purpose

Composer packages ship with known CVEs occasionally. The orchestrator's `composer-audit` stage fails the recheck on any High/Critical CVE in installed deps. Letting vulnerable packages ship is both a security problem and a blocking gate.

## Check

```bash
composer audit --format=plain
```

Exit 0 = clean. Exit non-zero = CVEs present. The plain-format output lists each advisory with its package, version, severity, and CVE ID.

## Fix action

For each flagged package:

1. Read the advisory to see the affected version range and the patched version
2. Update `composer.json` to a patched version constraint (e.g. `"phpunit/phpunit": "^10.5.64"` if `10.5.63` has a CVE)
3. Run `composer update <package>` to regenerate `composer.lock`
4. Re-run the audit to confirm green
5. Run `composer check:strict` + tests — a dep bump sometimes breaks downstream code

If the advisory is in a dev-only dep (PHPUnit, PHPCS, Psalm, etc.), the bump is low-risk. Production deps need careful regression testing.

## When NOT to bump

If the patched version breaks PHP-version compatibility (you're on PHP 8.3, patch needs 8.4) OR requires an ecosystem-wide migration, leave `[unfixed: composer-audit — <CVE-ID>]` with a note explaining the constraint. The applier will see it and decide whether to block.

## Related

- `composer update --dry-run` shows what WOULD change without modifying `composer.lock`
- `composer why <package>` shows who depends on a transitive dep you can't directly update

## Verification

Sample gate output is in [examples/](examples/):
- [examples/pass.log](examples/pass.log) — what stdout looks like when this gate is green on the diff
- [examples/fail.log](examples/fail.log) — what stdout looks like when this gate finds a violation; per-finding detail is in `/tmp/hydra-gate-composer-audit.log` so the builder/reviewer can read line-by-line and apply Fix actions deterministically. Confirm the gate is green by running `./scripts/run-hydra-gates.sh --scope-to-diff` from the app dir before pushing.
