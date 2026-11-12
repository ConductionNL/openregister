# Tasks: example-set-organisation-gains-its-quota

- [x] `ExampleSetInstance::ensure()` takes the uuids the set made and fills the declared quota a made organisation lacks (`fillQuota()`)
- [x] `ExampleSetPrerequisites::ensureOrganisations()` passes the record's organisations
- [x] Tests (failing first): a made organisation gains the quota it lacks and keeps the one it has; one the set did not make is not written; a failed write is an error
