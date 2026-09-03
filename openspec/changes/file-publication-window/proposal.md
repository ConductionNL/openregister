# A publication window on the file, so an attachment needs no object

## Why

Several apps grew their own `document` object, and measuring one of them showed
what it is actually for. opencatalogi's `document` carries `title`, `filename`,
`mimeType`, `summary`, `description`, `publication`, `organization`,
`publicationDate` and `depublicationDate`. Every one of those already has a home
somewhere else:

| document property | where it already lives |
| --- | --- |
| `filename`, `mimeType` | the file |
| `description` | `File.description` |
| `title` | the filename, or a label |
| `publication`, `organization` | the object the file hangs from |
| `summary` | fold into `description` |
| `publicationDate` / `depublicationDate` | **nowhere** |

The window is the only real gap. `publishFile()` is a boolean: it creates a
public share or it does not. So an attachment could not be depublished on a date
independently of the record it belongs to, which is exactly what a WOO bijlage
needs, and that alone forced a whole object type into existence.

There is a second reason, and it is the stronger one. OpenRegister already
extracts file text into `openregister_chunks` with `source_type='file'`, and
`ContentSearchHandler::resolveOwningObject()` already resolves a file chunk to
its owning object through `FileMapper::findOwningObjectUuid()`. A keyword hit
inside a file attached to a publication therefore resolves straight to the
publication. The schema-widening in opencatalogi PR #1391 exists ONLY because
the attachment is a separate `document` object living outside the catalog's
schema scope. Files attached to publications would have made that whole class of
bug impossible.

## What changes

`openregister_files` gains `published` and `depublished`, both nullable, and the
file API reports the window and a computed `isPublished`.

A depublication date is written onto the public share's `expiration` column,
which Nextcloud already honours. An OR-side flag alone would leave a public URL
that still works, and a URL that still works is not a depublication.

## Two things this repairs on the way

`formatFile()` reported `'published' => creationTime`. Every file that had ever
existed therefore looked published, and no file could be read as unpublished.
The creation time is kept, under `created`, where it is true.

`FileMapper` declared `@phpstan-type File` as a filecache ROW shape, named after
the entity the mapper maps. That alias shadowed the entity in every docblock in
the file, so a method annotated `@return File` read as an array, and the phpstan
baseline carried an entry for each one. Renaming the alias to `FilecacheRow` and
naming the entity in the generic removed 12 baseline entries.

## What this does not do

It does not retire any app's `document` schema. That is per-app work with its
own migration and its own repointing, and it should follow this rather than ride
along with it.
