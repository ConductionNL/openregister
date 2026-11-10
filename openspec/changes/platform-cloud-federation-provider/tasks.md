# Tasks: platform-cloud-federation-provider

- [ ] 1.1 Outbound send on the federation provider, beside the existing `shareReceived()` (D-4).
- [ ] 1.2 Accept, decline and revoke on both sides, with far-side changes reflected here (D-3).
- [ ] 1.3 A federated recipient evaluated by the permission layer as a principal (D-1).
- [ ] 1.4 A per-share declaration of what crosses, defaulting to the narrowest (D-2).
- [ ] 1.5 A schema declaration of whether its objects may be federated, defaulting to not.
- [ ] 2.1 `tests/e2e/ci/platform-federation.spec.ts`: share to a second instance, accept, open, revoke, fail to open.
- [ ] 2.2 Unit tests: the payload for each declaration, the undeclared schema refusal, an unchanged inbound path.
- [ ] 2.3 `openspec validate platform-cloud-federation-provider --strict`.
- [ ] 3.1 Hand over to the dossiq lane: declare the federatable schemas and the default payload, register no provider.
