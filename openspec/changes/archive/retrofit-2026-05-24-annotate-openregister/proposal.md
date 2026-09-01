# Retrofit — annotate openregister against existing specs

Retroactive annotation of 168 methods across 45 files against 32 REQs in 16 capabilities. No code logic changes. No spec deltas (all REQs already exist in openspec/specs/).

Source: openspec/coverage-report.md generated 2026-05-24 (Bucket 1 only, post-triage by 12 Opus agents that demoted 1417 false positives from the heuristic scanner).

Capabilities: actions, archival-annotation-vocabulary, archival-destruction-workflow, chat-ai, content-versioning, deprecate-published-metadata, extended-field-types, geo-metadata-kaart, mock-registers, nested-aggregations, oas-generation, oas-validation, object-lifecycle, seed-related-items, text-extraction-eml, tmlo-validation

See [retrofit playbook](../../../.github/docs/claude/retrofit.md).

## Why

This is the third retrofit-annotate pass on OpenRegister (after 2026-04-23 and 2026-04-30). The 2026-05-24 coverage-scan picked up 1585 raw Bucket 1 candidates from a deterministic Python signal-scorer; two rounds of triage (6 Opus agents × 2 bands) confirmed 168 real matches against 32 REQs. Those 168 methods are the ones tagged in this pass — any method already carrying an `@spec ... retrofit-*-annotate-openregister` tag from a prior run is skipped (per the opsx-annotate idempotent-rerun rule).

This pass exists primarily because the spec inventory grew significantly since the April runs: 75 new spec.md files landed in the consolidation PR #1744 (chat-ai, oas-generation, nested-aggregations, geo-metadata-kaart, archival-destruction-workflow, etc.) and those new REQs deserve back-pointers from the existing implementations.
