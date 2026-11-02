# Tasks: example-set-object-owner

- [x] `ExampleSetValues::ownerOf()`: the declared owner, a cast account, or a refusal
- [x] Installer passes it to `ExampleSetStore::create(owner:)` -> `saveObject(_owner:)`; the check refuses an owner outside the cast
- [x] `ExampleSetRewriter` hands a kept object to its declared owner on `--refresh` (`ExampleSetStore::ownerOf/assignOwner`, as the system)
- [x] `ObjectOwnershipService::requireAdmin` admits the system scope
- [x] Tests (failing first): created as the owner, handed over on refresh once, refused outside the cast; store passes `_owner` and hands over as the system; system scope may assign, outside it not
