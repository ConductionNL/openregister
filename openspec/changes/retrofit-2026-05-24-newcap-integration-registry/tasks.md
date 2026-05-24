# Tasks: retrofit-2026-05-24-newcap-integration-registry

> Annotation-only retrofit. No spec delta — see proposal.md for the
> extend-vs-mint decision. The 9 files below each gain a single
> `@spec` PHPDoc tag pointing at their matching leaf change.

## Annotation tasks

- [ ] `lib/Service/Integration/Providers/ActivityProvider.php` → `@spec openspec/changes/integration-activity/tasks.md`
- [ ] `lib/Service/Integration/Providers/AnalyticsProvider.php` → `@spec openspec/changes/integration-analytics/tasks.md`
- [ ] `lib/Service/Integration/Providers/CollectivesProvider.php` → `@spec openspec/changes/integration-collectives/tasks.md`
- [ ] `lib/Service/Integration/Providers/CospendProvider.php` → `@spec openspec/changes/integration-cospend/tasks.md`
- [ ] `lib/Service/Integration/Providers/FormsProvider.php` → `@spec openspec/changes/integration-forms/tasks.md`
- [ ] `lib/Service/Integration/Providers/MapsProvider.php` → `@spec openspec/changes/integration-maps/tasks.md`
- [ ] `lib/Service/Integration/Providers/PhotosProvider.php` → `@spec openspec/changes/integration-photos/tasks.md`
- [ ] `lib/Service/Integration/Providers/TimeProvider.php` → `@spec openspec/changes/integration-time-tracker/tasks.md`
- [ ] `lib/Service/Integration/Providers/MarkerLookupTrait.php` → `@spec openspec/changes/pluggable-integration-registry/tasks.md#task-1`

## Process

For each file:
1. Locate the main file docblock (the `<?php` followed by `/** ... */` block at top).
2. Insert the `@spec` tag immediately above the closing `*/` of that docblock, preserving the PHPCS blank-line-before-tag rule already used in the sibling files.
3. Do NOT edit any code outside the docblock.
