i18n-api-language-negotiation
---
status: draft
---
# i18n API Language Negotiation

## Purpose

Implement the request/response API contract half of hydra ADR-025: a
canonical `?_lang=` query parameter (with `?language=` alias) for
explicit per-request language override; a write-side
`X-Translation-Target-Language` header to disambiguate which
language a scalar PATCH/PUT/POST body targets; explicit precedence
between query, header, and `Accept-Language`; lax fallback that never
406s. The companion change `i18n-source-of-truth` covers the
schema/DB/render-metadata side.

## ADDED Requirements

### Requirement: The system MUST accept `?_lang=` and `?language=` query parameters

`LanguageMiddleware::beforeController` MUST honour these query parameters as the highest-priority source for the requested language. `?_lang=` is the canonical name; `?language=` is an accepted alias. When both are present on the same request, `?_lang=` wins.

#### Scenario: Resolve language from `?_lang=` query parameter
- GIVEN a request `GET /api/objects/r/s/uuid?_lang=fr`
- AND the client also sends `Accept-Language: nl`
- WHEN the middleware resolves
- THEN `LanguageService::getPreferredLanguage()` MUST return `"fr"`
- AND `LanguageService::getRequestedLanguageSource()` MUST return `"query"`

#### Scenario: `?language=` alias works identically
- GIVEN a request `GET /api/objects/r/s/uuid?language=fr`
- WHEN the middleware resolves
- THEN `LanguageService::getPreferredLanguage()` MUST return `"fr"`
- AND `LanguageService::getRequestedLanguageSource()` MUST return `"query"`

#### Scenario: `?_lang=` wins over `?language=` on conflict
- GIVEN a request `GET /api/objects/r/s/uuid?_lang=en&language=fr`
- WHEN the middleware resolves
- THEN `LanguageService::getPreferredLanguage()` MUST return `"en"` (canonical wins)

### Requirement: Resolution precedence MUST be query → header → register-default → 'nl'

When multiple sources of language are available, the middleware MUST resolve in this order, stopping at the first valid BCP-47 tag:

1. `?_lang=<bcp47>` query parameter
2. `?language=<bcp47>` query parameter (alias)
3. `Accept-Language` header (existing RFC 9110 parsing)
4. Register default (`Register::getDefaultLanguage()` — first element of `Register.languages`)
5. Hardcoded fallback `"nl"`

#### Scenario: Default fallback when nothing is set
- GIVEN a request with no `?_lang=`, no `?language=`, and no `Accept-Language`
- AND the resolved register has `languages = ["nl", "en"]`
- WHEN the middleware resolves
- THEN `LanguageService::getPreferredLanguage()` MUST return `"nl"` (register default)
- AND `LanguageService::getRequestedLanguageSource()` MUST return `"default"`

#### Scenario: Hardcoded `nl` fallback when register has no languages
- GIVEN a request with no language hints
- AND the resolved register's `languages` field is empty/null
- WHEN the middleware resolves
- THEN `LanguageService::getPreferredLanguage()` MUST return `"nl"`
- AND `LanguageService::getRequestedLanguageSource()` MUST return `"default"`

### Requirement: Invalid BCP-47 tags on query parameters MUST fall through, not 400

If `?_lang=` or `?language=` is set but does NOT match the BCP-47 syntax (basic regex `^[a-z]{2,3}(-[A-Z]{2})?$`), the middleware MUST log a warning AND continue resolution down the priority chain. The request MUST NOT 400; the client receives a usable fallback response.

#### Scenario: Invalid `?_lang=` falls through to Accept-Language
- GIVEN a request `GET /api/objects/r/s/uuid?_lang=GARBAGE_TAG`
- AND `Accept-Language: en`
- WHEN the middleware resolves
- THEN a `LoggerInterface::warning()` MUST be emitted: `Invalid ?_lang value 'GARBAGE_TAG' — falling through`
- AND `LanguageService::getPreferredLanguage()` MUST return `"en"` (Accept-Language)
- AND the request MUST NOT 400

