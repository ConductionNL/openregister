# Design: content search index

## D-1: one provider, two hit kinds

The unified search provider already exists and is the fleet's only one. A
file hit is a second result kind from the same provider, not a second
provider, so the Nextcloud search bar shows one OpenRegister section. Each
hit carries `kind`, the owning object's deep link, and for a file hit the
file name and the matching excerpt.

## D-2: scopes are a filter the caller declares

A scope is one of `app:<id>`, `register:<slug>`, `schema:<slug>` or `files`.
The provider's OCS capability lists the scopes available to the user, so a
consuming app renders scope chips from the capability, not from a copy. The
`searchable` flag on a schema still decides exposure.

## D-3: backend when present, database when not

With a configured search backend (search-index) the query goes to the object
collection and the file collection in one round trip. Without one, object
data uses the existing database full-text path and file text uses the
`openregister_file_chunks` table with a `LIKE` over chunk text bounded by the
same page size. Same contract, different cost; the response names the
backend so a slow instance is diagnosable.

## D-4: PR 3528 stays the shape

The chunked schema listing from PR 3528 is the loop this change extends; the
scope filter narrows the schema list before chunking, so a scoped query is
cheaper, never dearer, than an unscoped one.

## D-5: kind

Code, in OpenRegister. Consuming apps declare offered scopes in config.
