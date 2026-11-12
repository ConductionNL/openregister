# Tasks: store-local-registry

- [x] LocalStoreRegistry: read one schema of one register as arrays, slug matched, limit applied
- [x] GenericStoreService: `registry_url` `local` reads through LocalStoreRegistry; buildUrl reuses registerSlug
- [x] Tests in GenericStoreServiceTest (red first): no network call, resolve by slug, missing schema is unreachable
