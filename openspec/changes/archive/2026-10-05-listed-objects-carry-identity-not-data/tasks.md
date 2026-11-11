# Tasks: listed-objects-carry-identity-not-data

- [x] 1.1 The components.objects loop creates a new object under `@self.uuid`, else the top-level `uuid`.
- [x] 1.2 It strips the top-level `uuid` and `slug` from the data unless declared (`withoutSeedMetadataKeys()`).
- [x] 2.1 `ImportHandlerComponentsObjectsIdentityTest`: top-level identity, declared slug kept, `@self.uuid` wins; red on development.
