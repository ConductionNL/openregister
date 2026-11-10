---
kind: code
depends_on: []
---

# Proposal: modelling-schema-draft

## Summary

An administrator edits a record type as a draft. Live records keep validating against the published version until the administrator publishes the draft, which then goes through the version bump and changelog every schema edit already gets.

## The rows this closes

Source matrix: openregister `openspec/parity/capabilities.json` (comparedOn 2026-09-25). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### mod-type-versions, keep versions of a record type, with a draft that does not affect live records until it is published

Own rating `partial`, built.state `building`, owner `ConductionNL/openregister`, area `modelling`, source `competitor-derived`.

Matrix evidence, verbatim:

> lib/Service/Schema/SchemaVersioningService.php diffs, semver-bumps and records a changelog on every schema update (SchemasController.php:184); changelog API routes.php:1634. No draft state: grep 'draft' in lib/Db/Schema.php and SchemasController.php finds only JSON Schema draft-2020-12 refs; Corrections round 8 (2026-09-28), openregister#4102: the version bump and changelog run only on PUT /api/schemas/{id}, lib/Controller/SchemasController.php:1140-1182 is the only caller of SchemaVersioningService, while a configuration or app import saves the schema through schemaMapper->update() with no classification, version bump or changelog entry, lib/Service/Configuration/ImportHandler.php:2210 and :2224 at 555af72.

Matrix note, verbatim:

> Edits go live immediately; there is no unpublished draft of a schema. Corrections round 8 (2026-09-28): schema changes that arrive through a configuration or app import get no version bump and no changelog entry, openregister#4102; rating kept because partial already reflects the missing draft state, and edits through the schema API are still versioned.

Competitor cells rated `yes`, verbatim:

- objects-api: driven at 4.2.1 on 2026-09-26 (smoke.sh step 4): a version is created as draft and published with PATCH {status: published}; objects were accepted against version 1 only after publishing. source read at 4.2.1: objects-api:src/objects/core/models.py:204 version status draft/published/deprecated (objects-api:src/objects/core/constants.py:5); objects-api:src/objects/api/validators.py:39 VersionUpdateValidator 'Only draft versions can be changed'; objects-api:src/objects/api/v2/views.py:232 only drafts can be deleted; staff screen publish and new version buttons objects-api:src/objects/core/admin.py:186 and :199. Records pin the version they were written against (core/models.py:301)

## Why

Every schema edit goes live the moment it is saved. On a register with live intake, a half-finished edit refuses records in the meantime. Versioning and the changelog exist; the draft that keeps an edit away from live records does not.

## What is built today

- `lib/Service/Schema/SchemaVersioningService.php` diffs, bumps the semantic version and records a changelog entry on every schema update through `SchemasController`.
- Changelog API `GET /api/schemas/{id}/changelog`.
- No draft state on `lib/Db/Schema.php`.

## What changes

1. A schema can hold one pending draft of its definition beside the published one; saving with `?draft=true` writes the draft only.
2. Validation of records keeps using the published definition while a draft exists.
3. Publishing the draft replaces the published definition through the existing update path, so the version bump and the changelog run once.
4. Discarding the draft removes it.

## Out of scope

- More than one draft per schema.
- Drafts arriving through a configuration import (an import publishes, as today).
