# Design — retrofit-2026-05-24-file-actions

**Retrofit change. Tasks describe retroactive annotation, not new implementation work.**

This change retroactively annotates shipped OpenRegister code as the observed behavior of 5 REQs under a new `file-actions` capability. It does not modify behavior — every method covered by these REQs is already in production. The retrofit serves two purposes:

1. **Make `coverage-report.json` honest.** The 30 methods covered here move from Bucket 2 (observed-but-unspecified) to Bucket 1 (specified) once this change archives and `/opsx-coverage-scan` re-runs.
2. **Anchor future work.** Each REQ now has a stable address (`file-actions#REQ-NNN`) that future controller methods, refactors, and tests can reference.

## Scope (5 REQs, this run)

- **REQ-001**: File CRUD operations on objects (rename/copy/move/delete + audit + events)
- **REQ-002**: Distributed file locking (lock/unlock + admin force-unlock + dispatched events + audit)
- **REQ-003**: File preview & download streaming (auth + public-via-published-share gate)
- **REQ-004**: Object & register folder management (idempotent folder creation + node-by-ID lookups)
- **REQ-005**: Object tagging via Nextcloud system tags (generate/add/remove + facades on FileService)

## Out of scope (this run — see tasks.md `## DROP — future-pass:next`)

- Publish / depublish / generic file sharing (separate REQ)
- File metadata enrichment — labels / description / category (separate REQ)
- File upload pipeline (multipart, validation, normalisers) (separate REQ)
- File versioning (list / restore / metadata GET/PUT) (separate REQ)
- File chunking & Solr indexing (separate REQ)
- File settings admin (Dolphin/Presidio/OpenAnonymiser) (separate REQ)
- Document anonymisation (separate REQ)
- File sidebar & UI (separate REQ — likely cross-capability)
- File integration providers (separate REQ)

## Coexistence with existing `file-actions` change

There is an **unarchived `openspec/changes/file-actions/` change** (status: draft) that proposed the file-actions surface as forward-looking work. That change includes its own draft REQs for many of the same behaviors covered here, but in aspirational language ("MUST add", "SHALL register"). The two changes are not in conflict because:

1. The existing change has not been archived — `openspec/specs/file-actions/spec.md` does not yet exist on `development`.
2. This retrofit creates the canonical `openspec/specs/file-actions/spec.md` from observed behavior at archive time.
3. When the original change is eventually archived, the maintainer should reconcile its REQs against the retrofit REQs and either merge (if the original draft is more precise) or supersede (if the retrofit is the canonical truth). The retrofit REQs are derived from the live code and should be treated as the source of truth for archive-merge conflicts.

## Annotation conventions (per ADR-003)

- Each covered method gets a `@spec` tag inside its existing PHPDoc block (or a new block if missing).
- Tag format: `@spec openspec/changes/retrofit-2026-05-24-file-actions/tasks.md#task-N`
- Edits use the Edit tool only (per project rules — no sed/awk/python for code modification).
- PHPCS blank-line-before-@spec rule honored: `@spec` tags go in the same docblock as existing `@param` / `@return` tags, with no leading or trailing blank tag lines.
- The two `FileService` facade methods (`generateObjectTag`, `getAllTags`) get annotated alongside the handler methods — the facade is observable behavior too.

## Observed-but-flagged

See proposal.md "Notes" section. The most material observations:

- **FileLockHandler cache fallback is volatile** when `ICacheFactory::createDistributed` throws — locks no longer survive between requests in that mode. The handler logs a warning but continues. REQ-002 captures this as observed behavior; tightening to fail-closed is a future change.
- **`FilesController::preview` mixes response types** (`JSONResponse|StreamResponse`). The 404-with-fallback-icon shape is a deliberate UI contract — captured in REQ-003 as-is.
- **Controller error-message-substring matching** (rename, lock, unlock) means localizing those strings would break the HTTP status mapping. REQ-001/REQ-002 capture the substrings literally; future i18n work will need to find an alternative dispatch (e.g., custom exception classes).

## After archive

Once this change archives:

1. `openspec/specs/file-actions/spec.md` SHALL exist with the 5 REQs and `retrofit: true` frontmatter.
2. `/opsx-coverage-scan openregister` SHOULD move the 30 covered methods from Bucket 2 to Bucket 1.
3. A follow-up `retrofit-2026-05-NN-file-actions` change can be opened for the next batch of REQs from the deferred list.

## References

- Source coverage scan: `/tmp/or-scan/rspec-cluster-file-actions.json`
- Coverage report: `openspec/coverage-report.json` (2026-05-24)
- Retrofit playbook: `.github/docs/claude/retrofit.md`
- Sibling change with overlapping draft REQs: `openspec/changes/file-actions/`
