---
title: Building Client Registers with Open Register
sidebar_position: 1
---

# Building Client Registers with Open Register

## References and Standards

This research and implementation guide draws upon the following standards and references:

### Core Standards
- [vCard Format Specification (RFC 6350)](https://datatracker.ietf.org/doc/html/rfc6350) - Contact information format
- [iCalendar Format (RFC 5545)](https://datatracker.ietf.org/doc/html/rfc5545) - Calendar and task data format
- [Internet Message Format (RFC 5322)](https://datatracker.ietf.org/doc/html/rfc5322) - Email message format
- [JSON Meta Application Protocol (JMAP)](https://jmap.io/) - Modern email and object protocol

### Semantic Web Standards
- [Schema.org Person](https://schema.org/Person) - Person entity definition
- [Schema.org Organization](https://schema.org/Organization) - Organization entity definition
- [Schema.org PlanAction](https://schema.org/PlanAction) - Task/action representation
- [Schema.org Message](https://schema.org/Message) - Message representation
- [Schema.org Comment](https://schema.org/Comment) - Note/comment representation

### Business Standards
- [Universal Business Language (UBL) 2.1](http://docs.oasis-open.org/ubl/os-UBL-2.1/UBL-2.1.html) - Business document schemas
- [UBL Party Schema](http://docs.oasis-open.org/ubl/os-UBL-2.1/UBL-2.1.html#S-PARTY) - Business party representation

### European Standards
- [EU Core Vocabularies](https://joinup.ec.europa.eu/collection/semantic-interoperability-community-semic/solution/e-government-core-vocabularies) - Simplified data models
- [Core Person Vocabulary](https://joinup.ec.europa.eu/collection/semantic-interoperability-community-semic/solution/core-person-vocabulary) - Person data model
- [Core Business Vocabulary](https://joinup.ec.europa.eu/collection/semantic-interoperability-community-semic/solution/core-business-vocabulary) - Business data model
- [Core Location Vocabulary](https://joinup.ec.europa.eu/collection/semantic-interoperability-community-semic/solution/core-location-vocabulary) - Location data model
- [Core Public Organization Vocabulary](https://joinup.ec.europa.eu/collection/semantic-interoperability-community-semic/solution/core-public-organisation-vocabulary) - Public organization model
- [DCAT Application Profile](https://joinup.ec.europa.eu/collection/semantic-interoperability-community-semic/solution/dcat-application-profile-data-portals-europe) - Metadata specification

### Commercial CRM Systems
- [Salesforce API Documentation](https://developer.salesforce.com/docs/atlas.en-us.api.meta/api/data_model.htm) - Salesforce data model
- [Microsoft Dynamics 365 Entity Reference](https://learn.microsoft.com/en-us/dynamics365/customerengagement/on-premises/developer/entities/account) - Dynamics 365 entities
- [Exact Online REST API](https://start.exactonline.nl/docs/HlpRestAPIResources.aspx?SourceAction=10) - Exact Online resources

## Introduction

This tutorial walks through creating a comprehensive client management system using Open Register, with APIs for client information, tasks, messages, and notes.

## Overview

We'll build a complete client management system with the following components:
- Client information (based on vCard standard)
- Tasks associated with clients
- Messages for client communication
- Notes for client records

Each component will be implemented as a register in Open Register, with proper schemas and relationships.

## Client Information Based on vCard

For our client information, we'll use the [vCard standard](https://datatracker.ietf.org/doc/html/rfc6350) (RFC 6350), which is an international standard for contact information exchange.

### Why vCard?

vCard is an ideal foundation for client data because:
1. It's an established international standard
2. It provides a comprehensive structure for contact information
3. It supports both individuals and organizations
4. It's extensible for custom requirements
5. It's widely supported across systems

### Key vCard Properties for Clients

The vCard standard includes many properties that are useful for client information:

| Property | Description | Example |
|----------|-------------|---------|
| FN | Formatted Name | "John Smith" |
| N | Structured Name | ["Smith", "John", "", "Dr.", "Jr."] |
| ORG | Organization | ["Acme, Inc.", "Marketing"] |
| TITLE | Title | "Director" |
| PHOTO | Photo/Avatar | [Binary data or URL] |
| TEL | Telephone | "+1-555-123-4567" |
| EMAIL | Email | "john.smith@example.com" |
| ADR | Address | ["", "", "123 Main St", "Anytown", "CA", "91921", "USA"] |
| BDAY | Birthday | "1973-04-22" |
| URL | Website | "https://example.com" |
| NOTE | Notes | "Prefers email contact" |

## Tasks Based on iCalendar

For tasks, we'll use the [iCalendar standard](https://datatracker.ietf.org/doc/html/rfc5545) (RFC 5545), specifically the VTODO component, which is designed for task/to-do items.

### Why iCalendar?

iCalendar is ideal for tasks because:
1. It's an established international standard for calendar data
2. It includes a dedicated VTODO component for tasks
3. It supports scheduling, reminders, and recurrence
4. It's compatible with many calendar applications
5. It provides a comprehensive set of properties for task management

### Key iCalendar Properties for Tasks

The iCalendar VTODO component includes many properties useful for task management:

| Property | Description | Example |
|----------|-------------|---------|
| SUMMARY | Task title | "Call client about project" |
| DESCRIPTION | Detailed description | "Discuss timeline and requirements" |
| DUE | Due date/time | "20230615T160000Z" |
| DTSTART | Start date/time | "20230610T140000Z" |
| COMPLETED | Completion date/time | "20230612T153000Z" |
| STATUS | Current status | "NEEDS-ACTION", "IN-PROCESS", "COMPLETED", "CANCELLED" |
| PRIORITY | Priority level (1-9) | "1" (highest) |
| CATEGORIES | Categories/tags | "Client", "Sales", "Follow-up" |
| RELATED-TO | Related items | UUID of related task or client |
| ORGANIZER | Person responsible | "mailto:jane.doe@example.com" |
| ATTENDEE | People involved | "mailto:john.smith@example.com" |
| PERCENT-COMPLETE | Completion percentage | "75" |
| RRULE | Recurrence rule | "FREQ=WEEKLY;BYDAY=MO" |

## Messages

For client messages, we'll create a schema inspired by email and messaging standards, designed to track all communications with clients.

### Key Message Properties

| Property | Description | Example |
|----------|-------------|---------|
| subject | Message subject | "Project Update - June 2023" |
| body | Message content | "Dear John, I'm writing to update you on..." |
| from | Sender information | {"name": "Jane Doe", "email": "jane.doe@example.com"} |
| to | Recipient information | [{"name": "John Smith", "email": "john.smith@example.com"}] |
| cc | Carbon copy recipients | [{"name": "Alice Brown", "email": "alice@example.com"}] |
| sentAt | When message was sent | "2023-06-10T14:30:00Z" |
| receivedAt | When message was received | "2023-06-10T14:31:05Z" |
| readAt | When message was read | "2023-06-10T15:45:22Z" |
| attachments | File attachments | [{"name": "proposal.pdf", "url": "https://..."}] |
| thread | Thread identifier | "thread-123456" |
| channel | Communication channel | "email", "sms", "chat", "phone" |
| direction | Message direction | "inbound", "outbound" |
| status | Delivery status | "sent", "delivered", "read", "failed" |

## Notes

For client notes, we'll create a simple but flexible schema to capture important information and observations about clients.

### Key Note Properties

| Property | Description | Example |
|----------|-------------|---------|
| title | Note title | "Meeting Summary - June 10" |
| content | Note content | "Met with client to discuss new requirements..." |
| createdBy | Author information | {"id": "user-123", "name": "Jane Doe"} |
| createdAt | Creation timestamp | "2023-06-10T16:30:00Z" |
| updatedAt | Last update timestamp | "2023-06-11T09:15:00Z" |
| tags | Categorization tags | ["meeting", "requirements", "important"] |
| visibility | Who can see the note | "private", "team", "public" |
| pinned | Whether note is pinned | true/false |

## Relationships Between Entities

All these entities are interconnected in our client management system:

![Entity Relationships](clientRegisters.svg)

The diagram above shows how:

1. **Clients** are the central entity
2. **Tasks** are associated with clients (one client can have many tasks)
3. **Messages** are linked to clients (communication history)
4. **Notes** are attached to clients (observations and information)
5. Tasks can be related to other tasks (for dependencies or subtasks)

In the next sections, we'll define the API endpoints for each entity and show how to implement them in Open Register.

## Standards Comparison and Justification

In our API design, we've based our entities on established standards. Let's compare our implementations with multiple standards to ensure alignment with widely-adopted practices and justify our design choices.

### Client (Person/Organization)

Our client object is primarily based on the [vCard standard](https://datatracker.ietf.org/doc/html/rfc6350) (RFC 6350), but we should also consider [Schema.org's Person](https://schema.org/Person) and [Organization](https://schema.org/Organization) types, as well as [UBL's Party](http://docs.oasis-open.org/ubl/os-UBL-2.1/UBL-2.1.html#S-PARTY) concept.

| Our Property | vCard Property | Schema.org Property | UBL Element | Notes |
|--------------|----------------|---------------------|-------------|-------|
| fn | FN | name | Name | Formatted name across standards |
| n | N | givenName, familyName | PersonName | Structured name components |
| org | ORG | worksFor, employee | PartyName | Organization affiliation |
| title | TITLE | jobTitle | JobTitle | Professional title |
| photo | PHOTO | image | - | Photo/avatar (not in UBL) |
| tel | TEL | telephone | Telephone | Phone numbers |
| email | EMAIL | email | ElectronicMail | Email addresses |
| adr | ADR | address | PostalAddress | Physical addresses |
| bday | BDAY | birthDate | BirthDate | Birth date |
| url | URL | url | WebsiteURI | Websites |
| note | NOTE | description | Note | Notes/description |

**Justification for vCard as primary standard:**

1. **Widespread adoption**: vCard is implemented in virtually all contact management systems
2. **Simplicity**: vCard provides a straightforward structure that's easy to implement
3. **Flexibility**: Supports both individuals and organizations in a single format
4. **Extensibility**: Allows for custom properties while maintaining compatibility
5. **Interoperability**: Enables data exchange with other systems

**UBL considerations:**

UBL (OASIS Universal Business Language) provides a rich set of business document schemas, including detailed party (customer/supplier) information. While more comprehensive for business contexts, UBL's Party structure is significantly more complex than vCard:

- UBL separates Person and Organization into distinct structures
- UBL includes extensive business-specific elements like tax information, legal classification, etc.
- UBL addresses are more structured with separate elements for each address component

For our client register, vCard offers the right balance of simplicity and completeness, while we can incorporate selected UBL concepts for business-specific extensions.

### Task

Our task object is based on [iCalendar's VTODO component](https://datatracker.ietf.org/doc/html/rfc5545) (RFC 5545), which can be compared to [Schema.org's Action](https://schema.org/Action) types, particularly [PlanAction](https://schema.org/PlanAction). UBL doesn't have a direct equivalent but includes concepts in its [WorkOrderType](http://docs.oasis-open.org/ubl/os-UBL-2.1/UBL-2.1.html#T-WORKORDER).

| Our Property | iCalendar Property | Schema.org Property | UBL Element | Notes |
|--------------|-------------------|---------------------|-------------|-------|
| summary | SUMMARY | name | Description | Task title |
| description | DESCRIPTION | description | Note | Detailed description |
| dtstart | DTSTART | startTime | StartDate | Start date/time |
| due | DUE | endTime | EndDate | Due date/time |
| completed | COMPLETED | completedTime | CompletionDate | Completion date/time |
| status | STATUS | actionStatus | StatusCode | Current status |
| priority | PRIORITY | priority | PriorityCode | Priority level |
| categories | CATEGORIES | category | CategoryCode | Categories/tags |
| relatedTo | RELATED-TO | object | DocumentReference | Related items |
| organizer | ORGANIZER | agent | RequestorParty | Person responsible |
| attendees | ATTENDEE | participant | Party | People involved |
| percentComplete | PERCENT-COMPLETE | completeness | PercentComplete | Completion percentage |

**Justification for iCalendar VTODO as primary standard:**

1. **Purpose-built for tasks**: Specifically designed for to-do items and task management
2. **Calendar integration**: Seamlessly works with calendar systems
3. **Recurrence support**: Built-in support for recurring tasks
4. **Status tracking**: Comprehensive status and completion tracking
5. **Widespread implementation**: Used in many task management applications

### Message

Our message object is inspired by [email standards (RFC 5322)](https://datatracker.ietf.org/doc/html/rfc5322) and the [JMAP specification](https://jmap.io/), which can be compared to [Schema.org's Message](https://schema.org/Message) type and UBL's [DocumentType](http://docs.oasis-open.org/ubl/os-UBL-2.1/UBL-2.1.html#S-DOCUMENT-STATUS) with communication elements.

| Our Property | Email/JMAP Property | Schema.org Property | UBL Element | Notes |
|--------------|---------------------|---------------------|-------------|-------|
| subject | Subject | about | Subject | Message subject |
| body | Body | text | TextContent | Message content |
| from | From | sender | SenderParty | Sender information |
| to | To | recipient | ReceiverParty | Recipient information |
| cc | Cc | recipient | CopyReceiverParty | Carbon copy recipients |
| sentAt | Date | dateSent | IssueDate | When message was sent |
| receivedAt | Received | dateReceived | ReceivedDate | When message was received |
| readAt | (Custom) | (None direct equivalent) | (None) | When message was read |
| attachments | Attachments | attachment | Attachment | File attachments |
| thread | References/Thread-ID | (None direct equivalent) | (None) | Thread identifier |
| channel | (Custom) | (None direct equivalent) | (None) | Communication channel |
| direction | (Custom) | (None direct equivalent) | (None) | Message direction |
| status | (Custom) | (None direct equivalent) | StatusCode | Delivery status |

**Justification for email standards as primary influence:**

1. **Universal familiarity**: Email concepts are understood by all users
2. **Comprehensive metadata**: Rich set of metadata for tracking communications
3. **Attachment support**: Built-in handling of file attachments
4. **Threading capability**: Support for conversation threading
5. **Multi-channel adaptability**: Core concepts apply across communication channels

### Note

Our note object is a custom implementation that can be compared to [Schema.org's Comment](https://schema.org/Comment) or [CreativeWork](https://schema.org/CreativeWork) types. UBL includes [Note](http://docs.oasis-open.org/ubl/os-UBL-2.1/UBL-2.1.html#S-NOTE) as a simple text element within documents.

| Our Property | Schema.org Property | UBL Element | Notes |
|--------------|---------------------|-------------|-------|
| title | headline | Subject | Note title |
| content | text | Note | Note content |
| createdBy | author | Author | Author information |
| createdAt | dateCreated | IssueDate | Creation timestamp |
| updatedAt | dateModified | RevisionDate | Last update timestamp |
| tags | keywords | Keyword | Categorization tags |
| visibility | - | - | - | Who can see the note |
| pinned | - | - | - | Whether note is pinned |
| about | about | - | - | Associated client |

**Justification for our custom note implementation:**

1. **Simplicity**: Straightforward structure that meets common note-taking needs
2. **Flexibility**: Accommodates both simple and rich-text notes
3. **Metadata support**: Includes essential metadata for organization and retrieval
4. **Tagging capability**: Supports categorization through tags
5. **Privacy controls**: Includes visibility settings not found in standard formats

## Standards Integration Strategy

To create a cohesive system that leverages the best aspects of each standard while maintaining interoperability, we recommend:

1. **Primary standards adherence**: 
   - Client: vCard (RFC 6350)
   - Task: iCalendar VTODO (RFC 5545)
   - Message: Email standards (RFC 5322) with JMAP influences
   - Note: Custom schema with Schema.org influences

2. **Schema.org compatibility**:
   - Include `@context` and `@type` properties
   - Map properties to Schema.org equivalents
   - Add Schema.org-specific properties where valuable

3. **UBL extensions for business contexts**:
   - Add UBL-inspired extensions for business-specific requirements
   - Include tax information, legal entity data, and procurement details when needed

4. **Relationship modeling**:
   - Use consistent relationship patterns across all entities
   - Leverage Schema.org relationship properties
   - Support both direct references and semantic links

This approach provides:

- **Standards compliance** with established formats
- **Semantic web compatibility** through Schema.org alignment
- **Business document integration** via UBL concepts
- **Flexibility** to adapt to specific use cases
- **Future-proofing** through adherence to widely-adopted standards

By carefully selecting elements from these complementary standards, our client management system achieves both technical excellence and practical usability.

## Comprehensive Standards Comparison

To provide a clear overview of how our API aligns with various standards, here's a comprehensive comparison table showing property mappings across all relevant standards:

### Client Entity Property Mapping

| Our API Property | Schema.org | vCard | UBL | EU Core Vocabularies | Description |
|------------------|------------|-------|-----|----------------------|-------------|
| id | identifier | UID | ID | identifier | Unique identifier |
| @type | @type | - | - | - | Entity type (Person/Organization) |
| name | name | FN | Name | fullName/legalName | Full name |
| givenName | givenName | N (part) | FirstName | givenName | First name |
| familyName | familyName | N (part) | FamilyName | familyName | Last name |
| additionalName | additionalName | N (part) | MiddleName | - | Middle name |
| honorificPrefix | honorificPrefix | N (part) | Title | - | Title prefix (Dr., Mr.) |
| honorificSuffix | honorificSuffix | N (part) | NameSuffix | - | Title suffix (Jr., PhD) |
| jobTitle | jobTitle | TITLE | JobTitle | - | Professional title |
| worksFor | worksFor | ORG (part) | PartyName | - | Organization name |
| department | department | ORG (part) | Department | - | Department within organization |
| image | image | PHOTO | - | - | Photo/avatar |
| telephone | telephone | TEL | Telephone | telephone | Phone numbers |
| email | email | EMAIL | ElectronicMail | email | Email addresses |
| address | address | ADR | PostalAddress | registeredAddress | Physical addresses |
| birthDate | birthDate | BDAY | BirthDate | dateOfBirth | Birth date |
| url | url | URL | WebsiteURI | - | Websites |
| description | description | NOTE | Note | - | Notes about the client |
| identifier | identifier | - | PartyIdentification | identifier | Official identifiers |
| dateCreated | dateCreated | - | CreationDate | - | Creation timestamp |
| dateModified | dateModified | - | LastModificationDate | - | Last update timestamp |

### Task Entity Property Mapping

| Our API Property | Schema.org | iCalendar | UBL | CPSV | Description |
|------------------|------------|-----------|-----|------|-------------|
| id | identifier | UID | ID | identifier | Unique identifier |
| @type | @type | - | - | - | Entity type (PlanAction) |
| name | name | SUMMARY | Description | name | Task title |
| description | description | DESCRIPTION | Note | description | Detailed description |
| startTime | startTime | DTSTART | StartDate | - | Start date/time |
| endTime | endTime | DUE | EndDate | - | Due date/time |
| completedTime | completedTime | COMPLETED | CompletionDate | - | Completion date/time |
| actionStatus | actionStatus | STATUS | StatusCode | status | Current status |
| priority | priority | PRIORITY | PriorityCode | - | Priority level |
| category | category | CATEGORIES | CategoryCode | type | Categories/tags |
| object | object | RELATED-TO | DocumentReference | - | Related items |
| agent | agent | ORGANIZER | RequestorParty | - | Person responsible |
| participant | participant | ATTENDEE | Party | - | People involved |
| percentComplete | - | PERCENT-COMPLETE | PercentComplete | - | Completion percentage |
| recurrenceRule | - | RRULE | - | - | Recurrence rule |
| clientId | about | - | CustomerParty | - | Associated client |
| dateCreated | dateCreated | CREATED | CreationDate | - | Creation timestamp |
| dateModified | dateModified | LAST-MODIFIED | LastModificationDate | - | Last update timestamp |

### Message Entity Property Mapping

| Our API Property | Schema.org | Email (RFC 5322) | JMAP | UBL | Description |
|------------------|------------|------------------|------|-----|-------------|
| id | identifier | - | id | ID | Unique identifier |
| @type | @type | - | - | - | Entity type (Message) |
| about | about | Subject | subject | Subject | Message subject |
| text | text | Body | bodyValues | TextContent | Message content |
| sender | sender | From | from | SenderParty | Sender information |
| recipient | recipient | To/Cc/Bcc | to/cc/bcc | ReceiverParty | Recipient information |
| dateSent | dateSent | Date | sentAt | IssueDate | When message was sent |
| dateReceived | dateReceived | Received | receivedAt | ReceivedDate | When message was received |
| dateRead | - | - | - | - | When message was read |
| attachment | attachment | Attachments | attachments | Attachment | File attachments |
| messageId | - | Message-ID | messageId | - | Unique message identifier |
| inReplyTo | - | In-Reply-To | inReplyTo | - | Message this is a reply to |
| references | - | References | references | - | Thread references |
| channel | - | - | - | CommunicationChannelCode | Communication channel |
| direction | - | - | - | - | Message direction |
| status | - | - | - | StatusCode | Delivery status |
| clientId | about | - | - | CustomerParty | Associated client |
| dateCreated | dateCreated | - | createdAt | CreationDate | Creation timestamp |
| dateModified | dateModified | - | updatedAt | LastModificationDate | Last update timestamp |

### Note Entity Property Mapping

| Our API Property | Schema.org | UBL | EU Core | Description |
|------------------|------------|-----|---------|-------------|
| id | identifier | ID | identifier | Unique identifier |
| @type | @type | - | - | Entity type (Comment) |
| headline | headline | Subject | - | Note title |
| text | text | Note | - | Note content |
| author | author | Author | - | Author information |
| dateCreated | dateCreated | IssueDate | - | Creation timestamp |
| dateModified | dateModified | RevisionDate | - | Last update timestamp |
| keywords | keywords | Keyword | - | Categorization tags |
| visibility | - | - | - | Who can see the note |
| pinned | - | - | - | Whether note is pinned |
| about | about | - | - | Associated client |

This comprehensive mapping demonstrates how our API design:

1. **Prioritizes Schema.org naming conventions** for better readability and web compatibility
2. **Maintains compatibility** with domain-specific standards (vCard, iCalendar, email)
3. **Incorporates business document concepts** from UBL where appropriate
4. **Aligns with European interoperability standards** through EU Core Vocabularies
5. **Adds custom extensions** only where necessary for functionality not covered by standards

By using Schema.org property names as our primary convention, we make the API more intuitive for developers while ensuring the underlying data model remains compatible with specialized standards for each domain.

## European Semantic Interoperability Standards

In addition to the standards we've already discussed, the European Union has developed several semantic interoperability initiatives that provide relevant data models and vocabularies for our client management system.

### Core Vocabularies

The [EU Core Vocabularies](https://joinup.ec.europa.eu/collection/semantic-interoperability-community-semic/solution/e-government-core-vocabularies) are simplified, reusable, and extensible data models that capture the fundamental characteristics of entities in a context-neutral way. Several of these are directly applicable to our client register:

#### Core Person Vocabulary

The [Core Person Vocabulary](https://joinup.ec.europa.eu/collection/semantic-interoperability-community-semic/solution/core-person-vocabulary) defines a simplified, reusable data model for describing natural persons.

| Our Property | Core Person Property | Notes |
|--------------|----------------------|-------|
| name | fullName | Full name of a person |
| givenName | givenName | First name |
| familyName | familyName | Last name |
| birthDate | dateOfBirth | Date of birth |
| address | registeredAddress | Official address |
| identifier | identifier | Unique identifier |

#### Core Business Vocabulary

The [Core Business Vocabulary](https://joinup.ec.europa.eu/collection/semantic-interoperability-community-semic/solution/core-business-vocabulary) provides a simplified, reusable data model for describing legal entities (businesses).

| Our Property | Core Business Property | Notes |
|--------------|------------------------|-------|
| name | legalName | Official name of the organization |
| alternativeName | alternativeName | Trading or alternative name |
| identifier | companyID | Official company registration ID |
| address | registeredAddress | Official registered address |
| status | status | Current status (active, inactive, etc.) |

#### Core Location Vocabulary

The [Core Location Vocabulary](https://joinup.ec.europa.eu/collection/semantic-interoperability-community-semic/solution/core-location-vocabulary) provides a simplified model for describing locations, which is relevant for client addresses.

| Our Property | Core Location Property | Notes |
|--------------|------------------------|-------|
| address.streetAddress | thoroughfare | Street name |
| address.postalCode | postCode | Postal code |
| address.locality | postName | City or town |
| address.region | adminUnitL2 | Region, state, or province |
| address.country | adminUnitL1 | Country |

### ISA² Programme and SEMIC

The [ISA² Programme](https://ec.europa.eu/isa2/home_en) (Interoperability Solutions for European Public Administrations, Businesses and Citizens) and its [SEMIC](https://joinup.ec.europa.eu/collection/semantic-interoperability-community-semic) (Semantic Interoperability Community) initiative provide additional relevant standards:

#### CPOV (Core Public Organization Vocabulary)

The [Core Public Organization Vocabulary](https://joinup.ec.europa.eu/collection/semantic-interoperability-community-semic/solution/core-public-organisation-vocabulary) is designed for describing public organizations in the European Union.

| Our Property | CPOV Property | Notes |
|--------------|---------------|-------|
| name | prefLabel | Preferred name of the organization |
| alternativeName | altLabel | Alternative name |
| description | description | Textual description |
| identifier | identifier | Unique identifier |
| purpose | purpose | Organization's purpose or mission |

#### CPSV (Core Public Service Vocabulary)

The [Core Public Service Vocabulary](https://joinup.ec.europa.eu/collection/semantic-interoperability-community-semic/solution/core-public-service-vocabulary) could be relevant for task management in public service contexts.

| Our Task Property | CPSV Property | Notes |
|-------------------|---------------|-------|
| name | name | Name of the service |
| description | description | Description of the service |
| status | status | Current status |
| type | type | Type of service |
| language | language | Language(s) the service is available in |

### DCAT-AP

The [DCAT Application Profile for data portals in Europe](https://joinup.ec.europa.eu/collection/semantic-interoperability-community-semic/solution/dcat-application-profile-data-portals-europe) (DCAT-AP) provides a specification for metadata records to meet the specific application needs of data portals in Europe.

While primarily focused on dataset descriptions, some concepts are relevant for document management aspects of our client system:

| Our Property | DCAT-AP Property | Notes |
|--------------|------------------|-------|
| document.title | title | Title of the document |
| document.description | description | Description of the document |
| document.created | issued | Date of formal issuance |
| document.updated | modified | Most recent date of modification |
| document.publisher | publisher | Entity responsible for making the document available |

## Integration with European Standards

To align our client management system with European semantic interoperability standards:

1. **Add Core Vocabulary identifiers**: Include standard identifiers from Core Vocabularies
   ```json
   "identifiers": [
     {
       "notation": "12345678",
       "scheme": "http://publications.europa.eu/resource/authority/corporate-body"
     }
   ]
   ```

2. **Support ADMS identifiers**: Add support for [Asset Description Metadata Schema](https://joinup.ec.europa.eu/collection/semantic-interoperability-community-semic/solution/asset-description-metadata-schema-adms) identifiers
   ```json
   "identifier": {
     "id": "12345678",
     "type": "CompanyCode",
     "schemeID": "BE:VAT"
   }
   ```

3. **Incorporate multilingual text**: Support multilingual text using the Core Vocabularies approach
   ```json
   "name": [
     {
       "text": "Example Company",
       "language": "en"
     },
     {
       "text": "Exemple Entreprise",
       "language": "fr"
     }
   ]
   ```

4. **Add controlled vocabularies**: Use EU-maintained controlled vocabularies for relevant fields
   ```json
   "country": {
     "code": "BE",
     "uri": "http://publications.europa.eu/resource/authority/country/BEL"
   }
   ```

5. **Support ELI references**: For legal entities, support [European Legislation Identifier](https://eur-lex.europa.eu/eli-register/about.html) references
   ```json
   "legalForm": {
     "code": "0312",
     "uri": "http://data.europa.eu/eli/ontology#LegalResource"
   }
   ```

By incorporating these European semantic standards, our client management system will be better positioned for:

- **Cross-border interoperability**: Easier data exchange with European systems
- **Regulatory compliance**: Alignment with EU data standards
- **Public sector integration**: Smoother integration with government systems
- **Future-proofing**: Compatibility with evolving European digital initiatives

These standards complement our existing approach based on vCard, iCalendar, and Schema.org, providing additional context specifically relevant to European business and regulatory environments.

## Client Object Based on European Core Vocabularies

After analyzing various standards, we've decided to base our client object primarily on the European Core Vocabularies (Core Person and Core Business), while ensuring compatibility with other standards and commercial CRM systems.

### Core Client Object Structure

Our client object will use a hybrid approach that accommodates both individuals (persons) and organizations, with the European Core Vocabularies as the foundation:

```json
{
  "id": "uuid-12345678-90ab-cdef-1234-567890abcdef",
  "@type": "Person", // or "Organization"
  "identifier": [
    {
      "notation": "BE0123456789",
      "scheme": "http://publications.europa.eu/resource/authority/corporate-body",
      "schemeID": "BE:VAT"
    }
  ],
  "name": [
    {
      "text": "John Smith",
      "language": "en"
    }
  ],
  "alternativeName": [
    {
      "text": "Johnny",
      "language": "en"
    }
  ],
  "givenName": "John",
  "familyName": "Smith",
  "birthDate": "1980-01-15",
  "gender": "http://publications.europa.eu/resource/authority/gender/M",
  "address": [
    {
      "type": "registered",
      "fullAddress": "123 Main Street, Brussels 1000, Belgium",
      "thoroughfare": "123 Main Street",
      "postName": "Brussels",
      "postCode": "1000",
      "adminUnitL1": "BE",
      "adminUnitL2": "Brussels-Capital Region"
    }
  ],
  "contactPoint": [
    {
      "type": "work",
      "email": "john.smith@example.com",
      "telephone": "+32 2 123 4567",
      "hoursAvailable": "Mo-Fr 09:00-17:00"
    }
  ],
  "legalEntity": {
    "legalName": "Smith Consulting Ltd",
    "companyType": "http://data.europa.eu/eiregistry/companyType#PrivateLimitedLiabilityCompany",
    "companyStatus": "active",
    "registrationDate": "2010-03-25",
    "companyActivity": [
      {
        "code": "62.01",
        "scheme": "NACE",
        "label": "Computer programming activities"
      }
    ]
  },
  "dateCreated": "2023-01-10T14:30:00Z",
  "dateModified": "2023-06-15T09:45:00Z"
}
```

### Comprehensive Standard Comparison

The following table compares our European-based client object with other standards and commercial CRM systems:

| Our Property | EU Core | vCard | Schema.org | UBL | Salesforce | Dynamics 365 | Exact Online |
|--------------|---------|-------|------------|-----|------------|--------------|--------------|
| id | identifier | UID | identifier | ID | Id | accountid | ID |
| @type | - | - | @type | - | RecordType | EntityType | - |
| identifier | identifier | - | identifier | PartyIdentification | ExternalId | msdyn_externalaccountid | Code |
| name | fullName/legalName | FN | name | Name | Name | name | Name |
| alternativeName | alternativeName | NICKNAME | alternateName | TradingName | - | - | SearchCode |
| givenName | givenName | N (part) | givenName | FirstName | FirstName | firstname | FirstName |
| familyName | familyName | N (part) | familyName | FamilyName | LastName | lastname | LastName |
| birthDate | dateOfBirth | BDAY | birthDate | BirthDate | Birthdate | birthdate | DateOfBirth |
| gender | gender | GENDER | gender | GenderCode | - | gendercode | Gender |
| address | registeredAddress | ADR | address | PostalAddress | Address | address1_* | Address |
| contactPoint | contactPoint | TEL/EMAIL | contactPoint | Contact | - | - | - |
| legalEntity | legalEntity | - | - | PartyLegalEntity | - | - | LegalEntity |
| companyType | companyType | - | - | CompanyLegalFormCode | - | businesstypecode | LegalForm |
| companyStatus | status | - | - | StatusCode | Status | statuscode | Status |
| companyActivity | activity | - | - | IndustryClassificationCode | Industry | industrycode | SbiCode |
| dateCreated | - | - | dateCreated | CreationDate | CreatedDate | createdon | Created |
| dateModified | - | - | dateModified | LastModificationDate | LastModifiedDate | modifiedon | Modified |

### Commercial CRM Features Not Covered

While our European-based client object covers many aspects of client data, commercial CRM systems include additional features:

#### Salesforce Features Not Covered

1. **Account Hierarchy**
   - Parent/Child relationships between accounts
   - Ultimate parent account tracking
   - Hierarchical revenue rollups

2. **Territory Management**
   - Assignment of accounts to territories
   - Territory-based access control
   - Territory-based forecasting

3. **Account Teams**
   - Multiple users assigned to accounts
   - Team member roles and access levels
   - Team collaboration features

4. **Partner Relationships**
   - Partner/Reseller designations
   - Channel management features
   - Partner portal access

5. **Industry-Specific Fields**
   - Financial services-specific fields (e.g., AUM, Investment Preferences)
   - Healthcare-specific fields (e.g., NPI Number, Specialties)
   - Manufacturing-specific fields (e.g., SIC Code, DUNS Number)

#### Microsoft Dynamics 365 Features Not Covered

1. **Relationship Insights**
   - Relationship health scoring
   - Interaction analysis
   - Relationship analytics

2. **Connection Roles**
   - Stakeholder role mapping
   - Influence tracking
   - Relationship type classification

3. **Marketing Automation Integration**
   - Marketing lists
   - Campaign responses
   - Marketing journey tracking

4. **Service Level Agreements**
   - SLA tracking
   - Entitlement management
   - Service case association

5. **Customer Insights**
   - Unified customer profile
   - Customer measures and KPIs
   - Predictive scoring

#### Exact Online Features Not Covered

1. **Financial Integration**
   - Direct link to GL accounts
   - Financial dimensions
   - Payment conditions and terms

2. **VAT and Tax Management**
   - VAT number validation
   - Tax exemption status
   - Fiscal representative information

3. **Credit Management**
   - Credit limit
   - Payment behavior score
   - Collection status

4. **Document Management**
   - Document templates
   - Document generation
   - Document approval workflows

5. **Multi-Company Support**
   - Cross-company relationships
   - Consolidated view
   - Intercompany transactions

### Advantages of European Core Vocabularies Approach

Using the European Core Vocabularies as our foundation provides several advantages:

1. **Regulatory Compliance**
   - Alignment with GDPR and other EU regulations
   - Support for official identifiers and schemes
   - Compatibility with government systems

2. **Multilingual Support**
   - Built-in support for multiple languages
   - Standardized approach to translations
   - Consistent handling of names across languages

3. **Standardized Codes**
   - Use of controlled vocabularies for countries, genders, etc.
   - Standard company activity codes (NACE)
   - Consistent legal form designations

4. **Cross-Border Interoperability**
   - Designed for EU-wide data exchange
   - Support for different addressing formats
   - Accommodation of various identification schemes

5. **Public Sector Integration**
   - Compatibility with e-government systems
   - Support for public procurement processes
   - Alignment with public service vocabularies

### Extensions for Commercial CRM Compatibility

To bridge the gap with commercial CRM systems, we recommend the following extensions to our European-based client object:

```json
{
  // Core properties as shown above
  
  "relationships": [
    {
      "relatedClientId": "uuid-98765432-10fe-dcba-9876-543210fedcba",
      "type": "parent",
      "description": "Parent company"
    }
  ],
  
  "teams": [
    {
      "userId": "user-12345",
      "role": "accountManager",
      "accessLevel": "edit"
    }
  ],
  
  "classification": {
    "segment": "Enterprise",
    "tier": "Platinum",
    "industry": {
      "code": "62.01",
      "scheme": "NACE",
      "label": "Computer programming activities"
    },
    "territory": "EMEA-North"
  },
  
  "financials": {
    "creditLimit": 50000,
    "paymentTerms": "net30",
    "vatExempt": false,
    "currency": "EUR",
    "fiscalYearEnd": "12-31"
  },
  
  "marketing": {
    "leadSource": "Website",
    "campaignId": "camp-2023-q2-webinar",
    "doNotContact": false,
    "preferences": {
      "channels": ["email", "phone"],
      "frequency": "weekly",
      "topics": ["product-updates", "events"]
    }
  },
  
  "serviceLevel": {
    "agreement": "premium",
    "responseTime": "4h",
    "supportLevel": "24/7",
    "expirationDate": "2024-12-31"
  }
}
```

### Implementation Recommendations

When implementing this client object in Open Register:

1. **Use a flexible schema**
   - Core properties should be required
   - Extensions should be optional
   - Allow for custom fields

2. **Implement validation rules**
   - Validate identifiers against official schemes
   - Enforce proper formatting of addresses
   - Check consistency between person/organization fields

3. **Support data transformations**
   - Provide mappings to/from vCard format
   - Support export to commercial CRM formats
   - Enable Schema.org JSON-LD generation

4. **Implement privacy controls**
   - Mark fields containing personal data
   - Support data minimization principles
   - Enable purpose-based access control

5. **Maintain audit trails**
   - Track changes to client data
   - Record purpose of data collection
   - Document data sharing activities

By basing our client object on European Core Vocabularies while accommodating other standards and commercial CRM features, we create a robust, interoperable foundation for client data management that works across borders and systems while meeting regulatory requirements.

## Regulatory and Standards Compliance

When implementing client registers in European contexts, it's important to understand that certain standards are not merely recommendations but regulatory requirements or officially endorsed standards that must be applied in specific scenarios.

### Mandatory Standards in European Context

#### EIDAS Regulation and Core Vocabularies

The [eIDAS Regulation](https://digital-strategy.ec.europa.eu/en/policies/eidas-regulation) (Electronic Identification, Authentication and Trust Services) establishes a legal framework for electronic identification and trust services across EU member states. While the regulation itself doesn't mandate specific data models, implementations that support cross-border identification should align with the EU Core Vocabularies.

The European Commission officially recommends the [Core Vocabularies](https://joinup.ec.europa.eu/collection/semantic-interoperability-community-semic/solution/e-government-core-vocabularies) for public administrations and entities that interact with them. In many EU-funded projects and cross-border services, these vocabularies are effectively mandatory.

**Key references:**
- [eIDAS Regulation (EU) No 910/2014](https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=uriserv:OJ.L_.2014.257.01.0073.01.ENG)
- [ISA² Programme Decision](https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX%3A32015D2240) - Establishing the program that developed Core Vocabularies
- [European Interoperability Framework](https://ec.europa.eu/isa2/eif_en) - Recommends Core Vocabularies

#### UBL and Forum Standaardisatie

In the Netherlands, the [Forum Standaardisatie](https://www.forumstandaardisatie.nl/) (Standardization Forum) maintains a list of mandatory and recommended standards for the public sector. UBL 2.1 is on the ["comply or explain" list](https://www.forumstandaardisatie.nl/open-standaarden/lijst/verplicht), making it effectively mandatory for Dutch public sector organizations.

**Key references:**
- [Forum Standaardisatie - UBL 2.1](https://www.forumstandaardisatie.nl/open-standaarden/ubl) - Official listing as a mandatory standard
- [NLCIUS](https://www.nen.nl/en/nlcius-1-0-1) - Dutch implementation of UBL for e-invoicing
- [Logius Digikoppeling](https://www.logius.nl/diensten/digikoppeling) - Dutch government service exchange that uses UBL

### Integration with Other Mandatory Standards

Our client object design also aligns with other mandatory standards in the Dutch and European context:

#### DCAT-AP-NL

The [DCAT-AP-NL](https://dcat-ap-nl.readthedocs.io/en/latest/) is the Dutch profile of the DCAT Application Profile for data portals in Europe. It's on the Forum Standaardisatie's mandatory list for describing datasets.

By using Schema.org properties that align with DCAT-AP-NL, our client object facilitates integration with data catalogs and open data initiatives:

```json
{
  "@context": "https://schema.org/",
  "@type": "Organization",
  "name": "Example Organization",
  "identifier": {
    "@type": "PropertyValue",
    "propertyID": "KVK",
    "value": "12345678"
  },
  "dataset": {
    "@type": "Dataset",
    "name": "Client Data",
    "license": "http://creativecommons.org/licenses/by/4.0/"
  }
}
```

**Key references:**
- [DCAT-AP-NL on Forum Standaardisatie](https://www.forumstandaardisatie.nl/open-standaarden/dcat-ap-nl)
- [DCAT-AP-NL Documentation](https://dcat-ap-nl.readthedocs.io/en/latest/)

#### NL API Strategy

The [Nederlandse API Strategie](https://docs.geostandaarden.nl/api/API-Strategie/) (Dutch API Strategy) provides guidelines for REST APIs in the Dutch public sector. Our client register design aligns with these guidelines:

- Use of JSON as the primary format
- Consistent naming conventions
- Support for filtering, sorting, and pagination
- Proper error handling

**Key references:**
- [NL API Strategy on Forum Standaardisatie](https://www.forumstandaardisatie.nl/open-standaarden/rest-api-design-rules)
- [API Design Rules](https://publicatie.centrumvoorstandaarden.nl/api/adr/)

### Practical Implementation Approach

Given these regulatory and standards requirements, we recommend the following approach for client registers in Open Register:

1. **Base core structure on EU Core Vocabularies**
   - Ensures compliance with European interoperability requirements
   - Supports cross-border identification under eIDAS
   - Facilitates public sector integration

2. **Include UBL compatibility layer**
   - Meets Dutch "comply or explain" requirements
   - Supports e-procurement and e-invoicing scenarios
   - Enables business document exchange

3. **Add Schema.org annotations**
   - Improves web discoverability
   - Aligns with DCAT-AP-NL for data catalog integration
   - Supports semantic web applications

4. **Implement commercial CRM extensions**
   - Addresses business requirements beyond standards
   - Maintains compatibility with common CRM systems
   - Provides practical functionality for users

This approach ensures that client registers built with Open Register will meet both regulatory requirements and practical business needs.

### Reference Implementation

Here's a reference implementation that demonstrates compliance with these mandatory standards:

```json
{
  "id": "uuid-12345678-90ab-cdef-1234-567890abcdef",
  "@context": "https://schema.org/",
  "@type": "Organization",
  
  "identifier": [
    {
      "schemeID": "NL:KVK",
      "notation": "12345678",
      "scheme": "http://publications.europa.eu/resource/authority/corporate-body"
    },
    {
      "schemeID": "NL:RSIN",
      "notation": "123456789",
      "scheme": "http://data.europa.eu/eli/ontology#LegalResource"
    }
  ],
  
  "name": [
    {
      "text": "Example Organization B.V.",
      "language": "nl"
    },
    {
      "text": "Example Organization Ltd.",
      "language": "en"
    }
  ],
  
  "legalEntity": {
    "legalName": "Example Organization B.V.",
    "companyType": "http://data.europa.eu/eiregistry/companyType#PrivateLimitedLiabilityCompany",
    "companyStatus": "active",
    "registrationDate": "2010-03-25",
    "companyActivity": [
      {
        "code": "62.01",
        "scheme": "NACE",
        "label": "Computer programming activities"
      }
    ]
  },
  
  "address": [
    {
      "type": "registered",
      "fullAddress": "Voorbeeldstraat 123, 1234 AB Amsterdam, Nederland",
      "thoroughfare": "Voorbeeldstraat 123",
      "postName": "Amsterdam",
      "postCode": "1234 AB",
      "adminUnitL1": "NL",
      "adminUnitL2": "Noord-Holland"
    }
  ],
  
  "contactPoint": [
    {
      "type": "primary",
      "email": "info@example.org",
      "telephone": "+31 20 123 4567",
      "hoursAvailable": "Mo-Fr 09:00-17:00"
    }
  ],
  
  // UBL-compatible extensions
  "partyTaxScheme": {
    "companyID": "NL123456789B01",
    "taxScheme": {
      "id": "VAT",
      "name": "Value Added Tax"
    }
  },
  
  // Schema.org extensions for DCAT-AP-NL compatibility
  "url": "https://www.example.org",
  "sameAs": [
    "https://www.linkedin.com/company/example-organization",
    "https://www.facebook.com/exampleorg"
  ],
  
  // Commercial CRM extensions
  "classification": {
    "segment": "Enterprise",
    "tier": "Gold",
    "industry": {
      "code": "62.01",
      "scheme": "NACE",
      "label": "Computer programming activities"
    }
  }
}
```

### Validation Resources

To ensure compliance with these standards, the following validation resources are available:

- [EU Core Vocabularies Validator](https://www.itb.ec.europa.eu/shacl/any/upload) - Validates against Core Vocabularies using SHACL
- [UBL Validation Tool](https://validatie.stpe.nl/) - Dutch UBL validation service
- [Schema.org Validator](https://validator.schema.org/) - Validates Schema.org markup
- [DCAT-AP Validator](https://www.itb.ec.europa.eu/shacl/dcat-ap/upload) - Validates DCAT-AP compliance

By using these validation tools during implementation, you can ensure that your client register meets all applicable standards and regulatory requirements. 