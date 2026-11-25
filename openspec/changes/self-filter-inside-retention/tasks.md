# Tasks: self-filter-inside-retention

## 1. Filter inside the archival metadata

- [x] 1.1 `MagicSearchHandler::METADATA_PATH_ROOTS` opens `retention` and `tmlo` (and nothing else) to `NestedPathFilter::split()`, so the dotted key and the nested request spelling (`@self_retention_archiefactiedatum[lt]`) both read the path (REQ-SFR-001)
- [x] 1.2 `MagicSearchHandler::applyMetadataFilters()` and `buildMetadataFilterConditionsSql()` write its conditions through `NestedPathFilter`, on PostgreSQL (`#>>`) and MySQL/MariaDB (`JSON_EXTRACT`) (REQ-SFR-001)
- [x] 1.3 Unit tests: both platforms, both query paths, isnull, a segment that is not a plain name, a refused path into an access column (`MagicSearchHandlerMetadataJsonPathTest`)
- [ ] 1.4 Live check on a dev instance: a list with `@self[retention.archiefactiedatum][lt]=<today>` returns the dossiers past their date
