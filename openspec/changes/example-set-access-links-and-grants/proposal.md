---
kind: code
depends_on: [an-object-lists-its-grants-with-their-origin]
---

# Proposal: example-set-access-links-and-grants

## Why

The Toegang card on board DqZaakBetrokkenen
(https://identity.conduction.nl/screens/board?id=dossiq/DqZaakBetrokkenen) draws who may open
the Lindelaan Woo case and why. Two of its rows cannot be seeded by an example set today:

- "Link voor Sanne de Vries · Alleen lezen, zonder account · Verloopt 15 nov": an access link
  (`AccessLink`, minted by `AccessLinkService::mint()`). The Zuiddrecht fragment can only write
  dossiq's `caseShare` record for it. That record names no minted link (`accessLinkId`,
  `accessLinkUuid`, `accessLinkUrl` stay empty), so the link opens nothing and the card's
  revoke button answers with its error text.
- "Juridische zaken · gedeeld door Pieter Jansen op 6 okt · Tot 31 okt": a grant, a Nextcloud
  group share on the case's folder (`ObjectSharingService::grant()`). No fragment kind writes
  one, so the row is missing.

Both are real OpenRegister records with a lifecycle of their own. Writing them by hand on a
demo instance does not survive a refresh, and an app cannot write them from its own fragment
without reaching into OpenRegister.

## What changes

1. A fragment MAY declare `accessLinks`: links over objects the set writes, minted by a cast
   account, with capabilities, a label and a required expiry.
2. A fragment MAY declare `grants`: shares of an object the set writes with a user or group,
   made by a cast account, with permissions, an optional share date and an optional expiry.
3. A new placeholder `{{accessLink:<key>.<field>}}` (`id`, `uuid`, `url`) lets an object of
   the same fragment name the link it belongs to, so dossiq's `caseShare` can carry
   `accessLinkId`, `accessLinkUuid` and `accessLinkUrl`.
4. Install creates each link and grant through the services that own them and records it.
   A second install writes nothing and reports a recorded link or grant that is gone as
   missing.
5. `--refresh` removes each recorded link and grant and creates it again, so relative dates
   follow the install day and a revoked demo link comes back.
6. Remove deletes the links and grants the record holds, and only those.

## What does not change

- `AccessLinkService` and `ObjectSharingService` keep their guards for every other caller.
  The set acts through them as the declared account (design D-2); it adds no second writer.
- A link's anchor stays secret: it is never declared, never written to the record and never
  printed. Only `url` reaches an object, through the placeholder, as the mint returns it.
- Partner shares, federated shares, transfers and e-mail invitations stay out of scope.

## Consumers

- portaliq's Zuiddrecht fragment: Sanne's read-only link on `woo-fietspad` and the Juridische
  zaken grant by Pieter.
- dossiq: its `caseShare` of type link names the minted link (`39-case-access-links.json`).
