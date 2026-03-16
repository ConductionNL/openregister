# product-service-catalog Specification

## Purpose
Implement a government product and service catalog (PDC - Producten- en Dienstencatalogus) conforming to the Uniforme Productnamenlijst (UPL) and Single Digital Gateway (SDG) standards. Products MUST support structured content blocks, publication lifecycle, target audience classification, pricing, and multilingual content for cross-border EU access.

**Source**: Gap identified in cross-platform analysis; mandated standard for Dutch municipalities.

## ADDED Requirements

### Requirement: Products MUST be stored as register objects with UPL compliance
Products MUST conform to the UPL standard, using the official product name list maintained by VNG/Logius.

#### Scenario: Create a product linked to UPL
- GIVEN the UPL reference list is available in the system
- WHEN the admin creates a product:
  - `uplNaam`: `Paspoort` (from UPL list)
  - `uplUri`: `http://standaarden.overheid.nl/owms/terms/Paspoort`
  - `publicNaam`: `Paspoort aanvragen`
  - `samenvatting`: `Vraag een nieuw paspoort aan bij uw gemeente.`
- THEN the product MUST be linked to the UPL entry
- AND the UPL URI MUST be validated against the official list

#### Scenario: Reject product with invalid UPL reference
- GIVEN a UPL URI that does not exist in the reference list
- WHEN the admin tries to create a product with this URI
- THEN the system MUST warn that the UPL reference is not recognized
- AND the admin MAY proceed (new products may not yet be in UPL)

### Requirement: Products MUST support SDG target audience classification
Products MUST be classifiable by SDG doelgroep (target audience) for EU cross-border service discovery.

#### Scenario: Classify product for citizens and businesses
- GIVEN a product `Omgevingsvergunning`
- WHEN the admin sets doelgroepen: `burger`, `bedrijf`
- THEN the product MUST be discoverable for both citizens and businesses in the SDG catalog
- AND the valid doelgroep values MUST be: `burger`, `bedrijf`, `burger_bedrijf`

### Requirement: Products MUST support structured content blocks
Product information MUST be organized in structured content blocks for consistent presentation.

#### Scenario: Configure product content blocks
- GIVEN a product `Paspoort aanvragen`
- WHEN the admin adds content blocks:
  - `wat_is_het`: description of the product
  - `hoe_werkt_het`: step-by-step process
  - `wat_kost_het`: pricing information
  - `wat_heb_ik_nodig`: required documents
  - `aanvraag_link`: URL to the application form
- THEN each content block MUST be stored as a structured section of the product

### Requirement: Products MUST support a publication lifecycle
Products MUST have a publication state controlling visibility in the public catalog.

#### Scenario: Publish a product
- GIVEN a product in status `concept`
- WHEN the admin publishes the product with publication date `2026-04-01`
- THEN the product MUST become visible in the public API from that date
- AND the product MUST NOT be visible before the publication date

#### Scenario: Depublish a product
- GIVEN a published product `Paspoort aanvragen`
- WHEN the admin depublishes the product
- THEN the product MUST be removed from the public API
- AND existing links MUST return HTTP 410 Gone with a redirect to the catalog index

### Requirement: Products MUST support pricing
Product pricing MUST support static prices, price ranges, and structured tariff tables.

#### Scenario: Simple static price
- GIVEN a product `Paspoort`
- WHEN the admin sets price: EUR 75.80
- THEN the product MUST display the price in the catalog

#### Scenario: Age-dependent pricing
- GIVEN a product `Paspoort` with different prices by age
- WHEN the admin configures:
  - `18+`: EUR 75.80
  - `< 18`: EUR 56.55
- THEN the pricing table MUST be displayed on the product page

### Requirement: Products MUST support multilingual content
Product content MUST support at minimum Dutch and English for SDG compliance.

#### Scenario: Product with Dutch and English content
- GIVEN a product `Paspoort aanvragen`
- WHEN the admin provides:
  - NL: `Vraag een nieuw paspoort aan bij uw gemeente.`
  - EN: `Apply for a new passport at your municipality.`
- THEN both translations MUST be stored and accessible via Accept-Language negotiation

### Requirement: The catalog MUST provide a public read-only API
Products MUST be accessible via a public API without authentication for integration with municipal websites.

#### Scenario: Public product listing
- GIVEN 50 published products
- WHEN an unauthenticated client requests GET /api/products
- THEN only published products MUST be returned
- AND each product MUST include: name, summary, content blocks, pricing, and UPL URI
