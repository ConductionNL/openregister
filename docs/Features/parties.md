---
title: Parties on an object
description: A party holds a typed role on an object for a period. It may have no Nextcloud account, carries its own addresses and indicators, and merges through the existing merge primitive.
keywords:
  - Open Register
  - Parties
  - Roles
  - Indicators
  - Betrokkenen
  - Gemachtigde
---

# Parties on an object

A case has one requester and a dozen other people. The gemachtigde. The neighbour
who files a zienswijze. The aannemer. The jurist at the omgevingsdienst. None of
them is the aanvrager, and every one of them belongs on the record.

A property that points at a person answers one question. Every other question
needs a second property. A case with two gemachtigden needs a third.

So a party is a row, not a property. The row names the object, the party, the
role and the period the role runs.

## What a party is

A party is an object. Its schema says so, with `x-openregister-party`:

```json
{
  "x-openregister-party": {
    "kind": "person",
    "nameProperty": "naam",
    "addressesProperty": "adressen",
    "indicatorsProperty": "indicatoren",
    "parentProperty": "moederorganisatie",
    "maxDepth": 10
  }
}
```

Your app keeps its own property names. Open Register keeps one model.

A party needs no Nextcloud account. Most melders never sign in, and making them
users is a permission surface nobody wants. The party carries its own name, its
own addresses and its own indicators.

The declaration is checked when the schema is saved. A field naming a property
you never declared is reported, not stored in silence.

## Which parties a schema accepts

The object's schema declares what it takes:

```json
{
  "partyKinds": [
    { "key": "person", "label": "Persoon", "roles": ["aanvrager", "gemachtigde"] },
    { "key": "organisation", "label": "Organisatie" }
  ],
  "linkRoles": [
    { "key": "aanvrager", "label": "Aanvrager" },
    { "key": "gemachtigde", "label": "Gemachtigde" }
  ]
}
```

A kind naming `roles` holds only those roles. A kind naming none holds any role
the schema declares.

A write naming a kind the schema does not accept is refused with a 400. The
message names the kind and lists what is accepted.

A schema that declares no `partyKinds` accepts every kind. Registers written
before this feature keep behaving as they did.

## Addresses

Addresses hang off the party, each with a kind:

```json
{
  "adressen": [
    { "kind": "correspondence", "type": "email", "value": "jan@example.org" },
    { "kind": "correspondence", "type": "email", "value": "j.jansen@example.org" },
    { "kind": "case", "type": "postal", "value": "Dorpsstraat 1" },
    { "kind": "location", "type": "geo", "value": "52.09,5.12" }
  ]
}
```

Outbound mail picks the correspondence address. Inbound mail from any address
resolves to the same party, so a second record is never created.

A bare string still reads as a correspondence e-mail. A register holding one
address per party keeps working.

## Indicators

An indicator that only renders is an indicator somebody misses. Each one
declares what it does:

| Effect | What happens |
|---|---|
| `warn` | The reader sees it. Nothing is blocked. |
| `refuse-publication` | Publishing a file on the object is refused. |
| `refuse-send` | An outbound message to that party is refused. |

```json
{
  "indicatoren": [
    { "key": "geheimhouding", "label": "Geheimhouding persoonsgegevens", "effect": "refuse-publication" }
  ]
}
```

The effect is evaluated where the act happens, not in the component that draws
the chip. The refusal names the indicator and the party.

An indicator with no effect, or an effect this version does not know, warns. It
never vanishes.

Set the indicator once, on the party. Every case that party holds a role on
reads it. None of those cases is written.

## The primary party

One link per object carries `primaryParty`. It is the party the case is filed
against.

An intake filed on the wrong person is an AVG incident, not a typo. So replacing
it writes one audit entry, action `party.primary-replaced`, naming the party
that went, the party that came and the actor.

## Merging two parties

Two records that turn out to be one person merge through the existing merge
primitive. The preview, the atomic execution, the reversal window and the merge
register are the ones `mdm-merge` already has.

The party vocabulary adds three things. Every role both parties held carries
over onto the survivor. Their addresses union, so the surviving record is never
less reachable than either original. A reversal inside the window puts both
parties back, holding the roles they held before.

Nothing about the merge itself changes. A second merge beside it would be two
merges that disagree about what happened.

## The query cap

A person search that silently returns the first ten looks like a search that
found ten.

Open Register counts first. Over the administered cap the query is refused with
a 403 naming the cap, and the response holds no party at all. The refused
attempt goes on the audit trail with the actor, the query and the cap.

Set the cap under `party.queryCap` in the app settings. The default is 50.

## The API

| Call | What it does |
|---|---|
| `GET /api/objects/{register}/{schema}/{id}/parties` | The parties, grouped by role, with the accepted kinds |
| `POST /api/objects/{register}/{schema}/{id}/parties` | Give a party a role |
| `PUT /api/objects/{register}/{schema}/{id}/parties/primary` | Replace the primary party |
| `DELETE /api/objects/{register}/{schema}/{id}/parties/{partyUuid}` | Take a party off, all roles or one |
| `GET /api/parties/{partyUuid}` | One party: kind, addresses, indicators, every object it is on |
| `GET /api/parties/search?q=` | The capped search |
| `GET /api/parties/resolve?address=` | The party holding an inbound address |

A listing answers `results`, `total`, `byRole`, `kinds`, `roles` and `primary`.
A picker reads one call.

## What dossiq declares

Per case type, on the case schema: `partyKinds` with the roles each kind may
hold, and `linkRoles` for the labels. Then render `byRole` from the listing.

Stop treating the initiator as the only party. The initiator is the link whose
`primaryParty` is true.

## What integriq writes into

The BRP and KvK adapters write into the party record, one per person, not one
copy per app. Give the party schema `x-openregister-party` and point
`addressesProperty` at the addresses the adapter fills.

An adapter that finds an existing party by address must call
`GET /api/parties/resolve` first. That is what stops a second record per source.

## Next

Declare `partyKinds` on one case type. Add a party in a role, then read
`GET .../parties` and check the vocabulary comes back beside it.
