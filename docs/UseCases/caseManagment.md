---
title: Dutch Case Management (ZGW)
sidebar_position: 2
---

import Tabs from '@theme/Tabs';
import TabItem from '@theme/TabItem';

# Dutch Case Management (ZGW)

## Project Introduction

The Dutch Case Management (Zaakgericht Werken, ZGW) implementation is a collaborative initiative between Open Register and Dutch municipalities. This project aims to implement the Dutch ZGW standards in a practical, interoperable register that can be used by governments throughout the Netherlands to manage cases in a standardized way.

### Dutch Collaboration 

This project represents a national effort to standardize case management in public administration:

- **VNG Realisatie** provides the ZGW API standards and specifications
- **Dutch municipalities** contribute practical implementation experience
- **Open Register** offers the technical framework for implementing standardized registers

Together, we're working to create a reference implementation that demonstrates how Dutch ZGW standards can be applied in practice to create interoperable case management systems.

### Common Ground Integration

This project aligns with the [Common Ground](https://commonground.nl/) principles developed in the Netherlands, which promote:

1. **Component-based architecture** - Building modular, reusable components
2. **Data at the source** - Storing data once and using it multiple times
3. **Standard APIs** - Using standardized interfaces for data exchange
4. **Open standards** - Adopting open standards for interoperability

The Case Management Register serves as a key building block in the Common Ground ecosystem, providing a standardized way to store and access case information across different government services and applications.

### Connectivity through Standardization

By implementing Dutch ZGW standards in a practical register, this project contributes to the broader goal of standardized case management in Dutch government organizations.

Key standardization efforts we're building upon include:

- **ZGW API Standards** - Core standards for case-oriented working
- **NL API Strategy** - Guidelines for API development in Dutch government
- **Common Ground** - Vision for modern government information provision
- **Dutch Government Reference Architecture (NORA)** - Architecture principles

## Purpose and Scope

This document presents research and implementation guidance for building case management registers based on Dutch ZGW standards. It aims to:

1. **Implement ZGW standards** for case management
2. **Compare different approaches** to implementing these standards
3. **Provide practical guidance** for implementing a standards-compliant case register
4. **Demonstrate interoperability** with existing systems

The resulting case register design serves as a reference implementation that can be adapted by government agencies across the Netherlands while maintaining interoperability.

## References and Standards

This research and implementation guide draws upon the following standards and references:

### Core Standards
- [ZGW API Standards](https://vng-realisatie.github.io/gemma-zaken/) - Core case management standards
- [Catalogi API](https://vng-realisatie.github.io/gemma-zaken/standaard/catalogi/index) - Case type definitions
- [Zaken API](https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/index) - Case management
- [Documenten API](https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/index) - Document management
- [Besluiten API](https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/index) - Decision management

### Dutch Government Standards
- [NL API Strategy](https://docs.geostandaarden.nl/api/API-Strategie/) - API development guidelines
- [NORA](https://www.noraonline.nl/wiki/NORA_online) - Dutch Government Reference Architecture
- [GEMMA](https://www.gemmaonline.nl/) - Municipal Reference Architecture
- [StUF](https://www.gemmaonline.nl/index.php/StUF_Berichtenstandaard) - Legacy messaging standard

### Common Ground Standards
- [Common Ground](https://commonground.nl/) - Vision and principles
- [Haven](https://haven.commonground.nl/) - Component catalog
- [NLX](https://nlx.io/) - Secure data exchange

## Case Management Components

The ZGW standards define several core components for case management:

### Catalogi (Case Types)

The Catalogi API defines case types and their properties:

<Tabs>
<TabItem value="zaaktype" label="Case Type">

| Property | Description | Example |
|----------|-------------|---------|
| identificatie | Unique identifier | "ZAAKTYPE-2023-001" |
| omschrijving | Description | "Building permit application" |
| doel | Purpose | "Process building permit requests" |
| aanleiding | Trigger | "Citizen submits permit request" |
| toelichting | Explanation | "Detailed process description..." |
| servicenorm | Service standard | "8 weeks" |
| doorlooptijd | Processing time | "P56D" (ISO 8601 duration) |
| vertrouwelijkheidaanduiding | Confidentiality | "openbaar" |
| statustypen | Status types | Array of possible statuses |
| resultaattypen | Result types | Array of possible results |

</TabItem>

<TabItem value="statustype" label="Status Type">

| Property | Description | Example |
|----------|-------------|---------|
| omschrijving | Description | "In treatment" |
| statustekst | Status text | "Application is being processed" |
| volgnummer | Sequence number | 2 |
| isEindstatus | Is final status | false |

</TabItem>

<TabItem value="resultaattype" label="Result Type">

| Property | Description | Example |
|----------|-------------|---------|
| omschrijving | Description | "Permit granted" |
| resultaattypeomschrijving | Result type | "Verleend" |
| archiefnominatie | Archive designation | "blijvend_bewaren" |
| archiefactietermijn | Archive retention | "P5Y" |

</TabItem>
</Tabs>

### Zaken (Cases)

The Zaken API manages actual cases:

<Tabs>
<TabItem value="zaak" label="Case">

| Property | Description | Example |
|----------|-------------|---------|
| identificatie | Case number | "ZAAK-2023-0001234" |
| zaaktype | Case type reference | URI to case type |
| status | Current status | URI to status |
| omschrijving | Description | "Building permit 123 Main St" |
| startdatum | Start date | "2023-06-15" |
| einddatum | End date | "2023-08-14" |
| registratiedatum | Registration date | "2023-06-15" |
| verantwoordelijkeOrganisatie | Responsible org | "123456789" |
| vertrouwelijkheidaanduiding | Confidentiality | "openbaar" |

</TabItem>

<TabItem value="status" label="Status">

| Property | Description | Example |
|----------|-------------|---------|
| statustype | Status type reference | URI to status type |
| datumStatusGezet | Status date | "2023-06-15T14:30:00Z" |
| statustoelichting | Status explanation | "Documents received" |

</TabItem>

<TabItem value="resultaat" label="Result">

| Property | Description | Example |
|----------|-------------|---------|
| resultaattype | Result type reference | URI to result type |
| toelichting | Explanation | "All requirements met" |
| datum | Result date | "2023-08-14" |

</TabItem>
</Tabs>

### Documenten (Documents)

The Documenten API manages case-related documents:

<Tabs>
<TabItem value="document" label="Document">

| Property | Description | Example |
|----------|-------------|---------|
| identificatie | Document ID | "DOC-2023-0001234" |
| bronorganisatie | Source organization | "123456789" |
| creatiedatum | Creation date | "2023-06-15" |
| titel | Title | "Building plans" |
| auteur | Author | "John Smith" |
| status | Status | "definitief" |
| formaat | Format | "application/pdf" |
| taal | Language | "nl" |
| versie | Version | "1.0" |
| bestandsnaam | Filename | "building_plans.pdf" |

</TabItem>

<TabItem value="gebruiksrechten" label="Usage Rights">

| Property | Description | Example |
|----------|-------------|---------|
| startdatum | Start date | "2023-06-15" |
| omschrijving | Description | "Copyright protected" |
| einddatum | End date | "2024-06-15" |

</TabItem>
</Tabs>

### Besluiten (Decisions)

The Besluiten API manages formal decisions:

<Tabs>
<TabItem value="besluit" label="Decision">

| Property | Description | Example |
|----------|-------------|---------|
| identificatie | Decision ID | "BES-2023-0001234" |
| verantwoordelijkeOrganisatie | Responsible org | "123456789" |
| datum | Decision date | "2023-08-14" |
| ingangsdatum | Effective date | "2023-09-01" |
| vervaldatum | Expiry date | "2024-09-01" |
| toelichting | Explanation | "Permit granted based on..." |
| bestuursorgaan | Administrative body | "College van B&W" |
| zaak | Related case | URI to case |

</TabItem>
</Tabs>

## API Specification

The complete API specifications are available as OpenAPI 3.0 documents:

- [Catalogi API](https://vng-realisatie.github.io/gemma-zaken/standaard/catalogi/openapi.yaml)
- [Zaken API](https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/openapi.yaml)
- [Documenten API](https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/openapi.yaml)
- [Besluiten API](https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/openapi.yaml)

## Overview Relationships Between Entities

The ZGW components are interconnected as shown in this diagram:


The diagram shows how:

1. **Case Types** define the structure and workflow
2. **Cases** are instances of case types
3. **Documents** are linked to cases
4. **Decisions** are based on cases
5. **Statuses** track case progress
6. **Results** record case outcomes

## Validation Resources

To ensure compliance with ZGW standards, the following validation resources are available:

- [API Test Platform](https://api-test.nl/) - API compliance testing
- [ZGW Reference Implementation](https://github.com/vng-realisatie/gemma-zaken-test-integratie) - Reference implementation
- [Common Ground Component Catalog](https://componentencatalogus.commonground.nl/) - Component validation

By using these validation tools during implementation, you can ensure that your case register meets all applicable standards and requirements.

## Implementation Guidelines

When implementing the ZGW standards, consider these guidelines:

1. **Use URIs for References**
   - All references between objects should use URIs
   - URIs should be resolvable within the network

2. **Implement Proper Versioning**
   - Version all API endpoints
   - Track object versions where needed
   - Maintain backward compatibility

3. **Follow Security Guidelines**
   - Implement OAuth2/OpenID Connect
   - Use proper scopes for authorization
   - Follow NL API Security requirements

4. **Support Audit Trail**
   - Log all changes to objects
   - Track who made changes
   - Maintain change history

5. **Enable Notifications**
   - Implement Notificaties API
   - Send notifications for important events
   - Allow webhook subscriptions

These guidelines help ensure a robust and compliant implementation of the ZGW standards.