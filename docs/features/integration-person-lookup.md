# Person lookup (BRP)

| Property   | Value |
|------------|-------|
| Status     | Implemented |
| Standards  | Haal Centraal BRP Personen Bevragen API v2 |
| App        | OpenRegister |

## Overview

`BrpPersonProvider` asks the BRP for people through the `brp-haalcentraal` source of the connector app, which holds the credentials and certificate. OpenRegister never stores them. Every answer carries the transport metadata a Wet-BRP audit record needs (`correlationId`, `durationMs`, `status`). When the source is missing or down the provider answers `{ unavailable, cause, results: [], total: 0 }` instead of throwing.

| Method | Answers |
|--------|---------|
| `lookupByBsn(bsn)` | The person with the default field set, as the BRP returns them. |
| `countResidentsAtAddressObject(id)` | How many people the BRP registers at an address object, when the source allows it. |
| `lookupFamily(bsn, relations, sameAddressOnly)` | The resident's current partner and living children, minimised (below). |
| `resolveFamilyReference(bsn, reference)` | The BSN behind a reference that `lookupFamily()` answered. |

## Family members

`lookupFamily()` makes two `RaadpleegMetBurgerservicenummer` calls. The first asks for the resident's address and their `partners` and `kinderen`. The second asks for those relatives, at most 20, with name, birth date, date of death and address.

- `relations` is `partner`, `child` or both. Other values are ignored.
- A partner whose marriage or partnership has ended is left out, and so is a relative who has died.
- With `sameAddressOnly`, a relative who lives at another address is left out.

The answer holds only what a form needs:

```json
{
  "results": [
    { "reference": "f3a9…", "name": "Henk de Vries", "relation": "partner", "birthYear": 1983, "sameAddress": true }
  ],
  "total": 1,
  "meta": { "correlationId": "…", "durationMs": 212, "status": 200 },
  "relativesMeta": { "correlationId": "…", "durationMs": 140, "status": 200 }
}
```

The answer never contains a relative's BSN, full birth date or address. `meta` comes from the first call and `relativesMeta` from the second (`null` when nobody was asked).

### The reference

`reference` is an HMAC-SHA256 over `residentBsn:relativeBsn`, keyed with the instance's `secret` from `config.php` and shortened to 32 hex characters. To check the person picked on a form before submitting it, call `resolveFamilyReference(residentBsn, reference)`. It repeats the first call and gives back the relative's BSN only if the reference belongs to a current partner or child of that same resident. A reference taken from another resident's answer, or one for somebody who is no longer a relative, gives back `null`, as does an unreachable source.

Without an instance secret, `lookupFamily()` answers `{ unavailable: true, cause: "no-instance-secret" }` and makes no call.

## Who may ask

The provider does not check who is asking. The calling app decides that. For example, portaliq only offers the family lookup to a resident signed in with DigiD.
