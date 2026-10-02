# Tasks: archival-category-per-object

- [x] 1.1 `RetentionService::effectiveClassification()` and `classificationPropertyOf()`; `applyArchivalMetadata()` resolves the effective category.
- [x] 1.2 `ArchivalNominationService::derive()` resolves the effective category; `ArchivalDeclarationReader` carries `categoryProperty`.
- [x] 1.3 `RetentionService::guardClassificationOverride()` and the update re-derive, called from `SaveObject` on create and update.
- [x] 1.4 Tests, red on the archival-for-apps head first: `RetentionClassificationOverrideTest`, the nomination override test.
- [x] 1.5 Comment on openregister#4228 naming the property, so decidiq can set it.
