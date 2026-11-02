# Proposal: example-set-flow-placeholder

## Why

Dossiq's case dialogs board (DqZaakDialogen) shows a "Flow starten" dialog on the Woo case. The dialog lists the flows the case type allows: "Zienswijze derde vragen", "Documenten opvragen bij een afdeling" and "Verdaging aankondigen". A case type lists those flows in `startableFlows` by flow uuid. A fragment can declare flows, but a flow's uuid exists only once the install has written it. Before this change the install wrote flows as the last engine part, after the `changes` that would put them on a shipped case type.

## What changes

- The installer writes a fragment's flows straight after its objects, before its changes.
- A new placeholder, `{{flow:<key>.uuid}}`, gives the uuid of a flow the same fragment declares. It works in changes and in the engine parts, which are all written after the flows.
- During the check it resolves to a stand-in. A key that names no flow of the fragment is refused with "cannot resolve".
- Flows are now written before tasks, so a flow can no longer quote `{{task:}}`. No shipped fragment does.
