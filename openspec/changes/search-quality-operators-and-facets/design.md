# Design: search-quality-operators-and-facets

## D-1. The absent value is a bucket, not a filter nobody offers

Asking for the records that have no result type is asking a question the
facet already knows the answer to: it counted the ones that do. Emitting
the complement as a bucket costs one count in the same query and turns a
data quality report into a click. Building it as a separate filter the
user has to know about is how it stays unused.

## D-2. A malformed term is refused, never treated as literal

Searching `dakkapel AND (geweigerd` has one correct answer, and it is not
"no results". A parser that falls back to a literal string looks exactly
like a search that found nothing, which is the failure the whole sweep
keeps finding. The refusal names the position of the fault.

## D-3. The match type lives on the property, not in the list

A date wants a range, a zaaknummer wants exact, a description wants full
text. Putting that on the property means one declaration serves the list,
the facet, the API and the portal. Putting it in the list component means
four answers and three of them drift.

## D-4. A rebuild answers from the current index until it is finished

A rebuild that empties the index first makes search wrong for the length
of the rebuild, and a municipality notices. The new index is built beside
the current one and swapped when complete, so the worst case is a stale
answer rather than no answer.

## D-5. The query language is translated where the label already is

Property labels are already translated. Resolving a query's field name
through the same labels means nothing new is administered and no second
vocabulary drifts. It also means the Dutch query works only for properties
that carry a Dutch label, which is the honest limit and is worth saying.

## D-6. kind

Code, in OpenRegister. Leaf apps declare and consume.
