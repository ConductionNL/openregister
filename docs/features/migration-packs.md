# Migration packs

A migration pack says which column of an import file becomes which field of an object, and how
the value is converted on the way. Imports that read spreadsheets or exports from another system
use one, for example stackiq's TOPdesk CMDB import.

The requirements are in [the migration-mapping-packs spec](../../openspec/specs/migration-mapping-packs/spec.md)
and, until it is archived, in the
[migration-pack-editor-ui change](../../openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md).

## Who can do what

| You are | You can |
| --- | --- |
| A Nextcloud administrator | Open **Migration packs** in the OpenRegister navigation, next to Connections; change packs, test them, import, export and delete |
| Any other signed-in user | Read and export a pack when you have its link; the menu entry is not shown |

## Change a pack

1. Open **Migration packs** and choose the pack.
2. Under **Field mappings**, each row reads one source column and writes one target field.
   Rows run from top to bottom. When two rows write the same field and both columns have a
   value, the lower row wins; the editor marks those rows. Use the arrows to change the order.
3. Choose the transform of a row to change how its value is converted:

   | Transform | What it does |
   | --- | --- |
   | Trim spaces | Removes spaces at the start and end |
   | Date | Reads the date in the source format and stores it in the stored format (`Y-m-d` when empty) |
   | Lookup table | Replaces a source value by the value in the table |
   | Yes/no table | Like a lookup table, storing true or false |
   | Join columns | Puts the values of more columns after this one, with a separator |
   | Fixed value | Stores the same value for every row, also when the column is empty |

   For a lookup or yes/no table, choose what happens to a value that is not in the table:
   report an error for the row, leave the field empty, or store a value you give. A yes/no table
   stores false instead of leaving the field empty.
4. Use **Test with a sample row** to see which fields an import of that row would write, before
   you save.
5. Choose **Save**. When you did not change the version, it goes up by one patch number.

**Advanced (JSON)** shows the whole pack. Settings the form does not show are kept when you save
from the form.

## Packs that an app installed

A pack marked **Built-in** was installed by an app. Its slug cannot change, because the app finds
the pack by it. The app may also apply rules of its own on top of the pack; check that app's
documentation before you change one. When you delete a built-in pack, the app installs it again,
unedited, on its next install or update. If you import or create a pack with the same slug before
that, your pack takes its place: it is marked **Built-in**, its slug stays locked, and the app does
not install its own version over it.

## Who changed a pack

Every create, change, import and delete writes a line to the Nextcloud log with the user, the pack
slug and the pack id. An import also says whether it created a pack or replaced one. These are
info lines: set the Nextcloud log level to Info (`loglevel` 1 in `config.php`) to keep them.

## For scripts and API clients

The screens use `/apps/openregister/api/migration-packs`. Reading (list, one pack, export) is open
to any signed-in user; create, update, delete, import and the sample-row test
(`POST /api/migration-packs/preview`) need an administrator and Nextcloud's request token. A
script outside a browser sends `OCS-APIRequest: true` or signs in with an app password.

Create and update take `{"definition": {...}}`; on update the row id in the URL decides which pack
changes. Add `"expectedUpdatedAt"`, the `updatedAt` of the pack as you read it, to an update and the
server refuses it when someone saved the pack since; leave it out and the update replaces whatever is
stored. When the server refuses, the answer is `{"message": "...", "error": "<code>"}`, with the
validator's findings in `details.errors` where there are any. Read the code, not the message: the
message is translated.

| Status | `error` | Meaning |
| --- | --- | --- |
| 400 | `invalid-request` | The body is not what the route takes, for example a preview without a `definition` object, a preview over 1 MiB in all, or an `expectedUpdatedAt` that is not a date-time as the API writes `updatedAt` |
| 400 | `too-many-rows` | A preview with more than 20 sample rows |
| 404 | `pack-not-found` | No pack has that row id |
| 409 | `pack-changed` | The pack was saved after the `expectedUpdatedAt` the update names |
| 422 | `invalid-pack` | The definition breaks a rule; `details.errors` lists each finding, rows numbered from 1 |
| 422 | `builtin-slug-locked` | The update would change the slug of a built-in pack |
| 422 | `pack-slug-exists` | Another pack already has the slug (the definition's `id`) |
| 500 | `migration-pack-error` | Something unexpected failed; the Nextcloud log has the details |

A request Nextcloud refuses before it reaches OpenRegister has Nextcloud's own answer: 401 when
nobody is signed in, 403 for a user who is not an administrator, 412 for a browser request
without the request token.
