# Tasks: special-right-to-manage-grants

## 1. The right
- [x] 1.1 `OrganisationSpecialRights::holdsOn()` reads a special right from the record's organisation, fail closed
- [x] 1.2 An entry is a group id, or a group narrowed to a register and schemas
- [x] 1.3 `Organisation::getDefaultAuthorization()` declares `grant_manage` with nobody holding it
- [x] 1.4 `OrganisationSpecialRights::give()` and `giveInEveryOrganisation()` for code shipped with an app

## 2. Where it is checked
- [x] 2.1 `ObjectSharingService::listGrants()`, `grant()`, `revoke()`
- [x] 2.2 `ObjectOwnershipService::setOwnerGroup()`
- [x] 2.3 A holder may not grant to itself or name its own group as the owner
- [x] 2.4 The scope, a link and an invitation stay owner and administrator only

## 3. Who assigns it
- [x] 3.1 `OrganisationController::update()` refuses a change to `grant_manage` from a member who is not the owner or an administrator

## 4. Tests
- [x] 4.1 A holder writes, lists and revokes a grant on a record it does not own
- [x] 4.2 A non-holder is refused with 403 through the sharing controller
- [x] 4.3 A holder is not admitted to the record itself, and the read side does not know the right
- [x] 4.4 An owner and an administrator behave as before
- [x] 4.5 A member may not change who holds the right

## 5. Live
- [ ] 5.1 A grant written by a service account that holds the right, on a running instance (the consuming app's live pass)
- [ ] 5.2 `grant_manage` in the organisation editor's Special Rights tab (it is assignable through the API and `give()` only)
