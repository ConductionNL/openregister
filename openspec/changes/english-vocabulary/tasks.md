# Tasks — english-vocabulary (openregister)

Measured scope: **0 own schemas / 0 own properties** (already clean), **15 mock-register
schemas / 79 properties** (wire, to be marked not renamed), **6 files / 6 classes /
22 methods** (the actual work).

## 1. Verify the marking mechanism before relying on it

- [ ] 1.1 Confirm OpenRegister tolerates an unknown `x-` marker key on a schema without
      dropping it on import. The whole strategy rests on this and it is **unverified** —
      a schema that fails import vanishes from the register, logged not raised. Test it
      on a throwaway schema first.

## 2. Mark the mock registers as wire

- [ ] 2.1 Add a marker to `bag_`, `brp_`, `dso_`, `kvk_` and `ori_register.json` naming
      the standard each mirrors (PDOK BAG, Haal Centraal BRP Personen Bevragen v2,
      CIM-OW/IMOW, official KVK test environment, VNG ODS-Open-Raadsinformatie).
- [ ] 2.2 Rename **nothing** inside them. `nummeraanduiding`, `verblijfsobject`, `pand`
      and `ingeschreven-persoon` are the standards' own names; a connector built against
      the mock must keep working against the real registry.

## 3. Rename the processing-activity family

- [ ] 3.1 `Verwerkingsactiviteit` → `ProcessingActivity`, plus its mapper and
      `VerwerkingsactiviteitenController`. GDPR Art. 30 is EU-wide law, so this is
      internationalised **without** a statute marker.
- [ ] 3.2 Check the rename does not collide with the existing English `ProcessingLogEntry`
      / `ProcessingLogMapper` / `ProcessingLogController` family. They are adjacent but
      distinct concepts and must not merge.

## 4. Rename the archival family, with a marker

- [ ] 4.1 `ArchiefactiedatumCalculator` → `ArchivalActionDateCalculator`; its methods
      `determineBrondatum`, `brondatumFromClosure`, `brondatumFromProperty`,
      `brondatumFromTermijn` → `determineSourceDate`, `sourceDateFrom*`; and
      `extendArchiefactiedatum` in `DestructionService`.
- [ ] 4.2 Attach the statute marker recording the Dutch archival statute — unlike GDPR,
      this concept has no international counterpart.

## 5. Rename the remaining code identifiers

- [ ] 5.1 `BrpPersoonProvider` → `BrpPersonProvider`. `Brp` is the registry's proper
      name and stays.
- [ ] 5.2 `MdtoXmlGenerator::addNaam/addWaardering/addBewaartermijn/addBestand` → English
      method names, with the emitted element strings `naam`, `waardering`,
      `bewaartermijn`, `bestand` **byte-identical**. Accept that `addName()` writing
      `<naam>` reads oddly; that is the documented cost, not a defect.
- [ ] 5.3 The remaining Dutch methods: `recomputeDossierTranslator`,
      `resolveDossierFolder`, `dossier()`, `betrokkene()`, `verwerkingsregister()`.

## 6. Defer the ZGW question

- [ ] 6.1 Do **not** rename `ZaaktypeAuthorizationService`. It maps ZGW Autorisaties API
      records, so `zaaktype` may be wire — but procest is renaming its `Zaak` family and
      may extend that to type names. Record as blocked on procest.

## 7. Data migration — why this app has none

- [ ] 7.1 Record the evidenced reason openregister needs no object migration: its own
      schemas measure **0 Dutch schemas / 0 Dutch properties**, and the 79 Dutch properties
      all sit inside mock registers this change deliberately does **not** rename. Confirm
      that by counting objects for the five mock registers' schemas and showing the rename
      set is empty — an evidenced skip, not an assumed one. Every other app in the
      programme carries a migration; openregister is the one exception and must prove it.

## 8. Verify against the whole fleet

- [ ] 8.1 Search every consuming app for the old class names. openregister is the
      foundation — a class that no longer resolves 500s **every route** in a consuming
      app, because the router reflects every controller, and only the class header is
      fatal. Check headers, not just call sites.
- [ ] 8.2 `l10n/nl.json` + `check-l10n`; full suite plus hydra gates 46/53/54/55/57/61.
- [ ] 8.3 Re-run the token-aware scan **excluding** `custom_apps/` and `lib.pre-orgcred.bak/`;
      require 0 own Dutch schemas, 0 own Dutch properties, 0 Dutch code identifiers, and
      the 15 mock schemas still present and marked.

## Acceptance criteria

- All five mock registers carry a wire marker and are otherwise byte-identical.
- Every MDTO XML element string is unchanged.
- `ProcessingActivity` and `ProcessingLog` both exist and remain distinct.
- The archival calculator carries a statute marker; the processing-activity family does not.
- No consuming app references a removed openregister class name.
- Re-measurement excludes the gitignored duplicate trees.
