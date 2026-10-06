# AppHost discovery

## ADDED Requirements

### Requirement: Apps declare discovery in their manifest (REQ-DISC-001)

An AppHost app SHALL declare the interoperability standards it provides or consumes in a `discovery` block of `src/manifest.json`. The block MAY contain:
- `standards[]`, each with `id` (lower-case slug), `name`, `role` (provides | consumes), and optionally `access` (public | token | authenticated), `version`, `endpoint` and `specUrl`;
- `links{}` (slug → path);
- `ocmResourceTypes[]` (`name`, `shareTypes`, `protocols`);
- `public` (boolean, default true).

#### Scenario: An invalid entry is dropped alone

- **WHEN** one `standards[]` entry has an unknown `role`
- **THEN** that entry is dropped, its reason is recorded, and the other entries are kept.

#### Scenario: Endpoints are paths on this instance

- **WHEN** an `endpoint`, link or OCM protocol is an absolute URL, protocol-relative, or contains `..`
- **THEN** the entry is dropped.

### Requirement: Discovery is published to anonymous callers (REQ-DISC-002)

OpenRegister SHALL register a capability implementing `IPublicCapability` and `IInitialStateExcludedCapability`.
- It publishes `discovery.{contractVersion, provider, apps}`.
- For every enabled app whose block is public and non-empty, it publishes `<appId>.discovery`.

#### Scenario: Login-only endpoints stay private

- **WHEN** a standard has `access: authenticated`, or no `access`
- **THEN** it is published without its `endpoint`.

#### Scenario: The capabilities endpoint survives a broken manifest

- **WHEN** reading the discovery catalogue throws
- **THEN** the capability returns an empty array and logs the error.

### Requirement: Admins control publication (REQ-DISC-003)

The app config key `openregister/discovery_public = no` SHALL suppress the capability entirely. App ids listed in `openregister/discovery_hidden_apps` (comma separated) SHALL never be listed. Both take effect without clearing caches.

### Requirement: Discovery is cached (REQ-DISC-004)

The catalogue SHALL cache parsed manifests in the local memory cache.
- The cache key changes whenever the set of enabled apps or any of their versions changes.
- Within one request, manifests are read at most once.

### Requirement: Declared OCM resource types are announced (REQ-DISC-005)

On `ResourceTypeRegisterEvent`, OpenRegister SHALL register its built-in `openregister` type and every manifest-declared type that has a cloud federation provider.
- A declared type without a provider is skipped and logged.
- A type name already registered is never registered twice.
