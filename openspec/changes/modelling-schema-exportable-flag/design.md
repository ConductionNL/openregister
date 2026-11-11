# Design: modelling-schema-exportable-flag

Read at openregister development 555af7212.

## Context

- `Schema::setConfiguration()` keeps only allowlisted keys:
  `$boolFields = ['allowFiles', 'autoPublish', 'defaultAutoShare']`
  (`lib/Db/Schema.php:2683`) and a `$passThrough` list (`:2697`), checked by
  `validateConfigurationEntry()` (`:2856-2928`). `exportable` is in neither, so
  it is dropped.
- `hydrate()` (`:1856`) folds top-level `x-openregister-*` blocks and
  `x-schema-org` into `configuration` before its setter loop (`:1875-1905`),
  because the loop's silent catch drops a key without a setter. A top-level
  `exportable` has no setter and is dropped the same way.
- `jsonSerialize()` (`:2028`) returns `configuration` as stored (`:2090`).
- `grep -rn exportable lib/` finds no schema flag.

## D-1: one stored place, two read places

The flag is stored once, as `configuration.exportable`, a boolean. `hydrate()`
folds a top-level `exportable` into it, with the configuration value winning
when both are given. `jsonSerialize()` adds a top-level `exportable` equal to
the stored value (false when unset). Nothing writes the top-level field back
on its own, so the two can never disagree.

## D-2: import follows the save path

Configuration import builds schemas through `hydrate()`, so the fold in D-1
covers stackiq's register fragment, which puts `exportable: true` at the top of
four schemas. A test imports such a fragment and reads the flag back.

## Risks

- A client that sends `exportable: "true"` as a string. The boolean key
  handling casts it like the other boolean keys.
