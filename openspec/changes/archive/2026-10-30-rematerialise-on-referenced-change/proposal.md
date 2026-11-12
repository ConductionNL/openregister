# Proposal: rematerialise-on-referenced-change

## Why

A materialised calculation is evaluated when its own object is saved. When it reads a referenced object through `@ref.<name>.<field>` and that object changes the field later, the stored value goes stale. Dossiq's case `hasStartableFlows` reads `@ref.caseType.startableFlows`. A case type that starts listing flows therefore leaves every running case at `false`, and the "Flow starten" entry stays hidden until someone runs `occ openregister:rematerialise-calculations`.

## What changes

- A new listener on `ObjectUpdatedEvent` finds the schemas in the updated object's register that declare an `x-openregister-references` entry in mode `relatedObject` pointing at the updated object's schema, and that have a materialised calculation reading `@ref.<name>.<field>` for a field that changed. It re-saves the objects whose reference field holds the updated object's uuid, and the save path materialises the new value.
- At most 200 objects per dependency are refreshed within the save. A larger set is logged, together with the occ command that refreshes the rest.
- Dependencies are worked out once per request for each register and target schema. A refresh cascades at most two levels deep.
