# Contacts leaf: a cases panel and a name search

## Why

Round 2 of the dossiq competitor analysis (row B01 in
`concurrentie-analyse/procest/_round2/compare/tier-b-and-sibling.md`, decision
D10) asks for a contact 360 view: a person or organisation, the cases they are
party to, their communication and a timeline, found by name. OpenCase offers it
as the Search citizen and Search company pages
(`opencase/round2/pages/Search-Citizen.md`, `Search-Company.md`: a 360 view with
Cases and Documents and a Create case button). Zaaksysteem offers Contactbeeld
and Contact zoeken (`xxllnc-zaken/round2/pages/ContactBeeld-persoon.md`,
`ContactZoeken.md`).

dossiq has the API half only: `/api/kcc/voorblad` renders nothing, and the
contacts leaf, which ADR-066 names as the contact record's surface, lists the
linked contacts of one object but never the objects of one contact. Reverse
lookup exists in the provider (integration-contacts, requirement Reverse
Lookup) and has no page.

## What changes

- The contacts leaf gains a detail surface for one contact with a cases panel:
  every register object the contact is linked to, grouped by schema, with the
  link role, the object's title and its status field when the schema declares
  one.
- The contacts leaf gains a name search: an index surface that finds a contact
  by (part of) a name, e-mail address or organisation, backed by the Contacts
  app's address books the user may read.
- Both surfaces are leaf surfaces in the sense of integration-leaf-foundation,
  so a fleet app declares them in its manifest and writes no code.

## Who benefits

dossiq (page Cases, the rung 5 Contacts domain candidate), zaakafhandelapp,
humaniq (a person's cases and requests), pipelinq (a contact's deals) and
opencatalogi (a publisher's publications).

## Impact

- Affected specs: integration-contacts (delta).
- Affected code: `lib/Service/Integration/Providers/ContactsProvider.php`,
  the contacts leaf's Vue surfaces under `src/integrations/contacts/`,
  one new route for the name search.
- Backwards compatible: the sidebar tab and the person chip are unchanged.
