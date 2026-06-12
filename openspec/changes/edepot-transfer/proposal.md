# Proposal: edepot-transfer

## Summary

Implement the ability to transfer OpenRegister objects and their associated files to e-Depot (regional digital archive) systems, with full MDTO/TMLO metadata compliance and durable format conversion (PDF/A, ODF). This enables Dutch government organisations to meet their legal obligation to transfer permanent records to a regional or national archive.

## Demand Evidence

**Cluster: e-Depot (digital archive)** -- 117 tenders, 247 requirements
**Cluster: MDTO (metadata standard)** -- 98 tenders, 196 requirements
**Cluster: TMLO (metadata local government)** -- 58 tenders, 104 requirements
**Combined**: 273 tenders, 547 requirements (with overlap across clusters)

### Sample Requirements from Tenders

1. **Gemeente Berkelland**: "Het is mogelijk om te zijner tijd zaken en documenten over te dragen aan een e-depot voorziening, dan wel een andere RM applicatie, waarbij zonder informatieverlies alle benodigde bestanden en metadata worden overgedragen."
2. **Gemeente Hilversum**: "Bestanden die horen bij een te archiveren zaak, worden zowel in het oorspronkelijke als in een duurzaam archief bestandsformaat gearchiveerd. De voorkeursformaten zoals omschreven in de Specificatie..."
3. **Gemeente Hilversum**: "Met de Oplossing is het mogelijk om geautomatiseerd gearchiveerde digitale zaken over te dragen aan andere RMA systemen en eDepots waarbij de bestanden, metadata en dossiers worden omgezet in een formeel voorgeschreven formaat (SIP)."
4. **Gemeente Winterswijk**: "RMA: eDepot Achterhoek. TMLO-Achterhoek. Beschrijf op welke manier het overbrengen van gearchiveerde digitale zaken aan andere RMA-systemen of e-Depots verloopt."
5. **Gemeente Zeist**: "De Oplossing levert de functionaliteiten om aan de normen van NEN-2082 of ISO 16175-2:2011 alsmede naar 15489-1 (2016) en 23081-1 (2017) te kunnen voldoen en ondersteunt het TMLO (en MDTO zodra van toepassing)."
6. **Gemeenschappelijke Regeling Omgevingsdienst**: "De MDTO-standaard moet worden gevolgd door de leverancier."

## Scope

### In Scope

- **MDTO metadata mapping**: Map OpenRegister object metadata to MDTO (Metagegevens Duurzaam Toegankelijke Overheidsinformatie) schema fields
- **TMLO backward compatibility**: Support TMLO (Toepassingsprofiel Metadatering Lokale Overheden) for organisations not yet migrated to MDTO
- **SIP package generation**: Generate Submission Information Packages (SIP) conforming to e-Depot ingest specifications, containing objects, files, and metadata
- **Durable format conversion**: Convert documents to archival formats before transfer (PDF/A-1b or PDF/A-2b for documents, ODF for spreadsheets, TIFF for images)
- **Transfer workflow**: Multi-step process: select objects for transfer, validate metadata completeness, generate SIP, submit to e-Depot, confirm receipt
- **e-Depot connector**: Configurable API connector supporting common Dutch e-Depot systems (Preservica, Archivematica, Het Utrechts Archief, e-Depot Achterhoek)
- **Transfer status tracking**: Track transfer status per object (pending, submitted, accepted, rejected) with error reporting
- **Metadata completeness validation**: Pre-transfer check that all required MDTO/TMLO fields are populated

### Out of Scope

- Retention period management (separate change: `retention-management`)
- Destruction workflow (separate change: `archival-destruction-workflow`)
- CSV import/export (already exists)
- Physical archive management

## Acceptance Criteria

1. Objects can be selected for e-Depot transfer individually or in bulk (by schema, register, or retention category)
2. MDTO metadata is generated from OpenRegister object properties with configurable field mapping
3. SIP packages are generated containing all object data, files, and MDTO/TMLO metadata in the required XML format
4. Documents are automatically converted to durable formats (PDF/A, ODF) before inclusion in SIP packages
5. Metadata completeness is validated before transfer -- missing required fields block the transfer with clear error messages
6. Transfer status is tracked per object and visible in the object detail view
7. At least one e-Depot system can be connected via API (Preservica or equivalent)
8. Failed transfers can be retried without data duplication
9. TMLO output is available as a fallback for organisations not yet on MDTO

## Dependencies

- **retention-management**: Transfer typically happens after retention period assessment
- **enhanced-audit-trail**: All transfers must be logged in the audit trail
- OpenRegister ObjectService and file handling
- Docudesk or equivalent for format conversion (PDF/A, ODF)
- External e-Depot API endpoint (configurable per installation)

## Standards & Regulations

- MDTO (Metagegevens Duurzaam Toegankelijke Overheidsinformatie) -- replaces TMLO
- TMLO (Toepassingsprofiel Metadatering Lokale Overheden) -- legacy support
- NEN 2082:2008 (Eisen voor functionaliteit van informatie- en archiefmanagement)
- NEN-ISO 16175-2:2011
- NEN-ISO 15489-1:2016 and NEN-ISO 23081-1:2017
- OAIS reference model (ISO 14721) for SIP/AIP/DIP concepts
- Archiefwet 1995 (Article 12: transfer obligation)
- Specificatie Duurzame Toegankelijkheid (preferred archival formats)

## Notes

- OpenRegister already has CSV import/export with ID support -- this change focuses on archival transfer
- Durable format conversion may leverage Docudesk capabilities already present in the ecosystem
- Many regional archives have their own TMLO profiles (e.g., TMLO-Achterhoek) -- the mapping must be configurable
