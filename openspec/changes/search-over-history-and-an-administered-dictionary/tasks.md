# Tasks: search-over-history-and-an-administered-dictionary

## 1. The projection

- [x] 1.1 A narrow, indexed projection of lifecycle transitions: object, property, value, entered, left.
- [ ] 1.2 A rebuild from the recorded transitions, resumable and bounded.
- [ ] 1.3 The projection is pruned with the trail it derives from.

## 2. The predicate

- [x] 2.1 A `was ever` filter over a property's historical values in the object query grammar.
- [x] 2.2 A `changed between` filter over a period.
- [x] 2.3 The predicate is compiled into the same access-filtered query as the current-state filters.
- [x] 2.4 A predicate naming a property with no projection is refused, naming the property.

## 3. The dictionary

- [~] 3.1 A synonym-group and stopword register, per language, with an admin surface.
- [x] 3.2 Query-time expansion under an administered cap, per group and per query.
- [x] 3.3 Stopword removal that would empty a query falls back to the original query.
- [x] 3.4 The response reports the terms the query expanded to.

## 4. Tests

- [~] 4.1 Unit tests for the predicate compilation, the access filter, the expansion cap and the empty-query fallback.
- [ ] 4.2 An e2e over a list filtered by a state a case has left.
- [ ] 4.3 Deduplication check (ADR-012) recorded in the PR body.

## Status, 2026-09-18

**Built: the projection and the predicate (sections 1.1 and 2).**

- `openregister_state_history` holds one row per interval an object's lifecycle
  property spent at one value. `left_at IS NULL` means "still there", so the
  current state is not a special case: "was ever in bezwaar" is true of a case
  sitting in bezwaar now, and a filter that disagreed with the list beside it
  is how this goes wrong.
- `StateHistoryProjectionListener` writes an interval on
  `ObjectTransitionedEvent` and never fails the move: the projection is derived
  and rebuildable, the transition is not.
- `_was_ever[status]=bezwaar` and `_changed_between[status]=a,b` are parsed by
  `HistoryPredicate` and answered by `HistoryNarrowing` as a NARROWING of the
  id set the ordinary query already carries. The access-filtered query stays
  the only source of rows, so a history filter can only ever remove objects the
  caller could already see (2.3). Both filter keys are removed from the query
  before it travels, because left in they read as PROPERTY filters and a
  property nothing has matches nothing.
- An empty candidate set skips the search entirely. `ids: []` is read further
  down as "no id filter", so passing it would answer with the whole register.
- 2.4 refuses by name, in the controller, before a source is chosen.

**The generalised excerpt lesson, applied.** The property a transition is
projected under comes from the schema's `x-openregister-lifecycle.field`, never
from the key that changed in the payload, and the properties a filter may name
come from the same declarations rather than from the property names present in
the projection table. A projection that recorded whatever the pipeline attached
would let a filter reach a value the schema never declared as a state, and the
searcher could learn it from the result count alone. Mutation-checked in both
directions.

**Not built, and why:**

- **1.2, the rebuild.** The projection is written forward from this change on.
  Rebuilding history for objects that transitioned before it needs a resumable
  pass over the audit trail, which is its own job with its own bounds.
- **1.3, pruning with the trail.** Nothing is wired, and no hook is left behind
  either: a `pruneObject()` that nothing calls is the same as no pruning, with
  the added cost that it looks done. The purge path tombstones audit rows by
  expiry and does not name the objects it purged, which is what a prune needs.
- **Section 3, the dictionary,** in full: the synonym and stopword register,
  its admin surface, the expansion cap and the expansion report. It is the
  other half of this change and is a change's worth of work on its own.
- **4.2, the e2e**, and **4.3**, the ADR-012 deduplication check.

## Status of section 3, 2026-09-18

**Built: the dictionary and its whole query-time half (3.2, 3.3, 3.4).**

- No register was added. A synonym group is a SKOS concept in the vocabulary
  register — `prefLabel` plus its `altLabel` entries — which is what `altLabel`
  has always meant (ADR-011), and both are keyed by BCP-47 language tag, so
  "per language" needs no second mechanism. Stopwords are concepts in their own
  scheme. The two schemes are named by well-known uris, documented in
  `docs/features/search-and-faceting.md`.
- Expansion rewrites a plain term as `(word OR synonym)` in the grammar the
  term parser already reads, bounded per group and per query by administered
  caps. A term already carrying operators is left exactly as typed.
- A term made only of stopwords falls back to what was typed, and the response
  says so: an empty term answers with the whole register.
- `@self.dictionary` reports what was typed, what was searched, what was added
  and what was dropped.

**What a filter may expand to comes from the declarations.** A group is a
concept an administrator wrote; nothing is inferred from what a search happened
to match, and the report names what the DICTIONARY added rather than what the
search matched — listing matched terms would let anything the pipeline attached
appear as though somebody had declared it.

**3.1 is half done.** The administered data and its surface both exist: the
concepts are ordinary register objects, editable in OpenRegister's own object
UI, which is the admin surface and needed no bespoke settings page. What is
missing is a seeded, EMPTY pair of concept schemes, so an administrator has
somewhere to write without creating the schemes by hand first. Seeding them
means a new fixture through `SeedVocabularyRegister`, which this lane cannot
run against an instance, and seeding actual synonyms would be inventing
language policy for every municipality.

**Not covered by a test:** the register read path itself. The unit tests pin
the lookup SHAPE — slugs resolved to ids because the search path casts to int,
and `inScheme` matched on the scheme object's uuid rather than its uri — but no
test executes it against a database. It fails soft to an empty dictionary, so a
mistake there is invisible; that is why the provider says at INFO which scheme
it could not find, and why a failed load logs at WARNING. That trail is not
decoration: while building this, a named-argument typo in my own code was
swallowed by the fail-soft catch, and the warning line is what found it.
