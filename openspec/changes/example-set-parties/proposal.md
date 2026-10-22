## Why

The dossiq people-involved board (DqZaakBetrokkenen) draws the Roles section from OpenRegister's party model:
- Sanne de Vries as the primary party, with "Geen geheimhouding";
- J. van Wijk as the authorised representative of Aannemersbedrijf Van Wijk, with "Machtiging tot 31 dec";
- the residents' association Lindelaan as a reporter without an account, with "Indicator: meerdere meldingen".

None of this can be example data today, for two reasons:
- An example set cannot put a party on an object. Party links are rows in `openregister_contact_links`, not register objects, and the only write path needs a signed-in user, which `occ` does not have.
- The party model has indicators but no notion of confidentiality. A person's secrecy flag (the BRP's "geheimhouding persoonsgegevens") is a property of the person, not an indicator with an effect, so it has nowhere to go.

## What Changes

- **A new example-set part, `parties`.** Each row gives one party a role on one of the set's objects: `{objectUuid, register, schema, partyUuid, role, validFrom?, validUntil?, note?, primary?}`.
  - It is written through the party role service's trusted path, which records the set's app as the linker.
  - The pair is recorded, proved on a later install and unlinked on remove.
  - The check refuses an unknown field, a row without an object, party or role, and a date that is not a date. The kind and role are checked as the API checks them.
- **`x-openregister-party` may name a `confidentialityProperty`.** The party read (`GET /api/parties/{uuid}`) answers `confidential`: true or false when the schema names the property, null when it does not. A value of 0, false or empty counts as not confidential, so the BRP's 0 to 7 scale works unchanged.

The library's parties widget shows the line ("Geheimhouding" or "Geen geheimhouding") from `confidential`. That is a nextcloud-vue change, not part of this one.
