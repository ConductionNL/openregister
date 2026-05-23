## QA Report: {change-name}

### Overall: PASS / FAIL

### Test Results

| Suite | Tests | Passed | Failed | Skipped | Time |
|-------|-------|--------|--------|---------|------|
| Unit | {n} | {n} | {n} | {n} | {s}s |
| Integration | {n} | {n} | {n} | {n} | {s}s |
| Database | {n} | {n} | {n} | {n} | {s}s |
| Service | {n} | {n} | {n} | {n} | {s}s |
| Jest | {n} | {n} | {n} | {n} | {s}s |
| Newman | {n} | {n} | {n} | {n} | {s}s |

### Coverage

| Metric | Value | Gate | Status |
|--------|-------|------|--------|
| PHP Line Coverage | {n}% | 75% | PASS/FAIL |
| PHP Method Coverage | {n}% | 75% | PASS/FAIL |
| Frontend Coverage | {n}% | — | info |

### Acceptance Criteria Verification

#### Task {n}: {title}
| # | Criterion | Method | Status |
|---|-----------|--------|--------|
| 1 | GIVEN... WHEN... THEN... | Unit test / Browser / API | PASS/FAIL |
| 2 | ... | ... | ... |

{Repeat for each completed task}

### Browser Verification
- [ ] App loads without console errors
- [ ] Navigation works correctly
- [ ] CRUD operations function as expected
- [ ] Loading states display properly
- [ ] Error states are handled gracefully
- [ ] Responsive layout (if applicable)

### Regression Check
- [ ] Existing tests still pass
- [ ] No new console errors introduced
- [ ] No new network errors
- [ ] Related features still work (cross-app if applicable)

### Issues Found
| # | Severity | Description | Acceptance Criterion |
|---|----------|-------------|---------------------|
| 1 | {CRITICAL/HIGH/MEDIUM/LOW} | {description} | {which criterion fails} |

### Evidence
- Screenshots: {list of screenshot files}
- Test output: {link to coverage report}

### Recommendation
APPROVE FOR MERGE / NEEDS FIXES / NEEDS MORE TESTS
