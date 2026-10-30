# Tasks: example-set-flow-placeholder

- [x] `ExampleSetValues` resolves `{{flow:key.uuid}}`, and `flowsOf()` returns the fragment's flows from the record, or stand-ins for the check
- [x] `ExampleSetEngineKinds::installFlows()` writes the flows on their own, and `install()` leaves them out
- [x] `ExampleSetInstaller` writes the flows before the changes and checks the changes against flow stand-ins
- [x] Tests: a change on a case type lists a flow of the fragment; a change quoting an unknown flow is refused
