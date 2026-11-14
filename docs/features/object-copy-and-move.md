# Copying and moving objects

Two acts that look alike and are not. A **move** keeps who the object is; a
**copy** makes a new object.

## Specs

- [objects-crud](../../openspec/changes/records-copy-with-links/specs/objects-crud/spec.md) — change `records-copy-with-links`
- [identity-survives-a-move](../../openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md)

## Move: same object, new place

`POST /api/objects/{register}/{schema}/{id}/move`

The object goes to another register and schema and keeps its uuid. Its audit
trail, versions, files, notes and watchers all stay with it, because they are
keyed on the uuid. Generated numbers (`x-openregister-generated`) are kept as
they are.

## Copy: a new object with the links you choose

`POST /api/objects/{register}/{schema}/{id}/copy`

```json
{
  "overrides": { "naam": "Zaaksysteem B" },
  "include": ["relationRows", "incoming"]
}
```

- The caller needs **read** on the source (else 404) and **create** on the
  schema (else 403). Without a session the answer is 401.
- The new object is made through the normal create path, so validation,
  defaults, generated identifiers and the audit trail all apply. A body the
  create path refuses is answered as that refusal (for example 422 with the
  validation errors), and nothing is written.
- The copy gets its **own uuid**, and starts its own audit trail and versions
  from its first save. The source is never changed.

### What is never copied

- The identity: `id`, `uuid` and the `@self` metadata.
- Control keys starting with `_`.
- Properties the platform mints (`x-openregister-generated`): the copy gets a
  fresh number.
- An upsert: `_upsertOn` is refused with 422, because a copy always creates.

### Links (`include`)

| Kind | What happens |
| --- | --- |
| `relationRows` | Each relation row of the source (manual links to objects and external links) is re-created with the copy as its source. Provenance rows (split, derive) and prose rows are not copied, because they describe the source, not the copy. A row to an object the caller cannot read is skipped. |
| `incoming` | Every object whose **array** reference holds the source gets the copy added beside it. Each such object is read and updated under the caller's own rights. A single-valued reference is left alone: pointing it at the copy would take it away from the source. |
| `files` | Not available yet; asking for it is refused with 422. |

An unknown kind is refused with 422.

A link the caller may not write is **reported, not forced**, and does not stop
the copy. The new object and its links are written in one transaction: if the
create is refused, or a link step fails unexpectedly, nothing is written
(answer 500 for the latter).

### The answer

`201`:

```json
{
  "object": { "...": "the new object" },
  "links": {
    "relationRows": [{ "id": "…", "title": "Conduction", "outcome": "copied", "reason": null }],
    "incoming": [{ "id": "…", "title": "Basis", "outcome": "skipped", "reason": "You may not update this object." }],
    "files": []
  }
}
```

## Related files

- `lib/Controller/ObjectsController.php` (`copy()`, `move()`)
- `lib/Service/Object/CopyPayload.php`, `lib/Service/Object/CopyObject.php`
- `lib/Service/Object/MoveObject.php`
