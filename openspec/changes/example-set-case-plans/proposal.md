# A fragment may put an adaptive case plan on its objects

## Why
A case's Zaakplan card reads the case's adaptive plan from OpenRegister's case layer. An example set could not seed one, so DqZaak printed "Dit zaaktype heeft geen adaptief plan". Its board draws Mijlpaal verzoek compleet (Bereikt 4 okt), Documenten beoordelen (Actief), Zienswijze derde vragen (Optioneel) and Besluit opstellen (Wacht).

## What changes
- A new `casePlans` part. Each row names the object, the plan `definition` (`settings` plus nested `items`, each item with its `state`) and an optional `history`.
- The items are brought in through `CasePlanService::ensureItems` as the fragment's app: convergent on the item key, with no cascade. So a second install creates nothing.
- Remove deletes the items the set created. Items the plan held before stay. The plan's audit stays, as an audit does.
