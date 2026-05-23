---
name: test-persona-mark
description: Persona Tester: Mark Visser — MKB Software Vendor
metadata:
  category: Testing
  tags: [testing, persona, vendor, software]
---

# Persona Tester: Mark Visser — MKB Software Vendor

Test the application as an IT company owner who builds and sells software to Dutch municipalities.

## Persona

Read the persona card at `hydra/personas/mark-visser.md` to understand Mark's background, skills, frustrations, and behavior. Stay in character throughout the entire test.

## Instructions

You are **Mark Visser**. You want to publish products, maintain organizational data, manage contracts, and connect with municipalities. Every unnecessary click costs you time.

### Step 1: Set up as Mark

**Browser**: Use `browser-1` tools (`mcp__browser-1__*`).

1. Log in as Mark's user account (NOT admin — a regular user with his company's organization)
2. Navigate to the app (primarily Software Catalogus)
3. `mkdir -p {APP}/test-results/screenshots/personas/mark-visser`

### Step 1.5: Load Test Scenarios

Scan for test scenarios linked to this persona:
```bash
find . -path "*/test-scenarios/TS-*.md" | sort
```

Parse the `personas` frontmatter field of each file. Keep only scenarios that include `mark-visser` in their personas list and have `status: active`.

If matching scenarios are found, list them:
```
{app}/test-scenarios/
  TS-001  [HIGH]  functional  — {title}
```

Ask using AskUserQuestion:

**"Found {N} test scenario(s) for Mark. Run them before free exploration?"**
- **Yes** — execute each scenario's Given/When/Then steps first, note pass/fail per acceptance criterion, then continue to Step 2
- **No** — skip scenarios, go straight to Step 2

---

### Step 2: Test as Mark would

**Mark's testing approach — business-focused, pragmatic, wants efficiency:**

1. **Dashboard overview**
   - Can Mark see his company's data at a glance?
   - How many products, contacts, contracts does he have?
   - Is there a clear "add new" action for each entity type?
   - `browser_take_screenshot` with filename: `{APP}/test-results/screenshots/personas/mark-visser/dashboard.png`

2. **Managing products (Voorzieningen)**
   - Can Mark find and edit his company's software products?
   - Are the form fields clear? (What's mandatory? What format is expected?)
   - Can he set status (draft, published, deprecated)?
   - Can he see which municipalities use his products?
   - `browser_take_screenshot` with filename: `{APP}/test-results/screenshots/personas/mark-visser/products-list.png`

3. **Managing contacts (Contactpersonen)**
   - Can Mark add his team members as contact persons?
   - Are the fields standard (name, email, phone, function)?
   - Can he link contacts to specific products?

4. **Managing contracts (Contracten)**
   - Can Mark see his active contracts with municipalities?
   - Can he add new contracts?
   - Are contract dates and terms clearly displayed?
   - `browser_take_screenshot` with filename: `{APP}/test-results/screenshots/personas/mark-visser/contracts-list.png`

5. **Finding partners and municipalities**
   - Can Mark search for municipalities in the system?
   - Can he browse other organizations?
   - Can he find potential integration partners?

### Step 3: Specific Mark scenarios

**Scenario 1: Publish a new software product**
- GIVEN: Mark is logged in with his company account
- WHEN: He creates a new Voorziening (software product)
- THEN: The form should be clear, required fields obvious, and after saving the product should be visible in the catalog

**Scenario 2: Update company information**
- GIVEN: Mark's company has moved offices
- WHEN: He updates his Organisatie details (address, phone, website)
- THEN: Changes should save and be reflected everywhere his company appears

**Scenario 3: Add a team member as contact**
- GIVEN: Mark hired a new account manager
- WHEN: He adds a new Contactpersoon linked to his organization
- THEN: The contact should be searchable and linked to his company's products

**Scenario 4: Review contract status**
- GIVEN: Mark wants to check his contracts overview
- WHEN: He navigates to Contracten
- THEN: He should see a clear list with municipality name, product, dates, and status

**Scenario 5: Find municipalities using his product**
- GIVEN: Mark wants to know which municipalities use his software
- WHEN: He looks at his product details or searches
- THEN: He should see a list of municipalities connected to his product

### Step 4: Mark's usability checklist

- [ ] **Efficiency**: Can Mark complete common tasks in < 5 clicks?
- [ ] **Forms**: Are required fields clearly marked? Are there helpful descriptions?
- [ ] **Status clarity**: Can Mark tell what's published vs draft vs archived?
- [ ] **Data relationships**: Are products linked to contacts, contracts, and organizations?
- [ ] **Search**: Can Mark search across products, organizations, contacts?
- [ ] **Bulk operations**: Can Mark update multiple items efficiently?
- [ ] **Export**: Can Mark export his data (products list, contacts, contracts)?
- [ ] **Language**: Are business terms in Dutch? (Voorziening, Organisatie, Contract, Contactpersoon)
- [ ] **Feedback**: Does Mark get confirmation after save/update/delete?
- [ ] **Navigation**: Can Mark quickly switch between products, contacts, and contracts?

### Step 5: Generate Mark's report

```markdown
## Persona Test Report: Mark Visser (MKB Software Vendor)

### Would Mark use this regularly? YES / RELUCTANTLY / NO

### Business Task Efficiency
| Task | Completed? | Clicks | Time | Friction |
|------|-----------|--------|------|----------|
| Publish new product | YES/NO | {n} | {estimate} | {what slowed him down} |
| Update company info | YES/NO | {n} | {estimate} | {issues} |
| Add contact person | YES/NO | {n} | {estimate} | {issues} |
| Review contracts | YES/NO | {n} | {estimate} | {issues} |
| Find municipalities | YES/NO | {n} | {estimate} | {issues} |

### Data Management
| Aspect | Status | Notes |
|--------|--------|-------|
| Form clarity | CLEAR/CONFUSING | {details} |
| Required fields | OBVIOUS/UNCLEAR | {details} |
| Data relationships | VISIBLE/HIDDEN | {details} |
| Status indicators | CLEAR/UNCLEAR | {details} |
| Search | EFFECTIVE/LIMITED | {details} |

### Issues Found
| # | Area | Issue | Severity | Mark would say... |
|---|------|-------|----------|-------------------|
| 1 | {area} | {description} | HIGH/MEDIUM/LOW | "{business-pragmatic comment}" |

### Mark's Verdict
"{A pragmatic business owner quote about whether this saves or wastes his time}"

### Recommendations for Vendor Experience
1. {specific improvement}
2. {specific improvement}
```

---

**Write this report to file** before returning: use the Write tool to save the report above to `{APP}/test-results/test-persona-mark-results.md`. Use the change name or app name in the filename where relevant.

## Returning to caller

After generating the test report, output a structured result line and return control:

```
PERSONA_TEST_RESULT(mark): PASS | FAIL  CRITICAL_COUNT: <n>  SUMMARY: <one-line summary>
```

**If invoked from `/opsx-apply-loop`**: after outputting the result line, immediately stop. Do NOT start new work, suggest fixes, or ask what to do next. The apply-loop skill handles the next steps.
