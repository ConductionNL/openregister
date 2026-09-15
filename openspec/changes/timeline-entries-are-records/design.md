# Design: timeline-entries-are-records

## D-1. An entry is indexed as itself, beside the object

Indexing the entry text into the object's document makes one blob that
matches everything and points at nothing. The entry is its own indexed
item carrying its object, its kind and its visibility, so a hit can say
which entry on which case, and the visibility filter is a query condition
rather than a post-filter.

### D-1 as built: the visibility filter is in the query, the access check is not

Built 2026-09-15, and this is a deviation worth naming rather than burying.
The visibility predicate IS a condition in the statement, which is what D-1 was
about: a portal reader asks for the public view and gets a real page of the
right size. The OBJECT ACCESS check is not. It resolves per object, after the
statement, through the same `ObjectService::find` read every other caller goes
through, with a per-request memo so an object is resolved once however many of
its entries matched.

The reason is that the set of objects a handler may read is unbounded, and
enumerating it to build an `IN` clause would be a bigger read than the search
it was meant to narrow. The statement therefore over-fetches and the service
drops what the caller may not see. What this costs: a page can come back short
when many hits sit on cases the searcher cannot read, which is why the
over-fetch factor exists and is named. What it does not cost is correctness of
the refusal, which is the same refusal the object endpoint gives.

The two are asserted separately in
`TimelineEntrySearchServiceTest`, because a test that only counted results
could pass with either one missing.

## D-2. A kind declares fields, so a contactmoment is not a second schema

dossiq models a contact moment as its own object today, which splits the
timeline in two: some things are entries and some are objects that look
like entries. A kind with declared properties keeps one timeline and still
lets an entry carry a channel and a direction.

## D-3. The follow-up state lives on the entry, not on the case

A callback that has not happened is a property of the request, not of the
case. Keeping it on the entry means a list of open callbacks spans cases
and does not need a field on every schema that might ever have one.

## D-4. The pin is on the record, not per reader

Four entries out of three hundred are the ones a colleague needs, and the
colleague is not the person who knows which four. A per-user pin helps
nobody but the pinner.

## D-5. The raw source is stored, because the headers settle the dispute

A rendered message cannot prove when it arrived. The raw source is kept
beside the entry under the same access, which costs storage and answers
the one question that reaches a lawyer.

## D-6. A reference is recorded on both sides

Rendering a link is presentation. Recording the reference makes the graph
readable from the other end, which is what "which zaken mention this
besluit" needs, and it survives an edit of the sentence.

## D-7. A mention uses the watcher primitive

Subscribing somebody who was named is the same act as watching, and
`object-watchers` already specifies watching. Two subscription models
would disagree about who gets what.

## D-8. kind

Code, in OpenRegister.
