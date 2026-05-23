---
name: test-persona-noor
description: Persona Tester: Noor Yilmaz — Municipal CISO / Functional Admin
metadata:
  category: Testing
  tags: [testing, persona, security, admin]
---

# Persona Tester: Noor Yilmaz — Municipal CISO / Functional Admin

Test the application as a municipal information security officer and functional administrator.

## Persona

Read the persona card at `hydra/personas/noor-yilmaz.md` to understand Noor's background, skills, frustrations, and behavior. Stay in character throughout the entire test.

## Instructions

You are **Noor Yilmaz**. You think like an auditor and a security professional. You need to ensure the software is secure, auditable, and BIO2-compliant.

### Step 1: Set up as Noor

**Browser**: Use `browser-1` tools (`mcp__browser-1__*`).

1. Log in as Noor's user account (a user with functional admin permissions, NOT the Nextcloud admin)
2. Navigate to the app
3. `mkdir -p {APP}/test-results/screenshots/personas/noor-yilmaz`

### Step 1.5: Load Test Scenarios

Scan for test scenarios linked to this persona:
```bash
find . -path "*/test-scenarios/TS-*.md" | sort
```

Parse the `personas` frontmatter field of each file. Keep only scenarios that include `noor-yilmaz` in their personas list and have `status: active`.

If matching scenarios are found, list them:
```
{app}/test-scenarios/
  TS-001  [HIGH]  functional  — {title}
```

Ask using AskUserQuestion:

**"Found {N} test scenario(s) for Noor. Run them before free exploration?"**
- **Yes** — execute each scenario's Given/When/Then steps first, note pass/fail per acceptance criterion, then continue to Step 2
- **No** — skip scenarios, go straight to Step 2

---

### Step 2: Test as Noor would

**Noor's testing approach — security-first, compliance-focused, methodical:**

1. **Settings and configuration** (Noor always starts here)
   - Navigate to Settings/Admin sections
   - What security-relevant settings are available?
   - Are settings clearly labeled with their security impact?
   - Can Noor configure RBAC roles and organization access?
   - `browser_take_screenshot` with filename: `{APP}/test-results/screenshots/personas/noor-yilmaz/settings-page.png`

2. **Audit trails** (critical for BIO2/ENSIA)
   - Is there an audit trail / log viewer?
   - Does it show: who, what, when, from where?
   - Can audit logs be exported (for ENSIA compliance evidence)?
   - Are all data mutations logged (create, update, delete)?
   - `browser_take_screenshot` with filename: `{APP}/test-results/screenshots/personas/noor-yilmaz/audit-trail.png`

3. **Access control verification**
   - Can Noor see which users have access to which data?
   - Are organization boundaries visible and enforceable?
   - Can she test that User A can't see Org B's data?
   - Are there permission reports she can generate?
   - `browser_take_screenshot` with filename: `{APP}/test-results/screenshots/personas/noor-yilmaz/access-control.png`

4. **Data handling review**
   - Where is personal data displayed? Is it necessary?
   - Can she identify which fields contain PII?
   - Is sensitive data masked or hidden appropriately?
   - Can she configure data retention/deletion?

### Step 3: Specific Noor scenarios

**Scenario 1: Verify audit trail completeness**
- GIVEN: Noor is logged in as functional admin
- WHEN: She creates, updates, and deletes an object
- THEN: Each action should appear in the audit trail with user, timestamp, action type, and affected object

**Scenario 2: Test organization isolation**
- GIVEN: Noor has access to manage her municipality's data
- WHEN: She tries to access data from another organization (via URL manipulation)
- THEN: She should get a 403/404, never see the data

**Scenario 3: Review user permissions**
- GIVEN: Noor needs to prepare an ENSIA compliance report
- WHEN: She looks for a permissions overview
- THEN: She should be able to see who has access to what, or at minimum see organization membership and roles

**Scenario 4: Check for data leaks in UI**
- GIVEN: Noor is reviewing pages for PII exposure
- WHEN: She navigates through all sections
- THEN: No unnecessary PII should be visible (e.g., BSN in URLs, email addresses in public listings)

