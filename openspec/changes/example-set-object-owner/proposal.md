# Proposal: example-set-object-owner

## Why

An example set writes as the system, so every object it creates is owned by `__system__`. Some apps read the owner as a business fact: decidiq's authority check takes a decision's owner as the actor ("Pieter Jansen mag dit besluit nemen"), and dossiq shows the drafter as the owner. The Zuiddrecht Woo decision therefore read "opgesteld door __system__", and the mandate notice could not show. A save cannot claim an owner (by design: SaveObject refuses or ignores `@self.owner`), so a set had no way to say who owns its objects.

## What changes

- A declared object MAY name `owner`, a placeholder that resolves to an account the set's cast declares (e.g. `{{cast:pieter.uid}}`). Anything else (a literal account id, a cast entry without an account, an unresolvable placeholder) refuses the set before anything is written.
- On create the trusted import passes it to `ObjectService::saveObject(_owner:)`. On `--refresh` a kept object owned by another account is handed to the declared owner through `ObjectOwnershipService` (audited, one entry per record), as the system.
- `ObjectOwnershipService` lets a trusted system operation (`SystemOperationContext`) assign a record, as every other system guard already does; outside that scope only an administrator may.
