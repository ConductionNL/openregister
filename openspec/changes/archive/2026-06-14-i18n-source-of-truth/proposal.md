# i18n Source of Truth

## Why

OpenRegister's translation system today treats every language as a peer.
The `openregister_translations` sidecar table tracks
`(object_uuid, property, language, value, status)`; given a value in
English a reader cannot tell whether English is the canonical original
or a translation derived from Dutch. `register-i18n/spec.md` line 221
calls for an automatic "outdated" status flip when the source value
changes, but the column to identify the source doesn't exist.

The OR-abstraction audit (R4, 2026-05-03) flagged this as the blocker
preventing opencatalogi (and decidesk, procest) from adopting i18n at
scale. Hydra ADR-025 (Proposed, 2026-05-03) ratified the
source-of-truth contract; this change is the first half of its
implementation. The companion change `i18n-api-language-negotiation`
covers the API-side contract.

## What Changes

- Add a `sourceLanguage: string` schema property modifier on
  translatable properties:
  `{"omschrijving": {"type": "string", "translatable": true,
  "sourceLanguage": "nl"}}`. If omitted, defaults to
  `Register.defaultLanguage` (first element of `Register.languages`).
- Add an optional object-level override:
  `_translationMeta.<property>.sourceLanguage = "<bcp47>"` on the
  object body. Used when content is authored in a non-default
  language for that specific object.
- Add `source_language VARCHAR(16) NOT NULL` column to
  `openregister_translations`. New migration
  `Version1Date20260520120000.php` adds the column with a back-fill
  job that infers `Register.defaultLanguage` for every existing row.
- Update `TranslationProjectionService` to populate `source_language`
  from the schema/object resolution above.
- Extend `TranslationStatusService` with
  `markDerivedTranslationsOutdated(string $objectUuid, string $property,
  string $sourceLanguage): int` that flips every non-source-language
  Translation row to status `outdated` when the source value changes.
- Wire the source-change automation into `SaveObject` so that
  modifying a translatable property in the source language triggers
  the outdated flip on every derived translation for that object +
  property.
- Extend `TranslationController` to accept new query filters:
  `?sourceLanguage=<bcp47>`, `?isOutOfDate=true`,
  `?compareToSource=true` (returns both source and translation values
  side-by-side for review tooling).
- Extend `RenderObject` to attach an `_meta.languageMeta` envelope
  field when `?_translationMeta=true` is requested. The envelope
  shape:
  ```json
  {
    "_meta": {
      "languageMeta": {
        "<property>": {
          "served": "en",
          "sourceLanguage": "nl",
          "isSource": false,
          "status": "approved"
        }
      }
    }
  }
  ```
- Add `X-Source-Language: <bcp47>` response header to every endpoint
  that returns object content. The companion `i18n-api-language-negotiation`
  change handles the rest of the header contract.
- Update `lib/AppInfo/Application.php` PHPDoc to list
  `TranslationStatusService::markDerivedTranslationsOutdated` in the
  public service surface.

## Problem

Without a source-language identifier, three things break:

1. **Translation freshness** — when the Dutch source is edited,
   `TranslationStatusService` cannot infer which derived translations
   went stale. The `outdated` status defined in the spec stays
   manual-only.
2. **Editorial workflow** — translators hitting `GET /api/translations/...?compareToSource=true`
   want both the source value and their target value side-by-side;
   today there's no flag distinguishing them.
3. **Consumer apps** — opencatalogi's planned per-property language
   editor (`PageContentForm`, `MenuItemForm`, `PublicationDetailPage`)
   needs to render an indicator like "Original (Dutch)" vs
   "Translation (out of date)" — neither piece of information is
   available without this change.

## Proposed Solution

Schema-property + DB-column + render-metadata, all opt-in. Existing
schemas without `sourceLanguage` keep working; the back-fill job
infers a sensible default. New schemas declaring `sourceLanguage`
gain the full freshness-tracking + render-metadata behaviour. The API
side ships in the companion change so the two can deliver
independently.

## Out of scope

- Request-side language negotiation (`?_lang=`,
  `X-Translation-Target-Language`) — companion change
  `i18n-api-language-negotiation`.
- Per-tenant override of `Register.defaultLanguage` — sufficient
  flexibility comes from the property + object-level overrides; revisit
  if multi-tenant customers need per-tenant defaults.
- Frontend language-editing UI — opencatalogi's adoption change
  delivers it (consumes this contract).

## See also

- Hydra ADR-025 (i18n source-of-truth + API language negotiation) —
  the parent decision.
- `openregister/openspec/specs/register-i18n/spec.md` — the
  pre-existing partial i18n spec; this change advances it to
  source-of-truth-aware.
- `.claude/audit-2026-05-03/research/R4-or-i18n-source-of-truth.md` —
  the audit research informing this change.
