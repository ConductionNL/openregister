---
name: hydra-gates
description: Run all thirteen Hydra mechanical quality gates (SPDX, forbidden-patterns, stub-scan, composer-audit, route-auth, orphan-auth, no-admin-idor, unsafe-auth-resolver, semantic-auth, initial-state, admin-router, nc-input-labels, modal-isolation) via the shared script and summarise the failures. Invoked by the builder's Rule 0b wrapper (mechanically, on every iteration) and by the reviewer / security reviewer's mandatory first step. Single source of truth lives in scripts/run-hydra-gates.sh.
metadata:
  category: Hydra pipeline gate
  tags: [hydra, mechanical-gate, all-gates]
---

## Purpose

One entrypoint for all thirteen Hydra mechanical gates. Run this first, **before** reading files for architectural review or broader work.

## How it works

The gate bash lives in **`scripts/run-hydra-gates.sh`** — one script invoked from three places:

1. **Builder wrapper** (`images/builder/entrypoint.sh` Rule 0b loop) — runs the script mechanically between Claude turns. Exit code drives focused fix passes; build can't reach push with any gate red.
2. **Reviewer / security reviewer containers** — invoke the script from this skill as the mandatory first step.
3. **Local / manual** — `./scripts/run-hydra-gates.sh [app-dir]` from any repo root.

The script emits `[gate-N] <name>: PASS | FAIL[<reasons>]` lines and returns exit code = number of failing gates. Detail logs land at `/tmp/hydra-gate-<name>.log` per-gate.

## The thirteen gates

| # | Gate | What it catches | Per-gate skill |
|---|---|---|---|
| 1 | **SPDX headers** | every `lib/**.php` has `@license` + `@copyright` PHPDoc | `hydra-gate-spdx` |
| 2 | **Forbidden patterns** | no `var_dump`/`die`/`error_log`/`print_r`/`dd`/`dump` in `lib/` | `hydra-gate-forbidden-patterns` |
| 3 | **Stub scan** | no "In a complete implementation", no empty BackgroundJob `run()`, no Vue hard-coded fetch stubs | `hydra-gate-stub-scan` |
| 4 | **Composer audit** | no known CVEs in composer.lock | `hydra-gate-composer-audit` |
| 5 | **Route-auth** | every controller method in `appinfo/routes.php` declares its NC auth posture via attribute/tag | `hydra-gate-route-auth` |
| 6 | **Orphan-auth** | `is*`/`requires*`/`validate*`/`authorize*`/`check*`/`ensure*`/`verify*`/`assert*` methods have at least one external caller | `hydra-gate-orphan-auth` |
| 7 | **No-admin-IDOR** | every `#[NoAdminRequired]` method has an in-body guard (OCSForbiddenException / isAdmin / authorize*/require*/ensure*) | `hydra-gate-no-admin-idor` |
| 8 | **Unsafe-auth-resolver** | no `catch(\Throwable){return null;}` in auth/permission/role resolver methods (CWE-863 fail-open) | `hydra-gate-unsafe-auth-resolver` |
| 9 | **Semantic-auth** | auth annotation matches method body: `#[NoAdminRequired]` must not coexist with `requireAdmin()` body; `#[PublicPage]` must not coexist with in-body auth checks | `hydra-gate-semantic-auth` |
| 10 | **Initial-state** | no `getElementById(...).dataset.*` reads in `.vue`/`.js`/`.ts` — server data must use `IInitialState` + `loadState()` | `hydra-gate-initial-state` |
| 11 | **Admin-router** | admin settings Vue components must NOT appear in `src/router/` — they're rendered by NC's settings framework, never as frontend routes | `hydra-gate-admin-router` |
| 12 | **NC-input-labels** | every `<NcSelect>` has `inputLabel` or `ariaLabelCombobox` (manual `<label>` breaks a11y wiring) | `hydra-gate-nc-input-labels` |
| 13 | **Modal-isolation** | `<NcModal>` / `<NcDialog>` markup lives in `src/modals/` or `src/dialogs/`, not inline in parent components | `hydra-gate-modal-isolation` |

## How to invoke

In a container (builder / reviewer / security):
```bash
/usr/local/lib/hydra/run-hydra-gates.sh --scope-to-diff
```

On a local checkout:
```bash
./scripts/run-hydra-gates.sh [--scope-to-diff [--base BRANCH]] [path-to-app-dir]
```

**ADR-020 — `--scope-to-diff` is the default pipeline mode.** The flag restricts every gate to files added/modified (`--diff-filter=ACMR`) in the current branch vs the base (default `origin/development`). All 4 pipeline positions (builder Rule 0b, code-reviewer pre/post-flight, security-reviewer pre/post-flight) now pass this flag. Override base via `--base main` or the `HYDRA_GATE_BASE_REF` env var. Without the flag, gates revert to full-repo scan — use that for `ready-for-audit` baseline sweeps, never for PR gating.

The per-gate skills (`hydra-gate-spdx`, `hydra-gate-no-admin-idor`, etc.) describe the fix for each failure class — consult them when the summary line points at a specific gate.

## Key guarantees

- **Same script, three call sites.** Builder wrapper + both reviewer containers + SKILL.md manual run all invoke `scripts/run-hydra-gates.sh`. Any gate edit touches one file.
- **Mechanical in the builder.** The Rule 0b wrapper runs the script between Claude turns — not via the skill system. Claude cannot skip a gate.
- **Skill-invoked in reviewers.** Reviewer/security CLAUDE.md tell Claude to run `/usr/local/lib/hydra/run-hydra-gates.sh` and fix every FAIL before anything else. Future work: move to wrapper enforcement in those containers too.
- **Exit code contract.** Zero = all green. Any non-zero = number of failing gates.
