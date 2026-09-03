# Tasks

## 1. Engine

- [ ] 1.1 `StoreManifest` parses `types` and exposes `declaredTypes()`
- [ ] 1.2 `StoreDescriptor` carries the declared type ids
- [ ] 1.3 `GenericStoreService::searchFederated()` calls
      `FederatedConfigService::discover()` per declared topic and normalises the
      results to the same card shape the objects path returns
- [ ] 1.4 A card carries `type`, `publisher` and `source` alongside the existing
      fields, so the surface can show where a set came from
- [ ] 1.5 `GenericStoreService::resolveFederated()` maps a card slug back to its
      repo, path and type id

## 2. Install

- [ ] 2.1 `GenericStoreController::install()` routes a card with a type id
      through `FederatedConfigService::install()`
- [ ] 2.2 A source outside the org allowlist is refused before any fetch, and
      the refusal names the source
- [ ] 2.3 The object install path is unchanged for a card with no type id

## 3. Selection

- [ ] 3.1 A descriptor with no declared types keeps the objects API, verified by
      a test that asserts no discovery call is made

## 4. Surface

- [ ] 4.1 `CnStorePage` renders the card's type and publisher
- [ ] 4.2 Kind filters fall back to type display names when an app declares no
      kinds

## 5. First consumer

- [ ] 5.1 Decidiq declares `store.types` and a Store menu entry at footer 92
- [ ] 5.2 Decidiq marks the schemas that may travel with
      `x-openregister-shareable`
- [ ] 5.3 A default gemeente configuration set is published and installs onto a
      clean instance
- [ ] 5.4 The four example sets in `lib/Settings/profiles/` are offered as
      built-in store items, so the store names what decidiq already ships
      rather than rendering blank without a registry
- [ ] 5.5 An example set serialises to a configuration set, so the seed data an
      app bundles and the configuration it publishes are one artefact rather
      than two formats holding the same thing

## 6. Verification

- [ ] 6.1 Unit tests for descriptor selection, discovery normalisation and
      install routing
- [ ] 6.2 An e2e test opens a store declaring types and asserts a config-set
      card renders
- [ ] 6.3 Live check on a throwaway instance: publish a set from one instance,
      install it on another
