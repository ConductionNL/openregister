# Design: AppHost discovery manifest

## Public payload

```json
{
  "discovery": {"contractVersion": 1, "provider": "openregister", "apps": ["decidiq", "opencatalogi"]},
  "decidiq": {"discovery": {
    "contractVersion": 1,
    "standards": [
      {"id": "ori", "name": "Open Raadsinformatie", "version": "1", "role": "provides",
       "access": "public", "endpoint": "/apps/decidiq/api/ori/v1", "specUrl": "https://..."}
    ]
  }},
  "opencatalogi": {"discovery": {
    "contractVersion": 1,
    "standards": [ ... ],
    "links": {"directory": "/apps/opencatalogi/api/directory"}
  }}
}
```

**One top-level key per app.** Capability readers present the top-level key as "the app"; Nextcloud Peek draws one card per key. Keys merge recursively with an app's own capabilities, so `thematiq.*` and `thematiq.discovery` sit side by side.

**Paths, never URLs.** An app must not be able to make this instance advertise somebody else's host, and the caller already knows ours.
- Paths are relative to the instance root, in the same style as OCM protocols (`/apps/openregister/api/federation`).
- A caller on an instance without pretty URLs prefixes `/index.php`. The core capability `core.mod-rewrite-working` says which applies.

**`access` decides what is published:**
- `public`: no credential needed. The endpoint is published.
- `token`: no Nextcloud login, but the caller needs a credential it already holds (a ZGW JWT, a share token, an LTI launch). The endpoint is published.
- `authenticated`: Nextcloud login needed. The entry is published *without* its endpoint. A peer still learns the app speaks the standard, without getting a map of login-only routes. This is the default when `access` is missing.

**Not published:**
- **App versions.** `status.php` already reveals the server version; per-app versions would make the capability a vulnerability index.
- **Register and schema names, and counts.**

## Why forgiving parsing

`StoreManifest` disables its whole block on any malformed field, because a half-working store is dangerous. A discovery list missing one entry is merely shorter, and the remaining entries are still true. So `DiscoveryManifest` drops entries one at a time and records each reason in `getProblems()`. The catalogue logs these reasons as warnings.

## Caching

The capabilities endpoint is hit by every client on every sync, and Nextcloud logs capabilities slower than 0.1 s.
- **What is cached:** the parsed manifests of all enabled apps, in the local memory cache.
- **Cache key:** the contract version plus a hash of `appId@version` for every enabled app. Enabling, disabling or upgrading an app invalidates it with no explicit purge.
- **Admin switches** are applied after the cache, so changing one takes effect at once.
- **Initial state:** `IInitialStateExcludedCapability` keeps the payload out of every page load.

## OCM

`ResourceTypeRegisterEvent` is deprecated in Nextcloud 33 in favour of `LocalOCMDiscoveryEvent`. Nextcloud 33 and 34 dispatch both events on the same provider object, so listening to both would register each type twice. While the minimum supported version is 32, the listener stays on the old event.

A declared type is registered only if `ICloudFederationProviderManager::getCloudFederationProvider($name)` resolves. Announcing a type nobody handles would invite shares that are then refused.

The admin publication switches do not gate OCM types. Nextcloud's own federation settings already do.

## Alternatives considered

- **An `IPublicCapability` class per app:** shapes drift, and it is missed for apps that skip `Bootstrap::register()`.
- **A custom `/.well-known/conduction` handler:** non-standard. Peek and other tools already read capabilities.
- **A separate `appinfo/discovery.json`:** a second file next to the manifest that AppHost already reads. It would also be invisible to the manifest gate.
