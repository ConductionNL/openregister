# Object read state

## Overview

An object carries a read state per user: the moment you last saw it, and an
unread flag derived from that. A list can show what is new to you, a tab can
badge an unread document, and a notification clears itself when you open the
work it was about.

This is not a view history. `favourites-and-recent` records that you looked at
something; a read state records that nothing has changed since you did. The
difference shows on the next write.

## How unread is derived

Unread is the **absence** of a row in `openregister_object_read_state`.

- Opening an object writes your row.
- A substantive change deletes every row for that object except the author's.
- Marking something back to unread deletes your own row.
- Deleting the object deletes all of them.

That is what lets the filter be one correlated `NOT EXISTS` in SQL rather than a
timestamp comparison against a column the per-schema object table does not
carry. The page, the total and the facets all go through one query builder, so
they cannot disagree about what was excluded.

A read state is private to the person it belongs to. There is no administrator
override: an administrator who could mark an object read for somebody else could
make a badge lie to them. Asking about another user's read state is refused with
403 rather than answered about yourself.

## Declaring what counts as a change

Without a declaration, every write marks an object unread for everybody, and the
first nightly recalculation of a computed field marks four hundred cases unread
overnight. A badge that lights up for reasons the reader cannot see is a badge
people learn to ignore.

So a schema declares it, per ADR-031:

```json
{
  "x-openregister-read-state": {
    "properties": ["status", "assignee", "title"],
    "subResources": {
      "files": { "kind": "files" },
      "messages": { "kind": "property", "property": "messages", "dateField": "created" },
      "notes": { "kind": "property", "property": "notes", "dateField": "created" }
    }
  }
}
```

| Key | Meaning |
|---|---|
| `properties` | The properties whose change is news. A change to anything else is a technical touch. Omit the key and any non-computed property counts. |
| `subResources` | The tab badges. `kind: "files"` counts the object's attached files; `kind: "property"` counts entries of an array-valued property that arrived after you last read that tab, using `dateField` as the entry's moment. |

`files` is always a sub-resource, declared or not: OpenRegister owns the object's
folder. A `kind: "property"` entry without a `dateField` is dropped rather than
badged as nought, because a badge that cannot be computed and a badge reading
nought are different claims.

Computed properties never count, declared or not. They are the one class of
change the system makes to itself.

## The API

| Verb | Path | What it does |
|---|---|---|
| `GET` | `/api/objects/{register}/{schema}/{id}/read-state` | Your marker, the moment, and the tab badges |
| `PUT` | `/api/objects/{register}/{schema}/{id}/read-state` | Records that you have seen it, and clears the notices about it. Pass `subResource` to record one tab and clear only that tab's notices |
| `DELETE` | `/api/objects/{register}/{schema}/{id}/read-state` | Puts it back to unread, for you only |

An ordinary object read carries `@self.unread` for the reader, and a
single-object read also carries `@self.unreadCounts` as one map, so a detail page
renders every tab badge without a call per tab. Both are omitted entirely for an
anonymous read, where there is no "you" to answer for.

A list takes `_unread=true`, which is resolved inside the query. An anonymous
caller asking for it gets an empty page, never the whole register.

```
GET /api/objects/cases/case?_unread=true&_limit=25&_page=2
```

## The bell

A notification clears when you open what it was about, which nothing else in the
corpus does. Beside its read state a notice now carries:

| Field | Meaning |
|---|---|
| `subjectType`, `subjectId` | What the notice is about. Distinct from `schemaId`, which says which schema's rule produced it |
| `snoozedUntil` | Absent from the unread list until that moment, unread afterwards |
| `archivedAt` | Out of the list without being read |

Archiving and reading are never collapsed into one another. An archive that also
wrote a read stamp would turn "I do not want to see this" into "I have dealt
with this", and a notice whose subject has been deleted is archived rather than
marked read, because nobody read it and nobody can.

| Verb | Path | What it does |
|---|---|---|
| `GET` | `/api/notification-history?subjectType=…&unreadOnly=true` | The bell, on an axis |
| `PUT` | `/api/notification-history/{id}/snooze` | Takes `snoozedUntil` |
| `PUT` | `/api/notification-history/{id}/archive` | Leaves the read state alone |
| `PUT` | `/api/notification-history/thread/read` | Takes `objectUuid`, optionally `subjectId` |

Each is scoped to your own notices inside the service, never by the route.

## Specification

`openspec/changes/object-read-state/`, requirements REQ-ORS-001 through
REQ-ORS-004.
