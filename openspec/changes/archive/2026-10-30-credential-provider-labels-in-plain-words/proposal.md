---
kind: code
depends_on: []
---

## Why

The "Add credential" picker reads its provider names from
`GET /apps/openregister/api/credentials/providers`, which returns each catalogue entry's `title`.
Five titles carried an em-dash ("Anthropic (Claude) — API key", "Anthropic (Claude Max) — OAuth
subscription", "Anthropic (Claude Max) — CLI subscription", "GitHub — repository push…",
"Bluesky (AT Protocol) — preview"), which the Conduction voice bans in anything a person reads.
Every title also shipped in English only, whatever the reader's language, because the endpoint
passed the raw catalogue string through.

Found by the round 4 cloud check of the pipelinq review (lane R5-OR).

## What changes

- The five dashed titles in `lib/Settings/credential-providers.json` are rewritten in plain words,
  sentence case, no dashes. Provider identifiers and keys do not move. Catalogue version 1.11.0
  becomes 1.11.1.
- `CredentialController::providers()` translates each title through the app's `IL10N`, so a Dutch
  reader gets Dutch titles. The Dutch titles live in the backend bundle `l10n/nl.json`. An entry with
  no title still falls back to its identifier, which is never translated.
- A unit test reads the real catalogue, the real endpoint and the real Dutch bundle and fails on any
  em-dash or en-dash in a title.

## Out of scope

- The developer-facing descriptions in `lib/Settings/credential_broker_register.json` (schema
  descriptions that cite ADRs and RBAC operators, and two seeded example names). Changing them needs
  a register version bump and a re-import; listed as a follow-up.
- The picker's own chrome, which lives in `@conduction/nextcloud-vue` (`CnCredentials`), not here.
- Locales other than English and Dutch fall back to the English title, as any missing key does.

## Impact

- `lib/Settings/credential-providers.json` (titles and version only; no allow-rule, host or auth
  scheme changes)
- `lib/Controller/CredentialController.php` (optional trailing `IL10N` constructor argument,
  autowired by Nextcloud's container)
- `l10n/nl.json`
- `tests/Unit/Controller/CredentialProviderLabelsTest.php`
