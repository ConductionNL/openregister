---
kind: code
---

## Why

Another Nextcloud instance, or a tool such as Nextcloud Peek, can only find out what an instance offers by reading its public endpoints: `status.php`, `/.well-known/ocm` and `/ocs/v2.php/cloud/capabilities`. An anonymous caller of the capabilities endpoint only receives capabilities implementing `IPublicCapability`.

None of the Conduction apps publish one today, so they are invisible to anyone who has not logged in:

- OpenRegister's `UrnCapability` and `IntegrationsCapability` are plain `ICapability` and only visible after login.
- OpenCatalogi federates through its own directory protocol, and a peer has to know the full app path before it can connect.
- decidiq serves a public ORI API that nothing advertises.
- dossiq serves ZGW APIs that nothing advertises.

For federation, and especially for OpenCatalogi, we need instances to discover each other's apps from a plain hostname. They also need to learn which standards those apps speak, so they can tell whether a connection is possible.

Writing an `IPublicCapability` class in each of twenty apps would drift in shape. It would also miss apps that do not call `Bootstrap::register()`. The AppHost already owns the app manifest (`ManifestLoader`) and is the natural single place for this.

## What Changes

**A `discovery` block in the app manifest (manifest v2).** Each app declares:
- `standards[]`: id, name, version, `role` (provides | consumes), `access` (public | token | authenticated), endpoint and specUrl;
- `links{}`: named public entry points;
- `ocmResourceTypes[]`: Open Cloud Mesh resource types it handles;
- `public`: opt-out flag.

The schema lives in `@conduction/nextcloud-vue` (`app-manifest-v2.schema.json`).

**`ManifestLoader::loadDiscovery()`** parses the block into a validated `DiscoveryManifest`.
- Invalid entries are dropped one by one, with a reason; the rest of the block stays.
- An entry without `access` counts as `authenticated`, whose endpoint is never published.

**`DiscoveryCatalog`** collects the blocks of all enabled apps.
- It caches the result in the local memory cache, keyed on the enabled app set and app versions.
- It applies the admin switches `discovery_public` and `discovery_hidden_apps` (app config of `openregister`).

**`DiscoveryCapability`** implements `IPublicCapability` and `IInitialStateExcludedCapability`.
- It publishes `discovery.{contractVersion, provider, apps}` plus `<appId>.discovery` for every listed app.
- It never publishes app versions or endpoints of login-only entries.

**`OcmResourceTypeListener`** registers the built-in `openregister` type plus every manifest-declared OCM resource type.
- A declared type is only registered when a cloud federation provider exists for it.
- The types appear in `/.well-known/ocm` and `/ocm-provider/`.

**OpenRegister declares its own standards:** OpenAPI, JSON Schema, JSON-LD, SKOS, GraphQL, URN, MCP and OCM.

**Developer docs:** `docs/Technical/discovery-and-federation.md`.

## Impact

- **Dependent apps:** opencatalogi, decidiq, dossiq and every other AppHost app add a `discovery` block and are then listed. Nothing changes for an app without the block.
- **Ordering:** the nextcloud-vue schema change must be released before apps add the block, because `check:manifest` validates against the installed `@conduction/nextcloud-vue`.
- **Privacy:** the capability is public by design. Admins can switch it off (`occ config:app:set openregister discovery_public --value=no`) or hide individual apps.
- **Performance:** cached, and excluded from page-load initial state.
