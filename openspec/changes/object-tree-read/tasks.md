# Tasks: object-tree-read

## 1. Backend

- [x] 1.1 `ObjectTreeService::tree()` and `declarationFor()`. Verify: `tests/Unit/Service/Relation/ObjectTreeServiceTest.php` (real organisation fragment, cycle, depth bound, node cap, RBAC pruning down and up, hierarchy declaration).
- [x] 1.2 `GET /api/objects/{register}/{schema}/{id}/tree` on `ObjectRelationsController`. Verify: `tests/Unit/Controller/ObjectRelationsControllerTreeTest.php` (404 before any walk, 400 on an unknown direction, query reaches the walk).

## 2. Consumers (other repositories)

- [ ] 2.1 dossiq: `kvkCompany` gains `parentCompany` (uuid of a `kvkCompany`) and `x-openregister-party` `{kind: organisation, nameProperty: tradeName, parentProperty: parentCompany}`.
- [ ] 2.2 portaliq: `PortalPartyTreeResolver` reads this tree from the configured party schema; "Reikt tot" lists it.

## 3. Live

- [x] 3.1 On a live instance: a holding with two subsidiaries and one below them; the tree route returns four nodes with levels 0, 1, 1, 2; a user without read on one subsidiary gets three.
  - Run 2026-11-25 on main against a sandbox Nextcloud 34.0.6 on MariaDB: a company schema with `x-openregister-party` `{kind: organisation, nameProperty: tradeName, parentProperty: parentCompany}` and a read rule `["admin", {group: tree-partial, match: {region: north}}]`; a holding, two subsidiaries (one in region south) and one below the first. `GET .../tree` as the administrator: four nodes, levels 0, 1, 1, 2, each with its parent; as a member of `tree-partial`, who gets 404 on the southern subsidiary: three nodes, that subsidiary left out. `?direction=ancestors` from the lowest: levels -2, -1, 0. Every node was named by its uuid, which is 3.2
- [x] 3.2 A node whose object has no name of its own (the schema names no name field, so its name reads as its uuid) is called by the party declaration's `nameProperty`; `PartyParentInterface::parentOf()` answers it beside the parent property (found live in 3.1: the spec's "each with its name" came back as a column of uuids)
  - Test: ObjectTreeServiceTest::testANodeWithoutANameIsCalledByTheDeclaredPartyName (red without the fix), PartyParentTest; live: Holding, Sub one, Sub two, Sub three
