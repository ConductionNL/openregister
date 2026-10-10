# Tasks: credential-provider-labels-in-plain-words

## 1. Catalogue titles
- [x] 1.1 Rewrite the five dashed titles in `lib/Settings/credential-providers.json` in sentence case without dashes; keep every identifier and key; bump the catalogue version to 1.11.1

## 2. Translated titles
- [x] 2.1 Translate each title in `CredentialController::providers()` through `IL10N`, falling back to the identifier when an entry has no title
- [x] 2.2 Add the Dutch titles to the backend bundle `l10n/nl.json`, appended without re-sorting

## 3. Tests
- [x] 3.1 `CredentialProviderLabelsTest`: every catalogue title, every endpoint title and every Dutch endpoint title is free of em-dashes and en-dashes; the five identifiers are still present; verified failing on the old code (3 of 3 tests red)
- [x] 3.2 Existing credential controller and catalogue tests stay green
