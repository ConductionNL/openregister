# Proposal: macro-flow-by-name

## Why

A macro binds a declared action to a flow by the flow's uuid. A flow an app ships in its register
(`x-openregister-flows`) is stored on import under a uuid minted per installation, so a shipped register cannot name
it, and an app cannot ship a macro at all. dossiq's board DqZaakAssistent needs two shipped macros ("Woo-verzoek
afronden", "Opschorten voor aanvulling").

## What changes

- A binding's `flow` may name a flow the same schema declares in `x-openregister-flows`.
- On save, a binding whose `flow` is no stored flow is checked against the schema's own declarations: a declared flow of
  that name with a manual trigger is accepted (it is stored right after the save), one without a manual trigger is
  refused, and a name nothing answers to is refused as before.
- On a run, the name is resolved to the stored flow the import made, by the import's own identity (name and declaring
  schema slug, `FlowMapper::findDeclared()`).

A shipped flow is imported inert until an administrator adopts it, as every declared flow is; until then a run is refused
by the engine, which the macros widget shows in words.

## Scope

Additive. A binding that names a stored flow by uuid works exactly as before.
