## Why

The dossiq people-involved board (DqZaakBetrokkenen) draws the Roles section from OpenRegister's party model:
- Sanne de Vries as the primary party, with "Geen geheimhouding";
- J. van Wijk as the authorised representative of Aannemersbedrijf Van Wijk, with "Machtiging tot 31 dec";
- the residents' association Lindelaan as a reporter without an account, with "Indicator: meerdere meldingen".

None of this can be example data today. Party links are rows in `openregister_contact_links`, not register objects, and the only write path needs a signed-in user, which `occ` does not have.

Confidentiality and the "meerdere meldingen" mark need no new field. The party model already carries both as **indicators** on the party record. A secrecy indicator is `{key: geheimhouding, effect: refuse-publication}`, and `PartyIndicatorGuard` already honours it. A party without that indicator has no secrecy, and the widget's "Geen geheimhouding" line reads exactly that absence.

## What Changes

- **A new example-set part, `parties`.** Each row gives one party a role on one of the set's objects: `{objectUuid, register, schema, partyUuid, role, validFrom?, validUntil?, note?, primary?}`.
  - It is written through a new trusted path on the party role service, `importParty`, which records the set's app as the linker.
  - The pair is recorded, proved on a later install and unlinked on remove.
  - The check refuses an unknown field, a row without an object, party or role, and a date that is not a date. The kind and role are checked as the API checks them.
- **Indicators** on a party record are written as part of that record's data, under the property its schema names. The example set needs nothing new for them.

The library's parties widget must still show "Geen geheimhouding" when a person party has no secrecy indicator. That is a nextcloud-vue change, not part of this one.
