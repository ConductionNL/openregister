# Tasks: platform-reference-provider

- [x] 1.1 One `IReferenceProvider` resolving any object URL the deep link registry parses (D-2).
- [x] 1.2 Resolution applies the reading user's access; no access means the bare link (D-1).
- [x] 1.3 A declared card per schema: title, up to three summary properties, icon, optional image (D-3).
- [x] 1.4 An undeclared schema renders title and schema name.
- [ ] 1.5 The smart picker offers objects over the same declaration.
- [ ] 2.1 `tests/e2e/ci/platform-reference-provider.spec.ts`: an object URL in a conversation renders a card for a reader and a bare link for a non-reader.
- [x] 2.2 Unit tests: URL parsing, the access branch, the declared and undeclared card shapes.
- [ ] 2.3 `openspec validate platform-reference-provider --strict`.
- [ ] 3.1 Hand over to the dossiq lane: declare the card per schema, register no provider.

## Build notes

- 1.1, 1.2, 1.4 and 1.5 were already met on main: `ObjectReferenceProvider` is the one
  provider, registered in `Application.php`, resolving any URL `DeepLinkRegistryService`
  parses through `ObjectPreviewFormatter`; the read runs as the caller and every failure,
  RBAC included, answers no reference, so a reader without access gets the bare link; an
  undeclared schema renders the object's title with its schema name in the rich object; and
  the provider is `ISearchableReferenceProvider` with `getSupportedSearchProviderIds()`, so
  the smart picker offers objects.
- 1.3 is this build: `x-openregister-card` (title property, up to three summary
  properties, an icon and an image property), read by `ReferenceCardDeclaration`, refused at
  schema save when it names a property the schema does not have, and used by
  `ObjectPreviewFormatter` for the card's title, description, rows and image. An undeclared
  schema keeps today's card exactly, including its rich-object shape: the `image_url` key
  appears only when a card declares an image.
- 1.5 is left unchecked: the provider declares the `openregister_objects` search provider, so
  the picker already offers objects, but it does not read `x-openregister-card` for what each
  result shows. That is the picker's own result shape and belongs with a follow-up.
- 2.2 is this build (16 cases). 2.1 (Playwright) and 2.3 (`openspec validate`) need a live
  instance and the openspec CLI, so they are left unchecked, and 3.1 is dossiq's lane.
