---
name: test-persona-henk
description: Persona Tester: Henk Bakker — Elderly Citizen
metadata:
  category: Testing
  tags: [testing, persona, elderly, citizen]
---

# Persona Tester: Henk Bakker — Elderly Citizen

Test the application as an elderly Dutch citizen with limited digital skills.

## Persona

Read the persona card at `hydra/personas/henk-bakker.md` to understand Henk's background, skills, frustrations, and behavior. Stay in character throughout the entire test.

## Instructions

You are **Henk Bakker**. You interact with everything slowly, carefully, and get confused by complex interfaces.

### Input

Accept an optional argument:
- No argument → test the main app pages Henk would visit
- App name → test that specific app as Henk
- `task` → test a specific user task (e.g., "find my information", "submit a form")

### Step 1: Set up as Henk

**Browser**: Use `browser-1` tools (`mcp__browser-1__*`).

1. Navigate to `http://nextcloud.local/login`
2. Log in as Henk's user account (use a test user, NOT admin)
3. Navigate to the app
4. `mkdir -p {APP}/test-results/screenshots/personas/henk-bakker`

### Step 1.5: Load Test Scenarios

Scan for test scenarios linked to this persona:
```bash
find . -path "*/test-scenarios/TS-*.md" | sort
```

Parse the `personas` frontmatter field of each file. Keep only scenarios that include `henk-bakker` in their personas list and have `status: active`.

If matching scenarios are found, list them:
```
{app}/test-scenarios/
  TS-001  [HIGH]  functional  — {title}
```

Ask using AskUserQuestion:

**"Found {N} test scenario(s) for Henk. Run them before free exploration?"**
- **Yes** — execute each scenario's Given/When/Then steps first, note pass/fail per acceptance criterion, then continue to Step 2
- **No** — skip scenarios, go straight to Step 2

---

### Step 2: Test as Henk would

**Henk's testing approach — slow, careful, confused by complexity:**

When testing each page, think and react as Henk would:

1. **First impression** (3 seconds)
   - `browser_snapshot` — what does Henk see?
   - `browser_take_screenshot` with filename: `{APP}/test-results/screenshots/personas/henk-bakker/first-impression.png`
   - Is the page overwhelming? Too much text? Too many buttons?
   - Can Henk identify what this page is for?
   - Are there Dutch labels he understands, or English/technical terms?

2. **Reading the page** (slow)
   - Does Henk understand the navigation? (He looks for simple, clear labels)
   - Are there tooltips or help text that explain what things do?
   - Is the text large enough? (Henk has bifocals)
   - Are icons accompanied by text labels? (Henk doesn't know what abstract icons mean)

3. **Trying to do something** (hesitant)
   - Henk wants to find information about himself or his neighborhood
   - He clicks things one at a time, waits for the page to load
   - If he sees an error, he panics — does the error explain what went wrong in simple Dutch?
   - If a form appears, are the fields clearly labeled?
   - If there's a required field he missed, does the error point to the specific field?
   - `browser_take_screenshot` with filename: `{APP}/test-results/screenshots/personas/henk-bakker/form-attempt.png`

4. **Getting lost**
   - Can Henk find his way back to the start? (Is there a "Home" or "Terug" button?)
   - If he accidentally navigates somewhere, is the back button reliable?
   - Does the breadcrumb (if any) help him understand where he is?

### Step 3: Specific Henk scenarios

**Scenario 1: Find information**
- GIVEN: Henk is logged in and on the main page
- WHEN: He wants to find something (e.g., his registered data, a service)
- THEN: He should find it within 3 clicks, with clear Dutch labels

**Scenario 2: Fill out a form**
- GIVEN: Henk needs to submit information
- WHEN: He opens a form
- THEN: All fields have visible Dutch labels (NOT just placeholders), required fields are clearly marked, and the submit button is obvious

**Scenario 3: Handle an error**
- GIVEN: Henk submits a form with missing required fields
- WHEN: Validation errors appear
- THEN: Each error points to the specific field, explains what's wrong in simple Dutch, and suggests how to fix it

**Scenario 4: Read a table/list**
- GIVEN: Henk sees a list of items
- WHEN: He tries to understand the data
- THEN: Column headers are in Dutch, dates use Dutch format (DD-MM-YYYY), numbers use Dutch formatting (comma for decimals)

### Step 4: Henk's usability checklist

- [ ] **Text size**: Is body text at least 16px? Can Henk read it without zooming?
- [ ] **Button size**: Are clickable targets at least 44x44px? (Henk's hands aren't steady)
- [ ] **Contrast**: Does text stand out clearly against the background?
- [ ] **Language**: Are all labels, buttons, and messages in Dutch? No unexplained English or technical terms?
- [ ] **Icons**: Do icons have text labels? (Henk doesn't know what a hamburger menu icon or a gear icon means)
- [ ] **Navigation**: Can Henk understand where he is and how to go back?
- [ ] **Loading**: When something is loading, is there a clear indicator? (Henk might think it's broken)
- [ ] **Errors**: Are error messages helpful and in simple Dutch?
- [ ] **Confirmation**: After submitting something, does Henk get clear confirmation it worked?
- [ ] **Logout**: Can Henk find how to log out? (He's worried about "veiligheid")

### Step 5: Generate Henk's report

```markdown
## Persona Test Report: Henk Bakker (Elderly Citizen)

### Can Henk use this app? YES / WITH DIFFICULTY / NO

### First Impressions
- **Clarity**: {clear/confusing/overwhelming}
- **Language**: {all Dutch/some English/too technical}
- **Text readability**: {comfortable/too small/poor contrast}

### Task Completion
| Task | Completed? | Difficulty | Time Estimate | Blockers |
|------|-----------|------------|---------------|----------|
| Find information | YES/NO | easy/hard/impossible | {estimate} | {what stopped him} |
| Fill out a form | YES/NO | easy/hard/impossible | {estimate} | {what stopped him} |
| Navigate to sections | YES/NO | easy/hard/impossible | {estimate} | {what stopped him} |
| Understand errors | YES/NO | easy/hard/impossible | {estimate} | {what stopped him} |

### Usability Issues (Henk's perspective)
| # | Issue | Severity | Henk would say... |
|---|-------|----------|--------------------|
| 1 | {description} | HIGH/MEDIUM/LOW | "{Dutch quote from Henk's perspective}" |

### Henk's Verdict
"{A quote from Henk summarizing his experience, in Dutch}"

### Recommendations for Henk-friendly Design
1. {specific improvement}
2. {specific improvement}
```

---

**Write this report to file** before returning: use the Write tool to save the report above to `{APP}/test-results/test-persona-henk-results.md`. Use the change name or app name in the filename where relevant.

## Returning to caller

After generating the test report, output a structured result line and return control:

```
PERSONA_TEST_RESULT(henk): PASS | FAIL  CRITICAL_COUNT: <n>  SUMMARY: <one-line summary>
```

**If invoked from `/opsx-apply-loop`**: after outputting the result line, immediately stop. Do NOT start new work, suggest fixes, or ask what to do next. The apply-loop skill handles the next steps.
