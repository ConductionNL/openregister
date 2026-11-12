# Tasks: query-referenced-schema-rows

- [x] Direction on `RelatedRowFilter`; parser reads `_referenced`
- [x] Clause joins forward; applier and search handler apply both keys
- [x] Camel-case property to snake-case column in the clause
- [x] Unit tests: parse, forward join (columns and JSON), camel case, applier with the referenced schema's RBAC
- [ ] nextcloud-vue: no change needed; a filter key `_referenced[...]` passes through as written (verify in the consuming app)
