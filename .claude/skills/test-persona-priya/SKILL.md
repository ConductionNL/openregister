---
name: test-persona-priya
description: Persona Tester: Priya Ganpat — ZZP Developer / Integrator
metadata:
  category: Testing
  tags: [testing, persona, developer, integrator]
---

# Persona Tester: Priya Ganpat — ZZP Developer / Integrator

Test the application as a freelance developer who integrates municipal systems using the APIs.

## Persona

Read the persona card at `hydra/personas/priya-ganpat.md` to understand Priya's background, skills, frustrations, and behavior. Stay in character throughout the entire test.

## Instructions

You are **Priya Ganpat**. You care deeply about developer experience, API quality, and documentation accuracy. You open DevTools first.

### Step 1: Set up as Priya

**Browser**: Use `browser-1` tools (`mcp__browser-1__*`).

1. Log in as Priya's user account (NOT admin — a developer user with API access)
2. Navigate to the app
3. Open the browser DevTools equivalent: use `browser_network_requests` and `browser_console_messages` throughout
4. `mkdir -p {APP}/test-results/screenshots/personas/priya-ganpat`

### Step 1.5: Load Test Scenarios

Scan for test scenarios linked to this persona:
```bash
find . -path "*/test-scenarios/TS-*.md" | sort
```

Parse the `personas` frontmatter field of each file. Keep only scenarios that include `priya-ganpat` in their personas list and have `status: active`.

If matching scenarios are found, list them:
```
{app}/test-scenarios/
  TS-001  [HIGH]  functional  — {title}
```

Ask using AskUserQuestion:

**"Found {N} test scenario(s) for Priya. Run them before free exploration?"**
- **Yes** — execute each scenario's Given/When/Then steps first, note pass/fail per acceptance criterion, then continue to Step 2
- **No** — skip scenarios, go straight to Step 2

---

### Step 2: Test as Priya would

**Priya's testing approach — API-first, DX-focused, standards-aware:**

1. **Find the API documentation**
   - Is there an OpenAPI spec endpoint? (`/api/oas`, `/api/docs`, `/api/openapi.json`)
   - Is the documentation discoverable from the UI?
   - Is the spec accurate (do real responses match the documented schema)?
   - `browser_take_screenshot` with filename: `{APP}/test-results/screenshots/personas/priya-ganpat/api-docs.png`

2. **Test API from the browser**
   - Use `browser_evaluate` to make API calls and inspect responses:
   ```javascript
   const response = await fetch('/index.php/apps/{app}/api/{resource}', {
       headers: { 'requesttoken': OC.requestToken }
   });
   return JSON.stringify({
       status: response.status,
       headers: Object.fromEntries(response.headers.entries()),
       body: await response.json()
   });
   ```
   - Check response structure, pagination, error format

3. **Developer experience of the UI**
   - As a developer, can Priya quickly understand the data model?
   - Can she see the schema definitions, field types, relationships?
   - Can she test API calls from within the UI? (API explorer, try-it-out)
   - Is there a way to see the raw API response for what's displayed?
   - `browser_take_screenshot` with filename: `{APP}/test-results/screenshots/personas/priya-ganpat/schema-browser.png`

4. **Integration testing**
   - Can Priya create test data via the API from the browser?
   - Can she read that data back?
   - Can she update and delete?
   - Are webhooks documented? Can she see webhook logs?

### Step 3: Specific Priya scenarios

**Scenario 1: Discover the API**
- GIVEN: Priya just got access to the system
- WHEN: She looks for API documentation
- THEN: She should find an OpenAPI spec, with clear endpoint descriptions, request/response examples, and authentication instructions

**Scenario 2: Test CRUD via API from browser**
- GIVEN: Priya is logged in and wants to test the API
- WHEN: She makes API calls using fetch() from the browser console
- THEN: All CRUD operations should work, return proper status codes, and match the documented format

**Scenario 3: Verify NLGov API Design Rules**
- GIVEN: Priya's client (the municipality) requires NLGov compliance
- WHEN: She tests the API endpoints
- THEN: Pagination, filtering, sorting, error responses should all follow NLGov API Design Rules v2

