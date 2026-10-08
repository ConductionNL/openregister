---
kind: code
---

# Proposal: brp-family-members-lookup

## Summary

Let the BRP provider answer a resident's partner and children, with name, relation, birth year and whether they live on the resident's address, in one call. Today `BrpPersonProvider::lookupByBsn()` answers one person with a fixed field list. This change adds `lookupFamily()`, which asks Haal Centraal for the resident's `partners` and `kinderen`, then the address of each, and answers a short, minimised list.

Rows covered: portaliq `int-family-prefill` (decision 104). This is OpenRegister's half of portaliq's `data-lookups-and-checks-in-forms` (merged in portaliq #1387).

## Why

Portaliq's change says: "Family: `GET /api/intake/{route}/family` for a DigiD session only, through `BrpPersonProvider`: partner and children, filtered on the same address when asked, returning a person reference, name, relation and birth year. On submit the server checks each chosen reference against the BRP again." The FormulierGezinsleden board draws "Wie verhuist er met u mee?" with cards "Henk de Vries, Partner, geboren in 1983".

Open Formulieren 4.0.1 has a family members prefill plugin over Haal Centraal (`src/openforms/prefill/contrib/family_members/plugin.py:63`).

What OpenRegister has:

- `BrpPersonProvider` with `lookupByBsn()` and `GET /api/integrations/brp/person` (`integration-person-lookup` spec), which returns the raw person with `DEFAULT_FIELDS` and the Wet-BRP audit metadata.
- The mock BRP register with families cross-referenced through `partners`, `ouders` and `kinderen` (`mock-registers` spec).
- No call that returns the relatives in a minimised shape, and no address comparison.

## What changes

- **`lookupFamily(bsn, relations, sameAddressOnly)`.** Asks Haal Centraal for the resident with `partners` and `kinderen` fields, then for the relatives' `verblijfplaats` and `geboorte` in one `RaadpleegMetBurgerservicenummer` call, and answers per relative: an opaque reference, the display name, the relation, the birth year, and `sameAddress`.
- **Data minimisation.** No BSN, no full birth date and no address leave the provider. The reference is an HMAC of the relative's BSN keyed per instance, which `resolveFamilyReference(bsn, reference)` turns back into the BSN on the server, only for a relative of that same resident.
- **Deceased and dissolved** are filtered: a partner whose partnership has ended and a deceased relative are not returned.
- **Audit.** Each call returns the Wet-BRP audit metadata, as `lookupByBsn()` does.
- **The mock register answers too,** so development instances show families without a Haal Centraal source.

## Out of scope

- Who may ask (DigiD sessions only) and how the cards render: portaliq.
- Parents, siblings and other relations.
- Correcting a BRP record ("Dat regelt u niet in dit formulier").

## Impact

- Specs: one requirement added to `integration-person-lookup`.
- Changed: `lib/Service/Integration/Providers/BrpPersonProvider.php` (`lookupFamily()`, `resolveFamilyReference()`), the mock provider path, `docs/features/integration-person-lookup.md`.

## Cross-project dependencies

- portaliq `data-lookups-and-checks-in-forms` calls `lookupFamily()` from `GET /api/intake/{route}/family` and `resolveFamilyReference()` on submit.
