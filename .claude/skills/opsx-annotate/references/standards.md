# opsx-annotate — Standards and Inputs

This skill produces annotation-only PRs that apply `@spec` tags to legacy code. It consumes the output of `/opsx-coverage-scan` and enforces Hydra's traceability + docblock standards. The skill does not re-derive any of these rules — it applies them.

## Upstream input: coverage-report.json

Produced by [/opsx-coverage-scan](../../opsx-coverage-scan/SKILL.md). Must be < 24h old. This skill reads only `buckets.bucket_1` and the `branch` field. Full schema is documented in the coverage-scan skill.

- **`buckets.bucket_1[]`** — each entry: `{file, method, capability, req_id, confidence, needs_review, signal}`. Entries with `needs_review: true` are skipped (reported to user).
- **`branch`** — the branch the scan ran against. This skill checks out that branch before creating the retrofit branch on top.

## Annotation format (authoritative)

Per [ADR-003 §Spec traceability](../../../../openspec/architecture/adr-003-backend.md) and [hydra-gate-spdx](../../hydra-gate-spdx/SKILL.md):

```php
/**
 * <description>
 *
 * @category <category>
 * @package  <Vendor\Package>
 * @author   <name> <<email>>
 * @copyright 2024-2026 Conduction B.V.
 * @license  AGPL-3.0-or-later
 * @link     <url>
 * @spec     openspec/changes/{change}/tasks.md#task-N
 */
```

**Key rules:**

1. `@spec` tags come **after** `@link`, in task-order.
2. One `@spec` tag per distinct task, even if multiple methods in the file share tasks.
3. Method-level docblocks carry only the `@spec` tags relevant to that method (a subset of the file-level tags).
4. `@spec` points at a **task**, never directly at a REQ. Tasks live in a change's `tasks.md`. For legacy code, the change is a "ghost change" (see below).

## Ghost changes (why this skill creates one)

Legacy code pre-dates OpenSpec, so there's no real change to point at. This skill creates one per run:

- **Name**: `retrofit-annotate-{app}-{YYYY-MM-DD}`
- **`proposal.md`**: fixed template (see SKILL.md step 5).
- **`specs/`**: empty directory — no spec delta. All REQs already exist in `openspec/specs/`.
- **`tasks.md`**: one task per Bucket 1 REQ. All tasks arrive `[x]` because the code is pre-existing.
- **Archived**: always archived at the end of the run (moves to `openspec/changes/archive/`). Tag paths stay valid because `@spec openspec/changes/...` is textual, not a live lookup.

## Downstream contract: hydra-gate checks

After annotation, [hydra-gates](../../hydra-gates/SKILL.md) run. The relevant gates:

- **hydra-gate-spdx** — checks `@license`, `@copyright`, `@spec` placement in file docblocks.
- **hydra-gate-forbidden-patterns** — unrelated to annotation but runs as part of `/hydra-gates`.

**Guardrail:** if any gate fails because of tag ordering, fix the PHPCS config — do NOT reorder tags. The ADR-003 order is fixed.

## PR labels

Two labels, created on first run if missing:

| Label | Colour | Meaning |
|---|---|---|
| `retrofit` | `#5319E7` | Part of the retrofit playbook (scan → annotate → reverse-spec) |
| `annotation-only` | `#C5DEF5` | Zero logic changes — reviewers can approve after a quick tag spot-check |

## `.git-blame-ignore-revs`

The annotation commit's SHA is appended to `.git-blame-ignore-revs` in the target repo so `git blame` skips the retrofit commit. Each developer must enable it once per clone:

```bash
git config blame.ignoreRevsFile .git-blame-ignore-revs
```

The skill does NOT set this config automatically — it's a per-developer choice.

## Related skills in the workflow chain

```
/opsx-coverage-scan → /opsx-annotate → /opsx-reverse-spec (Bucket 2)
                                     → /hydra-gates (verification)
                                     → /create-pr
                                     → /opsx-archive (ghost change)
```

Each step has its own skill. This skill orchestrates steps 5, 7, 10, 12 from that chain for Bucket 1 items only.
