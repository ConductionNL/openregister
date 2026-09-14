# Design: search-over-history-and-an-administered-dictionary

## D-1: a projection, not a scan of the trail

The audit trail is the record of what happened, and it is hash-chained,
append-only and long-lived. Filtering a list by joining it is how a case list
becomes a minute-long query. So the predicate reads a narrow projection:
object, property, value, entered, left. It is derived, it can be rebuilt from
the recorded transitions, and it is pruned with them.

## D-2: the predicate answers under the caller's access

A history predicate that widens what a caller can see would be a disclosure
channel: "which cases were ever in state X" is information even when the rows
are not returned. The projection is filtered by the same access compilation
the object query uses, so a caller sees the history only of records they may
read now.

## D-3: the dictionary expands the query, it does not rewrite the index

ADR-007 says the built-in database search is the only backend and that adding
one is an ADR-level decision. Expanding terms at query time keeps every stored
row untouched, makes a dictionary edit effective at once with no rebuild, and
leaves the door to a future analyser closed rather than half-open.

## D-4: expansion is bounded and visible

A synonym group with forty members multiplied by a three-word query is a query
nobody costed. The expansion is capped per group and per query, the cap is
administered, and the response reports what it expanded to. A search whose
results surprise somebody then has an explanation that does not require a
developer.

## D-5: stopwords are per language and never empty the query

Dropping every word of a query leaves a search that matches everything. When
stopword removal would empty a query, the original query is used instead.

## D-6: reuse analysis (ADR-012)

- The object query, its access compilation and its paging: reused.
- The lifecycle transition records: reused as the projection's source.
- The admin settings surface and the objects API: reused for the dictionary,
  which is an ordinary register.
- No new search backend, no analyser configuration, no second index.
