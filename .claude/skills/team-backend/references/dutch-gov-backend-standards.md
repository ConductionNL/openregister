### Dutch Government API & Security Standards

When implementing backend code, always apply these Dutch government requirements:

#### NLGov REST API Design Rules 2.0

All REST APIs MUST follow the NLGov API Design Rules ("pas toe of leg uit" since 2020):

**Resource URLs:**
- Use nouns (not verbs): `/api/objects` not `/api/getObjects`
- Use plural: `/api/registers` not `/api/register`
- Use lowercase with hyphens: `/api/audit-trails` not `/api/auditTrails`
- Nest logically: `/api/objects/{register}/{schema}/{id}`

**Pagination (mandatory for collections):**
```php
// Response format per NLGov API Design Rules
return new JSONResponse(data: [
    'results'  => $objects,
    'count'    => count($objects),
    'total'    => $totalCount,
    'page'     => $page,
    'pages'    => $totalPages,
    'pageSize' => $limit,
    '_links'   => [
        'self'  => ['href' => $selfUrl],
        'next'  => ['href' => $nextUrl],  // if applicable
        'prev'  => ['href' => $prevUrl],  // if applicable
    ],
]);
```

**Error responses (standardized format):**
```php
// NLGov-compliant error response
return new JSONResponse(data: [
    'type'    => 'https://developer.overheid.nl/errors/validation-failed',
    'title'   => 'Validation failed',
    'status'  => 400,
    'detail'  => $e->getMessage(),
    'instance' => $request->getRequestUri(),
], statusCode: 400);
```

**Filtering:** Use query parameters: `?filter[field]=value`, `?sort=-created`, `?fields=id,name`

#### ZGW API Compatibility

If implementing zaakgericht werken (case management) features, follow the ZGW API standards:
- Zaken API, Documenten API, Catalogi API, Besluiten API, Autorisaties API
- Use UUID-based resource identifiers
- Support `expand` query parameter for related resources
- Use `Accept-Crs` and `Content-Crs` headers for geo data
- Open Zaak is the production-quality reference implementation

#### Haal Centraal Integration (Basisregistraties)

When querying national base registries (BRP, BAG, BRK, HR), use the Haal Centraal REST APIs:
- Include `x-doelbinding` header (purpose binding — declare why you need the data)
- Include `x-verwerking` header (processing record for audit trail)
- Request only needed fields (data minimization via `?fields=` parameter)
- Never store local copies of base registry data — fetch at runtime
- Handle network latency (queries go to national infrastructure)
- BSN from BRP must be encrypted at rest and never exposed in logs or URLs

#### FSC (Federatieve Service Connectiviteit) — Standard since Jan 2025

For inter-organizational API calls (replaces NLX):
- **Inway**: Reverse proxy handling incoming connections to your services
- **Outway**: Forward proxy handling outgoing connections to other organizations
- **Directory**: Registry where peers publish their HTTP APIs as Services
- **Manager**: Negotiates Contracts between peers; provides access tokens
- All connections use **mutual TLS (mTLS)** with X.509 certificates
- Contract-based authorization (not ad-hoc API keys)
- Register APIs for discoverability via FSC Directory

#### StUF → API Migration

When integrating with existing municipal systems that still use StUF (SOAP/XML):
- **Active StUF development has been discontinued** — only bug fixes and legal amendments
- All new integrations must use REST APIs
- Provide adapter/translation layers only if StUF systems haven't migrated yet
- StUF-ZKN → replaced by ZGW APIs (Open Zaak)
- StUF-BG → replaced by Haal Centraal APIs
- StUF notifications → replaced by CloudEvents / NRC

#### BIO2 Security Controls

All code must support the Baseline Informatiebeveiliging Overheid (ISO 27001:2023 based):
- **Input validation**: All user input validated server-side (OWASP top 10)
- **Authentication**: Support Nextcloud auth, DigiD (SAML), eHerkenning
- **Authorization**: RBAC + multi-tenancy isolation per organization
- **Logging**: Audit trails for all data mutations (who, what, when)
- **Data protection**: No PII in logs, encrypt sensitive fields at rest
- **Session management**: Use Nextcloud session handling, never roll your own

#### AVG/GDPR Compliance

- **Data minimization**: Only collect/store what's needed
- **Purpose binding**: Data used only for its stated purpose
- **Right to erasure**: Support deletion of personal data upon request
- **Pseudonymization**: Use UUIDs as external identifiers, never expose internal IDs or BSN directly
