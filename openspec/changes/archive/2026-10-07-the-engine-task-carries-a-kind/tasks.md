# Tasks: the-engine-task-carries-a-kind

- [x] 1.1 Migration adding `kind` to `openregister_tasks`, indexed with
  `is_terminal`; `appinfo/info.xml` bumped so it runs.
- [x] 1.2 `Task` carries `kind`: property, `addType`, `@method` pair and
  `jsonSerialize`. `TaskBuilder::fromData()` reads it from the payload.
- [x] 1.3 `TaskInboxCriteria` gains `kind` (appended last, so no positional
  caller shifts), `TaskMapper::applyFilters()` filters on it, and
  `TaskController::index()` accepts `?kind=`.
- [x] 2.1 Unit tests: the kind survives the builder and the serialisation,
  and the filter reaches the query. `openspec validate
  the-engine-task-carries-a-kind --strict`.
