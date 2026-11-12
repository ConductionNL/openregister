# Proposal: import-adopts-own-unlinked-schema

## Why

On a test instance every dossiq schema added in one release exists twice (17 slugs): two imports three minutes apart
on 29 Sep 2026 each created them, and only the second run's rows are in the dossiq register. The first run's rows stand
in no register and hold no objects. Schema resolution is scoped to the register's stored `schemas` list
(per-register slug uniqueness), so a row that a run created but never linked (the run stopped between creating schemas
and saving the register) is invisible to the next import, which creates a twin. The twins surface downstream: the
notification preference list showed dossiq's "Mijn dagelijkse werkoverzicht" twice (#4809).

WOO-563 closed one way to leave such a row (the register's version gate). A run that stops early is another, and any
row left behind forks again on every later import.

## What changes

- When the register's own set has no schema with the slug, the import adopts the importing app's own row with that slug
  that no register lists (highest version when there are several, in the current tenant), instead of creating a new
  one. Another app's row is never adopted; per-register uniqueness is unchanged.

## Scope

ImportHandler (`resolveSchemaWithinRegisterScope`, new `findOwnUnlinkedSchema`). Existing twins are instance data.
