# Tasks: platform-capability

- [x] 1.1 An `ICapability` publishing app version, API versions and status, deep link patterns, limits and enabled integrations (D-1).
- [x] 1.2 A nested block per claiming app with id, display name and enabled integrations (D-2).
- [x] 1.3 No register name, no schema name and no secret in the block (D-3).
- [x] 1.4 Versions and limits read from the same source as the API capabilities endpoint.
- [ ] 2.1 `tests/e2e/ci/platform-capability.spec.ts`: read the capabilities and find the app block and the limits.
- [x] 2.2 Unit tests: the absence of register names, the agreement with the API endpoint.
- [ ] 2.3 `openspec validate platform-capability --strict`.
- [x] 3.1 Close `deep-link-registry`'s open note that ICapability exposure is not implemented.

Build notes: `lib/Capabilities/PlatformCapability.php` (an `IPublicCapability`, block `openregister.platform`) reads versions and limits from `ApiCapabilitiesServiceInterface::publicCapabilities()`, the source of `GET /api/capabilities`. The claiming apps are the apps with deep link registrations (`DeepLinkRegistryServiceInterface::registrations()`, new). `lib/Capabilities/PlatformIntegrations.php` maps each of the ten platform integrations to its implementing class; one without a class is reported off. 2.1 (e2e) and 2.3 (`openspec validate`) were not run here: no running instance and no openspec CLI in this environment.
