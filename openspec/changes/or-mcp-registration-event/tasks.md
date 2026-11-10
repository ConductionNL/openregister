# Tasks: or-mcp-registration-event

- [x] `RegisterMcpToolProvidersEvent`.
- [x] `collectAnnouncedMcpProviders()`, collected before the alias scan, with
      appId de-duplication and non-fatal failure.
- [ ] Migrate `nextcloud-app-template` (first — new apps copy it).
- [ ] Migrate hermiq, openbuild, opencatalogi, decidesk.
- [ ] Remove `collectPerAppMcpProviders()`, `buildMcpProviderCandidates()`,
      `tryResolveMcpProviderCandidate()` and the discovery cache — follow-up,
      once the fleet has migrated.
