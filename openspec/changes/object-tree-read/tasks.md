# Tasks: object-tree-read

## 1. Backend

- [x] 1.1 `ObjectTreeService::tree()` and `declarationFor()`. Verify: `tests/Unit/Service/Relation/ObjectTreeServiceTest.php` (real organisation fragment, cycle, depth bound, node cap, RBAC pruning down and up, hierarchy declaration).
- [x] 1.2 `GET /api/objects/{register}/{schema}/{id}/tree` on `ObjectRelationsController`. Verify: `tests/Unit/Controller/ObjectRelationsControllerTreeTest.php` (404 before any walk, 400 on an unknown direction, query reaches the walk).

## 2. Consumers (other repositories)

- [ ] 2.1 dossiq: `kvkCompany` gains `parentCompany` (uuid of a `kvkCompany`) and `x-openregister-party` `{kind: organisation, nameProperty: tradeName, parentProperty: parentCompany}`.
- [ ] 2.2 portaliq: `PortalPartyTreeResolver` reads this tree from the configured party schema; "Reikt tot" lists it.

## 3. Live

- [ ] 3.1 On a live instance: a holding with two subsidiaries and one below them; the tree route returns four nodes with levels 0, 1, 1, 2; a user without read on one subsidiary gets three.
