# Tasks: schema-metadata-i18n

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8.
     Acceptance criteria are plain bullets, not checkboxes. -->

## 1. Metadata resolution seam (the unit seam)

- [ ] 1.1 Implement `SchemaMetadataTranslator` with `resolve(string|array $value, string $requested, string $default): string` and `isMap()` (REQ-ORSMI-001, REQ-ORSMI-002)
  - Pure over its inputs (no DB, no request) so it is provable by PHPUnit: requested → base language → register default → first non-empty; a plain string returns itself; never returns an empty string or the raw map.

## 2. Entity + persistence

- [ ] 2.1 Hydrate and serialise map-valued `title`/`description` on `Schema` and on nested properties (REQ-ORSMI-001)
  - `lib/Db/Schema.php` — keep the full map in the properties/definition JSON; `hydrate()` must not flatten it (mirror the nested-property handling around `:1614`).
- [ ] 2.2 Always write the resolved default-language string to the scalar `title` column (REQ-ORSMI-003)
  - `NOT NULL` + `register_schemas_title_index` must keep working; never null/empty/JSON. Add a regression test that lists schemas after saving a map-titled one.

## 3. Read path

- [ ] 3.1 Resolve metadata using the existing `Accept-Language` negotiation (REQ-ORSMI-002)
  - Reuse the mechanism `LanguageMiddleware` / the content-i18n read path already applies; do NOT introduce a second negotiation. Default reads return plain strings.
- [ ] 3.2 Support `_metaLanguages=all` to return maps unresolved (REQ-ORSMI-004)
  - Used by schema editors and import/export so a round-trip preserves every language.

## 4. Validation

- [ ] 4.1 Reject non-BCP-47 keys and keys outside the register's `languages` (REQ-ORSMI-005)
  - Error message names the offending key; schema is not persisted. Empty `languages` disables the membership check but not the BCP 47 check.

## 5. Quality

- [ ] 5.1 PHPUnit for the translator, hydration/serialisation, the NOT NULL invariant and validation — min 75% on new code
  - Run in the `nextcloud:34.0.0-apache` container (host PHP is too old); all pre-existing tests stay green.
- [ ] 5.2 Documentation + `openspec validate schema-metadata-i18n --strict`
  - Document the accepted shapes, the fallback chain and the `_metaLanguages=all` escape hatch; note that leaf apps (e.g. DocuDesk, ConductionNL/docudesk#341) can then author `{"nl": …, "en": …}` titles.
