# Design: i18n API Language Negotiation

## Reuse analysis

- `lib/Middleware/LanguageMiddleware.php` already parses
  `Accept-Language` and the `_translations=all` query param; we add
  the new query lookups + write-side header in the same hook.
- `lib/Service/LanguageService.php` already exposes the
  `preferredLanguage` field + `parseAcceptLanguageHeader()`; we add
  setters/getters for the new `targetLanguage` (write-side) and
  `requestedLanguageSource` (introspection).
- `lib/Service/Object/TranslationHandler::normalizeTranslationsForSave`
  already wraps non-language-keyed bodies under the register's
  default language; we extend it to honour the target-language
  header.
- `lib/Db/Register::getDefaultLanguage()` is the existing default
  fallback; unchanged.

## Request-side resolution chain

```
1. ?_lang=<bcp47>          (canonical query param)
2. ?language=<bcp47>        (alias)
3. Accept-Language header  (RFC 9110)
4. Register default        (first element of Register.languages)
5. 'nl'                    (hardcoded fallback)
```

The middleware iterates this chain and stops at the first valid BCP-47
tag. Invalid input at any step (e.g. `?_lang=garbage`) MUST log a
warning AND fall through; we never 400 on a malformed language
parameter.

## Write-side wrap behaviour

Three input shapes; the handler dispatches based on which:

```php
// Shape A (legacy): scalar body, no header → wrap under register default
PATCH /api/.../obj-uuid
Content-Type: application/json
{ "title": "Hello" }
// → stored as { "title": { "nl": "Hello" } } when default is nl

// Shape B (new): scalar body + target-language header → wrap under target
PATCH /api/.../obj-uuid
Content-Type: application/json
X-Translation-Target-Language: en
{ "title": "Hello" }
// → stored as { "title": { "en": "Hello" } }

// Shape C (existing full-object): language-keyed body, no header → as-is
PATCH /api/.../obj-uuid
Content-Type: application/json
{ "title": { "nl": "Hallo", "en": "Hello" } }
// → stored as-is

// Shape D (CONFLICT): language-keyed body + header → 400
PATCH /api/.../obj-uuid
Content-Type: application/json
X-Translation-Target-Language: en
{ "title": { "nl": "Hallo", "en": "Hello" } }
// → 400 TranslationTargetConflictException
```

Shape D rejection is intentional: a client sending both is signalling
conflicting intent; failing fast surfaces the bug to the consumer.

## Why query overrides header

ADR-025's open-question 2 picked query-overrides-header. Rationale:

- Query parameters are explicit per-request override; users hit
  `?_lang=fr` to force French regardless of what their browser sends.
- `Accept-Language` is the browser-default fallback; it should
  always be the lower-priority fallback for explicit requests.
- Mixing `?_lang=fr` + `Accept-Language: nl` is the typical "user
  switched language in the UI but their browser still has Dutch as
  default" case — query winning is what the user expects.

## Fallback laxity

Per ADR-025 open-question 7, OR accepts any BCP-47 tag on the query
parameter and lets the existing fallback chain resolve. A request for
`?_lang=zz-NEVER` resolves through:

1. No `zz-NEVER` row → fallback to register default (`nl`)
2. Response: `Content-Language: nl`, `X-Content-Language-Fallback: true`

The client gets a usable response and clear signalling that fallback
happened. No 406; no 300. This matches `Accept-Language` behaviour.

## Open design questions

1. **Should the header be `X-Translation-Target-Language` or
   `X-Content-Language` (as a write-side echo)?** Picked the explicit
   prefix to avoid confusion with the response `Content-Language`.
   `X-Translation-Target-Language` is verbose but unambiguous.
2. **What if the target-language header is set on a GET request?**
   Currently the middleware only consumes it on POST/PUT/PATCH
   (Phase 2 task). On GET it's silently ignored — defer until a use
   case surfaces.
3. **Should the request-side query be `_lang` or `lang` (no
   underscore)?** Picked `_lang` to match the existing
   `_translations=all` convention in OR's URL parameters. The bare
   `?language=` alias is offered for clients that find `_lang`
   unfamiliar.
