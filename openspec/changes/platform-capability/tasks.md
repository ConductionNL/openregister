# Tasks: platform-capability

- [ ] 1.1 An `ICapability` publishing app version, API versions and status, deep link patterns, limits and enabled integrations (D-1).
- [ ] 1.2 A nested block per claiming app with id, display name and enabled integrations (D-2).
- [ ] 1.3 No register name, no schema name and no secret in the block (D-3).
- [ ] 1.4 Versions and limits read from the same source as the API capabilities endpoint.
- [ ] 2.1 `tests/e2e/ci/platform-capability.spec.ts`: read the capabilities and find the app block and the limits.
- [ ] 2.2 Unit tests: the absence of register names, the agreement with the API endpoint.
- [ ] 2.3 `openspec validate platform-capability --strict`.
- [ ] 3.1 Close `deep-link-registry`'s open note that ICapability exposure is not implemented.
