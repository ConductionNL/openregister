# Favourites and recently opened

## Overview

Star an object and it appears on your favourites list. Open one and it appears
on your recent list. Both are yours alone, and neither changes the object.

A consuming app gets two chips on an index page for the cost of two query
parameters. dossiq's Cases page and a zaakafhandelapp work list want the same
two lists, so they live here rather than once per app.

## Why the state sits beside the object

A star is a fact about a person, not about the object. Write it into the object
and every star cuts a version and adds an audit entry, so a case a hundred
people find interesting carries a hundred revisions that say nothing about the
case.

So a star lives in its own table, `openregister_favourites`, keyed by user and
object. Unstarring deletes the row. Deleting an object clears its stars. Objects
live in per-schema tables, so no foreign key can cascade from them and a
listener does the work instead.

## Recently opened comes from the audit trail

Opening an object already writes a `read` entry on the audit trail, with you and
the moment. Your recent list is read straight from those entries. There is no
second table to keep in step.

The list holds distinct objects, newest first, up to a hundred. Open a case five
times and it shows once, with the time of your last open. The audit trail keeps
all five entries.

A view is not a read state. A read state records that nothing changed since you
looked, and the next write clears it. See
[Object read state](object-read-state.md).

### When the audit trail is off

An administrator can switch the audit trail off with the retention setting
`auditTrailsEnabled`. Your recent list is then empty, and the response says why.
OpenRegister keeps no shadow log to fill it.

The AVG processing log is separate. It records reads of personal data for
accountability, whatever the audit setting says, and it is never used for your
recent list.

## The API

| Verb | Path | What it does |
|------|------|--------------|
| `PUT` | `/api/objects/{register}/{schema}/{id}/favourite` | Stars it for you. Starring twice is starring once |
| `DELETE` | `/api/objects/{register}/{schema}/{id}/favourite` | Removes your star, and nobody else's |

There is no `GET` here, because every object read already carries
`@self.favourite` for the reader. A detail page renders the star from data it
has, and a list renders a column of them from one query. The marker is omitted
entirely for an anonymous read, where there is no "you" to answer for.

Opening an object records the read. No call is needed.

## The two lenses

A list takes `_favourite=true` or `_recent=true`. Both are resolved inside the
query, so the page, the total and the facets see one restriction.

```
GET /api/objects/cases/case?_favourite=true&status=open
GET /api/objects/cases/case?_recent=true&_limit=10
```

Each composes with every other filter, so "my starred open cases" is one query
and not two. `_recent=true` orders by when you last opened each object, newest
first, unless you ask for an order of your own.

On a `_recent=true` page every object carries `@self.viewedAt`, the moment you
last opened it in ISO 8601. The response says whether the lens could answer:

```json
{
  "results": [
    { "@self": { "id": "…", "viewedAt": "2026-10-09T10:15:00+00:00" } }
  ],
  "@self": { "lenses": { "recent": { "available": true, "reason": null } } }
}
```

When `available` is `false` the page is empty and `reason` is one of
`audit-trail-disabled`, `anonymous` or `read-history-unavailable`.

An anonymous caller asking for either gets an empty page. A lens over nothing
answers nothing, never the whole register.

## Adding the chips to an app

Three chips on an index page, with no code in the consuming app:

```json
{
  "chips": [
    { "label": "Mine", "query": { "owner": "@me" } },
    { "label": "Favourites", "query": { "_favourite": true } },
    { "label": "Recent", "query": { "_recent": true } }
  ]
}
```

Render the star itself from `@self.favourite`, and write it with the two verbs
above.

## Specification

`openspec/changes/archive/2026-10-05-favourites-and-recent/` and
`openspec/changes/read-history-on-audit-trail/`, in the `object-interactions`
capability.
