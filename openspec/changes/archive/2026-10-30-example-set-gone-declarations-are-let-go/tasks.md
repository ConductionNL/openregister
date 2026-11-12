# Tasks: example-set-gone-declarations-are-let-go

- [x] `ExampleSetRecord::letGoUndeclared()` drops the held entries of a part that the fragment no longer declares and names them under `left`
- [x] `ExampleSetInstaller::installChanges()` and `ExampleSetEngineRows::installFragment()` call it after their own entries
- [x] `ExampleSetChanges::writtenAgain()` counts a field that reads back as null as not set
- [x] Tests (failing first): a change and a read state the set no longer declares are let go; an app outside the plan keeps its entries; a field that reads back null is written again
