# Tasks: Virtual schemas + inheritance-aware semantic providers

## 1. Inheritance-aware implemented-types (Q1)

- [x] 1.1 Make implemented-types union in `allOf` ancestors: resolve each `allOf`
  ref to its parent `Schema` and union each parent's implemented-types
  (recursive, visited-set circular guard). Do the walk in `SemanticTypeResolver`
  (has `SchemaMapper`) so `JsonLdContextService` stays dependency-light — or add
  an `implementedTypesWithAncestors()` helper. A child adds, never removes.
- [x] 1.2 Unit-test: a schema `allOf`-extending a `schema:Person` schema resolves
  for `https://schema.org/Person`; own markers still win/merge; no `allOf` ⇒
  unchanged; circular `allOf` doesn't loop.

## 2. NC-entity semantic seed map (Q3)

- [x] 2.1 Add a `NcEntitySemanticMap` constant (user→Person/user-directory-source,
  group→Organization/group-source; leave placeholders for contact/event/file/etc).
- [x] 2.2 A Repair/seed step materialises one virtual register + schema per row
  (idempotent; reuse the register-import-via-Repair pattern). The row's schema.org
  value becomes the schema's `x-schema-org`.

## 3. Directory register + object-source providers (Q2)

- [x] 3.1 `UserDirectoryObjectSourceProvider implements ObjectSourceProvider` —
  `IUserManager` backed (`findAll`/`find`/`count`), read-only, `toObjectEntity`
  no-persist (uuid=uid; displayName, email), user-scoped, `isEnabled()=true`.
- [x] 3.2 `GroupObjectSourceProvider` — `IGroupManager` backed, same shape (gid,
  displayName).
- [x] 3.3 Register both via `registerObjectSourceProviders` /
  `bootObjectSourceProviders` (`Application.php`).
- [x] 3.4 Seed the `directory` virtual register (`application: openregister`) with
  `nc-user` (`x-schema-org: schema:Person`, provider `user-directory-source`) and
  `nc-group` (`x-schema-org: schema:Organization`, provider `group-source`).

## 4. Verify live + tests

- [ ] 4.1 `GET /api/schemas/resolve-by-implements?uri=https://schema.org/Organization`
  → resolves to `nc-group` (register `directory`) with NO resolver change.
- [ ] 4.2 `GET /api/objects/directory/nc-group` lists live NC groups via the
  provider; `/api/objects/directory/nc-user` lists users; both read-only.
- [ ] 4.3 App-enabled gate: `directory` (application openregister) never degrades.
- [x] 4.4 Provider + seed unit tests; strict gates (phpstan/psalm/phpcs) green
  in-container.

Audit of the second pass: 1.x to 3.x were already on main without the ticks.
`SemanticTypeResolver::implementedTypesWithAncestors()` (tested by
`SemanticTypeResolverTest::testChildAllOfInheritsPersonType`, `testOwnAndAncestorMarkersUnion`,
`testNoAllOfIsUnchanged`, `testCircularAllOfDoesNotLoop`), `NcEntitySemanticMap`,
`SeedDirectoryVirtualSchemas` (registered in `appinfo/info.xml`), `UserDirectoryObjectSourceProvider` and
`GroupObjectSourceProvider` (wired in `ObjectSourceWiring`), each with unit tests that pass. phpstan and psalm
report no errors on those files. 4.1 to 4.3 are live checks on a running instance and stay open.

## 5. Follow-ons (OUT of this change — one PR each)

- [ ] 5.1 Contacts/Calendar/Files/Deck/Talk/Tasks object-source providers (reuse
  the Integration Registry read code) + their seed-map rows.
- [ ] 5.2 Physical dedup of leaf `organization`/`Payee` copies behind the stable
  URIs (deferred; needs data migration + go-ahead).