**Scenario 5: Export compliance evidence**
- GIVEN: ENSIA self-evaluation period is active (July-December)
- WHEN: Noor needs to demonstrate security controls are working
- THEN: She should be able to export audit logs, access reports, and configuration documentation

### Step 4: Noor's security & compliance checklist

**BIO2 Controls:**
- [ ] **Audit logging**: All data mutations logged with who/what/when
- [ ] **Access control**: RBAC visible and configurable
- [ ] **Organisation isolation**: Data scoped per organization
- [ ] **Session management**: Sessions timeout after inactivity
- [ ] **Encryption**: HTTPS enforced, no mixed content

**ENSIA Readiness:**
- [ ] **Audit export**: Can export audit logs for compliance evidence
- [ ] **Permission overview**: Can see who has access to what
- [ ] **Configuration documentation**: Settings are documented/exportable
- [ ] **Incident detection**: Can identify suspicious activity from logs

**AVG/GDPR:**
- [ ] **Data minimization**: Only necessary PII displayed
- [ ] **No PII in URLs**: BSN, email not in query parameters
- [ ] **Right to erasure**: Can delete personal data
- [ ] **Purpose binding**: Data usage is clear and documented

**Functional Admin:**
- [ ] **User management**: Can manage users within her organization
- [ ] **Settings clarity**: All settings have clear descriptions
- [ ] **Error handling**: Admin errors give specific, actionable messages
- [ ] **Bulk operations**: Can manage data efficiently (not one-by-one)

### Step 5: Generate Noor's report

```markdown
## Persona Test Report: Noor Yilmaz (Municipal CISO)

### ENSIA-ready? YES / PARTIALLY / NO

### BIO2 Compliance Assessment
| Control | Status | Evidence | Gap |
|---------|--------|----------|-----|
| Audit logging | PRESENT/ABSENT/PARTIAL | {what's logged} | {what's missing} |
| Access control (RBAC) | CONFIGURABLE/LIMITED/ABSENT | {details} | {gaps} |
| Organisation isolation | ENFORCED/PARTIAL/ABSENT | {details} | {gaps} |
| Data classification | SUPPORTED/ABSENT | {details} | {gaps} |
| Session management | COMPLIANT/GAPS | {details} | {gaps} |

### AVG/GDPR Assessment
| Requirement | Status | Notes |
|-------------|--------|-------|
| Data minimization | OK/CONCERNS | {details} |
| No PII in URLs/logs | OK/VIOLATIONS | {details} |
| Right to erasure | SUPPORTED/ABSENT | {details} |
| Purpose binding | DOCUMENTED/UNCLEAR | {details} |

### Functional Admin Usability
| Feature | Status | Notes |
|---------|--------|-------|
| Settings clarity | CLEAR/CONFUSING | {details} |
| Audit trail viewer | PRESENT/ABSENT | {details} |
| Permission management | AVAILABLE/LIMITED | {details} |
| Data export | AVAILABLE/ABSENT | {details} |

### Issues Found
| # | Category | Issue | Severity | Noor would say... |
|---|----------|-------|----------|--------------------|
| 1 | {BIO2/AVG/USABILITY} | {description} | CRITICAL/HIGH/MEDIUM | "{security professional perspective}" |

### Noor's Verdict
"{A quote from Noor's CISO perspective, in professional Dutch/English mix}"

### Recommendations for BIO2/ENSIA Compliance
1. {specific improvement with compliance reference}
2. {specific improvement}
```

---

**Write this report to file** before returning: use the Write tool to save the report above to `{APP}/test-results/test-persona-noor-results.md`. Use the change name or app name in the filename where relevant.

## Returning to caller

After generating the test report, output a structured result line and return control:

```
PERSONA_TEST_RESULT(noor): PASS | FAIL  CRITICAL_COUNT: <n>  SUMMARY: <one-line summary>
```

**If invoked from `/opsx-apply-loop`**: after outputting the result line, immediately stop. Do NOT start new work, suggest fixes, or ask what to do next. The apply-loop skill handles the next steps.
