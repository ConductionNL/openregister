# Tasks: self-filter-inside-retention

## 1. Filter inside the archival metadata

- [x] 1.1 `MagicSearchHandler::METADATA_PATH_ROOTS` opens `retention` and `tmlo` (and nothing else) to `NestedPathFilter::split()`, so the dotted key and the nested request spelling (`@self_retention_archiefactiedatum[lt]`) both read the path (REQ-SFR-001)
- [x] 1.2 `MagicSearchHandler::applyMetadataFilters()` and `buildMetadataFilterConditionsSql()` write its conditions through `NestedPathFilter`, on PostgreSQL (`#>>`) and MySQL/MariaDB (`JSON_EXTRACT`) (REQ-SFR-001)
- [x] 1.3 Unit tests: both platforms, both query paths, isnull, a segment that is not a plain name, a refused path into an access column (`MagicSearchHandlerMetadataJsonPathTest`)
- [x] 1.4 Live check on a dev instance: a list with `@self[retention.archiefactiedatum][lt]=<today>` returns the dossiers past their date
  - 2026-11-25: on a sandbox Nextcloud 34 on MariaDB, three dossiers dated from their closing date (P1Y): `[lt]=<today>` returned the two past their date in both spellings and `[gte]` the third. Pinned in `tests/integration/openregister-self-filter-inside-retention.postman_collection.json` (13 requests, 16 assertions, 0 failed).
