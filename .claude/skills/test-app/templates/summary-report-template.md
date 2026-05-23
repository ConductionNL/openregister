# {APP} — Test Results Summary

**Date:** {today's date}
**Environment:** {BACKEND}
**Mode:** {Quick / Full (6 perspectives)}
**Method:** Automated browser testing with Playwright MCP (headless)

> Experimental agentic testing — results should be verified manually for critical findings.

---

## Overall Results

| Status | Count | Percentage |
|--------|-------|------------|
| **PASS** | {n} | {pct}% |
| **PARTIAL** | {n} | {pct}% |
| **FAIL** | {n} | {pct}% |
| **CANNOT_TEST** | {n} | {pct}% |

---

## FAIL Issues (Requires Attention)

| Feature | Perspective | Summary | Severity |
|---------|-------------|---------|----------|
| {feature} | {perspective} | {one-line summary} | HIGH/MEDIUM/LOW |

---

## PARTIAL Issues (Needs Investigation)

| Feature | Perspective | What Works | What Doesn't |
|---------|-------------|------------|--------------|
| {feature} | {perspective} | {working parts} | {broken parts} |

---

## CANNOT_TEST (Blocked)

| Feature | Perspective | Reason |
|---------|-------------|--------|
| {feature} | {perspective} | {why it couldn't be tested} |

---

## Results by Perspective

### {Perspective Name}
- **PASS**: {n} | **PARTIAL**: {n} | **FAIL**: {n} | **CANNOT_TEST**: {n}
- **Key findings**: {2-3 bullet points}

{repeat for each perspective}

---

## Console Errors (Across All Perspectives)

| Error | Occurrences | Pages |
|-------|-------------|-------|
| {error} | {n} | {pages} |

---

## Recommendations

### High Priority
{numbered list of FAIL items}

### Medium Priority
{numbered list of PARTIAL items}

### For Next Test Run
{improvements to testing approach}
