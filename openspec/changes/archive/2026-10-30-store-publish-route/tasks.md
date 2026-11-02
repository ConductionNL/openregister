# Tasks: store-publish-route

- [x] StoreManifest: parse `publish.fields` / `publish.groups`; the manifest schema documents them
- [x] GenericStoreController: descriptor carries publishFields/publishGroups; `publish()` with the 401 / not_configured / 403 / outcome contract
- [x] Routes: canonical `store#publish` POST /api/store/publish
- [x] GenericStoreService::publish(): `local` registry writes through LocalStoreRegistry::write()
- [x] LocalStoreRegistry::write(): update the object with the same slug, else create
- [x] Tests red first: StoreManifest, GenericStoreController, GenericStoreService, LocalStoreRegistry
