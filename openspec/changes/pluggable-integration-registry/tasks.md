# Tasks: Pluggable Integration Registry

## Phase 1 — Backend contract

- [ ] Define `lib/Service/Integration/IntegrationProvider.php` interface
      with the methods listed in `proposal.md` (`getId`, `getLabel`,
      `getIcon`, `isEnabled`, `getStorageStrategy`, `authRequirements`,
      `linkedColumnName`, `query`, `mutate`).
- [ ] Define `lib/Service/Integration/IntegrationRegistry.php` —
      registry service with `register/listAll/listEnabled/listIds/
      getById/requireById`.
- [ ] Wire DI tag `IntegrationProvider` in `lib/AppInfo/Application.php`
      so any service implementing the interface auto-registers.
- [ ] Add `lib/Service/Integration/Exception/IntegrationNotFoundException.php`
      and `IntegrationDisabledException.php` with `getId()` accessor.
- [ ] Add `lib/Service/Integration/ExternalIntegrationRouter.php` —
      dispatches to OpenConnector for `getStorageStrategy() === 'external'`
      providers. OR does not own credentials; the router surfaces auth
      status only.

## Phase 2 — Migrate built-ins

For each of files / notes / tasks / calendar / mail / contacts / deck /
talk:

- [ ] Add `lib/Service/Integration/Builtin/{Type}Provider.php`
      implementing `IntegrationProvider`. Wraps the existing
      handler logic for that type.
- [ ] Confirm the provider's `getId()` matches the existing
      `LinkedEntityService::TYPE_COLUMN_MAP` key for backwards
      compatibility on existing `linkedTypes` configurations.

After all 8 are migrated:

- [ ] Mark `LinkedEntityService::TYPE_COLUMN_MAP` `@deprecated`; switch
      every internal caller to `IntegrationRegistry::listIds()`.
- [ ] Delete `TYPE_COLUMN_MAP` in a follow-up cleanup change once 1
      release-cycle has passed.

## Phase 3 — Schema validator + capability advertisement

- [ ] Update `lib/Db/Schema.php::validateLinkedTypesValue()` to call
      `IntegrationRegistry::listIds()` instead of the deprecated
      constant.
- [ ] On read: warn (non-throwing) for unknown ids — schemas with
      stale references still load.
- [ ] On write: reject unknown ids with a `400 Bad Request` carrying
      a structured error pointing at the unknown id.
- [ ] Add `lib/Capabilities/IntegrationsCapability.php` implementing
      `OCP\Capabilities\ICapability` so registered integrations appear
      in `GET /ocs/v2.php/cloud/capabilities` under
      `openregister.integrations`.

## Phase 4 — Tooling + CI gate

- [ ] Add `php occ openregister:integrations:list` (prints registry
      contents incl. enabled status, storage strategy, auth
      requirements).
- [ ] Add `scripts/scaffold-integration.sh <id>` generating: provider
      PHP file + spec delta + unit-test stub + FE registration stub
      (in nextcloud-vue/src/integrations/<id>/).
- [ ] Add `scripts/check-integration-parity.sh` — exits non-zero if
      any backend provider lacks a frontend registration entry.
- [ ] Wire the parity script into `hydra/scripts/run-hydra-gates.sh`
      as a new gate; document in `hydra/openspec/architecture/adr-019`
      under "implementation reference".

## Phase 5 — Spec + tests

- [ ] Write `specs/integration-registry/spec.md` with one Requirement
      per public surface (interface contract, registry behaviour,
      schema validator, OCS capability, parity gate).
- [ ] Add `tests/Unit/Service/Integration/IntegrationRegistryTest.php`
      covering: register/list/getById/requireById, enabled-vs-all
      filtering, exception paths.
- [ ] Add `tests/Unit/Service/Integration/Builtin/<Type>ProviderTest.php`
      for each migrated builtin (8 files).
- [ ] Add `tests/Unit/Db/SchemaValidateLinkedTypesTest.php` — covers
      registry-driven validation paths (warn-on-read, reject-on-write).
- [ ] Add Newman collection
      `tests/integration/openregister-integration-registry.postman_collection.json`
      hitting OCS capabilities + a couple of provider-backed endpoints
      end-to-end.
- [ ] Wire the new collection into `tests/newman/run-all.sh::DOMAIN_ORDER`
      after `relations`.

## Phase 6 — Documentation

- [ ] `docs/integrations/README.md` — developer guide: writing a new
      provider, registering FE side, parity expectations.
- [ ] Update `lib/AppInfo/Application.php` PHPDoc with the new public
      services.
- [ ] Cross-reference this change from
      `hydra/openspec/architecture/adr-019-integration-registry.md`'s
      "Implementation reference" once shipped.

## Phase 7 — Companion FE coordination

- [ ] Open `nextcloud-vue/openspec/changes/integration-registry-frontend/`
      with the matching FE-side proposal (registry, four widget
      surfaces, `CnObjectSidebar` refactor, snapshot tests for the 5
      existing tabs).
- [ ] Cross-link both changes in their `proposal.md` "See also"
      sections; the parity gate enforces they stay in lockstep.
