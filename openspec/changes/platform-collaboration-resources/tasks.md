# Tasks: platform-collaboration-resources

- [x] 1.1 A collaboration resource provider answering name, icon, link and access for any object (D-1).
  - `lib/Collaboration/ObjectResourceProvider.php`, type `openregister-object`, resource id `<registerId>/<schemaId>/<uuid>`, registered at boot. Name, icon and link are the object's reference card (`ObjectPreviewFormatterInterface::buildReference`).
- [x] 1.2 The access answer resolved through the object read path, never reimplemented.
  - `canAccessResource()` is `ObjectOperationsInterface::find()` with RBAC and multitenancy on. The read path answers for the signed-in user, so a question about another user, a guest or no session is refused (fail closed).
- [x] 1.3 A schema declaration opting its objects into collections, defaulting to out (D-3).
  - `configuration.collaborationResources: true` on the schema; anything else, or a schema that cannot be read, stays out.
- [ ] 1.4 Membership changes require update rights and are audited (D-2).
  - Not built: Nextcloud core adds and removes collection members and asks the provider only `canAccessResource()`; enforcing update rights and writing the audit entry needs a hook on those core calls that the provider interface does not give.
- [x] 1.5 Reading an object returns its collections and the members the reader may access.
  - `GET /api/objects/{register}/{schema}/{id}/collections` (`ObjectCollectionsController`, `lib/Collaboration/ObjectCollections.php`): the object is read through the object read path first (404 when unreadable); collections and members the reader may not access are left out.
- [ ] 2.1 `tests/e2e/ci/platform-collaboration-resources.spec.ts`: add a case to a collection, see the folder beside it, fail to remove it as a reader.
- [ ] 2.2 Unit tests: the access branch, the opted-out schema, the audit entry.
  - The access branch and the opted-out schema: `tests/Unit/Collaboration/ObjectResourceProviderTest.php`. The audit entry waits on 1.4.
- [x] 2.3 `openspec validate platform-collaboration-resources --strict`.
- [x] 3.1 Hand over to the dossiq lane: declare which schemas may join, render the collection, register no provider.
  - `docs/features/collaboration-resources.md`: the opt-in, the collections endpoint and its answer, and that a leaf app registers no provider.
