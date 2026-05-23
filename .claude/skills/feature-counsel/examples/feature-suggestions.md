<!-- Example output — feature-counsel synthesis report for OpenRegister -->

# Feature Counsel Report: openregister

**Date:** 2026-04-10
**Method:** 8-persona feature advisory analysis against OpenSpec specifications
**Personas:** Henk Bakker, Fatima El-Amrani, Sem de Jong, Noor Yilmaz, Annemarie de Vries, Mark Visser, Priya Ganpat, Jan-Willem van der Berg

---

## Executive Summary

OpenRegister has a solid data management foundation with strong CRUD functionality and an emerging API layer. However, the specs reveal significant gaps in three areas that appear consistently across personas: onboarding/discoverability (no help system, no sample data), event-driven integration capabilities (no webhooks, no change notifications), and NLGov API compliance (pagination format, error responses, publiccode.yml). Accessibility and plain-language concerns are the most broadly shared pain points — affecting 5 of 8 personas.

---

## Consensus Features (suggested by 3+ personas)

| # | Feature | Suggested by | Priority | Impact |
|---|---------|-------------|----------|--------|
| 1 | Webhooks / event notifications on object lifecycle | Mark, Priya, Annemarie, Noor | MUST | Event-driven integrations; reduces polling; required for modern municipal middleware |
| 2 | CSV/Excel import and export | Mark, Jan-Willem, Priya, Fatima | MUST | Data migration, reporting, offline access — every client deployment needs this |
| 3 | In-app help, tooltips, and glossary | Henk, Fatima, Jan-Willem, Mark | MUST | Most new users are blocked by jargon; onboarding essential for adoption |
| 4 | Plain-language labels throughout (B1 Dutch level) | Fatima, Henk, Jan-Willem | MUST | Accessibility requirement; also Woo compliance |
| 5 | NLGov API pagination format compliance | Priya, Annemarie | SHOULD | total/page/pages/pageSize fields missing from collection responses |
| 6 | publiccode.yml in repo root | Annemarie | SHOULD | Required for listing on developer.overheid.nl and EU OSS Catalogue |
| 7 | Server-side search with indexing | Sem, Priya, Mark | SHOULD | 2.1s search response unacceptable at scale |
| 8 | Color + text status indicators (not color-only) | Fatima, Henk, Noor | SHOULD | WCAG 1.4.1 compliance; visually accessible |

---

## Per-Persona Highlights

### Henk Bakker (Elderly Citizen)
- **Top need**: Plain Dutch labels, date picker widget, larger table text
- **Key missing feature**: In-app help explaining "register", "schema", "object" in simple Dutch
- **Quote**: "Als iemand mij een uitleg geeft, kan ik het gebruiken. Maar het begrijpelijk maken zou voor iedereen beter zijn."

### Fatima El-Amrani (Low-Literate Migrant)
- **Top need**: Simplified language, visual hierarchy on mobile, color + text status indicators
- **Key missing feature**: Glossary with icons for technical terms
- **Quote**: "Ik begrijp het niet als er nergens staat wat ik moet doen."

### Sem de Jong (Young Digital Native)
- **Top need**: Sub-500ms search, code splitting for bundle size, responsive tables
- **Key missing feature**: Server-side search + virtual scrolling for large object lists
- **Quote**: "The API is solid but the frontend bundle is embarrassingly large. Code-split the schema editor."

### Noor Yilmaz (Municipal CISO)
- **Top need**: Webhook event audit trail, data export for backup, clear RBAC documentation
- **Key missing feature**: Audit log export (downloadable CSV of all mutations for ENSIA evidence)
- **Quote**: "I need to export the audit log every year for ENSIA. Right now that's only in Nextcloud activity — not accessible enough."

### Annemarie de Vries (VNG Standards Architect)
- **Top need**: NLGov API compliance, publiccode.yml, GEMMA component mapping
- **Key missing feature**: publiccode.yml + NLGov API pagination fix
- **Quote**: "We can't list this on developer.overheid.nl without a publiccode.yml and NLGov-compliant pagination."

### Mark Visser (MKB Software Vendor)
- **Top need**: Webhooks, CSV import, bulk operations
- **Key missing feature**: Webhook subscriptions for event-driven municipal middleware integrations
- **Quote**: "Every RFP I respond to asks for webhooks. Without them I'm polling every minute — that's not a real integration."

### Priya Ganpat (ZZP Developer)
- **Top need**: Webhook API, NLGov pagination, OpenAPI spec accuracy, error format
- **Key missing feature**: Full NLGov API compliance including RFC 7807 error format
- **Quote**: "The pagination response format is wrong. Fix total/page/pages/pageSize and I can recommend this API to clients."

### Jan-Willem van der Berg (Small Business Owner)
- **Top need**: Plain Dutch throughout, CSV export, onboarding flow
- **Key missing feature**: Onboarding wizard explaining registers/schemas/objects in plain Dutch with examples
- **Quote**: "Als er een korte rondleiding was geweest bij de eerste keer inloggen, had ik meteen geweten wat ik moest doen."

---

## Feature Suggestions by Category

### Accessibility & Inclusivity

| # | Feature | Personas | Priority | Notes |
|---|---------|----------|----------|-------|
| 1 | In-app glossary / tooltips for "register", "schema", "objecten", "UUID" | Fatima, Henk, Jan-Willem | MUST | Show on first encounter; dismissable |
| 2 | Color + text status indicators ("● Actief" / "● Inactief") | Fatima, Henk, Noor | MUST | WCAG 1.4.1 compliance |
| 3 | Date picker widget (not ISO input field) | Henk, Fatima | SHOULD | Accept dd-mm-yyyy and natural Dutch dates |
| 4 | Minimum 15px table cell font size | Henk, Fatima | SHOULD | Current 11–13px too small |
| 5 | `aria-live` on toast notifications | Henk | SHOULD | Screen reader accessibility |
| 6 | Onboarding flow for new users | Jan-Willem, Henk, Fatima | MUST | Wizard or empty-state guidance |

