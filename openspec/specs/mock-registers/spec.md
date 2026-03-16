# Mock Registers Specification

## Purpose

Provide mock/demo registers for the five Dutch base registries on OpenRegister: **BRP** (persons), **KVK** (businesses), **BAG** (addresses/buildings), **DSO** (environmental permits), and **ORI** (council information). These registers contain realistic seed data sourced from official test environments and open APIs, enabling Procest, Pipelinq, and other apps to develop and demo integrations without external API credentials or government certificates.

**Why this matters**: The alternative products we compete with (KISS, Dimpact ZAC, Open Formulieren) all require extensive external infrastructure to run locally — KISS couldn't even be spun up without OIDC, Elasticsearch, ZGW backends, KVK API and Haal Centraal API. Our mock registers make the entire suite self-contained.

**Delivery format**: Each register is a `*_register.json` file in `lib/Settings/` following the existing OpenAPI 3.0.0 + `x-openregister` extension pattern (same as `procest_register.json`, `pipelinq_register.json`). Seed data lives in the `components.objects[]` array using the `@self` envelope format. Files are imported via the existing RepairStep → SettingsService → ImportHandler pipeline.

---

## REQ-MOCK-001: BRP Mock Register (Basisregistratie Personen)

The system MUST provide a mock BRP register with fictional person records aligned to the Haal Centraal BRP Personen Bevragen API v2 data model.

### Data source: RVIG test personas

The seed data MUST be derived from the official RVIG (Rijksdienst voor Identiteitsgegevens) test dataset. The Haal Centraal BRP mock (`ghcr.io/brp-api/personen-mock:2.7.0-latest`) ships 1182 test persons with complete family relationships, nationality, immigration, and address history. Key reference personas:

| BSN | Name | Scenario |
|-----|------|----------|
| `999993653` | Suzanne Moulin | French national in Rotterdam, immigration history |
| `999990627` | Stephan Janssen | Father with 2 children (999997580, 999995145) |
| `999992570` | Albert Vogel | Man with partner, child, 2 parents |
| `999995376` | Brigitte Moulin | French-born, partner Jean Roussaex |
| `999999655` | Astrid Abels | Deceased person (2020-06-06) |
| `999995091` | Thanatos Olympos | Greek national, immigrated 1989 |
| `999993355` | Jan-Kees Brouwers | Person "in onderzoek" |
| `999970033` | Mira Maasland | Minor (born 2017), custody scenario |
| `999990949` | Marianne de Jong | Common Dutch name with voorvoegsel |

### Schema: `ingeschreven-persoon`

