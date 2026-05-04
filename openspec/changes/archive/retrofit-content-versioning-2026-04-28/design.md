## Context

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

The existing `content-versioning` capability covers *object* versioning (semantic version numbering, draft states, rollback through audit trail). It does **not** cover *file attachment* versioning, which OpenRegister exposes through Nextcloud's `IVersionManager` / `files_versions` app via the thin wrapper `FileVersioningHandler`.

The ghost change `retrofit-content-versioning-2026-04-28` extends `content-versioning` with REQ-017 to specify the file-attachment versioning behavior as observed in `FileVersioningHandler`.

**Files covered:**
- `lib/Service/File/FileVersioningHandler.php` (4 methods, 1 private) — wrapper around Nextcloud `IVersionManager` for listing and restoring file-attachment versions

## Goals / Non-Goals

**Goals:**
- Add REQ-017 covering file-attachment version listing and restoration
- Annotate the 4 methods (3 public + 1 private helper) with `@spec` tags
- Document that file-attachment versioning is a *delegation* to Nextcloud's existing infrastructure rather than a custom OpenRegister implementation

**Non-Goals:**
- No code changes — annotations + spec only
- Does not respec object versioning (unchanged)
- Does not address scanner misclassification of `ExecutionHistoryCleanupJob` and `MessageHistoryHandler` (deferred to their correct capability clusters: workflow-engine-abstraction and chat-ai)
- Vue/JS components are skipped — the `@spec` convention is PHP-only at present

## Decisions

**Decision: `--extend content-versioning` rather than `--cluster file-versioning`**

File-attachment versioning is the same conceptual capability as object versioning ("track and restore prior states") applied to a different artifact type. Splitting them would force consumers to reason about two specs for one feature.

**Decision: Spec the delegation, not the underlying implementation**

REQ-017 specifies what `FileVersioningHandler` exposes (list + restore), not the inner workings of Nextcloud's `IVersionManager`. The wrapper is the contract surface; the underlying versioning behavior is Nextcloud's responsibility.

**Decision: Defer ExecutionHistoryCleanupJob and MessageHistoryHandler**

The scanner clustered both under `content-versioning` because their names contain "history" tokens. Reading the code confirmed they belong elsewhere (workflow execution history and LLM chat conversation history, respectively). Including them here would have polluted the spec with unrelated concerns.

## Risks / Trade-offs

- **Versioning availability is environment-dependent**: `isVersioningEnabled` returns false if the `files_versions` app is disabled. REQ-017 surfaces this as a scenario; consumers must handle the disabled state.
- **No version-comparison REQ**: `FileVersioningHandler` does not implement diff/compare semantics — that capability sits in Bucket 3b and is tracked as a deferred REQ in `coverage-report.md`. REQ-017 covers list+restore only.
- **No pruning/quota REQ**: file-version retention is governed by Nextcloud's `files_versions` config, not OpenRegister. Surfaced in Notes.

## Migration Plan

No migration required — annotations only. The ghost change is archived immediately and the new REQ-017 lands in `openspec/specs/content-versioning/spec.md`.

`.git-blame-ignore-revs` was updated with the annotation commit SHA.
