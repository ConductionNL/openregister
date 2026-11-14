# Tasks: platform-cloud-federation-provider

- [ ] 1.1 Outbound send on the federation provider, beside the existing `shareReceived()` (D-4).
- [ ] 1.2 Accept, decline and revoke on both sides, with far-side changes reflected here (D-3). — partial: a far-side accept or decline of a share sent from here, and a withdrawal of a share received here, are now reflected (`RemoteShareNotification`, called from `notificationReceived()`, authenticated by the share secret and the share id). Sending these notifications from this side waits on 1.1.
- [ ] 1.3 A federated recipient evaluated by the permission layer as a principal (D-1).
- [ ] 1.4 A per-share declaration of what crosses, defaulting to the narrowest (D-2).
- [ ] 1.5 A schema declaration of whether its objects may be federated, defaulting to not. — partial:
      `configuration.federatable: true` (`Schema::isFederatable()`, kept by `ConfigurationEntryRules`)
      is the declaration; `ShareGrantor::federationRefusal()` refuses creating a schema, object or
      query share of a schema that does not declare it. Existing shares keep serving. A whole-register
      share is not judged per schema yet.
- [ ] 2.1 `tests/e2e/ci/platform-federation.spec.ts`: share to a second instance, accept, open, revoke, fail to open.
- [ ] 2.2 Unit tests: the payload for each declaration, the undeclared schema refusal, an unchanged inbound path. — partial:
      the undeclared schema refusal (`ShareGrantorTest`, `ShareCreationRightsChainTest`,
      `FederationShareServiceTest`); the payload tests wait on 1.4.
- [ ] 2.3 `openspec validate platform-cloud-federation-provider --strict`.
- [ ] 3.1 Hand over to the dossiq lane: declare the federatable schemas and the default payload, register no provider.
