# Tasks: generated-identifier

## 1. Storage and declaration

- [ ] 1.1 `openregister_sequences` migration with the unique key.
- [ ] 1.2 Validate `x-openregister-generated` at schema save (string
      property, known placeholders, `resetOn` in the vocabulary).

## 2. Generation

- [ ] 2.1 Listener on `ObjectCreatingEvent` filling an empty property under
      the lock, on both databases.
- [ ] 2.2 Update guard refusing a change to a generated property.
- [ ] 2.3 Import keeps a supplied value and advances the counter.

## 3. Tests

- [ ] 3.1 Unit tests for the format renderer, the guard and the import
      advance; a concurrency test creating fifty objects in parallel.
- [ ] 3.2 `tests/e2e/api-direct/generated-identifier.spec.ts`: create two
      objects, read consecutive identifiers, try to edit one and get 422.

## Discovery cluster 41

- [ ] C41.1 A random sequence kind with a declared length and alphabet, under the existing allocation lock (D-C41-1).
- [ ] C41.2 Foreign identifiers on an object, each naming its issuer, indexed and exactly resolvable (D-C41-2).
- [ ] C41.3 A second generated identifier beside the first, with its own sequence and format.
- [ ] C41.4 Reserved values and patterns that no sequence issues.
- [ ] C41.5 A scheme change as a background job with progress, keeping each previous value as a foreign identifier of this instance (D-C41-3).
- [ ] C41.6 A second migration of the same sequence is refused, naming the running one (D-C41-4).
- [ ] C41.7 Tests: random uniqueness under concurrency, the two-issuer lookup, the reserved skip, the old number still resolving, the refused second migration.
- [ ] C41.8 Hand over to the dossiq lane for the mask per case type and to the integriq lane for the ZGW and StUF correlation, with candidate ids C-case-core-20, -28, -30, -39 and C-configuration-61.
