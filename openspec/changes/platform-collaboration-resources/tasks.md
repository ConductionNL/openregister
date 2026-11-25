# Tasks: platform-collaboration-resources

- [x] 1.1 A collaboration resource provider answering name, icon, link and access for any object (D-1).
  - `lib/Collaboration/ObjectResourceProvider.php`, type `openregister-object`, resource id `<registerId>/<schemaId>/<uuid>`, registered at boot. Name, icon and link are the object's reference card (`ObjectPreviewFormatterInterface::buildReference`).
- [x] 1.2 The access answer resolved through the object read path, never reimplemented.
  - `canAccessResource()` is `ObjectOperationsInterface::find()` with RBAC and multitenancy on. The read path answers for the signed-in user, so a question about another user, a guest or no session is refused (fail closed).
- [x] 1.3 A schema declaration opting its objects into collections, defaulting to out (D-3).
  - `configuration.collaborationResources: true` on the schema; anything else, or a schema that cannot be read, stays out.
- [x] 1.4 Membership changes require update rights and are audited (D-2).
  - `POST /api/objects/{register}/{schema}/{id}/collections` (`collectionId`) and `DELETE .../collections/{collectionId}` (`ObjectCollectionsController::create()`/`destroy()`, `lib/Collaboration/ObjectMembership.php`): the object is read as the caller (404), the schema must opt in (400), the caller must hold `update` through `ObjectUpdateRightsInterface` (403, and a check that throws refuses), the collection must be one the caller may access (404). A change that happened writes one `collection.added` / `collection.removed` entry on the object's audit trail naming the collection; a no-op writes none.
  - Not covered: Nextcloud core's own collection endpoints ask the provider only `canAccessResource()`, so a change made from core's sidebar is not update-guarded or audited. The provider interface gives no hook for it; `docs/features/collaboration-resources.md` says so.
- [x] 1.5 Reading an object returns its collections and the members the reader may access.
  - `GET /api/objects/{register}/{schema}/{id}/collections` (`ObjectCollectionsController`, `lib/Collaboration/ObjectCollections.php`): the object is read through the object read path first (404 when unreadable); collections and members the reader may not access are left out.
- [ ] 2.1 `tests/e2e/ci/platform-collaboration-resources.spec.ts`: add a case to a collection, see the folder beside it, fail to remove it as a reader.
- [x] 2.2 Unit tests: the access branch, the opted-out schema, the audit entry.
  - The access branch and the opted-out schema: `tests/Unit/Collaboration/ObjectResourceProviderTest.php`. The audit entry, the update refusal, the fail-closed check and the no-op: `tests/Unit/Collaboration/ObjectMembershipTest.php`; the status mapping: `tests/Unit/Controller/ObjectCollectionsControllerTest.php`.
- [x] 2.3 `openspec validate platform-collaboration-resources --strict`.
- [x] 3.1 Hand over to the dossiq lane: declare which schemas may join, render the collection, register no provider.
  - `docs/features/collaboration-resources.md`: the opt-in, the collections endpoint and its answer, and that a leaf app registers no provider.
