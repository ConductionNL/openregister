# Proposal: the configuration preview survives duplicate seed slugs and comes to rest

## Why

The live pass of 5 Oct (lane 15) found three things in `PreviewHandler`:

- O12: `GET /api/configurations/8/preview` (Filinq Register) answered 500, "Multiple objects found with same identifier". The live register holds four seed slugs with two or three rows each. `previewObjectChange()` caught only `DoesNotExistException` around `MagicMapper::find()`, so one duplicate failed the whole preview, while the import (`ImportHandler`) catches the same exception, warns and skips that object.
- O13a: after an import every new preview still listed each object as "update" with `notInSchema: null -> x1`. The import discards properties the schema does not declare (`MagicMapper::reportDroppedProperties()`), so the change can never arrive and the preview never comes to rest.
- O13b: on a configuration whose register and schema the same import creates, the first preview listed the objects as "skip: Register or schema not found locally", while the import then created them.

## What changes

- A duplicate slug makes that object row a "skip" whose reason names the slug and says the import skips it; the rest of the preview answers as before.
- Top-level properties the target schema does not declare are left out of the comparison and listed under `discarded` on the row. An object whose remaining fields all match the stored object is a "skip" ("already holds what the import would write") instead of an "update" with nothing in it.
- An object whose register and schema are each either local or listed as "create" in the same preview is a "create".

## Impact

- `lib/Service/Configuration/PreviewHandler.php` only. The preview response gains an optional `discarded` list per object row. The import itself is unchanged.
