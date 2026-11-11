# Design: modelling-field-names-per-language

Read at openregister development 555af7212.

## Context

- Property modifiers are declared once in
  `PropertyValidatorHandler::MODIFIERS` (`lib/Service/Schemas/PropertyValidatorHandler.php:471`),
  with `value` type and a sentence. `PropertyVocabulary` publishes them from
  there, and `PropertyVocabularyTest` fails when the two drift.
- `conceptScheme` was added the same way (`:523-527`).
- The register i18n work (archived `2026-03-21-register-i18n`) translates object
  CONTENT: a `translatable: true` property stores values per language, and the
  object read projects them to the negotiated language
  (`lib/Service/Object/QueryHandler.php:643`). Nothing translates a property's
  NAME.
- nextcloud-vue renders a field label as `tr(prop.title || key)`
  (`src/utils/schema.js:599` in nextcloud-vue v2.56.0), which only finds
  names shipped in an app's l10n files.

## D-1: `titles` beside `title`, not instead of it

`title` stays the default name, so every reader that ignores `titles` keeps
working. `titles` is `{ "<bcp47>": "<name>" }`. The modifier table entry is
`'titles' => ['value' => 'object', ...]`, and `validateProperty()` checks each
key against a BCP 47 pattern and each value for a non-empty string.

## D-2: projection on read is opt-in

A schema read with `_lang` or `Accept-Language` replaces each property's
`title` with `titles[<lang>]` when present, trying the base language
(`nl` for `nl-BE`) second, and leaves `titles` in place. Without a language,
the stored schema is returned untouched, so an editor round-trips it. The
object read already negotiates language the same way, so one helper serves
both.

## D-3: export and import carry it

`titles` is part of the property JSON, so configuration export and import
carry it with no extra code. A test proves it survives a round trip, because
an unknown key has been dropped on import before.

## D-4: the editor

The property form shows one input per language in the register's configured
languages, with the default `title` first. An empty input removes that key.

## Risks

- A long `titles` map on every property grows the schema. Names are short; no
  cap is set beyond the register's configured languages in the editor.
