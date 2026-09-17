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

So both live in their own table, keyed by user and object:

| Table | Holds |
|-------|-------|
| `openregister_favourites` | One row per star. Unstarring deletes the row |
| `openregister_object_views` | One row per object you have opened, carrying when you last opened it |

Deleting an object clears both. Objects live in per-schema tables, so no foreign
key can cascade from them and a listener does the work instead.

## What a view is, and what it is not

A view records that you looked at something. A read state records that nothing
has changed since you did. The difference shows on the next write: a view
survives it, a read state does not. See
[Object read state](object-read-state.md).

Your history holds one row per object, not one per opening. A hundred rows are a
hundred distinct things you looked at, and the oldest drop off past a hundred.

Opening the same object several times in a minute records one view, at the
moment of the first read. A detail page reads its object more than once while it
renders, and each of those is the same act of opening it.

## The API

| Verb | Path | What it does |
|------|------|--------------|
| `PUT` | `/api/objects/{register}/{schema}/{id}/favourite` | Stars it for you. Starring twice is starring once |
| `DELETE` | `/api/objects/{register}/{schema}/{id}/favourite` | Removes your star, and nobody else's |

There is no `GET` here, because every object read already carries
`@self.favourite` for the reader. A detail page renders the star from data it
has, and a list renders a column of them from one query. The marker is omitted
entirely for an anonymous read, where there is no "you" to answer for.

Opening an object's detail records the view. No call is needed, and no other
read path records one: a list, an export and a webhook all render objects, and
none of them is somebody looking at one thing.

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

`openspec/changes/favourites-and-recent/`, in the `object-interactions`
capability.
