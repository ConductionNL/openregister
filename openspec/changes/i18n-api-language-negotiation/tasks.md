# Tasks: i18n API Language Negotiation

## Phase 1 — Request-side query parameters

- [~] Update `lib/Middleware/LanguageMiddleware::beforeController` to — deferred to downstream cycle / fleet-wide adoption (handoff)
      check for `?_lang=` and `?language=` query parameters before
      the `Accept-Language` header. Resolution order:
      1. `?_lang=<bcp47>` (canonical)
      2. `?language=<bcp47>` (alias)
      3. `Accept-Language` (existing)
      4. Default (existing — register first language, or `nl`)
- [~] Add a `LanguageService::setRequestedLanguageSource(string — deferred to downstream cycle / fleet-wide adoption (handoff)
      $source)` enum-style flag (`'query' | 'header' | 'default'`)
      so consumers can introspect WHY a particular language was
      chosen. Used by the `X-Source-Language` setter in the companion
      change for response diagnostics.
- [~] Validate that the resolved language is a syntactically valid — deferred to downstream cycle / fleet-wide adoption (handoff)
      BCP-47 tag (basic regex). Invalid input MUST log a warning AND
      fall through to the next priority level (do NOT 400 — clients
      that mistype a tag should still get a usable response).

## Phase 2 — Write-side target-language header

- [~] Update `lib/Middleware/LanguageMiddleware::beforeController` to — deferred to downstream cycle / fleet-wide adoption (handoff)
      read `X-Translation-Target-Language` request header on POST /
      PUT / PATCH. Stash on `LanguageService::setTargetLanguage()`.
- [~] Update `lib/Service/Object/TranslationHandler::normalizeTranslationsForSave` — deferred to downstream cycle / fleet-wide adoption (handoff)
      to consume `LanguageService::getTargetLanguage()`:
      - If header is set AND the body has a scalar (non-language-keyed)
        translatable property → wrap under the target language.
      - If header is set AND the body has a full language-keyed object
        for that property → reject with `400 Bad Request` (conflict).
      - If header is unset → preserve current behaviour (wrap under
        register default).
- [~] Add `lib/Exception/TranslationTargetConflictException.php` for — deferred to downstream cycle / fleet-wide adoption (handoff)
      the conflict case; controller catches and returns `400` with a
      structured error body.

## Phase 3 — Spec + tests

- [~] Write `specs/i18n-api-language-negotiation/spec.md` with one — deferred to downstream cycle / fleet-wide adoption (handoff)
      Requirement per public surface (query precedence, header
      precedence, write-side header, conflict rejection, BCP-47
      validation).
- [~] Update `openregister/openspec/specs/register-i18n/spec.md` to — deferred to downstream cycle / fleet-wide adoption (handoff)
      reflect the new request-side contract; cross-reference this
      change.
- [~] Add `tests/Unit/Middleware/LanguageMiddlewareTest.php` covering: — deferred to downstream cycle / fleet-wide adoption (handoff)
      `?_lang=` overrides Accept-Language, `?language=` alias,
      conflict (both set) → query wins, invalid `?_lang=` falls
      through to next priority, default fallback when nothing set.
- [~] Add `tests/Unit/Service/Object/TranslationHandlerTargetLanguageTest.php` — deferred to downstream cycle / fleet-wide adoption (handoff)
      covering: scalar body + header → wrap under target, full
      language object + header → 400 conflict, scalar body without
      header → wrap under register default (legacy).
- [~] Add Newman collection — deferred to downstream cycle / fleet-wide adoption (handoff)
      `tests/integration/openregister-i18n-api-language-negotiation.postman_collection.json`
      hitting: GET with `?_lang=`, GET with `?language=`, GET with
      both query + Accept-Language (query wins), POST with
      `X-Translation-Target-Language`, conflict POST returning 400.
- [~] Wire the Newman collection into — deferred to downstream cycle / fleet-wide adoption (handoff)
      `tests/newman/run-all.sh::DOMAIN_ORDER` after the
      `i18n-source-of-truth` collection (ordering dependency on the
      companion change shipping first; gracefully skips if companion
      hasn't shipped).

## Phase 4 — Documentation

- [~] Update `docs/i18n.md` with the request-side contract, the — deferred to downstream cycle / fleet-wide adoption (handoff)
      header table, and the request-precedence ordering.
- [~] Add a `docs/i18n.md#client-snippets` section showing canonical — deferred to downstream cycle / fleet-wide adoption (handoff)
      curl + axios + Postman invocations for: read in a language,
      write to a target language, edit a translation without
      touching the source.
- [~] Cross-reference this change from — deferred to downstream cycle / fleet-wide adoption (handoff)
      `hydra/openspec/architecture/adr-025-i18n-source-of-truth.md`'s
      "Implementation reference" section once shipped (alongside the
      companion).
