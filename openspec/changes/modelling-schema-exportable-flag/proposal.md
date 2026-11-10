---
kind: code
depends_on: []
---

# Proposal: modelling-schema-exportable-flag

## Summary

An administrator marks a schema as exportable, and the Export menu appears on
every list page that allows export. OpenRegister keeps the flag when a schema
is saved or imported, whether an app writes it at the top of the schema or in
its configuration, and returns it on every schema read.

## Halves this closes

Two merged changes in other repositories depend on OpenRegister keeping a
schema `exportable` flag. Neither has a row in OpenRegister's matrix; the owner
moves pass of 28 Sep 2026 handed both here.

- nextcloud-vue `index-export-follows-the-page` (nextcloud-vue `development`
  e487bc8), covering humaniq `rep-export`, buildiq `data-export-records` and
  stackiq `ins-export-list`: "OpenRegister keeps neither place today. It drops
  an unknown top-level field (`lib/Db/Schema.php` hydrate ...), and it also
  drops an unknown `configuration` key: `setConfiguration()` keeps only the
  keys `validateConfigurationEntry()` allowlists (`lib/Db/Schema.php:2682-2697`
  and `:2856-2928` at `555af72`), and `exportable` is not among them. ... The
  OpenRegister half is to add `exportable` to the boolean configuration keys
  (`$boolFields`, `:2683`), or to serve a top-level field. Until one of them
  ships, the Export menu does not appear on a real instance."
- stackiq `insight-exports-and-custom-reports` (stackiq `development`
  d22033a): "Storing and serving the schema `exportable` flag. That is
  OpenRegister's half: a field on `Schema`, kept on import and returned by the
  schema API. Until it lands the four list pages keep what they have."

The nextcloud-vue lane recorded the same drop as a defect in its FINAL, and
stackiq's design D2 was corrected for it.

## What changes

- `exportable` joins the boolean configuration keys, so
  `configuration.exportable` survives a save.
- A top-level `exportable` in a schema payload or an imported register is
  folded into `configuration.exportable`, the way `x-openregister-*` blocks
  already are.
- A schema read returns `configuration.exportable` and a top-level
  `exportable` mirroring it, so readers of either place see the same value.
  Nextcloud-vue reads both (`CnIndexPage` `showExportMenu()`).

## Out of scope

- Who may export. The export route's own rights decide; the flag only says the
  schema offers it.

## Impact

- `lib/Db/Schema.php` (`$boolFields` at `:2683`, the fold in `hydrate()` at
  `:1875-1896`, `jsonSerialize()` at `:2028`).
