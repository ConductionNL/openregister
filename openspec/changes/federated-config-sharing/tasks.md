# Tasks: federated-config-sharing

## Phase 1 — design + trust (this change, then a design PR)
- [ ] `IShareableConfigType` contract (select / serialise / deserialise / exclude
      secrets / topic), storage-agnostic. Canonical repo layout. Trust model:
      org allowlist + version pinning + secret exclusion (signing deferred).

## Phase 2 — the engine
- [ ] `FederatedConfigService` in OpenRegister: extract OpenBuild's serialiser +
      broker-routed push + topic discovery; back it with ConfigurationService's
      bundle / version / preview-diff.
- [ ] `RegisterShareableConfigTypesEvent` + registry (mirror flow nodes).
- [ ] GitHub credentials via the credential broker's **Doriath** leaf per user/org
      (NC-vault fallback); retire the shared-token assumption for user publish.
- [ ] Per-org RBAC replacing the NC-admin gate; org source allowlist enforcement.

## Phase 3 — prove on two types (with Playwright e2e)
- [ ] OpenBuild apps onto the shared service (reference type).
- [ ] Flows as a shareable type — the reframed #2065 "integration network".
- [ ] e2e: publish → discover → install → run, on both.

## Phase 4 — fold in hermiq
- [ ] Migrate hermiq's duplicated GitHub sync (agent templates + skills) onto the
      shared service.

## Phase 5 — roll out declared types (parallel)
- [ ] nldesign: NL Design theme token sets as a type wrapping `ConfigBundleService`.
- [ ] procest case types / workflow templates (redirect OTAP export).
- [ ] shillinq payroll/tax packs (redirect OTAP export).
- [ ] opencatalogi publication types + DCAT/TOOI waardelijsten.
- [ ] docudesk templates; softwarecatalog GEMMA/AMEF; pipelinq; larpingapp.

## Phase 6 — governance + index
- [ ] Org source allowlist admin UI; optional curated discovery index over topics.
- [ ] "How to share a config" docs — publish without a PR to a Conduction repo.
- [ ] (Later) cryptographic signing / verification.
