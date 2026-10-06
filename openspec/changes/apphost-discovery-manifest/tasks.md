# Tasks

## 1. Engine

- [x] 1.1 `DiscoveryManifest` parses and validates the block; drops invalid entries one at a time with a reason; `toPublicPayload()` hides endpoints of authenticated entries.
- [x] 1.2 `ManifestLoader::loadDiscovery()` reuses the bundled-manifest read.
- [x] 1.3 `DiscoveryCatalog` collects enabled apps, applies `discovery_public` / `discovery_hidden_apps`, and caches per enabled-app signature.
- [x] 1.4 `DiscoveryCapability` (public, excluded from initial state) is registered in `Application::register()`.
- [x] 1.5 `OcmResourceTypeListener` also registers manifest-declared OCM types that have a federation provider.
- [x] 1.6 OpenRegister's own `discovery` block.

## 2. Verification

- [x] 2.1 Unit tests: manifest parsing (valid, invalid per field, path safety, OCM types), catalogue (switches, cache key, per-request memo, duplicate OCM types), capability shape and failure isolation, listener (built-in type, provider check, broken catalogue).
- [ ] 2.2 Live: anonymous `curl -H 'OCS-APIRequest: true' /ocs/v2.php/cloud/capabilities?format=json` lists `discovery.apps` and `openregister.discovery`; `/.well-known/ocm` lists the declared types.
- [ ] 2.3 Regression: opencatalogi and softwarecatalog still load and their manifests validate.

## 3. Elsewhere

- [ ] 3.1 `@conduction/nextcloud-vue`: add `discovery` to `app-manifest-v2.schema.json` and `src/types/manifest.d.ts` (must be released before apps add the block).
- [ ] 3.2 nextcloud-app-template: docs page + README section generated from the block.
- [ ] 3.3 Each app declares its block.
- [ ] 3.4 Move to `LocalOCMDiscoveryEvent` when the minimum Nextcloud version reaches 33.
