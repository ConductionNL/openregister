# Tasks: example-set-change-keeps-text

- [x] `ExampleSetStore::textProperties()`: the schema's text-only top-level properties
- [x] `ExampleSetChanges` adds those the row holds as a structure to its JSON-text fields in resolve, refresh and revert
- [x] Test (failing first): a change on a case whose JSON-text geometry reads back as a structure passes the check, installs, checks again on the kept object, and removes
