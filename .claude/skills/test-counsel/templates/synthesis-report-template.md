---
name: synthesis-report-template
description: Synthesis report template for the Hydra test-counsel multi-persona results (8 Dutch public sector personas)
type: reference
user-invocable: false
---

# Test Counsel Report: {PROJECT}

**Date:** {today's date}
**Environment:** {BACKEND} / {FRONTEND}
**Method:** 8-persona browser, API, and documentation testing against OpenSpec specifications
**Personas:** Henk Bakker, Fatima El-Amrani, Sem de Jong, Noor Yilmaz, Annemarie de Vries, Mark Visser, Priya Ganpat, Jan-Willem van der Berg

---

## Overall Results

| Persona | Features Tested | PASS | PARTIAL | FAIL | Not Implemented |
|---------|----------------|------|---------|------|-----------------|
| Henk Bakker | {n} | {n} | {n} | {n} | {n} |
| Fatima El-Amrani | {n} | {n} | {n} | {n} | {n} |
| Sem de Jong | {n} | {n} | {n} | {n} | {n} |
| Noor Yilmaz | {n} | {n} | {n} | {n} | {n} |
| Annemarie de Vries | {n} | {n} | {n} | {n} | {n} |
| Mark Visser | {n} | {n} | {n} | {n} | {n} |
| Priya Ganpat | {n} | {n} | {n} | {n} | {n} |
| Jan-Willem van der Berg | {n} | {n} | {n} | {n} | {n} |
| **Total** | {n} | {n} | {n} | {n} | {n} |

> Only include rows for personas that were actually tested in this run.

---

## Critical Issues (found by 3+ personas)

| # | Issue | Severity | Found by | Recommendation |
|---|-------|----------|----------|----------------|
| 1 | {issue} | CRITICAL/HIGH | {persona names} | {recommendation} |

---

## Spec vs Implementation Gap Analysis

| Spec Feature | Implemented? | Working? | Persona Feedback |
|-------------|-------------|---------|-----------------|
| {feature from spec} | YES/NO/PARTIAL | YES/NO | {summary of persona reactions} |

---

## Per-Persona Highlights

### Henk Bakker (Elderly Citizen)
- **Can Henk use this?** YES/WITH DIFFICULTY/NO
- **Top blocker**: {issue}
- **Quote**: "{in-character Dutch quote}"

### Fatima El-Amrani (Low-Literate Migrant)
- **Can Fatima complete key tasks?** YES/WITH DIFFICULTY/NO
- **Top blocker**: {issue}
- **Quote**: "{in-character quote}"

### Sem de Jong (Young Digital Native)
- **Does the modern UX meet expectations?** YES/PARTIALLY/NO
- **Top gap**: {issue}
- **Quote**: "{in-character quote}"

### Noor Yilmaz (CISO / Functional Admin)
- **Does the security posture hold up?** YES/PARTIALLY/NO
- **Top risk**: {issue}
- **Quote**: "{in-character quote}"

### Annemarie de Vries (VNG Standards Architect)
- **Does it meet NL standards (NLGov, GEMMA, ZGW)?** YES/PARTIALLY/NO
- **Top gap**: {issue}
- **Quote**: "{in-character quote}"

### Mark Visser (MKB Software Vendor)
- **Are business workflows efficient?** YES/WITH DIFFICULTY/NO
- **Top friction**: {issue}
- **Quote**: "{in-character quote}"

### Priya Ganpat (ZZP Developer / Integrator)
- **Would Priya enjoy integrating?** YES/IT'S OKAY/PAINFUL
- **Top blocker**: {issue}
- **Quote**: "{in-character quote}"

### Jan-Willem van der Berg (Small Business Owner)
- **Is the language plain and the path clear?** YES/PARTIALLY/NO
- **Top friction**: {issue}
- **Quote**: "{in-character quote}"

> Only include sections for personas that were actually tested in this run.

---

## Testing Categories

### Accessibility & Readability
| Issue | Severity | Personas | Spec Reference |
|-------|----------|----------|---------------|
| {issue} | {severity} | {who found it} | {spec section} |

### Security & Compliance
| Issue | Severity | Personas | Standard |
|-------|----------|----------|----------|
| {issue} | {severity} | {who found it} | {BIO2/AVG/etc} |

### API Quality & Standards
| Issue | Severity | Personas | NLGov Rule |
|-------|----------|----------|-----------|
| {issue} | {severity} | {who found it} | {rule} |

### UX & Performance
| Issue | Severity | Personas | Notes |
|-------|----------|----------|-------|
| {issue} | {severity} | {who found it} | {details} |

### Language & Content
| Issue | Severity | Personas | Notes |
|-------|----------|----------|-------|
| {issue} | {severity} | {who found it} | {details} |

---

## Console Errors Summary

| Error | Occurrences | Pages | Severity |
|-------|-------------|-------|----------|
| {error} | {count} | {pages} | {severity} |

---

## Recommendations

### CRITICAL (fix immediately)
1. {recommendation + which personas affected}

### HIGH (fix before next release)
1. {recommendation + which personas affected}

### MEDIUM (improve when possible)
1. {recommendation + which personas affected}

---

## Suggested OpenSpec Changes

| Change Name | Description | Related Issues | Personas Affected |
|-------------|-------------|---------------|------------------|
| {name} | {description} | {issue numbers from above} | {personas} |
