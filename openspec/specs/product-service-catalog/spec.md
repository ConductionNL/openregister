---
status: draft
---

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

### Current Implementation Status
- **Not implemented**: No product/service catalog functionality exists in the OpenRegister codebase. There are no UPL, SDG, product, or catalog-related services, controllers, or entities.
- **Foundation available**: OpenRegister's schema system can store product data as register objects with custom properties. The existing CRUD API, RBAC, and multi-tenancy infrastructure could serve as the foundation.
- **Configuration export/import exists**: `ConfigurationService` (`lib/Service/ConfigurationService.php`) and its handlers (`lib/Service/Configuration/ExportHandler.php`, `ImportHandler.php`) handle register/schema configuration export/import, which could be used to distribute a standard PDC schema template.
- **Public API support exists**: The existing `ObjectsController` supports public read access for published objects, which would support the public catalog API requirement.

### Standards & References
- Uniforme Productnamenlijst (UPL) — maintained by VNG/Logius: https://standaarden.overheid.nl/upl
- Single Digital Gateway (SDG) Regulation (EU) 2018/1724 — EU cross-border service discovery
- OWMS (Overheid Web Metadata Standaard) for government metadata
- SDG doelgroep classification (burger, bedrijf, burger_bedrijf)
- Dutch government PDC standards (Producten- en Dienstencatalogus)
- Accept-Language header (RFC 7231) for content negotiation
- Common Ground principles for API design

### Specificity Assessment
- **Moderately specific**: The spec covers UPL compliance, SDG classification, content blocks, publication lifecycle, pricing, multilingual content, and public API access.
- **Missing details**:
  - Data model: Should products be a dedicated schema or a generic register schema with conventions?
  - UPL reference list: How is the UPL list imported and kept up to date?
  - Content block structure: Are blocks free-form or a fixed set?
  - Multilingual content storage: Separate properties per language or a nested translation structure?
  - SDG integration: How is the SDG feed generated and published?
  - Admin UI: What does the product editing interface look like?
- **Open questions**:
  - Should this be a separate Nextcloud app (like OpenCatalogi) or part of OpenRegister core?
  - How does this relate to OpenCatalogi's existing catalog functionality?
  - Is the UPL reference list stored as a register schema or as a static lookup?

## Nextcloud Integration Analysis

**Status**: Not yet implemented. No product/service catalog functionality exists. OpenRegister's schema system, public API, and configuration import/export provide the foundation.

**Nextcloud Core Interfaces**:
- `ISearchProvider` (`OCP\Search\IProvider`): Register a `ProductSearchProvider` for Nextcloud's unified search so that products are discoverable through the global search bar. Results link to product detail pages via the deep link registry.
- `routes.php`: Expose a public read-only API endpoint (e.g., `/api/pdc/products`) that serves published products without authentication, supporting Accept-Language content negotiation for multilingual responses per RFC 7231.
- `IAppConfig`: Store PDC configuration (UPL reference list URL, SDG doelgroep options, default content block definitions) in Nextcloud app configuration. The UPL list can be cached in `IAppConfig` and refreshed periodically via a `TimedJob`.
- `ICapability`: Expose PDC availability and supported languages via Nextcloud capabilities, enabling municipal website integrations to discover the catalog endpoint programmatically.

**Implementation Approach**:
- Model products as OpenRegister objects in a dedicated `pdc` register with a `product` schema. Schema properties include: `uplNaam`, `uplUri`, `publicNaam`, `samenvatting`, `doelgroep`, `contentBlocks` (JSON array), `pricing` (structured object), `translations` (nested object keyed by language code), and `publicationStatus`.
- Import the UPL reference list as a separate schema in the PDC register (or as a lookup table). A `UplValidationHandler` checks `uplUri` values against the imported list on product save, warning but not blocking on unrecognized URIs.
- Implement content negotiation in the public API controller using Nextcloud's `IRequest::getHeader('Accept-Language')`. The controller selects the appropriate translation from the product's `translations` property, falling back to Dutch.
- Publication lifecycle is handled via a `publicationStatus` property (`concept`, `gepubliceerd`, `gedepubliceerd`) with date-based visibility. The public API filters on `publicationStatus = gepubliceerd` and `publicationDate <= now`.
- SDG feed generation can be implemented as a scheduled export (`QueuedJob`) that generates an SDG-compliant JSON feed of products classified by doelgroep.

**Dependencies on Existing OpenRegister Features**:
- `ObjectService` — CRUD for product objects with filtering and pagination.
- `SchemaService` — schema definitions with property validation for UPL URIs and structured content.
- `ConfigurationService` / `ImportHandler` — distribute pre-built PDC schema templates.
- Public API infrastructure — existing unauthenticated read endpoints for published objects.
- `DeepLinkRegistryService` — register product detail page URLs for unified search integration.
