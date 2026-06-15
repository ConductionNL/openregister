# i18n API Language Negotiation

## Why

OpenRegister's HTTP API parses `Accept-Language` headers and returns
`Content-Language` + `X-Content-Language-Fallback`, but the
request-side surface is incomplete: there's no `?_lang=` query
parameter for explicit override (only `_translations=all` is
recognised), and PATCH/PUT bodies are silently treated as updates to
the register's default language with no way for clients to indicate
they're sending an English-only edit.

The OR-abstraction audit (R5, 2026-05-03) flagged this as the
practical adoption blocker for opencatalogi (every UI language
selector needs `?_lang=`), decidesk (translation editing needs the
write-side header), and any external integration that wants to
request a specific language without manipulating its `Accept-Language`
header.

Hydra ADR-025 (Proposed, 2026-05-03) ratified the API contract; this
change is the second half of its implementation. The companion change
`i18n-source-of-truth` covers the schema/DB/render-metadata side.

## What Changes

- Extend `lib/Middleware/LanguageMiddleware::beforeController` to
  honour query parameters, in this priority order:
  1. `?_lang=<bcp47>` (canonical name)
  2. `?language=<bcp47>` (alias)
  3. `Accept-Language: <RFC 9110>` header (current; lower priority)
  4. Default — register's first language, or `nl`
- Extend `LanguageMiddleware::afterController` to read an optional
  `X-Translation-Target-Language` request header on write requests
  (POST / PUT / PATCH); when present, write logic MUST treat the
  request body as an update to that target language instead of the
  register default.
- Update `lib/Service/Object/TranslationHandler::normalizeTranslationsForSave`
  to consume the `X-Translation-Target-Language` value so requests
  like `PATCH /api/objects/{r}/{s}/{id} { "title": "Welcome" }` with
  `X-Translation-Target-Language: en` wrap as `{ title: { en: "Welcome" } }`
  instead of the register's default-language wrap.
- Add a new validation helper that confirms a body either declares a
  full language-keyed object (existing path — `{ title: { nl: ..., en: ... }}`)
  OR a single-language scalar paired with `X-Translation-Target-Language`
  (new path) OR a single-language scalar paired with no header (legacy
  path — interpreted as register default). Conflicting bodies (full
  language object + header) MUST return `400 Bad Request`.
- Document the precedence and write-side contract in
  `register-i18n/spec.md` (existing spec) and in this change's spec.
- Add a Newman collection covering: `?_lang=` overrides
  Accept-Language; `?language=` alias works; both query + header
  resolves to query winning; missing-translation 200 fallback;
  `X-Translation-Target-Language` write-side wrap; conflicting body +
  header rejection.

## Problem

The current API forces consumers into Accept-Language manipulation,
which is awkward for:

1. **UI language switchers** — clicking "Switch to English" should
   issue `GET /api/objects/.../?_lang=en`; today the UI has to mutate
   `axios.defaults.headers.common['Accept-Language']` globally, which
   leaks across components.
2. **Translation editors** — editing only the English translation
   means sending the full `{ nl: "...", en: "Welcome" }` object
   (forces the editor to know the Dutch source); sending just
   `{ "title": "Welcome" }` today silently overwrites the Dutch
   source. The header gives editors a clean shortcut.
3. **External clients** — non-browser HTTP clients (cron jobs,
   integrations, Newman tests) want explicit query parameters; today
   they have to format proper `Accept-Language` strings with quality
   factors.

## Proposed Solution

Extend the existing middleware and translation handler. No DB
migration; no breaking change to current consumers (`Accept-Language`
keeps working). New behaviour is opt-in via either the query param or
the new write-side header.

The companion `i18n-source-of-truth` change ships independently; the
two share no code dependency, just a logical pairing under ADR-025.

## Out of scope

- The schema-level `sourceLanguage` modifier and the
  `openregister_translations.source_language` column — companion
  change `i18n-source-of-truth`.
- An `X-Source-Language` response header on write responses
  (informational only on reads; the write-side echo is not specified
  by ADR-025).
- A `?_languageVariants=title,description` mixed-mode parameter to
  return a single language for some properties and full language
  objects for others — defer to a successor change once the basic
  contract has consumers.
- 406 Not Acceptable when zero languages match — keep the silent 200
  fallback per ADR-025; revisit if integrations report it as a
  blocker.

## See also

- Hydra ADR-025 (i18n source-of-truth + API language negotiation) —
  the parent decision.
- `openregister/openspec/changes/i18n-source-of-truth/` — companion
  change.
- `.claude/audit-2026-05-03/research/R5-or-api-language-negotiation.md` —
  the audit research informing this change.
