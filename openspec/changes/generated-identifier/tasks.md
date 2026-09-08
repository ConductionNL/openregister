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
