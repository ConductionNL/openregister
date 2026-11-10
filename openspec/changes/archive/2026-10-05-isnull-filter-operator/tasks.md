# Tasks: isnull-filter-operator

- [x] 1.1 Add `isnull` to `MagicSearchHandler::COMPARISON_OPERATORS`.
- [x] 1.2 Add a shared `isNullOperatorAsksForNull()` helper using
  `filter_var(..., FILTER_VALIDATE_BOOLEAN)`.
- [x] 1.3 Handle `isnull` in all four condition builders:
  `buildObjectFilterConditionsSql`, `buildMetadataOperatorConditionsSql`,
  `applyMetadataOperators` and `applyObjectFilters`.
- [x] 1.4 Delete the dead `SearchQueryHandler::cleanQuery()` and its tests.
- [x] 1.5 Add `MagicSearchHandlerIsNullOperatorTest` covering both paths, both halves of
  the operator and every query-string spelling. Verified red without the fix: 13 of 13.
- [x] 1.6 Correct `openspec/specs/zoeken-filteren`: the real normalisation mechanism, the
  exhaustive operator list, the sentinel, and `?ordering=` being unsupported.
- [x] 1.7 Verify over HTTP on a throwaway instance, both spellings and their complement.