### Security & Compliance

| # | Feature | Personas | Priority | Standard |
|---|---------|----------|----------|----------|
| 1 | Audit log export to CSV | Noor | MUST | BIO2 / ENSIA |
| 2 | publiccode.yml in repo root | Annemarie | MUST | developer.overheid.nl requirement |
| 3 | RBAC documentation (what roles can do what) | Noor | SHOULD | BIO2 / municipal admin requirement |
| 4 | Data retention settings per register | Noor | COULD | AVG/GDPR compliance |

### API & Developer Experience

| # | Feature | Personas | Priority | Notes |
|---|---------|----------|----------|-------|
| 1 | Webhooks (object.created/updated/deleted) | Mark, Priya, Annemarie, Noor | MUST | HMAC-signed, per-organisation subscriptions |
| 2 | NLGov pagination fix (total/page/pages/pageSize) | Priya, Annemarie | MUST | Required for NLGov API Design Rules v2 |
| 3 | RFC 7807 error response format | Priya, Annemarie | SHOULD | type/title/status/detail/instance fields |
| 4 | OpenAPI spec kept up to date | Priya | SHOULD | Currently missing 3 endpoints |
| 5 | API changelog / versioning documentation | Priya | COULD | Helps integrators detect breaking changes |

### UX & Performance

| # | Feature | Personas | Priority | Notes |
|---|---------|----------|----------|-------|
| 1 | Server-side search with full-text index | Sem, Priya, Mark | MUST | Current client-side scan fails at 1000+ objects |
| 2 | Virtual scrolling / server-side pagination in UI | Sem | SHOULD | Prevents DOM bloat at 2000+ objects |
| 3 | Code splitting — lazy-load schema editor | Sem | SHOULD | Reduces initial bundle from 3.8MB to ~1MB |
| 4 | CSV/Excel export from objects list | Mark, Jan-Willem | MUST | Standard user expectation for data portability |
| 5 | CSV import with field mapping wizard | Mark, Jan-Willem | SHOULD | Essential for data migration projects |

### Standards & Interoperability

| # | Feature | Personas | Priority | Standard |
|---|---------|----------|----------|----------|
| 1 | GEMMA component mapping in documentation | Annemarie | SHOULD | GEMMA reference architecture |
| 2 | OpenAPI spec hosted at /openapi.json | Priya, Annemarie | SHOULD | NLGov API discoverability |
| 3 | Common Ground 5-layer compliance documentation | Annemarie | COULD | Common Ground architecture reference |

### Business & Workflow

| # | Feature | Personas | Priority | Notes |
|---|---------|----------|----------|-------|
| 1 | Bulk operations (select multiple → delete/export) | Mark, Jan-Willem | SHOULD | Reduces repetitive actions |
| 2 | Saved filters / custom views | Mark | COULD | Power users need quick access to frequent queries |
| 3 | Schema versioning (draft/publish/deprecate) | Mark, Priya | SHOULD | Safe schema evolution without breaking integrations |

---

## Recommended Actions

### MUST (blocking for key user groups)

1. Add webhooks API — Mark and Priya can't build production-grade integrations without it; blocks several RFP requirements
2. Fix NLGov pagination response format — blocks listing on developer.overheid.nl; required since Sept 2025
3. Add in-app onboarding/glossary — Henk, Fatima, Jan-Willem cannot use the app independently without it
4. Add CSV/Excel export — standard user expectation; Jan-Willem considers it a dealbreaker
5. Add publiccode.yml — required for EU/NL OSS listing and OSPO compliance

### SHOULD (significant improvement for multiple personas)

1. Implement server-side search — current performance unacceptable at production data volumes
2. Add color + text status indicators — WCAG 1.4.1 compliance + helps 4 personas
3. Fix RFC 7807 error response format — improves API DX for Priya and Annemarie
4. Add audit log CSV export for ENSIA compliance evidence
5. Implement CSV import with field mapping wizard

### COULD (nice-to-have, improves specific persona experience)

1. Date picker widget (Henk, Fatima)
2. Code split schema editor module (Sem — 3.8MB bundle)
3. Schema versioning (Mark, Priya)
4. GEMMA component mapping documentation (Annemarie)

---

## Potential OpenSpec Changes

| Change Name | Description | Related Personas | Estimated Complexity |
|-------------|-------------|-----------------|---------------------|
| add-webhook-support | CRUD API for webhook subscriptions + HMAC dispatch on object lifecycle events | Mark, Priya, Annemarie, Noor | M |
| fix-nlgov-compliance | Fix pagination format, error response format, validate OpenAPI spec | Priya, Annemarie | S |
| add-onboarding | New user wizard + glossary tooltips + empty state guidance in plain Dutch | Jan-Willem, Henk, Fatima | M |
| add-csv-import-export | CSV/Excel import with field mapping wizard + export from objects list | Mark, Jan-Willem | M |
| add-publiccode | Add publiccode.yml to repository root | Annemarie | S |
| fix-status-indicators | Replace color-only status dots with color + text labels | Fatima, Henk, Noor | S |
| improve-search | Server-side full-text search with database index + virtual scrolling in UI | Sem, Priya, Mark | L |
| add-audit-export | CSV export of audit log entries for ENSIA compliance evidence | Noor | S |
