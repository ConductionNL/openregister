## 1. Lock current correct behavior (characterization tests)

- [x] 1.1 Add PHPUnit characterization tests for the existing UUID / prefixed-UUID / URL acceptance in `isReference()` (SaveObjects.php) and the schema-declared detection in `BulkRelationHandler::scanForRelations()`, so a regression in detection is caught before refactoring. Use the nil UUID `00000000-0000-0000-0000-000000000000` in fixtures.

## 2. Tighten detection

- [x] 2.1 In `lib/Service/Object/SaveObjects.php`, remove the loose "8+ chars with hyphen/underscore" catch-all branch from `isReference()` (and the now-dead hardcoded stop-list), keeping the UUID, prefixed-UUID, and URL branches verbatim.
- [x] 2.2 Ensure `scanForRelations`/`scanPropertyForRelation`/`scanStringForRelation` in SaveObjects.php still honor schema-declared reference properties (`type:object`, `format` uuid/uri/url, `$ref`/`inversedBy`) as authoritative.
- [x] 2.3 In `lib/Service/Object/SaveObject/RelationCascadeHandler.php`, align `isReference()` / `looksLikeObjectReference()` to the same rule (drop any length+separator heuristic), preserving the existing `$ref`-aware behavior.
- [x] 2.4 Confirm `lib/Service/Object/SaveObjects/BulkRelationHandler.php::scanForRelations()` already matches the rule; adjust only if it diverges.

## 3. Consolidate to prevent drift

- [x] 3.1 Extract one shared relation-detection helper (private method via trait, or a `RelationDetector` collaborator) implementing the single rule.
- [x] 3.2 Wire all three handlers (SaveObjects, BulkRelationHandler, RelationCascadeHandler) to the shared helper so the rule cannot diverge between paths. If full consolidation is disproportionate, document why and keep all three copies test-locked to the identical rule.

## 4. Tests proving the new contract

- [x] 4.1 Add tests proving canonical UUID, prefixed-UUID, URL, and schema-declared reference values ARE recorded in `@self.relations`.
- [x] 4.2 Add tests proving dates (`2026-05-20`), enum/code values (`bank_transfer`), and business identifiers (`DEMO-F-2026-04-02`, `demo-administration`) are NOT recorded when the property is not a declared reference.
- [x] 4.3 Add a cross-path test asserting the single-save, bulk, and cascade paths produce the same `@self.relations` set for the same payload.

## 5. Wrap-up

- [x] 5.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and fix any new or pre-existing issues touched by the change.
- [x] 5.2 Bump `info.xml` `<version>` so the bundle cache-busts on deploy.

## Acceptance Criteria

- A string is recorded in `@self.relations` only when it matches a UUID / prefixed-UUID / URL pattern, or its schema property declares it a reference (`type:object`, `format` uuid/uri/url, `$ref`/`inversedBy`).
- The loose "8+ chars with hyphen/underscore" heuristic no longer records relations.
- Real UUID-/URL-valued relations are detected exactly as before (backward compatible).
- The single-save, bulk, and cascade paths produce identical `@self.relations` for the same payload.
- No schema, lifecycle, aggregation, or notification declaration changes; `getUses()`/`used()` public response contract unchanged.

## Quality

- `composer check:strict` passes (no new PHPCS/PHPMD/Psalm/PHPStan findings; fix pre-existing ones in touched files).
- New PHPUnit tests cover both positive (recorded) and negative (rejected) cases across all three paths.
- Examples use the nil UUID `00000000-0000-0000-0000-000000000000`; no realistic UUIDs or secrets.

## Declarative-vs-imperative

N/A. This change only corrects the acceptance rule for a derived field (`@self.relations`) inside the
imperative save/import pipeline. It declares no schema, lifecycle, aggregation, computed-field, or
notification behavior, so there is no declarative-vs-imperative decision to make.

## Backfill note

Tightening detection affects only objects saved/imported after deploy; existing objects keep their
already-polluted `@self.relations` until re-saved. A repair step that re-scans all objects is
deferred (see design Open Questions) — DEFERRED decision for a human to confirm.

<!-- No Seed Data section: this change introduces no schema and no seed objects. -->
