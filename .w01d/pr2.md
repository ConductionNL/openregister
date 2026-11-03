REVIEW: visibility. Splits `QueryHandler::searchObjects` (75 LOC, McCabe 22, 7 params) into its steps. The public signature and the behaviour stay the same.

## What changed
- `searchObjects()` goes from 75/22/7 to 32/7/7 (LOC/McCabe/params).
- `activeOrganisation()` (6/3/1) returns the session organisation, or null without multitenancy.
- New `SearchResultShaper` turns the mapper result into the answer: `shape()` (18/5/2) for the `_count` answer and the render, `requestsRendering()`, `anySchemaHasPropertyAuthorization()` (17/6/1) and `schemaHasPropertyAuthorization()`. It lives in its own class because QueryHandler sits at phpmd's class-length and method-count limits.
- New value object `SearchRenderRequest` (query, rbac, multitenancy).
- The probe loop had a branch for a cached `true` schema that could never run: the loop already stops at the first `true`. The new loop has one path.

## Why REVIEW
This method decides whether results go through the render that applies property-level authorization, and which organisation the mapper search is scoped to. The golden pins:
- the mapper gets the session user's organisation (the organisation double answers only for the session user), and forged `_organisation`, `organisation`, `_activeOrgUuid`, `userId`, `@self.organisation` and `@self.owner` keys in the query do not change it; without multitenancy no organisation is looked up;
- a schema with property authorization anywhere in the result makes the whole result render, with the caller's `_rbac` and `_multitenancy`; each schema is looked up once and the probe stops at the first hit;
- a schema that cannot be loaded counts as having no property authorization (unchanged);
- `_count` answers before the probe, and only `true` and `'true'` count.

## Verification
- Golden test `QueryHandlerSearchObjectsGoldenTest`: 40 scenarios. Recorded on the old code and re-run in plain mode.
- Capped mutants: old code 30 killed plus 1 equivalent (the dead cached-`true` branch), new code 33 killed. A no-op control mutant survives on both (and in the shaper file).
- Targeted pcov over the 24 test files that build a real QueryHandler or ObjectService: 398 tests, 0 risky, exit 0.
- `coverage-guard --deletion-neutral` against development: 71.70% to 78.33%, OK.
- phpcs, phpmd, phpstan and psalm on the touched files exit 0 (phpcs: the inherited class-level @spec warning on QueryHandler).
- Gate 16 (diff): 0. Gate 119: 0 = development.
- The full suite is left to CI.

## SIG numbers (gate 122 reader, lib/Service/Object* + ObjectService.php, against development)
- size >15/30/60: 78.0/49.2/16.5 to 77.9/49.0/16.2
- McCabe >5/10/25: 54.9/22.3/4.9 to 54.8/22.0/4.9
- params >=3/5/7: 46.9/18.9/8.8 to 46.7/18.8/8.7

## Inherited, not changed here
- A mapper that returns an int (a count) for a query with `_extend`, `_fields`, `_filter` or `_unset` reaches `renderEntities()`, which throws a TypeError. The golden pins it.
- An object whose schema id is null makes the probe throw a TypeError from `SchemaMapper::find()`, because the catch only takes `\Exception`. The golden pins it.

This PR was prepared with AI assistance (Claude Code) and reviewed by the lane before opening.
