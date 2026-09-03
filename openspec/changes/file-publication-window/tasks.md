# Tasks

## 1. Storage

- [x] 1.1 Add `published` and `depublished` to `openregister_files`, both
      nullable, guarded so the step is re-runnable.
- [x] 1.2 Index `depublished`, which is what an expiry scan ranges on.

## 2. The window rule

- [x] 2.1 `File::isPublishedAt()`. Each null means something different: no
      `published` is never published, a future `published` is not yet, and no
      `depublished` is no end date rather than an end date in the past.
- [x] 2.2 `FileMapper::setPublicationWindowForFile()` sets both bounds together,
      because they are one fact.

## 3. Honouring it

- [x] 3.1 `publishFile()` writes the depublication date onto the share's
      `expiration`, so Nextcloud stops serving the link itself.
- [x] 3.2 `formatFile()` reports `published`, `depublished` and `isPublished`,
      and stops reporting the creation time as a publication date.

## 4. Repairs found on the way

- [x] 4.1 Rename `FileMapper`'s `@phpstan-type File` to `FilecacheRow`: it
      described a filecache row and shadowed the entity of the same name.
- [x] 4.2 Name the entity in the generic and drop the `@method` shadows that
      worked around the shadowing.
- [x] 4.3 Remove the 12 phpstan baseline entries those two defects required.

## 5. Tests

- [x] 5.1 Pin the window rule, including every null case and both boundaries.

## 6. Follow-on, not in this change

- [ ] 6.1 Retire opencatalogi's `document` schema: attachments become files on
      the publication, classified with labels. Revert the schema widening in
      opencatalogi PR #1391 once nothing needs it.
- [ ] 6.2 Decide whether an expired share needs a sweep, or whether Nextcloud's
      own expiration handling is sufficient on every deployment.
