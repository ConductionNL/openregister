# ADR-025: i18n source-of-truth and API language negotiation

## Status
Proposed

## Date
2026-05-03

## Context

OpenRegister already implements a partial i18n stack — `LanguageService`,
`LanguageMiddleware`, `TranslationHandler`, `TranslationProjectionService`,
`TranslationStatusService`, `Translation` entity — and exposes it via
`Accept-Language` request parsing + `Content-Language` /
`X-Content-Language-Fallback` response headers. The
[register-i18n spec](../../../openregister/openspec/specs/register-i18n/spec.md)
calls this work "partially implemented".

Two concrete gaps surfaced from the OR-abstraction audit (R4 + R5,
2026-05-03) that block consumer apps (notably opencatalogi for Pages /
Menu items / Publications / Themes / Glossary) from adopting i18n:

### Gap 1 — peer-language model has no source-of-truth

The Translation table tracks `(object_uuid, property, language, value,
status)`. Every language is treated symmetrically. A reader of an
English value cannot tell whether English is the canonical original or
a translation derived from Dutch. `TranslationStatusService` knows
"approved" / "draft" / "machine_translated" / "human_reviewed" but has
no automatic "outdated" trigger when the source value changes — the
spec calls for this on line 221 but the column doesn't exist.

### Gap 2 — request-side language negotiation is incomplete

`Accept-Language` works; `?_lang=` / `?language=` / `?lang=` is not
recognised — only `_translations=all` toggles the all-languages path.
PATCH/PUT bodies are silently treated as updates to the register's
default language; clients that want to send an English-only update
have to send the full language-keyed object.

### Why now

Three apps are about to adopt i18n at scale (opencatalogi multi-language
content, decidesk policy translations, procest bezwaar / parafering
notifications). Without a stable source-of-truth contract and a
predictable API surface, each app would invent its own conventions.

## Decision

Adopt the **source-of-truth model** and tighten the API contract. Two
linked openspec changes implement it:

1. **`openregister/openspec/changes/i18n-source-of-truth/`** —
   schema-level + DB-level + render-metadata work
2. **`openregister/openspec/changes/i18n-api-language-negotiation/`** —
   request/response contract tightening

### Source-of-truth contract

- **Schema property** — translatable properties MAY declare
  `sourceLanguage: "<bcp47>"` (e.g.
  `{"omschrijving": {"type": "string", "translatable": true,
  "sourceLanguage": "nl"}}`). If omitted, defaults to
  `Register.defaultLanguage`.
- **Object-level override** — objects MAY declare
  `_translationMeta.<property>.sourceLanguage = "<bcp47>"` to override
  the schema default for that single object. Rare but useful for
  English-authored content.
- **`Translation.sourceLanguage` column** — the
  `openregister_translations` table gains a non-null
  `source_language VARCHAR(16)` column. On projection, populated from
  the schema/object resolution above.
- **Render metadata** — the response body MUST expose, for each served
  translatable value:
  - which language was actually served (`Content-Language` header,
    already present)
  - which language is the source-of-truth (new `X-Source-Language`
    response header)
  - whether the served language equals the source (i.e. "this is the
    original") or is a translation (existing
    `X-Content-Language-Fallback` header expanded; new optional
    JSON envelope `_meta.languageMeta` for property-level granularity
    when `_translationMeta=true` is requested)
- **Source-change automation** — when a translatable property in the
  source language is updated, OR MUST flip every non-source-language
  Translation row for that property to status `outdated` via
  `TranslationStatusService::markDerivedTranslationsOutdated()`.
- **Query filters** — `TranslationController` search MUST accept
  `?sourceLanguage=<bcp47>`, `?isOutOfDate=true`, and
  `?compareToSource=true`.

### API language negotiation contract

- **Request precedence** (highest first):
  1. Query parameter `?_lang=<bcp47>` (canonical name)
  2. Query parameter `?language=<bcp47>` (alias)
  3. Header `Accept-Language: <RFC 9110>`
  4. Default — register's default language (first element of
     `Register.languages`); if none, `nl`
- **Response headers**:
  - `Content-Language: <bcp47>` — language served (already implemented)
  - `X-Content-Language-Fallback: true` — set when fallback was used
    (already implemented)
  - `X-Source-Language: <bcp47>` — canonical source language for the
    translatable properties on this response (new)
- **Missing translation** — keep the silent 200-fallback behaviour;
  do not 406. The `X-Content-Language-Fallback` header signals it.
- **Write-side disambiguation** — PATCH/PUT/POST MAY send
  `X-Translation-Target-Language: <bcp47>` to indicate the body updates
  that target language. Default (no header) preserves current behaviour
  (body merged into the register's default language). Bodies MAY also
  send full language-keyed objects directly (existing path); the
  header is the ergonomic shortcut.
- **Bulk listings** — `GET /api/objects/{r}/{s}` honours the same
  request precedence and returns each object resolved to that language.
- **Validation laxity** — the API accepts any BCP-47 code on
  `?_lang=`; if the register doesn't support it, the fallback chain
  resolves and the response declares the language actually served.

## Consequences

- Two parallel openspec changes land:
  `openregister/openspec/changes/i18n-source-of-truth/` and
  `openregister/openspec/changes/i18n-api-language-negotiation/`.
  They share a migration (the `source_language` column) but otherwise
  decouple; the API change can ship before the DB change if needed.
- Client SDKs (frontend stores in `@conduction/nextcloud-vue`,
  consumer apps, Newman test collections) gain a reliable way to ask
  for and detect a specific language.
- opencatalogi's multi-language editing UI work (the empty state R3
  surfaced on Pages / Menus / Publications / Themes / Glossary) becomes
  a straight implementation task — it can rely on this ADR's contract
  rather than inventing one.
- TranslationStatusService becomes an active workflow engine (the
  source-change → outdated trigger), not just a passive projector.
- A migration must back-fill `source_language` for existing
  Translation rows. Default = `Register.defaultLanguage`; back-fill
  job runs once at upgrade time.
- A successor ADR should pin per-property override granularity (currently
  the decision sketch allows schema, object, and register levels — open
  question whether all three are worth the complexity; flagged in O4
  open questions).
- Consumer apps that only care about the simple case (one language,
  bundled at install time) keep working unchanged — every new field
  and header is opt-in.

## See also

- `openregister/openspec/specs/register-i18n/spec.md` — the existing
  partial spec that this ADR formalises into source-of-truth + API
  negotiation contracts.
- ADR-007 (i18n) — the pre-existing "Dutch + English minimum"
  baseline. This ADR layers source-of-truth + API contract on top.
- `feedback_i18n-requirement.md` — operational rule that all apps
  must support nl + en.
- ADR-022 (apps consume OR abstractions) — once this ADR lands,
  consumer apps consume the contract instead of building their own.