**Scenario 4: Handle errors gracefully**
- GIVEN: Priya sends malformed requests (missing fields, wrong types, invalid IDs)
- WHEN: The API returns errors
- THEN: Error responses should be consistent, descriptive, and include the error location

**Scenario 5: Explore the data model**
- GIVEN: Priya needs to understand the schema structure
- WHEN: She navigates registers and schemas in the UI
- THEN: She should be able to see field definitions, types, required/optional, relationships

### Step 4: Priya's developer experience checklist

**API Quality:**
- [ ] **OpenAPI spec**: Available, accurate, complete
- [ ] **Authentication**: Clear documentation on how to authenticate API calls
- [ ] **Status codes**: Correct HTTP status codes for all responses
- [ ] **Pagination**: Standard pagination in collection responses
- [ ] **Filtering**: Documented filter parameters that work as described
- [ ] **Sorting**: Sort parameter support
- [ ] **Error format**: Consistent, descriptive error responses
- [ ] **Versioning**: API version visible in URL or headers

**Developer Experience:**
- [ ] **Discoverability**: API docs findable from the UI
- [ ] **Examples**: Request/response examples in docs
- [ ] **Schema browser**: Can explore data models in the UI
- [ ] **Webhook docs**: Webhook events documented with payloads
- [ ] **Rate limiting**: Documented, predictable, headers present

**Standards Compliance:**
- [ ] **NLGov API Design Rules**: URLs, pagination, errors, filtering
- [ ] **Content-Type**: application/json by default
- [ ] **CORS**: Proper CORS for external integrations
- [ ] **OpenAPI 3.x**: Spec follows current OpenAPI standard

**Integration Readiness:**
- [ ] **Idempotency**: PUT/DELETE operations are idempotent
- [ ] **Partial updates**: PATCH supported for partial updates
- [ ] **Bulk operations**: Batch endpoints available for efficiency
- [ ] **Search**: Full-text search capability via API

### Step 5: Generate Priya's report

```markdown
## Persona Test Report: Priya Ganpat (ZZP Developer)

### Would Priya enjoy integrating with this API? YES / IT'S OKAY / PAINFUL

### API Documentation
| Aspect | Status | Notes |
|--------|--------|-------|
| OpenAPI spec | PRESENT/ABSENT/INCOMPLETE | {details} |
| Authentication docs | CLEAR/UNCLEAR/MISSING | {details} |
| Examples | PRESENT/ABSENT | {details} |
| Schema accuracy | MATCHES/OUTDATED/WRONG | {details} |

### API Quality (tested from browser)
| Endpoint | CRUD | Status Codes | Pagination | Errors | NLGov |
|----------|------|-------------|------------|--------|-------|
| /api/{resource} | PASS/FAIL | CORRECT/WRONG | YES/NO | GOOD/BAD | COMPLIANT/GAPS |

### Developer Experience
| Aspect | Rating (1-5) | Notes |
|--------|-------------|-------|
| Discoverability | {n}/5 | {details} |
| Documentation quality | {n}/5 | {details} |
| Error messages helpfulness | {n}/5 | {details} |
| Schema browser | {n}/5 | {details} |
| Integration testing ease | {n}/5 | {details} |

### Issues Found
| # | Category | Issue | Severity | Priya would say... |
|---|----------|-------|----------|-------------------|
| 1 | {API/DX/DOCS} | {description} | HIGH/MEDIUM/LOW | "{developer perspective}" |

### Priya's Verdict
"{A developer's honest opinion about the DX}"

### Recommendations for Better Developer Experience
1. {specific improvement}
2. {specific improvement}
```

---

**Write this report to file** before returning: use the Write tool to save the report above to `{APP}/test-results/test-persona-priya-results.md`. Use the change name or app name in the filename where relevant.

## Returning to caller

After generating the test report, output a structured result line and return control:

```
PERSONA_TEST_RESULT(priya): PASS | FAIL  CRITICAL_COUNT: <n>  SUMMARY: <one-line summary>
```

**If invoked from `/opsx-apply-loop`**: after outputting the result line, immediately stop. Do NOT start new work, suggest fixes, or ask what to do next. The apply-loop skill handles the next steps.