| Property | Type | Description | Haal Centraal field |
|----------|------|-------------|-------------------|
| `burgerservicenummer` | string (9 digits) | BSN, passes 11-proef | `burgerservicenummer` |
| `voornamen` | string | First names (space-separated) | `naam.voornamen` |
| `voorletters` | string | Initials (derived) | `naam.voorletters` |
| `voorvoegsel` | string | Name prefix ("de", "van der") | `naam.voorvoegsel` |
| `geslachtsnaam` | string | Family name | `naam.geslachtsnaam` |
| `aanduidingNaamgebruik` | string (enum) | E=eigen, P=partner, V=partner+eigen, N=eigen+partner | `naam.aanduidingNaamgebruik.code` |
| `geslachtsaanduiding` | string (enum) | M=man, V=vrouw, O=onbekend | `geslacht.code` |
| `geboortedatum` | string (date) | ISO 8601 | `geboorte.datum` |
| `geboorteplaats` | string | Birth place | `geboorte.plaats.omschrijving` |
| `geboorteland` | string | Birth country | `geboorte.land.omschrijving` |
| `geboortelandCode` | string | Country code | `geboorte.land.code` |
| `nationaliteit` | string | Nationality description | `nationaliteiten[0].nationaliteit.omschrijving` |
| `nationaliteitCode` | string | Nationality code | `nationaliteiten[0].nationaliteit.code` |
| `verblijfplaats` | object | Current address | `verblijfplaats` |
| `verblijfplaats.straat` | string | Street name | `verblijfplaats.naamOpenbareRuimte` |
| `verblijfplaats.huisnummer` | integer | House number | `verblijfplaats.huisnummer` |
| `verblijfplaats.huisletter` | string | House letter (optional) | `verblijfplaats.huisletter` |
| `verblijfplaats.huisnummertoevoeging` | string | Addition (optional) | `verblijfplaats.huisnummertoevoeging` |
| `verblijfplaats.postcode` | string | Postal code (####XX) | `verblijfplaats.postcode` |
| `verblijfplaats.woonplaats` | string | City | `verblijfplaats.woonplaats` |
| `verblijfplaats.adresseerbaarObjectIdentificatie` | string (16 digits) | BAG link | `verblijfplaats.adresseerbaarObjectIdentificatie` |
| `verblijfplaats.nummeraanduidingIdentificatie` | string (16 digits) | BAG link | `verblijfplaats.nummeraanduidingIdentificatie` |
| `verblijfplaats.functieAdres` | string | W=woonadres, B=briefadres | `verblijfplaats.functieAdres.code` |
| `gemeenteVanInschrijving` | string | Municipality name | `gemeenteVanInschrijving.omschrijving` |
| `gemeenteVanInschrijvingCode` | string | Municipality code | `gemeenteVanInschrijving.code` |
| `datumInschrijvingInGemeente` | string (date) | Registration date | `datumInschrijvingInGemeente` |
| `immigratie` | object | Immigration details (optional) | `immigratie` |
| `immigratie.datumVestiging` | string (date) | Settlement date | `immigratie.datumVestigingInNederland` |
| `immigratie.landVanHerkomst` | string | Country of origin | `immigratie.landVanwaarIngeschreven.omschrijving` |
| `overlijden` | object | Death details (optional) | `overlijden` |
| `overlijden.datum` | string (date) | Date of death | `overlijden.datum` |
| `overlijden.plaats` | string | Place of death | `overlijden.plaats.omschrijving` |
| `partners` | array | Partner references | `partners[]` |
| `partners[].burgerservicenummer` | string | Partner's BSN | `partners[].burgerservicenummer` |
| `partners[].naam` | string | Partner's full name | computed |
| `partners[].soortVerbintenis` | string | H=huwelijk, P=partnerschap | `partners[].soortVerbintenis.code` |
| `ouders` | array | Parent references | `ouders[]` |
| `ouders[].burgerservicenummer` | string | Parent's BSN | `ouders[].burgerservicenummer` |
| `ouders[].naam` | string | Parent's full name | computed |
| `ouders[].ouderAanduiding` | string | "1" or "2" | `ouders[].ouderAanduiding` |
| `kinderen` | array | Children references | `kinderen[]` |
| `kinderen[].burgerservicenummer` | string | Child's BSN | `kinderen[].burgerservicenummer` |
| `kinderen[].naam` | string | Child's full name | computed |

### Seed data requirements

- MUST contain at least 30 person records selected from the RVIG test dataset
- MUST include at least 5 complete family units with consistent cross-references
- MUST cover: married couple with children, single parent, deceased person, foreign national, minor with custody, person "in onderzoek"
- MUST span at least 6 municipalities (Amsterdam 0363, Rotterdam 0599, Den Haag 0518, Utrecht 0344, Groningen 0014, Almere 0034)
- All BSNs MUST pass 11-proef validation
- Addresses SHOULD link to BAG mock data via `adresseerbaarObjectIdentificatie` where both registers contain matching records

### Reference codes table

| Code Type | Code | Description |
|-----------|------|-------------|
| Geslacht | M | Man |
| Geslacht | V | Vrouw |
| Geslacht | O | Onbekend |
| Naamgebruik | E | Eigen geslachtsnaam |
| Naamgebruik | P | Naam partner |
| Naamgebruik | V | Partner + eigen |
| Naamgebruik | N | Eigen + partner |
| Verbintenis | H | Huwelijk |
| Verbintenis | P | Geregistreerd partnerschap |
| FunctieAdres | W | Woonadres |
| FunctieAdres | B | Briefadres |
| Land | 6030 | Nederland |
| Land | 5002 | Frankrijk |
| Land | 6003 | Griekenland |
| Land | 6014 | Verenigde Staten |
| Land | 5001 | Canada |
| Nationaliteit | 0001 | Nederlandse |
| Nationaliteit | 0057 | Franse |
| Nationaliteit | 0059 | Griekse |
| Nationaliteit | 0223 | Amerikaans burger |

---

## REQ-MOCK-002: KVK Mock Register (Kamer van Koophandel)

The system MUST provide a mock KVK register with fictional business records aligned to the KVK Handelsregister API data model.

### Data source: KVK test environment

The seed data MUST be derived from the official KVK test environment (`https://api.kvk.nl/test/api/`). This environment is freely accessible with API key `l7xx1f2691f2520d487b902f4e0b57a0b197` (no registration required). The test data uses Disney-themed company names.

| KVK Nummer | Name | Rechtsvorm | Plaats |
|-----------|------|------------|-------|
| `69599084` | Test EMZ Dagobert | Eenmanszaak | Amsterdam |
| `68727720` | Test NV Katrien | Naamloze Vennootschap | Veendam |
| `68750110` | Test BV Donald | Besloten Vennootschap | Lollum |
| `69599068` | Test Stichting Bolderbast | Stichting | Lochem |
| `69599076` | Test VOF Guus | Vennootschap Onder Firma | Almere |
| `90000102` | Stichting Free opentrans | Stichting | Leiden |
| `90001354` | Grand Kontex B.V. | Besloten Vennootschap | Sterksel |
| `55344526` | Regional Stimflex Cooperatie | Cooperatie | (buitenland) |

### Schema: `maatschappelijke-activiteit`

| Property | Type | Description | KVK API field |
|----------|------|-------------|--------------|
| `kvkNummer` | string (8 digits) | Registration number | `kvkNummer` |
| `naam` | string | Primary name | `naam` |
| `handelsnamen` | array | Trade names with ordering | `handelsnamen[].{naam, volgorde}` |
| `rechtsvorm` | string | Legal form description | `_embedded.eigenaar.rechtsvorm` |
| `uitgebreideRechtsvorm` | string | Detailed legal form | `_embedded.eigenaar.uitgebreideRechtsvorm` |
| `formeleRegistratiedatum` | string (date) | Registration date | `formeleRegistratiedatum` (YYYYMMDD→ISO) |
| `materieleRegistratie` | object | Material registration dates | `materieleRegistratie` |
| `materieleRegistratie.datumAanvang` | string (date) | Start date | `materieleRegistratie.datumAanvang` |
| `materieleRegistratie.datumEinde` | string (date) | End date (null=active) | `materieleRegistratie.datumEinde` |
| `totaalWerkzamePersonen` | integer | Total employees | `totaalWerkzamePersonen` |
| `sbiActiviteiten` | array | SBI activity codes | `sbiActiviteiten[]` |
| `sbiActiviteiten[].sbiCode` | string | SBI code | `sbiActiviteiten[].sbiCode` |
| `sbiActiviteiten[].sbiOmschrijving` | string | SBI description | `sbiActiviteiten[].sbiOmschrijving` |
| `sbiActiviteiten[].indHoofdactiviteit` | string | "Ja"/"Nee" | `sbiActiviteiten[].indHoofdactiviteit` |
| `indNonMailing` | string | "Ja"/"Nee" | `indNonMailing` |
| `actief` | boolean | Currently active | computed from datumEinde |

### Schema: `vestiging`

| Property | Type | Description | KVK API field |
|----------|------|-------------|--------------|
| `vestigingsnummer` | string (12 digits) | Branch number | `vestigingsnummer` |
| `kvkNummer` | string (8 digits) | Parent KVK number | `kvkNummer` |
| `eersteHandelsnaam` | string | Primary trade name | `eersteHandelsnaam` |
| `indHoofdvestiging` | string | "Ja"/"Nee" | `indHoofdvestiging` |
| `indCommercieleVestiging` | string | "Ja"/"Nee" | `indCommercieleVestiging` |
| `voltijdWerkzamePersonen` | integer | Full-time employees | `voltijdWerkzamePersonen` |
| `deeltijdWerkzamePersonen` | integer | Part-time employees | `deeltijdWerkzamePersonen` |
| `totaalWerkzamePersonen` | integer | Total employees | `totaalWerkzamePersonen` |
| `adressen` | array | Addresses | `adressen[]` |
| `adressen[].type` | string | "bezoekadres" or "correspondentieadres" | `adressen[].type` |
| `adressen[].straatnaam` | string | Street | `adressen[].straatnaam` |
| `adressen[].huisnummer` | integer | House number | `adressen[].huisnummer` |
| `adressen[].huisletter` | string | House letter | `adressen[].huisletter` |
| `adressen[].postcode` | string | Postal code | `adressen[].postcode` |
| `adressen[].plaats` | string | City | `adressen[].plaats` |
| `adressen[].land` | string | Country | `adressen[].land` |
| `handelsnamen` | array | Trade names | `handelsnamen[].{naam, volgorde}` |
| `sbiActiviteiten` | array | SBI activities | same as parent |

### Seed data requirements

- MUST contain at least 15 business records from the KVK test environment
- MUST include at least 8 vestiging records (some companies have hoofdvestiging + nevenvestiging)
- MUST cover legal forms: BV, NV, Eenmanszaak, Stichting, VOF, Cooperatie
- MUST include at least one inactive business with `datumEinde` set
- MUST span at least 4 provinces
- Addresses SHOULD link to BAG mock data where possible

---

## REQ-MOCK-003: BAG Mock Register (Basisregistratie Adressen en Gebouwen)

The system MUST provide a mock BAG register with address and building records aligned to the Kadaster BAG API v2 / PDOK BAG data model.

### Data source: PDOK (freely accessible, no auth required)

Seed data MUST be obtained from the PDOK BAG OGC API Features endpoint (`https://api.pdok.nl/kadaster/bag/ogc/v2`). This API is freely accessible without authentication and provides the full BAG dataset. Records SHOULD correspond to the addresses used in the BRP and KVK mock registers for cross-referencing.

Additional free sources:
- PDOK Locatieserver: `https://api.pdok.nl/bzk/locatieserver/search/v3_1/free?q={address}`
- BAG Linked Data API: `https://bag.basisregistraties.overheid.nl/api/v1/`

### Schema: `nummeraanduiding`

| Property | Type | Description | BAG API field |
|----------|------|-------------|--------------|
| `identificatie` | string (16 digits) | BAG ID (GGGGTTNNNNNNNNNN) | `identificatie` |
| `huisnummer` | integer | House number (1-99999) | `huisnummer` |
| `huisletter` | string (1) | Optional letter | `huisletter` |
| `huisnummertoevoeging` | string (4) | Optional addition | `huisnummertoevoeging` |
| `postcode` | string (6) | Dutch postcode | `postcode` |
| `status` | string | Current status | `status` |
| `typeAdresseerbaarObject` | string | Verblijfsobject/Standplaats/Ligplaats | `typeAdresseerbaarObject` |
| `openbareRuimteNaam` | string | Street name | from related OpenbareRuimte |
| `woonplaatsNaam` | string | City name | from related Woonplaats |

### Schema: `verblijfsobject`

| Property | Type | Description |
|----------|------|-------------|
| `identificatie` | string (16 digits) | BAG ID (type code `01`) |
| `gebruiksdoel` | array of strings | One or more of 11 values (woonfunctie, kantoorfunctie, etc.) |
| `oppervlakte` | integer | Floor area in m² |
| `status` | string | e.g. "Verblijfsobject in gebruik" |
| `pandIdentificatie` | string (16 digits) | Reference to Pand |
| `nummeraanduidingIdentificatie` | string (16 digits) | Reference to Nummeraanduiding |

### Schema: `pand`

| Property | Type | Description |
|----------|------|-------------|
| `identificatie` | string (16 digits) | BAG ID (type code `10`) |
| `oorspronkelijkBouwjaar` | string (4) | Construction year |
| `status` | string | e.g. "Pand in gebruik" |

### BAG identification format

Format: `GGGGTTNNNNNNNNNN` (16 digits)
- `GGGG` = Municipality code (e.g. `0363` = Amsterdam)
- `TT` = Object type (`01`=Verblijfsobject, `02`=Ligplaats, `03`=Standplaats, `10`=Pand, `20`=Nummeraanduiding, `30`=OpenbareRuimte)
- `NNNNNNNNNN` = Sequential number

### Seed data requirements

- MUST contain at least 30 nummeraanduiding records
- MUST contain at least 20 verblijfsobject records
- MUST contain at least 15 pand records
- Records MUST correspond to addresses used in BRP and KVK seed data
- MUST include multiple gebruiksdoel types (woonfunctie, kantoorfunctie, winkelfunctie)
- MUST span the same municipalities as BRP seed data
- BAG IDs MUST follow the official 16-digit format with correct municipality codes

### Gebruiksdoel enum values

| Value | Description |
|-------|-------------|
| `woonfunctie` | Residential |
| `bijeenkomstfunctie` | Assembly |
| `celfunctie` | Detention |
| `gezondheidszorgfunctie` | Healthcare |
| `industriefunctie` | Industrial |
| `kantoorfunctie` | Office |
| `logiesfunctie` | Lodging |
| `onderwijsfunctie` | Education |
| `sportfunctie` | Sports |
| `winkelfunctie` | Retail |
| `overige gebruiksfunctie` | Other |

---

## REQ-MOCK-004: DSO Mock Register (Digitaal Stelsel Omgevingswet)

The system MUST provide a mock DSO register with environmental permit data aligned to the CIM-OW/IMOW data model.

### Data source: DSO developer portal + Amsterdam Vergunningcheck

DSO APIs require API keys via `developer.omgevingswet.overheid.nl`. For seed data, use the open-source Amsterdam Vergunningcheck data model (https://github.com/Amsterdam/vergunningcheck) and the CIM-OW specification (https://geonovum.github.io/dso-cim-ow/) for structurally correct test records.

### Schema: `activiteit`

| Property | Type | Description |
|----------|------|-------------|
| `identificatie` | string | Unique ID |
| `naam` | string | Activity name (e.g. "Dakkapel plaatsen") |
| `activiteitgroep` | string | Category group |
| `regelkwalificatie` | string (enum) | vergunningplicht, meldingsplicht, informatieplicht, vergunningvrij |
| `bovenliggendeActiviteit` | string | Parent activity (hierarchy) |
| `omschrijving` | string | Description |

### Schema: `locatie`

| Property | Type | Description |
|----------|------|-------------|
| `identificatie` | string | Unique ID |
| `naam` | string | Location name |
| `type` | string | Location type |
| `gemeenteCode` | string | Municipality code |
| `gemeenteNaam` | string | Municipality name |
| `adres` | object | Optional address reference |

### Schema: `omgevingsdocument`

| Property | Type | Description |
|----------|------|-------------|
| `identificatie` | string | Document ID |
| `type` | string (enum) | omgevingsplan, omgevingsverordening, waterschapsverordening, AMvB, ministeriele_regeling |
| `status` | string | Publication status |
| `bevoegdGezag` | string | Authority (OIN) |
| `titel` | string | Document title |
| `publicatiedatum` | string (date) | Publication date |

### Schema: `vergunningaanvraag`

| Property | Type | Description |
|----------|------|-------------|
| `identificatie` | string | Application ID |
| `activiteiten` | array | Referenced activities |
| `locatie` | object | Application location |
| `initiatiefnemer` | object | Applicant details |
| `bevoegdGezag` | string | Competent authority |
| `status` | string (enum) | ingediend, in_behandeling, verleend, geweigerd, ingetrokken |
| `indieningsdatum` | string (date) | Submission date |
| `besluitdatum` | string (date) | Decision date (optional) |
| `bijlagen` | array | Attachments |

### Seed data requirements

- MUST contain at least 20 activiteit records covering common construction scenarios (dakkapel, aanbouw, zonnepanelen, etc.)
- MUST contain at least 10 locatie records in mock municipalities
- MUST contain at least 5 omgevingsdocument records
- MUST contain at least 10 vergunningaanvraag records in various statuses
- Activity hierarchy MUST be consistent (bovenliggendeActiviteit references valid parents)

---

## REQ-MOCK-005: ORI Mock Register (Open Raadsinformatie)

The system MUST provide a mock ORI register with council information aligned to the VNG ODS-Open-Raadsinformatie specification and the Open State Foundation Elasticsearch data model.

### Data source: Open State Foundation API (freely accessible, no auth)

The live ORI Elasticsearch API at `https://api.openraadsinformatie.nl/v1/elastic/` is freely accessible and contains 7.26 million records across 331 municipalities. Seed data SHOULD be derived from real council meetings from one representative municipality (e.g. Utrecht `ori_utrecht*` — richest dataset with all entity types).

Additional sources:
- VNG OAS 2.0 spec: https://github.com/VNG-Realisatie/ODS-Open-Raadsinformatie
- Open State connector source: https://github.com/openstate/open-raadsinformatie

### Schema: `vergadering`

| Property | Type | Description | ORI field |
|----------|------|-------------|----------|
| `naam` | string | Meeting name | `name` |
| `type` | string (enum) | raadsvergadering, commissievergadering, etc. | `classification[]` |
| `status` | string (enum) | gepland, bevestigd, afgelast | mapped from `status` |
| `startDatum` | string (datetime) | Start date/time | `start_date` |
| `eindDatum` | string (datetime) | End date/time | `end_date` |
| `locatie` | string | Meeting location | `location` |
| `organisatie` | string | Organization reference | `organization` |
| `commissie` | string | Committee reference | `committee` |

### Schema: `agendapunt`

| Property | Type | Description |
|----------|------|-------------|
| `onderwerp` | string | Subject/title |
| `omschrijving` | string | Description |
| `volgorde` | integer | Position on agenda |
| `vergadering` | string | Reference to vergadering |
| `bovenliggendAgendapunt` | string | Parent item (for sub-items) |
| `bijlagen` | array | Document references |

### Schema: `raadsdocument`

| Property | Type | Description |
|----------|------|-------------|
| `titel` | string | Document title |
| `type` | string (enum) | motie, amendement, besluit, brief, rapport, notulen |
| `classificatie` | string | Category |
| `url` | string | Document URL |
| `bestandsnaam` | string | File name |
| `bestandsgrootte` | integer | File size in bytes |
| `inhoudType` | string | MIME type |

### Schema: `stemming`

| Property | Type | Description |
|----------|------|-------------|
| `onderwerp` | string | Subject voted on |
| `type` | string | Vote type |
| `resultaat` | string (enum) | aangenomen, verworpen |
| `agendapunt` | string | Reference to agendapunt |
| `stemmenVoor` | integer | Votes in favor |
| `stemmenTegen` | integer | Votes against |
| `onthoudingen` | integer | Abstentions |
| `fractieResultaten` | array | Per-party results |

### Schema: `raadslid`

| Property | Type | Description |
|----------|------|-------------|
| `naam` | string | Full name |
| `fractie` | string | Party/faction reference |
| `functie` | string (enum) | raadslid, wethouder, burgemeester, griffier |
| `actief` | boolean | Currently active |

### Schema: `fractie`

| Property | Type | Description |
|----------|------|-------------|
| `naam` | string | Party name |
| `zetels` | integer | Number of seats |
| `classificatie` | string | coalitiepartij, oppositiepartij |

### Seed data requirements

- MUST contain a fictional municipality "Voorbeeldstad" with:
  - At least 1 raad (council) organization + 3 commissies (committees)
  - At least 8 fracties (parties) reflecting typical Dutch council composition
  - At least 20 raadsleden (council members) distributed across fracties
  - At least 10 vergaderingen (meetings) spanning 6 months
  - At least 30 agendapunten (agenda items)
  - At least 15 raadsdocumenten (documents) of various types
  - At least 5 stemmingen (votes) with per-fractie results
- Data MUST be internally consistent (agendapunten reference valid vergaderingen, etc.)
- Meeting dates SHOULD be on Tuesdays and Thursdays (typical Dutch council schedule)
- Party names SHOULD be fictional but recognizable (e.g. "Voorbeeldstad Vooruit", "Groen Links Voorbeeldstad")

---

## REQ-MOCK-006: Register File Format

Each register MUST be delivered as a `*_register.json` file following the existing `x-openregister` pattern.

### File structure

```json
{
  "openapi": "3.0.0",
  "info": {
    "title": "BRP Mock Register",
    "description": "Mock BRP (Basisregistratie Personen) register with RVIG test data",
    "version": "1.0.0"
  },
  "x-openregister": {
    "type": "mock",
    "app": "openregister",
    "openregister": "^v0.2.10",
    "description": "BRP mock register for development and testing"
  },
  "paths": {},
  "components": {
    "registers": {
      "brp": {
        "slug": "brp",
        "title": "BRP (Basisregistratie Personen)",
        "description": "Mock BRP register with fictional RVIG test persons",
        "folder": "Open Registers/BRP"
      }
    },
    "schemas": {
      "ingeschreven-persoon": { ... }
    },
    "objects": [
      {
        "@self": {
          "register": "brp",
          "schema": "ingeschreven-persoon",
          "slug": "suzanne-moulin"
        },
        "burgerservicenummer": "999993653",
        "voornamen": "Suzanne",
        "geslachtsnaam": "Moulin",
        ...
      }
    ]
  }
}
```

### File naming convention

| Register | File name | Location |
|----------|-----------|----------|
| BRP | `brp_register.json` | `openregister/lib/Settings/` |
| KVK | `kvk_register.json` | `openregister/lib/Settings/` |
| BAG | `bag_register.json` | `openregister/lib/Settings/` |
| DSO | `dso_register.json` | `openregister/lib/Settings/` |
| ORI | `ori_register.json` | `openregister/lib/Settings/` |

### Import mechanism

- Each file MUST be loaded via the existing `RepairStep → SettingsService → ImportHandler` pipeline
- Import MUST be idempotent (skip if register already exists with `force: false`)
- A new app config key `mock_registers_enabled` (default: `true`) MUST control whether mock registers are imported
- Setting `mock_registers_enabled` to `false` MUST prevent import but NOT delete existing mock data

---

## REQ-MOCK-007: Cross-Register Referencing

Mock register data MUST be cross-referenced where the same real-world entity appears in multiple registers.

### Linking strategy

| BRP field | Links to |
|-----------|----------|
| `verblijfplaats.adresseerbaarObjectIdentificatie` | BAG `verblijfsobject.identificatie` |
| `verblijfplaats.nummeraanduidingIdentificatie` | BAG `nummeraanduiding.identificatie` |
| `gemeenteVanInschrijvingCode` | BAG municipality code in identification prefix |

| KVK field | Links to |
|-----------|----------|
| `adressen[].straatnaam + huisnummer + postcode` | BAG `nummeraanduiding` (postcode + huisnummer) |

| DSO field | Links to |
|-----------|----------|
| `locatie.gemeenteCode` | BAG municipality code |
| `vergunningaanvraag.locatie.adres` | BAG `nummeraanduiding` |

### Minimum cross-references

- At least 5 BRP persons MUST have `adresseerbaarObjectIdentificatie` values that match BAG `verblijfsobject` records
- At least 3 KVK vestigingen MUST have addresses that match BAG `nummeraanduiding` records
- At least 3 DSO vergunningaanvragen MUST reference locations matching BAG records

---

## REQ-MOCK-008: OCC Commands

The system MUST provide OCC commands for managing mock register data.

### Commands

| Command | Description |
|---------|-------------|
| `occ openregister:seed-mock-registers` | Seed all mock registers (skip if exists) |
| `occ openregister:seed-mock-registers --force` | Delete and re-seed all mock registers |
| `occ openregister:seed-mock-registers --register=brp` | Seed only the specified register |

---

## Standards & References

| Standard | URL | Relevance |
|----------|-----|-----------|
| Haal Centraal BRP Personen API v2 | https://brp-api.github.io/Haal-Centraal-BRP-bevragen/ | BRP data model |
| RVIG test data | https://www.rvig.nl/proefomgeving-brp-v | BRP test personas |
| BRP mock Docker image | `ghcr.io/brp-api/personen-mock:2.7.0-latest` | Reference implementation |
| BSN 11-proef | https://nl.wikipedia.org/wiki/Burgerservicenummer | BSN validation algorithm |
| KVK test environment | https://developers.kvk.nl/documentation/testing | KVK test data |
| KVK test API key | `l7xx1f2691f2520d487b902f4e0b57a0b197` | Free test access |
| SBI classification | https://www.kvk.nl/over-kvk/over-het-handelsregister/sbi-codes/ | Business activity codes |
| PDOK BAG OGC API | https://api.pdok.nl/kadaster/bag/ogc/v2 | BAG data (free, no auth) |
| BAG Linked Data API | https://bag.basisregistraties.overheid.nl/api/v1/ | BAG records (free, no auth) |
| Kadaster BAG API v2 | https://api.bag.kadaster.nl/lvbag/individuelebevragingen/v2/ | BAG reference (free API key) |
| BAG identification format | https://imbag.github.io/praktijkhandleiding/attributen/identificatie | 16-digit ID format |
| CIM-OW 3.0 | https://geonovum.github.io/dso-cim-ow/ | DSO data model |
| IMOW 3.2-rc | https://docs.geostandaarden.nl/ow/imow/ | DSO implementation model |
| DSO developer portal | https://developer.omgevingswet.overheid.nl/ | DSO API access |
| Amsterdam Vergunningcheck | https://github.com/Amsterdam/vergunningcheck | DSO open-source reference |
| VNG ODS-Open-Raadsinformatie | https://github.com/VNG-Realisatie/ODS-Open-Raadsinformatie | ORI specification |
| Open State Foundation ORI API | https://api.openraadsinformatie.nl/v1/elastic/ | ORI data (free, no auth) |
| Popolo specification | https://www.popoloproject.com/specs/ | ORI data model base |
| GGM (Gemeentelijk Gegevensmodel) | ggm-openregister repository | 955 schemas, potential reuse |

## Current Implementation Status

**Implemented.** All five mock register JSON files exist in `openregister/lib/Settings/` and can be loaded on demand:

| Register | File | Records | Slug | Schemas |
|----------|------|---------|------|---------|
| BRP | `brp_register.json` | 35 persons | `brp` | `ingeschreven-persoon` |
| KVK | `kvk_register.json` | 16 businesses + 14 branches | `kvk` | `maatschappelijke-activiteit`, `vestiging` |
| BAG | `bag_register.json` | 32 addresses + 21 objects + 21 buildings | `bag` | `nummeraanduiding`, `verblijfsobject`, `pand` |
| DSO | `dso_register.json` | 53 records | `dso` | `activiteit`, `locatie`, `omgevingsdocument`, `vergunningaanvraag` |
| ORI | `ori_register.json` | 115 records | `ori` | `vergadering`, `agendapunt`, `raadsdocument`, `stemming`, `raadslid`, `fractie` |

### Using Mock Register Data

**Loading via OCC CLI:**
```bash
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/brp_register.json
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/kvk_register.json
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/bag_register.json
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/dso_register.json
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/ori_register.json
```

**Loading via the API:**
```bash
curl -X POST "http://localhost:8080/index.php/apps/openregister/api/registers/import" \
  -u admin:admin -H "Content-Type: application/json" \
  -d @openregister/lib/Settings/brp_register.json
```

**Querying loaded data:**
```bash
# Find person by BSN
curl "http://localhost:8080/index.php/apps/openregister/api/objects/{brp_register_id}/{person_schema_id}?_search=999993653" -u admin:admin

# Find business by KVK number
curl "http://localhost:8080/index.php/apps/openregister/api/objects/{kvk_register_id}/{business_schema_id}?_search=69599084" -u admin:admin
```

**In Vue frontend stores:**
```javascript
const brpRegisterId = store.getters.getRegisterBySlug('brp')?.id
const personSchemaId = store.getters.getSchemaBySlug('ingeschreven-persoon')?.id
const response = await fetch(`/index.php/apps/openregister/api/objects/${brpRegisterId}/${personSchemaId}?_search=${bsn}`)
```

**Not yet implemented:**
- OCC command `openregister:seed-mock-registers` (REQ-MOCK-008) -- files must be loaded individually via `openregister:load-register`
- App config key `mock_registers_enabled` -- no toggle to control auto-import

**Foundation available:**
- Register/schema creation pipeline is well-established (RepairStep -> SettingsService -> ImportHandler)
- Object seeding via `@self` envelope in `components.objects[]` is proven (OpenCatalogi seeds 8 objects this way)
- The `ggm-openregister` repository provides 955 GGM schemas that could inform field naming
- All external data sources for seed data are freely accessible (PDOK, Open State ORI, KVK test env)
- BRP mock Docker image available for data extraction

## Consuming Apps

| App | Spec | Uses |
|-----|------|------|
| Pipelinq | klantbeeld-360 | BRP + KVK enrichment |
| Pipelinq | kcc-werkplek | BRP + KVK citizen/business identification |
| Pipelinq | prospect-discovery | KVK prospect search |
| Pipelinq | contact-relationship-mapping | BRP family relationships |
| Procest | case-dashboard-view | BRP-persoon linked object |
| Procest | mijn-overheid-integration | BRP BSN lookup |
| Procest | stuf-support | BRP for StUF-BG person queries |
| Procest | zaak-intake-flow | BAG address validation |
| Procest | vth-module | DSO permit integration |
| OpenConnector | dso-omgevingsloket | DSO activity/location data |
| OpenConnector | ibabs-notubiz-connector | ORI council data |

## Specificity Assessment

This spec is implementation-ready. All schemas are fully defined with field types, source mappings, and concrete test data references. The external data sources are verified accessible and documented with URLs and API keys.

**Open questions:**
1. Should the BRP mock data include the full RVIG test set (1182 persons) or a curated subset (30-50)? Recommendation: curated subset with all scenarios covered, keeping file size manageable.
2. Should ORI seed data use real council meeting data from the Open State API or fully fictional "Voorbeeldstad" data? Recommendation: fictional for IP clarity, but structure derived from real Utrecht data.
3. Should the mock register files live in `openregister/lib/Settings/` (loaded always) or in a separate `openregister/data/mock/` directory (loaded only when enabled)? Recommendation: `lib/Settings/` for consistency with existing pattern, gated by `mock_registers_enabled` config.
