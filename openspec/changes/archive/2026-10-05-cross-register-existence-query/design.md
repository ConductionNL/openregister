# Design: cross-register-existence-query

## D-1. Existence is a different disclosure from the row

A row in a Jeugdwet register is special-category data. That a row exists is
not. Collapsing the two is what forces a caller to read everything and throw
most of it away, in code the register's owner never sees. The endpoint exists
so the smaller disclosure has its own door.

## D-2. Field by field, never filtered down

The answer is ASSEMBLED from named fields rather than built by removing
fields from a row. The difference is what happens tomorrow: a filtered answer
grows every property somebody adds to the schema until a reviewer notices,
and an assembled one grows nothing. A leak in the first shape needs a new
`unset()`; in the second it cannot happen.

## D-3. `reveal` defaults to empty, and the schema bounds it

The caller says which fields it needs beside the existence, and the default is
none. The schema decides whether it may have them: a property the schema marks
sensitive is refused BY NAME, so the caller learns their request was narrowed
rather than silently receiving less. A caller that could widen `reveal`
without limit would have re-invented the read.

## D-4. Authorisation is the read it replaces

Every probe is authorised as a read of that register and schema by the calling
identity. The endpoint may never answer about a register the caller could not
have searched: an existence answer the caller could not otherwise obtain is a
new disclosure channel, not a narrower one.

## D-5. A count, not rows

The answer carries `exists` and `matches`, bounded. Returning rows would make
the endpoint the read it exists to avoid, and returning only a boolean would
send callers back to searching when they need to know whether there is one or
forty.

## D-6. The ground stays with the caller

dossiq requires an authorisation ground to be chosen before it asks and writes
it to `sociaalDomeinAuditLog`. That is case administration: the grounds are a
social-domain vocabulary and mean nothing to a register of invoices. The
platform logs the read it performed, as it already does for every read, and
does not invent a second vocabulary.
