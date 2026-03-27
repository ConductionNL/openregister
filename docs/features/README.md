# OpenRegister Feature Documentation

OpenRegister is the core data registration platform for Nextcloud — a structured data engine that turns any Nextcloud installation into a domain-specific or organisational data register. It provides schema-driven object storage, flexible querying, rich access control, government-compliant archiving, workflow automation, and AI-ready APIs.

## GEMMA Reference Components

OpenRegister implements or supports the following GEMMA (Gemeentelijke Model Architectuur) reference components:

| Component | Category | GEMMA URL |
|-----------|----------|-----------|
| Gegevensmagazijncomponent | Objectregistratie | [GEMMA](https://gemmaonline.nl/index.php/GEMMA/id-06321658-50d1-4153-b007-6630ffabcd80) |
| Gegevensdistributiecomponent | Objectregistratie | [GEMMA](https://gemmaonline.nl/index.php/GEMMA/id-6c681cd8-9401-4103-82fc-51c0492d67e7) |
| Serviceregistercomponent | Objectregistratie | [GEMMA](https://gemmaonline.nl/index.php/GEMMA/id-c39c9b8f-efb0-47b5-a288-fb7a8f57393e) |
| Bedrijven- en instellingen-registratiecomponent | Objectregistratie | [GEMMA](https://gemmaonline.nl/index.php/GEMMA/id-cd0ddeb9-42dc-4385-9a78-0cca5e835e5e) |
| Terugmeldingen-registratiecomponent | Objectregistratie | [GEMMA](https://gemmaonline.nl/index.php/GEMMA/id-f284907e-1ac9-4742-a5a7-1e583838afc4) |
| Documentregistratiecomponent | DMS | [GEMMA](https://gemmaonline.nl/index.php/GEMMA/id-0e99ec6c-283a-4ec9-8efa-e11468e6b878) |
| Documentbeheercomponent | DMS | [GEMMA](https://gemmaonline.nl/index.php/GEMMA/id-25ee9ea7-be66-4bdd-b40c-191777a88b35) |
| Archiefregistratiecomponent | Archivering | [GEMMA](https://gemmaonline.nl/index.php/GEMMA/id-215355e8-af2a-4274-bd42-b57c214166fe) |
| Archiefbeheercomponent | Archivering | [GEMMA](https://gemmaonline.nl/index.php/GEMMA/id-b209fee8-d39a-4699-b0b4-02273c35c8c1) |
| Archiefportaalcomponent | Archivering | [GEMMA](https://gemmaonline.nl/index.php/GEMMA/id-6244d235-9319-48dd-b7b8-8701e0bde21d) |

## Standards Compliance

| Standard | Scope | Status |
|----------|-------|--------|
| Archiefwet 1995 | Archival retention, destruction, e-Depot transfer | Implemented |
| MDTO (Metagegevens Duurzaam Toegankelijke Overheidsinformatie) | Archival metadata XML, SIP packages | Implemented |
| NEN 15489 | Records management, destruction workflows | Implemented |
| AVG / GDPR Article 30 | Processing activity register (verwerkingsregister) | Spec defined |
| BIO (Baseline Informatiebeveiliging Overheid) | Audit logging, access control | Implemented |
| NL API Design Rules | REST API, versioning, Dutch government API interoperability | Implemented |
| ZGW APIs (Zaakgericht Werken) | Cases, documents, authorisations; mapped via Procest app | Via Procest |
| CloudEvents v1.0 | Webhook payload format, event bus | Implemented |
| VNG Notificaties API | Dutch government notification delivery format | Implemented |
| JSON Schema (Draft 7 / 2020-12) | Object validation | Implemented |
| OpenAPI 3.1.0 | API documentation, OAS generation per register | Implemented |
| GraphQL | Query and subscription API, auto-generated from schemas | Implemented |
| MCP (Model Context Protocol) | AI agent tool and resource access, JSON-RPC 2.0 | Implemented |
| OAuth2 / RBAC Scopes | Group-based scopes in OAS security definitions | Implemented |
| iCalendar RFC 5545 | Tasks/TODOs on objects via CalDAV | Implemented |
| Schema.org | Schema import from Schema.org vocabulary | Implemented |
| GGM (Gemeentelijk Gegevensmodel) | Schema import from Dutch municipal data model | Implemented |

## Feature Index

| Feature | Doc | Category | Status | Key Standards |
|---------|-----|----------|--------|---------------|
| Registers & Schemas | [registers-and-schemas.md](registers-and-schemas.md) | Core | Implemented | JSON Schema, Schema.org, GGM |
| Object Storage & Lifecycle | [object-storage.md](object-storage.md) | Core | Implemented | UUID, soft delete, versioning |
| Search, Filtering & Faceting | [search-and-faceting.md](search-and-faceting.md) | Core | Implemented | NL API Design Rules, PostgreSQL, Solr, Elasticsearch |
| Access Control (RBAC) | [access-control.md](access-control.md) | Security | Implemented | OAuth2 scopes, ZGW Autorisaties, BIO |
| Content Versioning & Audit Trail | [versioning-and-audit.md](versioning-and-audit.md) | Compliance | Implemented | Archiefwet, BIO, AVG |
| Data Import & Export | [data-import-export.md](data-import-export.md) | Integration | Implemented | CSV, Excel, JSON, XML, OpenAPI |
| Event-Driven Architecture | [event-driven-architecture.md](event-driven-architecture.md) | Integration | Implemented | CloudEvents v1.0, PSR-14 |
| Webhooks & Notifications | [webhooks-and-notifications.md](webhooks-and-notifications.md) | Integration | Implemented | CloudEvents, HMAC, VNG Notificaties |
| Workflow Automation | [workflow-automation.md](workflow-automation.md) | Automation | Implemented | n8n, Windmill, BPMN |
| Archiving & Records Management | [archiving.md](archiving.md) | Compliance | Implemented | Archiefwet, MDTO, NEN 15489, e-Depot |
| OpenAPI & GraphQL APIs | [api-generation.md](api-generation.md) | Integration | Implemented | OpenAPI 3.1.0, GraphQL, NL API Design Rules |
| AI & MCP Integration | [ai-and-mcp.md](ai-and-mcp.md) | AI | Implemented | MCP, JSON-RPC 2.0, SSE |
| Object Interactions | [object-interactions.md](object-interactions.md) | Collaboration | Implemented | CalDAV, RFC 5545, Nextcloud Comments |
| Real-Time Updates | [realtime-updates.md](realtime-updates.md) | Integration | Implemented | SSE, WebSocket, notify_push |
| Multi-Tenancy & SaaS | [multi-tenancy.md](multi-tenancy.md) | Platform | Implemented | Organisation scoping, quota management |
| Deep Link Registry | [deep-link-registry.md](deep-link-registry.md) | Integration | Implemented | Nextcloud app interoperability |
| Computed Fields | [computed-fields.md](computed-fields.md) | Core | Implemented | Twig expressions, server-side evaluation |
| Geo Metadata & Map Visualization | [geo-metadata.md](geo-metadata.md) | Core | Planned | GeoJSON, PDOK, RD/WGS84 |

## Feature Categories

### Core Data Management
The foundational layer for defining, storing, and querying structured data.

- [Registers & Schemas](registers-and-schemas.md) — JSON Schema-based data model definition, import from Schema.org and GGM
- [Object Storage & Lifecycle](object-storage.md) — CRUD, soft delete, relations, file attachments, locking
- [Search, Filtering & Faceting](search-and-faceting.md) — Full-text search, field filtering, faceted drill-down, multi-backend
- [Computed Fields](computed-fields.md) — Server-side Twig expressions for derived properties

### Security & Access Control
Fine-grained authorization at every level of the data hierarchy.

- [Access Control (RBAC)](access-control.md) — Register, schema, row, and property level permissions; OAuth2 scopes

### Compliance & Audit
Meeting Dutch government regulatory requirements.

- [Content Versioning & Audit Trail](versioning-and-audit.md) — Immutable hash-chained audit log, semantic versioning, rollback
- [Archiving & Records Management](archiving.md) — Retention schedules, destruction workflows, MDTO XML, e-Depot transfer (SIP)

### Integration
Connecting OpenRegister to external systems and workflows.

- [Data Import & Export](data-import-export.md) — CSV, Excel, JSON, XML, OpenAPI configuration portability
- [Event-Driven Architecture](event-driven-architecture.md) — Typed PHP events, pre/post mutation hooks, StoppableEventInterface
- [Webhooks & Notifications](webhooks-and-notifications.md) — CloudEvents delivery, HMAC signing, VNG Notificaties, retry
- [Workflow Automation](workflow-automation.md) — Schema hooks, n8n/Windmill integration, import-time workflow triggers
- [OpenAPI & GraphQL APIs](api-generation.md) — Auto-generated specs and GraphQL schema per register
- [Real-Time Updates](realtime-updates.md) — SSE subscriptions, RBAC-filtered events, reconnection with replay

### AI & Agent Interfaces
Enabling AI systems and LLMs to access register data.

- [AI & MCP Integration](ai-and-mcp.md) — MCP standard protocol, tool discovery, tiered REST discovery catalog

### Collaboration
Human-to-human and human-to-system interaction on objects.

- [Object Interactions](object-interactions.md) — Notes, tasks (CalDAV), file attachments, tags

### Platform
Infrastructure for multi-tenancy and app interoperability.

- [Multi-Tenancy & SaaS](multi-tenancy.md) — Organisation isolation, quota enforcement, tenant lifecycle
- [Deep Link Registry](deep-link-registry.md) — Boot-time URL routing from consuming Nextcloud apps
