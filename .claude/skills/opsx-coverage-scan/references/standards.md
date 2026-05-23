# opsx-coverage-scan — Standards and Downstream Contracts

This skill is the entry point of the [retrofit playbook](../../../../.github/docs/claude/retrofit.md). It is read-only — it produces two files and never modifies code. Its output is consumed by `/opsx-annotate` and `/opsx-reverse-spec`.

## The 6-bucket taxonomy (authoritative)

Every non-plumbing method lands in exactly one bucket:

| Bucket | Meaning | Downstream action |
|---|---|---|
| **annotated** | `@spec openspec/changes/...` already in docblock | None — already tagged |
| **plumbing** | Framework-only: empty constructors, `__call`/`__get`, listener dispatch shims, controller methods with no logic | Never tagged |
| **1** | REQ-matched, confidence ≥ 0.85 (or 0.70–0.85 with `NEEDS-REVIEW`) | `/opsx-annotate` |
| **2a** | File belongs to an existing capability but no REQ covers the behavior | `/opsx-reverse-spec --extend <cap>` |
| **2b** | No capability owns the file | `/opsx-reverse-spec --cluster <name>` |
| **3a** | REQ with no Bucket 1 match; `git log -S` finds removed implementation | Separate fix PR |
| **3b** | REQ with no Bucket 1 match; no git history reference | Mark deferred or remove |
| **4** | ADR conformance findings (missing SPDX tags, forbidden patterns, hardcoded strings, direct SQL) | Follow-up issue |

**Confidence scoring** (Bucket 1 only) is judgment — driven by:
1. REQ title verb+noun appearing in method name
2. REQ scenario text referencing the same domain nouns as file/class
3. File path aligned with capability directory

Record the signal(s) used per entry so humans can audit.

## Output files (authoritative schemas)

### `openspec/coverage-report.json` (parseable — consumed by `/opsx-annotate`)

See SKILL.md Step 9 for the full schema. Key invariants:

- `generated_at` — ISO 8601 UTC; annotate-side checks `< 24h old`.
- `app` — app slug (matches directory name).
- `branch` — branch the scan ran against. `/opsx-annotate` checks out this branch before creating its retrofit branch.
- `buckets.bucket_1[]` entries MUST include `{file, method, capability, req_id, confidence, needs_review, signal}`. Missing any field breaks the annotation skill.

### `openspec/coverage-report.md` (human-readable companion)

Structure enforced by SKILL.md Step 9. The human reviewer uses this before approving `/opsx-annotate`. The Notes section is free-text — use it for ambiguity flags.

## Inputs this skill reads

| Path | Purpose |
|---|---|
| `{app}/openspec/specs/*/spec.md` | REQ inventory (capability + REQ-NNN + scenarios + keywords) |
| `{app}/openspec/changes/*/specs/*/spec.md` | In-flight delta REQs (drafts count) |
| `{app}/lib/**/*.php` | PHP code units (skip `lib/Migration/`, `lib/Db/` entity boilerplate) |
| `{app}/src/**/*.{vue,ts,js}` | Frontend code units (skip `*.spec.*`, `*.test.*`, `__tests__/`, `src/main.js`, `src/bootstrap.js`) |
| `{app}/.opsx-ignore` (optional) | Glob patterns to exclude from Buckets 1, 2a, 2b, 4 (not 3) |
| Git history | `git log -S` for Bucket 3a detection |

## ADR references used for Bucket 4 (conformance sweep)

| ADR | Rule |
|---|---|
| [ADR-001](../../../../openspec/architecture/adr-001-data-layer.md) | Direct SQL (`$this->db->query`, `prepare`) should use OpenRegister |
| [ADR-003](../../../../openspec/architecture/adr-003-backend.md) | `@spec` traceability tag in file + method docblocks |
| [ADR-014](../../../../openspec/architecture/adr-014-licensing.md) | SPDX license + copyright in file docblocks |
| [hydra-gate-forbidden-patterns](../../hydra-gate-forbidden-patterns/SKILL.md) | `var_dump`, `dd(`, `die(`, `print_r(`, `error_log(` outside tests |

Bucket 4 is surfaced for humans to triage — non-blocking.

## Related skills in the workflow chain

```
[start] → /opsx-coverage-scan → /opsx-annotate (Bucket 1 → ghost change → PR)
                              → /opsx-reverse-spec (Bucket 2 → new REQs → PR)
                              → manual triage (Bucket 3, 4)
```

## Chunking guidance (large apps)

- **> 500 PHP files** → skill pauses before Pass A to ask for confirmation
- **Bucket 1 > 150 methods across 50+ files** → surface a warning to user; downstream `/opsx-annotate --capability <cap>` flag can chunk later
- **Python ExApps** → stop with "Python variant deferred" message
