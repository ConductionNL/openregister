---
kind: code
depends_on: [archival-for-apps]
---

# Proposal: archival-category-per-object

## Why

Ruben's decision (2 Oct, build-all DECISIONS row 48): "record management and selectielijst should be on schema type, and possible to overwrite per object." Today the selectielijst category is read from the schema alone (`archive.classification`, or `category` in an `x-openregister-archival` block), at two places: `RetentionService::applyArchivalMetadata()` when a record is created and `ArchivalNominationService::derive()` when it reaches a terminal state. A decidiq dossier whose category differs from its type's (openregister#4228) has no way to say so.

## What changes

- A schema names the object property that may carry a per-object category: `archive.classificationProperty` (or `categoryProperty` in an `x-openregister-archival` block). The schema's `classification` stays the default.
- The effective category of a record is the value of that property when it is a non-empty string, and otherwise the schema's category. `RetentionService::effectiveClassification()` is the one place that decides it.
- Creation and nomination resolve the selectielijst row of the effective category, and store it in `retention.classification`, which is what the destruction sweep, destruction lists and the certificate already read. So routing follows the override with no change of its own.
- A save whose override names a category no selectielijst row has is refused (400) with the category in the message. An empty value is no override.
- A changed override on an update re-derives the retention of a record that is still `active`. A record already past that (nominated at its terminal state, semi-static, frozen) keeps its derivation, and changing its category is refused (409): re-nominating it is the explicit recompute's job, which records who asked and why.

## Rows

No openregister matrix row. decidiq's records-management rows (pub-11, pub-18) set the property on a dossier whose category differs; told on openregister#4228.
