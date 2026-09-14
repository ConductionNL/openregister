# Design: timeline-entries-are-records

## D-1. An entry is indexed as itself, beside the object

Indexing the entry text into the object's document makes one blob that
matches everything and points at nothing. The entry is its own indexed
item carrying its object, its kind and its visibility, so a hit can say
which entry on which case, and the visibility filter is a query condition
rather than a post-filter.

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
