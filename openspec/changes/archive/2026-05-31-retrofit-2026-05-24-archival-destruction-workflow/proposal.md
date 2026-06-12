# Retrofit: reverse-spec archival-destruction-workflow

## Why

Two methods in `lib/Service/ArchivalService.php` (`setRetentionMetadata`,
`extendRetentionForObject`) implement object-level retention behaviour that is
genuinely owned by the `archival-destruction-workflow` capability but is not
covered by REQ-001..REQ-009. This retrofit captures the observed behaviour as
two new requirements (REQ-010, REQ-011) so the methods can be `@spec`-annotated
and stay traceable.

The other 8 methods in this cluster (DSL parser/comparator, hourly retention
sweep cron, schema-save annotation validator, generic duration utility) were
triaged out — they belong to the sibling `add-archival-annotation-support`
capability (schema-level `x-openregister-archival` rules) rather than the
per-object NEN 15489 destruction workflow tracked here. They are intentionally
NOT annotated against this capability.

## What Changes

- Append REQ-010 (`setRetentionMetadata` write-path validation) to
  `openspec/specs/archival-destruction-workflow/spec.md`.
- Append REQ-011 (`extendRetentionForObject` selectionlist-driven extension)
  to the same spec.
- Annotate `ArchivalService::setRetentionMetadata()` with
  `@spec ...#task-1`.
- Annotate `ArchivalService::extendRetentionForObject()` with
  `@spec ...#task-2`.

No code is modified; this is annotation-only.

## Impact

- Affected specs: `archival-destruction-workflow` (+2 REQs).
- Affected code: 2 docblocks in `lib/Service/ArchivalService.php` gain
  `@spec` tags.
- No runtime behaviour change.
