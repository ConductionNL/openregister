# Cast members keep the default organisation

## What was wrong

The Zuiddrecht example set declares a second organisation, Gemeente Waterland, with the members
`marieke` and `pieter` (requirement "A cast organisation may declare its quota and its members").
The install creates Waterland before it creates the cast's accounts, so neither of them belongs to
any other organisation.

A user's active organisation is the oldest organisation they belong to
(`OrganisationService::activateOldestOrganisation`). For Pieter that is Waterland. Every case the set
installs sits in the Default Organisation, so every OpenRegister read as Pieter filters them out:
`GET /api/objects/{register}/{schema}/{case}` answers 404 although his read rules grant the case.
Dossiq's household card (change `example-set-pieter-reads-the-household`) answers 403
`household-not-allowed` for that reason. Found on a sandbox on 25 Nov 2026 (NC 34.0.6, MariaDB).

## What changes

- An organisation the install creates makes each of its declared members a member of the default
  organisation too. The default organisation is older than any organisation a set creates, so it
  stays the member's active organisation, and the new organisation is still among their organisations,
  which the existing scenario "A second tenant Pieter can read" asks.
- An organisation that was created but could not be completed is still recorded as made by the set,
  so `remove` deletes it; the failure is reported as a refusal, as before.

Nothing else changes: no new cast field, no change to how an active organisation is chosen.
