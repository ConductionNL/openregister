---
kind: code
---

# Proposal: unified-search-file-content

## Summary

OpenRegister extracts text from attached files and stores it in chunks, and
its Nextcloud unified-search provider does not look at it. Pass
`_content_search: true` so a term that appears only inside an attached PDF
finds the object that owns it.

## Motivation

Both halves already exist and are not connected.

**The text is there.** `TextExtractionService` writes to
`oc_openregister_chunks`: `sourceType`, `sourceId`, `textContent`, an
embedding, `owner`, `organisation`, `checksum`. `ChunkMapper::searchByKeyword()`
searches it, and `FileSearchController` exposes semantic and hybrid arms at
`/api/search/files/*`.

**The provider does not use it.** `lib/Search/ObjectsProvider.php` — the
single fleet-wide provider — contains **zero** references to chunks. Measured
2026-08-15.

So a user searching Nextcloud for a term that appears only inside an attached
document finds nothing, while OpenRegister holds that text indexed and
searchable. The file itself may surface through the Files provider if the user
can see it in their own tree, but the OBJECT it documents — the case, the
publication, the invoice — does not.

## Decision

`ObjectsProvider::search()` passes `_content_search: true` to
`searchObjectsPaginated()`.

That is the change. `ObjectService` already merges chunk hits into the object
result set through the same RBAC and multitenancy pipeline, so a file hit is
returned as the OBJECT that owns the file — which is the thing with a
deep-link URL, an icon and a title. A naked chunk is not navigable and is not
what a searcher wants.

## Why not a separate file provider

OR's own provider docblock says it: *"This class is the single, fleet-wide
Nextcloud unified-search provider… Leaf apps do NOT register their own
`OCP\Search\IProvider`."* A second provider would mean two result lists over
the same objects, two RBAC paths, and two places for a disclosure bug. The
existing provider already states its security contract — it performs **no**
second access filter and delegates everything to
`searchObjectsPaginated(_rbac: true, _multitenancy: true)`. Widening its query
keeps that contract intact; adding a provider would fork it.

## Scope note: this is authenticated search, and cannot be otherwise

Nextcloud unified search cannot serve anonymous callers. Measured three ways
on 2026-08-15:

- `OCP\Search\IProvider::search(IUser $user, ISearchQuery $query)` — the
  `IUser` is non-nullable; the interface has no way to express "no user"
- `UnifiedSearchController` carries no `#[PublicPage]` on any method
- Live probe: anonymous **401**, authenticated **200**

So this change serves logged-in users only. Anonymous portal search is a
different mechanism entirely and lives in
`portaliq/openspec/changes/portal-public-search`.

## Affected Projects

- [ ] `openregister` — one flag on the provider's query, plus tests and a
      performance guard.

## Design notes

**Excerpts must keep coming from the rendered object.** The provider derives
excerpts from the object the user is allowed to read, which is what makes
field-level redaction apply to excerpts for free. A chunk hit must not
short-circuit that and put raw extracted text in a result line — the text in a
file can include fields the reader is redacted out of.

**Content search is more expensive than metadata search.** It adds a keyword
pass over chunk bodies. The provider runs on every keystroke-ish search in the
Nextcloud UI, so the cost is measured before and after, and the change carries
a limit rather than an unbounded candidate set.

## Risks

- **A chunk carries text from a whole file; an object's fields may be
  redacted.** If an excerpt ever came from chunk text, redaction would be
  bypassed in the excerpt while the object itself stayed correctly filtered.
  The excerpt source is asserted, not assumed.
- **Latency in the global search bar is felt immediately by every user.** A
  slow provider is experienced as a slow Nextcloud.
- **`_content_search` is opt-in today and its default is false.** Turning it on
  in the provider makes it the fleet's first always-on consumer, so any
  weakness in that path stops being theoretical.
