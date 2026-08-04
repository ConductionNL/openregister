# Tasks

## 1. Annotate ArchivalService::setRetentionMetadata against REQ-010

- [x] task-1: `lib/Service/ArchivalService.php::setRetentionMetadata()` —
      validates archiefnominatie / archiefstatus enums, normalises
      archiefactiedatum to ISO 8601, defaults missing keys, merges into
      existing retention.

## 2. Annotate ArchivalService::extendRetentionForObject against REQ-011

- [x] task-2: `lib/Service/ArchivalService.php::extendRetentionForObject()` —
      recomputes archiefactiedatum from the object's classificatie via
      `SelectionListMapper::findByCategory()`, persists via direct UPDATE,
      swallows exceptions with a warning log.

## DROP (not annotated against this capability)

The remaining 8 methods in the input cluster were triaged out — they belong to
the sibling `add-archival-annotation-support` capability (schema-level
`x-openregister-archival` rules), not the per-object NEN 15489 destruction
workflow specified here:

- `lib/Service/Archival/RetentionConditionEvaluator.php::parseLiteral` — DSL
  literal parser (owned by `add-archival-annotation-support`).
- `lib/Service/Archival/RetentionConditionEvaluator.php::compare` — DSL
  operator comparator (owned by `add-archival-annotation-support`).
- `lib/Cron/ArchivalRetentionTask.php::run` — hourly retention sweep cron
  (owned by `add-archival-annotation-support`).
- `lib/Cron/ArchivalRetentionTask.php::sweepSchema` — per-schema sweep helper
  (owned by `add-archival-annotation-support`).
- `lib/Cron/ArchivalRetentionTask.php::extractArchivalAnnotation` — reads
  `x-openregister-archival` from schema config (owned by
  `add-archival-annotation-support`).
- `lib/Cron/ArchivalRetentionTask.php::stripMetadataColumns` — strips
  magic-table metadata columns from a row before evaluation (owned by
  `add-archival-annotation-support`).
- `lib/Service/Archival/ArchivalAnnotationValidator.php::validateRule` —
  schema-save validator for one rule entry (owned by
  `add-archival-annotation-support`).
- `lib/Service/Archival/RetentionEvaluator.php::addDuration` — generic
  `DateInterval` addition utility used by the schema-level evaluator (owned
  by `add-archival-annotation-support`).
