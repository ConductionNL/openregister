# English vocabulary for openregister — a code-layer change, not a schema change

> Implements `hydra/openspec/changes/fleet-english-vocabulary`.

## Why

**This proposal previously said "2 Dutch-named schemas and 20 Dutch property names" and
listed properties to rename. A token-aware rescan shows that was wrong in both
directions, and the correction changes what this change is.**

Measured scope:

| layer | count | verdict |
|---|---:|---|
| openregister's **own** schemas | **0 schemas / 0 Dutch properties** | already clean |
| mock registers (`bag_`, `brp_`, `dso_`, `kvk_`, `ori_`) | 15 schemas / 79 properties | **wire — exempt, to be marked** |
| code: files / classes / methods | 6 / 6 / 22 | **the actual work** |

The properties the old proposal listed as "ours" — `naam`, `omschrijving`, `toelichting`,
`onderwerp`, `bijlagen`, `startDatum`, `besluitdatum`, `indieningsdatum`,
`vergunningaanvraag` — are all **inside the mock registers**. None is an openregister
domain property.

### The mock registers are the wire, and that is the whole point of them

Each declares what it is in its own `info.description`:

| register | describes itself as |
|---|---|
| `bag_register.json` | "Mock BAG register with realistic Dutch address and building data **from PDOK**" |
| `brp_register.json` | "**aligned to the Haal Centraal BRP Personen Bevragen API v2** data model" |
| `dso_register.json` | "**aligned to the CIM-OW/IMOW** data model" |
| `ori_register.json` | "Based on the **VNG ODS-Open-Raadsinformatie specification**" |
| `kvk_register.json` | "test data **from the official KVK test environment**" |

`nummeraanduiding`, `verblijfsobject` and `pand` are the literal BAG object types;
`ingeschreven-persoon` is the literal Haal Centraal resource. A mock exists so that a
connector developed against it works against the real registry. Renaming its fields
does not internationalise openregister — it destroys the only property the mock has.

This is the fleet exemption for wire vocabulary, and a mock register **is** the wire.

## What changes

### 1. Mark the mock registers as wire (no renaming)

Each of the five gets an explicit marker naming the standard it mirrors, so the
classification travels with the register and a later vocabulary sweep does not "fix"
them into uselessness.

### 2. Rename the code layer — this is the real scope

| current | proposed | reasoning |
|---|---|---|
| `Verwerkingsactiviteit`, `VerwerkingsactiviteitMapper`, `VerwerkingsactiviteitenController` | `ProcessingActivity*` | GDPR Art. 30 "record of processing activities" — **EU-wide law, not NL-only**, so internationalised without a statute marker |
| `ArchiefactiedatumCalculator` | `ArchivalActionDateCalculator` | Archiefwet / MDTO concept — NL statutory, English name **plus** statute marker |
| `BrpPersoonProvider` | `BrpPersonProvider` | `Brp` is the registry's proper name and stays; `Persoon` is ours |
| `determineBrondatum`, `brondatumFrom*`, `extendArchiefactiedatum` | `determineSourceDate`, `sourceDateFrom*`, `extendArchivalActionDate` | ours |
| `recomputeDossierTranslator`, `resolveDossierFolder`, `dossier()`, `betrokkene()`, `verwerkingsregister()` | English equivalents | ours |
| `MdtoXmlGenerator::addNaam/addWaardering/addBewaartermijn/addBestand` | English method names, **unchanged XML element strings** | see risk below |
| `ZaaktypeAuthorizationService` | **deferred** — see open question | maps ZGW Autorisaties API records; depends on procest |

### 3. `Verwerkingsactiviteit` sits beside an English sibling already

`lib/Db/` contains both `Verwerkingsactiviteit.php` and `ProcessingLogEntry.php`;
`lib/Controller/` contains both `VerwerkingsactiviteitenController.php` and
`ProcessingLogController.php`. Two adjacent concepts, one named in each language. That
inconsistency is the clearest evidence that the Dutch name here is an accident rather
than a deliberate statutory choice.

## Tasks

- [ ] Mark the five mock registers as wire; rename nothing inside them.
- [ ] Rename the `Verwerkingsactiviteit` family to `ProcessingActivity`, checking it does
      not collide with the existing `ProcessingLog` family.
- [ ] Rename `ArchiefactiedatumCalculator` and its methods; attach the Archiefwet marker.
- [ ] Rename the remaining Dutch methods; preserve MDTO XML element strings exactly.
- [ ] Resolve the `Zaaktype` question with procest before touching that service.
- [ ] `l10n/nl.json` + `check-l10n`; full suite + hydra gates.

## Risks

- ⚠️🔥 **openregister is the fleet's foundation.** A renamed class here can break every
  consuming app, and `extends` on another app's class 500s every route when resolution
  fails. Cross-check each rename against consumers; this is the one app where a rename
  is never app-local.
- ⚠️ **Renaming a mock register's fields is an integration outage disguised as tidying.**
  The marker in task 1 exists specifically so a future sweep does not do it.
- ⚠️ **`addNaam()` emits a literal `<naam>` MDTO element.** Renaming the method to
  `addName()` leaves a method whose name disagrees with the element it writes. The fleet
  rule says the identifier is English and the wire string is preserved; the cost is a
  small, permanent readability wrinkle at that boundary. Worth stating rather than
  discovering in review.
- The five mock registers are duplicated under the gitignored `custom_apps/` tree
  (4.5 GB of other apps' checkouts). Any re-measurement must exclude it, and
  `lib.pre-orgcred.bak/`, or it double-counts.
