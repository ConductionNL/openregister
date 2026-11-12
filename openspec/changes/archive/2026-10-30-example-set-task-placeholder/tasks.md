# Tasks: example-set-task-placeholder

- [x] `ExampleSetValues` resolves `{{task:key.uuid}}` from the context's `tasks`, and `tasksOf()` returns the fragment's tasks from the record, or stand-ins for the check
- [x] `ExampleSetEngineKinds` adds the tasks to the context once the tasks part is written, and stand-ins for the check
- [x] Tests: a note on a task of the fragment; a note on an unknown task is refused
