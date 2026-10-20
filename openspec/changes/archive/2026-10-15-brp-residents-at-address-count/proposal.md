---
kind: code
---

# Proposal: brp-residents-at-address-count

## Summary

Let the BRP provider answer how many people are registered at an address object, as a number and nothing else. Portaliq's "Mijn gegevens" shows a resident that number for their own address, as MijnOverheid and NL Portal (`getBewonersAantalV2`) do.

Rows covered: portaliq `cmp-id-residents-at-address` (REQ-IRD-005). This is OpenRegister's half of portaliq's `identity-registered-details` task T07.

## Why

Portaliq reads the BRP only through `BrpPersonProvider`, which answers `RaadpleegMetBurgerservicenummer` only. Portaliq's section already says "The number of residents at this address is not available" and waits for a method it can call (`method_exists`).

## What changes

- **`BrpPersonProvider::countResidentsAtAddressObject(addressObjectId)`.** Asks Haal Centraal `ZoekMetAdresseerbaarObjectIdentificatie` with the smallest field set and answers `{ total, meta }`. No names, no BSNs and no person object leave the provider.
- **Behind a setting on the source.** The question is asked only when the `brp-haalcentraal` source carries `configuration.residentCount: true`, the way integriq reads BRP relations only behind `configuration.relationFields: true` (decision 220). Without it the answer is `{ unavailable, cause: 'resident-count-not-enabled' }` and the registry is not asked.
- **Recorded without bodies.** The answer holds the residents' numbers, so the call goes to the connector with `withholdBodies`: integriq records that the call happened, not what it carried. A connector that cannot withhold bodies is not called.
- **Router.** `ExternalIntegrationRouter` gains `sourceConfiguration(provider)` and honours a `withholdBodies` call option. Both are generic: any provider may use them.

## Out of scope

- Who may see the count (the resident, for their own address) and how it renders: portaliq.
- Names or any other detail of the residents.
- Seeding `residentCount` on integriq's source: an administrator turns it on.

## Impact

- `lib/Service/Integration/Providers/BrpPersonProvider.php`
- `lib/Service/Integration/ExternalIntegrationRouter.php`
- Tests: `BrpPersonProviderResidentCountTest`, `ExternalIntegrationRouterTest`
