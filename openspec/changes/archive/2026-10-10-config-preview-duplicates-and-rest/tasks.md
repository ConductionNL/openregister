# Tasks: config-preview-duplicates-and-rest

- [x] 1.1 `previewObjectChange()` catches `MultipleObjectsReturnedException` and reports the object as a skip with the duplicate slug in the reason (O12).
- [x] 1.2 Undeclared top-level properties are left out of the comparison and listed as `discarded`; an object with no remaining change is a skip (O13a).
- [x] 1.3 Objects under a register and schema that are local or created by the same preview are a create (O13b).
- [x] 2.1 `tests/Unit/Service/Configuration/PreviewHandlerComesToRestTest.php`: the real exception and message `MagicMapper::find()` throws; red on development (the exception escapes; `notInSchema` reported as a change; first-import objects skipped), green after. `PreviewHandlerChangesTest` declares the properties its objects carry, as the import requires.
- [ ] 3.1 Live: `GET /api/configurations/8/preview` answers 200 with the four duplicate slugs as skips; a seed with an undeclared property comes to rest after one import; a first preview of a configuration that creates its own register and schema lists its objects as create (needs the live instance). (live pass, decision 139)
