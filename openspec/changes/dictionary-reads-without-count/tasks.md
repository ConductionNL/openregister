# Tasks

## 1. Reads

- [x] 1.1 `SearchDictionaryProvider` reads the scheme lookups and the concept lists through `searchObjects()` (rows only, no count), via one `pageOf()` helper.

## 2. Verification

- [x] 2.1 Unit test: `SearchDictionaryProviderTest::testTheDictionaryReadsWithoutCounting` (four reads, unscoped, capped, never `searchObjectsPaginated` or `countSearchObjects`); the existing dictionary tests answer through `searchObjects()`.
- [ ] 2.2 Live on the sandbox reference stack: query count and timing of a cross-table search before and after, answers byte-identical, in the PR body.
