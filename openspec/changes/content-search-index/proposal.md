# Content search index: one box, file content and object data, with scopes

## Why

Round 2 of the dossiq competitor analysis (row B02 in
`concurrentie-analyse/procest/_round2/compare/tier-b-and-sibling.md`, decision
D10) finds that every competitor searches document content and case data from
one box. OpenCase runs Elasticsearch over metadata and file content and adds a
Free text page (`opencase/round2/search-anatomy.md`); GZAC has an optional
OpenSearch (`valtimo/round2/code-census.md`); Zaaksysteem's Globaal zoeken
has the scopes Zaken, Organisaties, Medewerkers, Documenten and Objecten
(`xxllnc-zaken/round2/pages/GlobaalZoeken.md`).

dossiq has zero hits for full-text, Tika or Elasticsearch. OpenRegister owns
unified search for the fleet (unified-search-provider) and already indexes
file chunks when a search backend is configured (search-index, FileHandler).
PR 3528, merged 2026-09-08, keeps the provider working on many-schema
instances by chunking the schema list; this change builds on that provider,
it does not replace it.

## What changes

- The unified search provider answers one query over object data and over the
  extracted text of files attached to objects, and marks each hit with its
  kind (`object` or `file`).
- The provider accepts scopes: an owning app, a register, a schema, or `files`.
  A consuming app declares which scopes its search box offers; the default is
  every scope the user may read.
- Instances without a search backend get the same contract over the database
  (object data through the existing full-text path, file text through the
  file-chunk table), so the box works everywhere and is fast where a backend
  is configured.

## Who benefits

dossiq (case and document search from one box), filinq (document intake),
opencatalogi (publication content), keepiq, larpinq.

## Impact

- Affected specs: unified-search-provider (delta).
- Affected code: `lib/Service/Search/ObjectsProvider.php` (or its successor
  after PR 3528), `lib/Service/Search/FileHandler.php`, the provider's OCS
  capability advertisement.
- Backwards compatible: a query without scopes behaves as today plus file
  hits.