### Requirement: The write-side `X-Translation-Target-Language` header MUST be honoured on POST/PUT/PATCH

When a write request (POST / PUT / PATCH) carries `X-Translation-Target-Language: <bcp47>`, OR MUST treat scalar (non-language-keyed) body values for translatable properties as updates to the target language. The header MUST NOT affect read requests (GET / HEAD).

#### Scenario: Target-language header wraps scalar body
- GIVEN a PATCH request body `{"title": "Welcome"}`
- AND the request carries `X-Translation-Target-Language: en`
- AND the schema declares `title` as translatable
- WHEN OR persists the change
- THEN the stored object MUST have `title.en = "Welcome"`
- AND `title.nl` (the register default) MUST be unchanged

#### Scenario: Target-language header on GET is silently ignored
- GIVEN a GET request with `X-Translation-Target-Language: en`
- WHEN OR resolves
- THEN the header MUST be silently ignored (no warning, no error)
- AND `LanguageService::getTargetLanguage()` MUST return null

### Requirement: Conflict between full language-object body + target-language header MUST 400

If a write request body sends a full language-keyed object for a translatable property AND the request also carries `X-Translation-Target-Language`, OR MUST return `400 Bad Request` with a `TranslationTargetConflictException`-shaped error body.

#### Scenario: Conflict triggers 400
- GIVEN a PATCH body `{"title": {"nl": "Hallo", "en": "Welcome"}}`
- AND the request carries `X-Translation-Target-Language: en`
- WHEN OR receives the request
- THEN OR MUST return status `400 Bad Request`
- AND the response body MUST include `error.code: "TRANSLATION_TARGET_CONFLICT"`, `error.property: "title"`, `error.targetLanguage: "en"`

### Requirement: Legacy scalar-body without header MUST preserve current behaviour

When a write request sends a scalar body for a translatable property AND no `X-Translation-Target-Language` header, OR MUST wrap the value under the register's default language (existing behaviour). This preserves backwards compatibility with consumers that haven't adopted the new header.

#### Scenario: Legacy scalar body wraps under register default
- GIVEN a PATCH body `{"title": "Welcome"}`
- AND no `X-Translation-Target-Language` header
- AND the register's `defaultLanguage = "nl"`
- WHEN OR persists the change
- THEN the stored object MUST have `title.nl = "Welcome"` (existing behaviour)

### Requirement: Both query parameter and header MAY appear on a single request

When both a query parameter (`?_lang=` / `?language=`) and the `X-Translation-Target-Language` header appear on a write request, OR MUST treat them independently:

- The query parameter governs the language of the *response* (controls how OR renders the response object).
- The header governs the language of the *body* (controls how OR interprets the request body).

These two surfaces are distinct contracts and MUST NOT influence each other.

#### Scenario: Read in English while writing the German translation
- GIVEN a PATCH request `PATCH /api/objects/r/s/uuid?_lang=en`
- AND request body `{"title": "Willkommen"}`
- AND `X-Translation-Target-Language: de`
- WHEN OR processes the request
- THEN the stored object MUST have `title.de = "Willkommen"` (header governs body)
- AND the response body MUST be rendered in English (query governs response)
- AND the response MUST include header `Content-Language: en`

### Requirement: Bulk listings MUST honour the same precedence

`GET /api/objects/{r}/{s}` (the bulk listing endpoint) MUST honour the same query-parameter precedence as single-object reads. Every object in the response MUST be rendered in the resolved language.

#### Scenario: Bulk list in a specific language
- GIVEN 5 objects with translatable `title` properties
- WHEN `GET /api/objects/r/s?_lang=fr` is called
- THEN every object's `title` in the response MUST be rendered in French (or fall through to the fallback chain per object)
- AND the response MUST include header `Content-Language: fr`
