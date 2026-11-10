# Design: records-copy-with-links

Read at openregister development 555af7212.

## Context

- `objects#move` (`appinfo/routes.php:1245`) keeps the uuid, and its comment
  explains that a copy is a different act because a second uuid starts a new
  audit trail, versions, files and notes.
- The three link kinds already have read endpoints: `objects#used`
  (`:1190`, objects that point at this one), `objectRelations#index` and
  `#addLink` (`:1214-1215`, explicit relation rows), and the object's files
  with `files#copy` (`:1466`).
- `lib/Service/Object/MoveObject.php` is the model for a single-object act
  with its own service class; `RelationHandler.php` reads references.
- Nextcloud-vue's `CnCopyDialog` clones in the browser today and saves one
  object per row (its design, Context), so links are never copied.

## D-1: one server act, one transaction for data

`CopyObject::copy(source, overrides, include, actor)`:

1. reads the source with the caller's read rights;
2. builds the new payload: source fields minus `id`, `uuid`, `@self`
   metadata and generated identifiers, plus `overrides`;
3. saves it through `SaveObject` as a create, so every create rule applies;
4. for `relationRows`, adds each source row to the copy through the relation
   row service, with the caller's rights;
5. for `incoming`, for each object from `used` whose reference to the source
   is array-valued, patches that object to add the copy's uuid, with the
   caller's update rights on that object.

Steps 3 to 5 share one database transaction. A link refused for rights is
recorded and skipped, not a rollback. A failure of the create itself rolls
back everything.

## D-2: files after commit

File copies go through the file service after the transaction commits,
because Nextcloud's file system is not transactional. Each file is reported
`copied` or `failed` with a reason.

## D-3: the answer

`201` with `{ object, links: { relationRows: [...], incoming: [...], files: [...] } }`,
each entry `{ id, title, outcome: copied | skipped, reason }`. The audit trail
records a `copy` entry on the new object naming the source.

## D-4: rights

The caller needs `create` on the schema. Each link needs the right its own
endpoint needs. Nothing the caller could not do by hand is done for them.
