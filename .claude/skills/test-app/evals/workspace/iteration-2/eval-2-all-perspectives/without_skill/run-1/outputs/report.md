# OpenRegister Multi-Perspective Test Report

**App:** OpenRegister (Nextcloud)
**URL:** http://nextcloud.local
**Nextcloud Version:** 34.0.0 dev
**Date:** 2026-04-13
**Method:** curl-based API testing + HTML analysis (no browser tool available)
**Credentials:** admin/admin

---

## Executive Summary

OpenRegister is a register/schema management app for Nextcloud. Testing covered 6 perspectives: Functional, API, Security, Performance, Accessibility, and UX. The app is generally functional with working CRUD operations and proper authentication on most endpoints, but has **critical security issues** (unauthenticated data exposure, verbose error messages leaking internals) and several quality concerns.

| Perspective     | Verdict | Critical Issues |
|----------------|---------|-----------------|
| Functional     | PASS (with issues) | DELETE returns 500 instead of 404 for already-deleted resources |
| API            | PASS (with issues) | Missing pagination metadata on /registers; inconsistent response structure |
| Security       | FAIL | Unauthenticated access to /schemas and /objects; SQL query + stack traces leaked in error responses |
| Performance    | PASS (marginal) | Average response times 650-900ms; 9MB JavaScript bundle |
| Accessibility  | PASS (partial) | Good Nextcloud shell support; app content is SPA with minimal server-side a11y |
| UX             | PASS (with notes) | PWA support present; localization works; very large JS bundle |

---

## 1. Functional Testing

### 1.1 CRUD Operations on Registers

| Operation | Endpoint | HTTP Status | Result |
|-----------|----------|-------------|--------|
| CREATE | POST /api/registers | 201 | PASS - Created register with auto-generated UUID, id=7 |
| READ (list) | GET /api/registers | 200 | PASS - Returns array of registers |
| READ (single) | GET /api/registers/7 | 200 | PASS - Returns created register |
| UPDATE | PUT /api/registers/7 | 200 | PASS - Title updated to "Updated Test Register" |
| DELETE | DELETE /api/registers/7 | 200 | PASS - Register deleted |
| VERIFY DELETE | GET /api/registers/7 | **500** | **FAIL** - Expected 404, got 500 (Internal Server Error) |

### 1.2 CRUD Operations on Schemas

| Operation | Endpoint | HTTP Status | Result |
|-----------|----------|-------------|--------|
| CREATE | POST /api/schemas | 201 | PASS - Created schema id=56 |
| DELETE | DELETE /api/schemas/56 | 200 | PASS - Schema deleted |

### 1.3 Lookup by Identifier

| Lookup Method | Example | HTTP Status | Result |
|---------------|---------|-------------|--------|
| By numeric ID | /api/registers/1 | 200 | PASS |
| By slug | /api/registers/pipelinq | 200 | PASS |
| By UUID | /api/registers/a900ff70-2f9c-4247-b76e-11bf59152a34 | 200 | PASS |

### 1.4 Data Integrity

The system has 3 registers:
- **Pipelinq** (id=1): 8 schemas, v1.0.0 - CRM register
- **Procest** (id=2): 26 schemas, v1.0.0 - Case management
- **Planix** (id=3): 5 schemas, v0.2.0 - Task/project management

Total schemas in system: 55 (across pipelinq, procest, planix applications).

### 1.5 Issues Found

- **BUG [MEDIUM]:** Getting a deleted/non-existent register returns HTTP 500 instead of 404. The DoesNotExistException is not caught and converted to a proper 404 response.
- **BUG [LOW]:** Created register has created: null, updated: null, and owner: null -- these should be auto-populated.

---

## 2. API Testing

### 2.1 Endpoint Inventory

| Endpoint | GET (list) | GET (single) | POST | PUT | DELETE | HEAD | OPTIONS |
|----------|-----------|-------------|------|-----|--------|------|---------|
| /api/registers | 200 | 200 | 201 | 200 | 200 | -- | -- |
| /api/schemas | 200 | 200 | 201 | -- | 200 | -- | -- |
| /api/objects | 200 | 404 (no data) | -- | -- | -- | -- | -- |
| /api/sources | 200 | -- | -- | -- | -- | -- | -- |
| /api/audits | **404** | -- | -- | -- | -- | -- | -- |

### 2.2 Pagination

