# Dutch Government Architecture Standards

Reference for all Conduction software serving Dutch municipalities. Apply these frameworks when reviewing architectural decisions in `team-architect`.

---

## NORA → GEMMA Hierarchy

**NORA** (Nederlandse Overheid Referentie Architectuur) is the parent architecture for all Dutch government. Since Jan 2023, it uses a 4-level structure of **Binding Architectural Agreements**: Core Values → Quality Goals → 17 Architectural Principles → ~90 Implications.

**GEMMA** is NORA's "daughter architecture" for municipalities. Verify all designs align with both.

---

## GEMMA Reference Architecture

GEMMA (GEMeentelijke Model Architectuur) is the reference architecture for all 342 Dutch municipalities. It describes how municipal processes, information systems, data, and infrastructure are interconnected. Map every change to the GEMMA model:

**GEMMA Reference Components (map your apps):**
| Conduction App | GEMMA Reference Component | Layer |
|---------------|--------------------------|-------|
| OpenRegister | Registratiecomponent | Services / Data |
| OpenCatalogi | Publicatiecomponent | Interaction / Services |
| Softwarecatalog | Domeinspecifiek portaal | Interaction |
| OpenConnector | Integratiecomponent / Servicebus | Integration |
| DocuDesk | Documentbeheercomponent | Services |
| Procest | Zaakafhandelcomponent | Process |
| OpenZaak | Zaakregistratiecomponent | Services / Data |

Check for:
- [ ] Change fits within the correct GEMMA layer
- [ ] No layer violations (e.g., interaction layer directly accessing data layer)
- [ ] Reference component boundaries respected

---

## Common Ground 5-Layer Model

```
┌─────────────────────────────────────┐
│ Layer 5: Interaction                │ ← Portals, apps, UIs (Softwarecatalog, frontends)
├─────────────────────────────────────┤
│ Layer 4: Process                    │ ← Business process orchestration (Procest, workflows)
├─────────────────────────────────────┤
│ Layer 3: Integration                │ ← API gateway, FSC, connectors (OpenConnector)
├─────────────────────────────────────┤
│ Layer 2: Services                   │ ← Business logic, APIs (OpenRegister, OpenCatalogi)
├─────────────────────────────────────┤
│ Layer 1: Data                       │ ← Databases, registrations (PostgreSQL, registers)
└─────────────────────────────────────┘
```

**Core Common Ground Principles:**
- [ ] **Data at the source**: Data is NOT copied between systems — it's fetched via APIs when needed
- [ ] **Component-based**: Each component has a single responsibility
- [ ] **Open standards**: Uses open API standards (NLGov REST API Design Rules, ZGW APIs)
- [ ] **Open source**: Code is publicly available under open license (EUPL-1.2)
- [ ] **Vendor-independent**: No vendor lock-in, runs on any Haven-compliant infrastructure

---

## FSC (Federatieve Service Connectiviteit) — Standard since Jan 2025

FSC replaced NLX as the standard for federated data sharing between government organizations (Programmeringsraad GDI decision Dec 2024).

**FSC Architecture Components:**
| Component | Role |
|-----------|------|
| **Inway** | Reverse proxy handling incoming connections to your Services |
| **Outway** | Forward proxy handling outgoing connections to other organizations |
| **Directory** | Registry where Peers publish their HTTP APIs as Services |
| **Manager** | Negotiates Contracts between Peers; provides access tokens |

Check for:
- [ ] External API calls between organizations use FSC contract-based access
- [ ] mTLS with X.509 certificates for all Inway/Outway connections
- [ ] APIs registered for discoverability via FSC Directory
- [ ] No direct database connections between components (always via API)
- [ ] Trust Anchors list configured with approved Certificate Authorities

---

## Interoperability: StUF → API Migration

Active StUF (SOAP/XML) development has been discontinued — only bug fixes and legal amendments. Design for the migration path:

| Legacy | Replacement |
|--------|------------|
| StUF-ZKN (Case mgmt) | ZGW APIs (Open Zaak) |
| StUF-BG (Base data) | Haal Centraal APIs |
| StUF notifications | CloudEvents / NRC |
| SOAP/WUS inter-org | Digikoppeling REST API profile + FSC |

- [ ] New integrations use REST APIs exclusively
- [ ] Legacy StUF adapters only when required by existing systems
- [ ] Migration path documented in design.md if StUF systems are involved

---

## Haven Compliance — Hosting Standard

Applications must be deployable on Haven-compliant Kubernetes clusters. Haven defines 16 mandatory + 2 suggested checks across 7 sections:

| Section | Key Requirements |
|---------|-----------------|
| Infrastructure | Multiple availability zones; min 3 master + 3 worker nodes; SELinux/AppArmor enabled |
| Cluster | Latest major K8s version; RBAC enabled; basic auth disabled; ReadWriteMany PVs |
| Deployment | Standard deployment practices; Helm charts or K8s manifests |

Check for:
- [ ] Application is containerizable (Dockerfile or Helm chart exists/planned)
- [ ] No host-specific dependencies (file paths, local storage assumptions)
- [ ] Configuration via environment variables (12-factor app)
- [ ] Stateless application layer (state in database, not in memory/filesystem)
- [ ] Health check endpoints available (`/status` or similar)
- [ ] No hardcoded ports or hostnames
- [ ] No cloud-provider-specific dependencies (works on any Haven-compliant cluster)
- [ ] No basic auth — use RBAC-based authorization

---

## Identity Federation Architecture

If authentication is involved, consider the Dutch identity landscape:

| System | Purpose | Protocol | Returns |
|--------|---------|----------|---------|
| DigiD | Citizen auth | SAML 2.0 | BSN |
| eHerkenning | Business auth | SAML 2.0 | KvK number |
| eIDAS | Cross-border EU | SAML 2.0 / OIDC | Varies |

**eIDAS 2.0 / EUDI Wallet** (major upcoming change):
- By **Dec 2026**: Member States must provide EUDI Wallet to all citizens
- By **Dec 2027**: Municipalities must fully accept EUDI Wallet
- Protocol: likely OpenID for Verifiable Presentations
- Design authentication flows to be protocol-agnostic where possible

---

## Basisregistraties Integration Patterns

If the change touches citizen/address/organization data, consider integration with:
| Registration | API | Use Case |
|-------------|-----|----------|
| BRP | Haal Centraal BRP API | Person data (naam, adres, geboortedatum) |
| BAG | Haal Centraal BAG API | Address data (postcode, huisnummer) |
| HR | Haal Centraal HR API | Organization data (KvK, vestigingsnummer) |
| BRK | Haal Centraal BRK API | Cadastral data |

Check for:
- [ ] No local copies of basisregistratie data (fetch from source via API)
- [ ] Appropriate caching strategy (TTL-based, not permanent copies)
- [ ] BSN handling follows AVG/GDPR rules (pseudonymize, encrypt)
