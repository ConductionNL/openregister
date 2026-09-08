# Tasks: content-search-index

## 1. Provider

- [ ] 1.1 Add the `kind` field and file hits to the provider's result shape.
- [ ] 1.2 Parse scopes from the query and narrow the schema list before the
      PR 3528 chunk loop.
- [ ] 1.3 Advertise available scopes in the OCS capability.

## 2. Storage paths

- [ ] 2.1 Backend path: query object and file collections in one round trip.
- [ ] 2.2 Database path: file-chunk `LIKE` bounded by page size; name the
      backend in the response.

## 3. Tests

- [ ] 3.1 Unit tests for scope parsing and the two paths.
- [ ] 3.2 `tests/e2e/ci/content-search-index.spec.ts`: attach a text file to
      an object, search a word from the file, see a file hit that deep-links
      to the object.