| Parameter | Registers | Objects |
|-----------|-----------|---------|
| _limit=2&_page=1 | Returns 2 results | -- |
| _limit=2&_page=2 | Returns 1 result | -- |
| Total in metadata | **Missing** | Present |
| Page in metadata | **Missing** | Present |
| Pages in metadata | **Missing** | Present |
| Limit in metadata | **Missing** | Present |
| Facets in metadata | **Missing** | Present |

### 2.3 Search/Filtering

- Search for "Pipelinq" on /api/registers returned 3 results (all registers) -- **search appears to not filter properly** or the query matched broadly.

### 2.4 Input Validation

| Test | HTTP Status | Result |
|------|-------------|--------|
| Empty POST body | 409 (Conflict) | PASS - Properly rejected |
| Malformed JSON | **500** | **FAIL** - Should return 400 Bad Request |
| Invalid content-type (XML) | 409 | Acceptable |
| DELETE on collection | 405 | PASS - Method Not Allowed |

### 2.5 Response Structure

**Registers endpoint** returns only {"results": [...]} without pagination metadata (total, page, pages, limit, facets).

**Objects endpoint** returns the full structure: {"results": [], "total": 0, "page": 1, "pages": 1, "limit": 20, "offset": 0, "facets": [], "@self": {...}}.

### 2.6 Issues Found

- **BUG [MEDIUM]:** /api/registers response is missing pagination metadata (total, page, pages, limit, facets) that /api/objects correctly includes. Inconsistent API contract.
- **BUG [MEDIUM]:** /api/audits returns 404 -- endpoint appears non-functional or not implemented.
- **BUG [LOW]:** Malformed JSON POST returns 500 instead of 400 Bad Request.
- **BUG [LOW]:** Search on registers (_search=Pipelinq) returns all 3 registers instead of just the one matching "Pipelinq".

---

## 3. Security Testing

### 3.1 Authentication & Authorization

| Test | Endpoint | Expected | Actual | Result |
|------|----------|----------|--------|--------|
| Unauthenticated GET | /api/registers | 401 | **401** | PASS |
| Unauthenticated GET | /api/schemas | 401 | **200** | **FAIL - CRITICAL** |
| Unauthenticated GET | /api/objects | 401 | **200** | **FAIL - CRITICAL** |
| Wrong password | /api/registers | 401 | 401 | PASS |

**55 schemas are exposed to unauthenticated users**, including full property definitions, application names, and organizational UUIDs.

### 3.2 CSRF Protection

| Test | Result |
|------|--------|
| Missing OCS-APIRequest header | **HTTP 200** -- requests succeed without CSRF token |

Note: Nextcloud typically requires OCS-APIRequest: true for CSRF protection on API calls. The fact that the OpenRegister API works without it suggests CSRF protection may not be enforced on these endpoints.

### 3.3 Injection Testing

