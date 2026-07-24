# Proposal: schema-metadata-i18n

## Why

OpenRegister ships a rich i18n stack for **object content** — `translatable: true`
properties whose *values* are stored as per-language maps, negotiated with
`Accept-Language`, with source-language tracking and out-of-date detection
(`register-i18n`, `i18n-source-of-truth`, `i18n-api-language-negotiation`).

None of it applies to **schema metadata**. A schema's `title`/`description`, and
the `title`/`description` of every property, are plain scalars:

- `lib/Db/Schema.php:144` — `protected ?string $title = null;`
- `oc_openregister_schemas.title` — `character varying(255) NOT NULL`
- `register-i18n/spec.md` states outright that it is "distinct from the app UI
  string translations … which handle Nextcloud `IL10N` / `t()` for interface
  labels"

Those property titles are exactly what consuming apps render as **column headers
and form labels** on every manifest `type:"index"` page. Because the title is a
single scalar, whatever language it was authored in is what every user sees.

Live evidence (DocuDesk on the shared dev instance, 2026-07-24, admin account
`lang=en`, Nextcloud chrome correctly in English):

```
/apps/docudesk/templates  →  NAAM · CATEGORIE · PAGINAFORMAAT · NAMESPACE · BESCHRIJVING
/apps/docudesk/signing    →  DOCUMENTNAAM · MODUS · NIVEAU · STATUS · PROVIDER · DEADLINE
```

because `docudesk_register.json` authors them in Dutch (`"name": {"title": "Naam"}`,
`"signatureLevel": {"title": "Niveau"}`, …). Tracked as ConductionNL/docudesk#341.

The leaf app cannot fix this properly on its own: authoring the titles in English
merely inverts the problem and strands Dutch users. ADR-005 mandates NL + EN for
every Conduction app, and SDG Regulation (EU) 2018/1724 — already cited as the
driver for `register-i18n` — requires English availability for cross-border
services. A label is as user-facing as a value; the same guarantee has to cover it.

`Register.languages` (`lib/Db/Register.php:255`) already declares the language set
per register, so the vocabulary for this exists — it is simply never consulted for
metadata.

## What Changes

- Accept a **per-language map** for schema `title` and `description`, and for each
  property's `title` and `description`: `{"nl": "Naam", "en": "Name"}`. A plain
  string keeps working unchanged and is treated as being in the register's
  default language.
- Resolve metadata to a single language on read using the **existing**
  `Accept-Language` negotiation, with a deterministic fallback chain: requested
  language → base language (`nl` for `nl-NL`) → register default (first entry of
  `Register.languages`, else `nl`) → first non-empty entry.
- Persist the map without a destructive migration: the scalar column keeps the
  resolved default-language value (so existing readers and the
  `register_schemas_title_index` btree keep working) while the full map lives in
  the schema's JSON definition.
- Expose the unresolved map on demand (`?_metaLanguages=all`) so schema editors
  and import/export round-trip every language rather than collapsing to one.
- Validate on write: reject language keys that are not BCP 47 and not present in
  the register's `languages`, mirroring how content i18n validates.

## Impact

- **Affected code**: `lib/Db/Schema.php` (`title`/`description` hydration +
  `jsonSerialize`, nested property hydration at `:1614`), the schema read path
  used by `SchemasController`, and whichever service applies `Accept-Language`
  for content today (reuse, do not duplicate, `LanguageMiddleware`).
- **Backwards compatible by construction**: every existing schema keeps a scalar
  title and resolves to itself. No consumer app is required to change.
- **Consumers**: DocuDesk can then supply `{"nl": …, "en": …}` titles and close
  docudesk#341; the same fix lands for every app rendering manifest index pages.
- **Related**: docudesk#338 (index pages render no visible heading) is a
  different defect in the shared renderer and is not addressed here.
- **Risk**: the schema `title` column is `NOT NULL` and indexed — the resolved
  scalar must always be written, never left null, or schema listing breaks.
