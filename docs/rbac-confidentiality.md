# Enforcing confidentiality (vertrouwelijkheidaanduiding)

OpenRegister can filter objects by ZGW confidentiality level on read. The
machinery is complete and shipped — but it is **off until a schema is configured
for it**, and as of 2026-08-12 no schema in this repository is.

This document is the missing half: what exists, what does not, and how to turn
it on.

## What already works

| piece | where | state |
|---|---|---|
| `$in` operator | `lib/Service/OperatorEvaluator.php` | implemented |
| conditional `match` on authorization entries | `lib/Service/Object/PermissionHandler.php` | implemented |
| clearance ordinal ordering | `ZaaktypeAuthorizationService::confidentialityOrdinal()` | implemented, unit-tested |
| clause builder | `ZaaktypeAuthorizationService::buildConfidentialityMatch()` | implemented, unit-tested |
| ZGW Autorisaties mapping | `ZaaktypeAuthorizationService::mapZgwAutorisatie()` | implemented, unit-tested |

## What does not

**Nothing calls any of it.** `ZaaktypeAuthorizationService` has zero references
in `lib/` and is not registered in the DI container; it is exercised only by
`tests/Unit/Service/ZaaktypeAuthorizationServiceTest.php`.

**No schema is configured.** Measured across `lib/Settings/*.json`: zero files
contain a `match` clause of any kind. So no read is currently filtered by
clearance.

This matters because the archived change `2026-06-14-rbac-zaaktype` marks the
confidentiality requirement complete, which reads as an active control. The
capability is real; the enforcement is not switched on.

## Turning it on

Confidentiality is enforced by attaching a conditional `match` to an
`authorization` entry on the schema. The engine then filters at both the PHP and
SQL layers with no further code.

```json
{
  "authorization": {
    "read": [
      {
        "groups": ["zaakbehandelaars"],
        "match": {
          "vertrouwelijkheidaanduiding": {
            "$in": ["openbaar", "beperkt_openbaar", "intern"]
          }
        }
      }
    ]
  }
}
```

`buildConfidentialityMatch()` generates the `match` value, so the list stays in
step with the ordinal ordering rather than being hand-maintained:

```php
$service->buildConfidentialityMatch(maxLevel: 'intern');
// => ['vertrouwelijkheidaanduiding' => ['$in' => ['openbaar', 'beperkt_openbaar', 'intern']]]
```

Pass `property:` when the schema stores the level under a different name — see
the naming caveat below.

## The decision this needs

Enabling it is a **policy** question, not a technical one: *which group may read
up to which level, on which schemas*. That mapping cannot be derived from the
code, which is why no default is shipped here.

Two properties of the design worth knowing before choosing:

- **It fails closed on an unknown level.** `isAccessibleAtClearance()` returns
  false when either level is unrecognised, so a typo denies rather than grants.
- **It filters, it does not error.** Objects above the clearance disappear from
  results. A user cannot distinguish "no such object" from "not cleared", which
  is usually what you want for confidential records.

## Naming caveat

The same concept appears under three property names in this codebase:

- `vertrouwelijkheidaanduiding` — the ZGW/GGM schema property, and the builder's
  default
- `confidentialityLevel` — what `SeedZgwZakenMigrationPack` maps it onto
- `confidentiality` — what `FederationController` reads

Point `buildConfidentialityMatch(property: ...)` at whichever name the schema
actually declares. Getting this wrong does not error: the `$in` clause simply
matches nothing, or everything, depending on the engine's null handling.

That mismatch already caused a live fail-open in the federation path — see
openregister#2438, where the guard read `confidentiality` while the migration
pack wrote `confidentialityLevel`, and the empty-string fallback sat inside the
public allowlist.
