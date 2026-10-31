---
kind: code
---
# Proposal: underscore-properties-and-config-paths

## Summary

The Zuiddrecht rehearsal (R2, 15 October 2026) logged two OpenRegister faults that each cost real data, not only log lines.

1. **A property whose name starts with an underscore broke every read of its row.** shillinq declares `_meta` on `RetentionRule`, `BbvAccountMapping` and `InnovatieboxTariff`. Its magic-table column is `_meta`, and the readers took every `_` column for object metadata: `MagicStatisticsHandler` called `ObjectEntity::setMeta()`, which throws, and dropped the whole row (16,038 log errors in one install, those objects unreadable through that path); `MagicSearchHandler` and `MagicMapper::rowToObjectEntity()` silently dropped the property; the bulk writer never wrote it.
2. **A dot-notation `objectDescriptionField` refused the schema.** opencatalogi's `publiccode` declares `description.nl.longDescription | description.en.longDescription`. The reader walks such paths (`MetadataHydrationHandler::getValueFromPath()`, and `Schema` documents the fields as dot-notation paths), but the save-time check compared the whole path with the top-level keys, so the schema was rejected on every import (`PARTIAL IMPORT`).

## What changes

- `MagicMetadataColumn` decides whether a `_` column is metadata or a schema property: a property the schema declares is a property, unless ObjectEntity has an attribute of that name. Both row readers, `rowToObjectEntity()` and the bulk writer use it or the same rule.
- A metadata column ObjectEntity has no attribute for is skipped and logged at debug once per field, instead of failing the row. A snake_case metadata column (`_schema_version`) reaches its camelCase attribute.
- `SchemaMapper::validateConfigField()` accepts a dot-notation path whose first segment is a property, alone or in a fallback list.

## Out of scope

- Renaming shillinq's `_meta` properties.
- Validating the segments after the first against nested `properties`: the reader does not need them declared.