| Test | HTTP Status | Result |
|------|-------------|--------|
| SQL injection in ID (1' OR 1=1--) | 500 | Input not sanitized, but parameterized queries prevent actual injection |
| SQL injection in search | 200 | No injection observed |
| XSS in search parameter | 200 | Script tags NOT reflected in JSON response - PASS |
| Path traversal (/../../../etc/passwd) | 404 | PASS - Not exploitable |

### 3.4 Information Disclosure

**CRITICAL:** Error responses (HTTP 500) include:
- Full PHP stack traces
- Internal file paths (e.g., /var/www/html/apps-extra/openregister/lib/Db/RegisterMapper.php)
- Database query strings (e.g., SELECT * FROM *PREFIX*openregister_registers WHERE...)
- Internal IP addresses
- Request IDs
- OCP framework exception types and line numbers

This gives attackers detailed knowledge of the application's internal structure, database schema, and technology stack.

### 3.5 Security Headers

| Header | Value | Assessment |
|--------|-------|------------|
| X-Content-Type-Options | nosniff | PASS |
| X-Frame-Options | SAMEORIGIN | PASS |
| Referrer-Policy | no-referrer | PASS |
| Content-Security-Policy | Comprehensive policy with nonces | PASS |
| CORS headers | None returned for cross-origin request | PASS |
| Strict-Transport-Security | Not present (HTTP only) | N/A (dev environment) |

### 3.6 Issues Found

- **CRITICAL [P0]:** /api/schemas and /api/objects are accessible without authentication, exposing all 55 schema definitions including property structures and organizational data.
- **CRITICAL [P0]:** Error responses leak full stack traces, SQL queries, internal file paths, and server structure. This must be disabled in production.
- **HIGH [P1]:** CSRF protection (OCS-APIRequest header) is not enforced on OpenRegister API endpoints.
- **MEDIUM [P2]:** Invalid input (negative IDs, SQL injection strings) returns 500 with full error details instead of clean 400/404 responses.

---

## 4. Performance Testing

### 4.1 Response Times (5 requests each, in seconds)

| Endpoint | Min | Max | Avg | Median |
|----------|-----|-----|-----|--------|
| GET /api/registers | 0.641 | 0.795 | 0.730 | 0.743 |
| GET /api/schemas | 0.650 | 0.887 | 0.723 | 0.674 |
| GET /api/objects | 0.634 | 0.865 | 0.705 | 0.659 |
| GET /api/registers/1 | 0.629 | 1.025 | 0.727 | 0.654 |
| GET /apps/openregister/ (page) | 0.642 | 0.998 | 0.749 | 0.700 |

### 4.2 Response Sizes

| Endpoint | Size |
|----------|------|
| /api/registers (3 items) | 2,552 bytes |
| /api/schemas (55 items) | 128,048 bytes (128 KB) |
| /api/objects (0 items) | 207 bytes |
| /api/registers/1 (single) | 867 bytes |
| App HTML page | 21,825 bytes |

### 4.3 Asset Sizes

| Asset | Size | Assessment |
|-------|------|------------|
| openregister main.css | 24,340 bytes (24 KB) | Acceptable |
| openregister main.js | **9,058,737 bytes (8.6 MB)** | **EXCESSIVE** |

### 4.4 Observations

- First requests consistently take ~1s (cold start), subsequent requests ~0.65s.
- TTFB matches total time closely, meaning most time is server processing with minimal data transfer overhead.
- The schemas endpoint returns 128KB for 55 schemas -- no lazy loading or pagination by default.
- **The JavaScript bundle at 8.6MB is extremely large** and will severely impact initial page load on slow connections.

### 4.5 Issues Found

- **HIGH [P1]:** JavaScript bundle is 8.6 MB uncompressed. This should be code-split, tree-shaken, and served with gzip/brotli compression.
- **MEDIUM [P2]:** Average API response times of ~700ms are slow for simple list/read operations. Consider caching, query optimization, or database indexing.
- **LOW [P3]:** Schemas endpoint dumps all 128KB of data with no default pagination.

---

## 5. Accessibility Testing

### 5.1 Page Structure

| Check | Status | Detail |
|-------|--------|--------|
| lang attribute | PASS | lang="nl" on html element |
| Character encoding | PASS | charset="utf-8" |
| Viewport meta | PASS | width=device-width, initial-scale=1.0, minimum-scale=1.0 |
| Page title | PARTIAL | Title tag exists but was empty at time of capture (SPA renders it client-side) |

### 5.2 Skip Navigation

| Check | Status | Detail |
|-------|--------|--------|
| Skip to content link | PASS | "Ga naar hoofdinhoud" (Go to main content) |
| Skip to navigation link | PASS | "Ga naar navigatie van app" (Go to app navigation) |

### 5.3 ARIA & Semantic HTML

| Check | Count | Assessment |
|-------|-------|------------|
| aria-label attributes | 1 | LOW - Only 1 aria-label on the entire page |
| aria-hidden attributes | 0 | -- |
| aria-describedby attributes | 0 | -- |
| role attributes | 0 | LOW - No ARIA roles defined |
| Heading elements (h1-h6) | 1 (h1 hidden) | The h1 is visually hidden ("Nextcloud") |

### 5.4 Forms & Interactive Elements

| Check | Count | Assessment |
|-------|-------|------------|
| Input elements | 13 | All are hidden initial-state inputs (not user-facing) |
| Label elements | 0 | N/A (no visible form elements in server HTML) |
| Button elements | 0 | N/A (SPA renders buttons client-side) |

### 5.5 Theme & Visual Accessibility

| Check | Status | Detail |
|-------|--------|--------|
| Dark mode CSS | PASS | Included via prefers-color-scheme media query |
| High contrast CSS | PASS | Both light and dark high-contrast variants |
| OpenDyslexic font | PASS | CSS for dyslexia-friendly font included |
| Color scheme meta | PASS | content="light dark" |
| Noscript fallback | PASS | Displays message about JavaScript requirement |

### 5.6 Limitations

Since OpenRegister is a Vue.js SPA (Single Page Application), the server-rendered HTML contains only the shell. All interactive elements (navigation, forms, buttons, data tables) are rendered client-side via JavaScript. A proper accessibility audit would require browser-based testing with a screen reader or axe-core to evaluate:
- Dynamic ARIA attributes on Vue components
- Focus management during navigation
- Keyboard operability of all interactive elements
- Color contrast ratios on rendered content
- Screen reader announcement of dynamic content changes

### 5.7 Issues Found

- **MEDIUM [P2]:** Cannot assess full accessibility without browser JS execution. The SPA approach means all a11y depends on client-side implementation.
- **LOW [P3]:** Server-rendered page has minimal ARIA attributes (1 aria-label, 0 roles). The Nextcloud shell provides skip links and basic structure, but the app content div is empty.

---

## 6. UX Testing

### 6.1 Progressive Web App (PWA)

| Check | Status | Detail |
|-------|--------|--------|
| Web app manifest | PASS | Available at /index.php/apps/theming/manifest/openregister |
| apple-mobile-web-app-capable | PASS | content="yes" |
| Mobile viewport | PASS | Responsive viewport configured |
| Theme color | PASS | #00679e |

### 6.2 Localization

| Check | Status | Detail |
|-------|--------|--------|
| Language detection | PASS | Dutch (nl) locale detected and applied |
| Localization files | PASS | openregister/l10n/nl.js loaded |
| Skip links localized | PASS | "Ga naar hoofdinhoud" (Dutch) |
| Date format localized | PASS | dd-MM-y (Dutch format) |

### 6.3 Navigation

The app is listed in the Nextcloud top navigation bar as "Register" alongside Dashboard, Bestanden (Files), Procest, Planix, Pipelinq, and Connector.

### 6.4 Page Load Composition

- **CSS files:** 3 app-specific + 10 theme CSS files = 13 total
- **JS files:** 7 core + 5 app-specific = 12 total script tags
- **Deferred loading:** All scripts use defer attribute - GOOD

### 6.5 Issues Found

- **HIGH [P1]:** 8.6 MB JavaScript bundle will cause poor perceived performance, especially on mobile devices and slow connections. Users may see a blank app area for several seconds.
- **LOW [P3]:** The page title renders as "Register - Nextcloud" which is the Dutch display name. This is correct but may be confusing in English contexts since "Register" could be misread as a verb.

---

## Summary of All Issues

### Critical (P0)
1. **[Security]** Unauthenticated access to /api/schemas exposes 55 schema definitions
2. **[Security]** Unauthenticated access to /api/objects exposes object data
3. **[Security]** Error responses leak full stack traces, SQL queries, and internal file paths

### High (P1)
4. **[Security]** CSRF protection not enforced on API endpoints
5. **[Performance]** JavaScript bundle is 8.6 MB -- needs code splitting and compression
6. **[Performance]** Average API response times ~700ms for simple operations

### Medium (P2)
7. **[Functional]** Accessing deleted/non-existent resources returns 500 instead of 404
8. **[API]** /api/registers missing pagination metadata (total, page, pages, limit, facets) that /api/objects has
9. **[API]** /api/audits returns 404 (not implemented?)
10. **[Security]** Invalid input (negative IDs, malformed requests) returns 500 with verbose error details
11. **[Accessibility]** Full a11y audit not possible without browser JS execution

### Low (P3)
12. **[API]** Malformed JSON POST returns 500 instead of 400
13. **[API]** Search on registers returns all results regardless of search term
14. **[Functional]** Created register has null created/updated/owner timestamps
15. **[Performance]** Schemas endpoint returns all 128KB with no default pagination
16. **[Accessibility]** Server-rendered HTML has minimal ARIA attributes

---

## Test Environment Notes

- Testing was performed using curl for API and HTML analysis. No browser automation tool (Playwright, Puppeteer) was available.
- The Nextcloud instance is running in development mode (_oc_debug=true), which explains verbose error output. However, the authentication gaps on /api/schemas and /api/objects are likely configuration issues that would persist in production.
- All tests used basic authentication (admin/admin). Token-based auth was not tested.
- The SPA nature of the app means that functional UI testing, full accessibility audits, and UX interaction testing could not be performed without a browser engine. The findings above are based on server-side responses and HTML structure analysis only.
