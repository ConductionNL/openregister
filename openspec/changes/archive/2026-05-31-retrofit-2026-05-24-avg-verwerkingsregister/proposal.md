# Retrofit — extend avg-verwerkingsregister with observed behaviors

The 2026-05-24 coverage scan found 1 method in the avg-verwerkingsregister capability that doesn't match any existing requirement on the parent spec: `AvgComplianceService::findUnannotatedSchemasWithPii()`. This change adds 1 new REQ derived from the method's observed code behavior — a compliance scan surfacing schemas where PII has been detected but no `x-openregister-processing-activity` annotation exists on the schema or its enclosing register.

No code logic changes. The annotation on the method points at this ghost change's task entry.

## Affected code units

- `lib/Service/AvgComplianceService.php` — `findUnannotatedSchemasWithPii()` (public, surfaced via `runAllChecks()`)

## REQ map

| Requirement | Methods |
|-------------|---------|
| "Surface schemas with detected PII but no processing-activity annotation" | AvgComplianceService::findUnannotatedSchemasWithPii |

## Notes

- The triage record (openregister-app-manifest#MAN-005) is unrelated — MAN-005 is the Vue main.js loader and was a false-positive cross-reference; this method is purely an AVG/PII compliance scanner.
- The aggregator query in `aggregatePiiBySchema()` silently skips legacy `entity_relations` rows where either `register_id` or `schema_id` is empty (predating the disambiguation migration). This is observed behavior, not aspirational — the REQ documents it.

See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
