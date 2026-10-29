# Proposal: store-publish-route

## Why

`GenericStoreService::publish()` and `StoreActionAuthorizer::canPublish()` exist, but nothing calls them over HTTP, so no store page can publish. Board PqStore draws a Publiceren button. An app's store also cannot declare which fields may travel and who may send them, because the manifest `store` block has no publish settings. And a store whose registry is this instance (`registry_url` `local`) cannot be published to at all, because the transport guard refuses `local` as a URL.

## What changes

- **Publish settings:** the manifest `store` block may declare `publish: {fields: [...], groups: [...]}`. StoreManifest parses it, and the generic descriptor carries it as `publishFields` and `publishGroups`.
- **Route:** a new canonical route `POST /api/store/publish` (`store#publish`) reaches `GenericStoreController::publish()`. The request body is `{item: {...}}`, an object of the store's schema that carries its `slug`.
  - It answers 401 to an anonymous caller, `not_configured` for an app without a store, and 403 unless `canPublish()` admits the user.
  - Otherwise it returns the outcome of `GenericStoreService::publish()`.
- **Local write path:** `publish()` to a `local` registry writes in-process through `LocalStoreRegistry::write()`. It updates the object that carries the same slug, or creates one. There is no HTTP request, and the body is the same filtered body a remote publish sends.
