# Proposal: example-set-writes-name-their-cause

## Why

A dossiq case type's history (board DqZaaktype "Historie") showed the System edits every window refresh leaves, the daily shift of the set's relative dates among them, while the entries the set seeds for the case type's own story ("Versie 4 gepubliceerd, Lars de Koning") were pushed out of view. The audit trail records why each write happened (`cause`, `cause_run`), but the example set's object writes went in as `person`, as if somebody had made them. The set's seeded audit rows were already `import` with an `example-set:` run; its own writes were not.

## What changes

- An example set install, a `--refresh` and the daily refresh run under the write cause `import`, with the run `example-set:<set>`. Every audit row the run writes says so, so a filter on cause or run finds one load as a whole, and a history can tell the set's upkeep from a person's change.
- A seeded audit row keeps the cause and run it declares.
- No schema or API change; the cause vocabulary is unchanged.
