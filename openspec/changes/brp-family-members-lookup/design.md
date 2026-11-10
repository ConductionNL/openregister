# Design: brp-family-members-lookup

No board of OpenRegister's own. Portaliq's FormulierGezinsleden board (canvas `5NkFW28vZUUij43xzxHg5a`) shows the result: one card per person with name, relation and birth year.

## D1. Two calls, not one per relative

1. `RaadpleegMetBurgerservicenummer` for the resident with fields `burgerservicenummer`, `verblijfplaats.adresseerbaarObjectIdentificatie`, `partners`, `kinderen`.
2. One `RaadpleegMetBurgerservicenummer` with the BSNs of the selected relatives (at most 20) and fields `burgerservicenummer`, `naam.volledigeNaam`, `geboorte.datum`, `overlijden`, `verblijfplaats.adresseerbaarObjectIdentificatie`.

A partner whose `ontbindingHuwelijkPartnerschap` is set is dropped after call 1. A relative with `overlijden` is dropped after call 2. `sameAddress` compares `adresseerbaarObjectIdentificatie`; with `sameAddressOnly` the others are dropped.

## D2. The answer

```json
{ "results": [ { "reference": "f3a9...", "name": "Henk de Vries", "relation": "partner", "birthYear": 1983, "sameAddress": true } ],
  "total": 1, "meta": { "correlationId": "...", "durationMs": 212, "status": 200 } }
```

`relation` is `partner` or `child`. Degraded answers follow `lookupByBsn()`: `{ unavailable, cause, results: [], total: 0 }`.

## D3. The reference

`reference = HMAC-SHA256(instanceSecret, residentBsn + ":" + relativeBsn)`, hex, truncated to 32 characters. `resolveFamilyReference(residentBsn, reference)` repeats call 1, recomputes the references and answers the matching BSN, or null. So a reference is useless for another resident, and the submit check against the BRP happens as a side effect.

## D4. The mock

When the provider runs on the mock BRP register, the same method reads `partners` and `kinderen` from the `ingeschreven-persoon` objects and answers the same shape.
