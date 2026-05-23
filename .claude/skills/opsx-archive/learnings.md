# Learnings — opsx-archive

## Patterns That Work

## Mistakes to Avoid

- **2026-05-01 — Archive moves the ghost change but `@spec` tags still point at the pre-archive path.** `/opsx-annotate` and `/opsx-reverse-spec` write `@spec openspec/changes/retrofit-{name}/tasks.md#task-N` into PHPDoc. After `/opsx-archive`, the directory moves to `openspec/changes/archive/retrofit-{name}/` (or `openspec/archive/retrofit-{name}/` in older manual-archive runs), so the literal path in every `@spec` tag no longer resolves on disk. Observed across openregister's 9 archived retrofit ghost changes (b2b-crossrefs, calendar-integration, content-versioning, object-lifecycle, tenant-lifecycle, schema-hooks, tenant-isolation-audit, notificatie-engine, chat-ai). The `@spec` convention is textual — a developer can still find the tasks.md by name — but any tool that resolves the literal path will 404. **Why:** the archive step doesn't rewrite annotations, and the annotation step doesn't anticipate the move. **How to apply:** when the archive lands a ghost change in a different path than the `@spec` tags reference, either (a) rewrite the `@spec` paths to point at the archive location during archive, or (b) make `/opsx-archive` write a redirect/symlink at the original `openspec/changes/retrofit-{name}/` path. Option (b) is cheaper and preserves git blame on the annotated files. Until decided, `/opsx-verify` should treat `openspec/changes/retrofit-X`, `openspec/changes/archive/retrofit-X`, and `openspec/archive/retrofit-X` as equivalent fallbacks when resolving `@spec` paths.

## Patterns That Work

- **2026-05-01 — Symlink at original path was tried but rejected — `/opsx-verify` flags it as CRITICAL.** `ln -s archive/2026-05-01-retrofit-{name} openspec/changes/retrofit-{name}` was attempted on openregister `retrofit-2026-05-01-actions` to redirect dangling `@spec` paths. Confirmed working on disk (literal path resolves; git tracks `mode 120000`). However, `/opsx-verify --app <slug>` §A4.2 fails any symlink under `{app}/openspec/changes/` as CRITICAL — the check exists to catch legacy half-archive forms but does not distinguish redirect symlinks. The correct resolution is **option (a) from the Mistakes entry above** — rewrite the `@spec` paths during archive so the literal text in PHPDoc points at `openspec/changes/archive/retrofit-{name}/...`. This keeps `opsx-verify` clean and avoids the symlink anti-pattern. Until `/opsx-archive` automates the rewrite, do it manually as a sed pass over `lib/`/`src/` after archiving.

## Domain Knowledge

## Open Questions

- **Archive location convention** — older retrofit changes in openregister landed in `openspec/archive/` (manual move), newer ones (`retrofit-2026-04-30-chat-ai`, `retrofit-2026-04-30-annotate-{app}`) landed in `openspec/changes/archive/` (via `/opsx-archive`). Both locations exist in the same repo. Pick one canonical destination and migrate the strays, otherwise tooling has to support three possible paths per `@spec` tag.

## Consolidated Principles
