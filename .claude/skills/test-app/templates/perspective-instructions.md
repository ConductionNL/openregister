# Perspective-Specific Instructions

Include the matching block as `{PERSPECTIVE_INSTRUCTIONS}` in the sub-agent prompt template.

---

### Quick Mode (comprehensive)

```
## Your Focus: Comprehensive Smoke Test
Test everything breadth-first. Visit every page, click every major button, submit every form.
Prioritize coverage over depth — make sure nothing is completely broken.
For forms: test with valid data (happy path) and one empty submission (validation).
```

---

### Functional

```
## Your Focus: Functional Correctness
Test every CRUD operation: Create, Read, Update, Delete.
Verify navigation between pages works correctly.
Test form validations — required fields, invalid input.
Check that data persists after creation (navigate away and back).
Test search/filter functionality if present.
Verify status changes, assignments, and workflows.
```

---

### UX

```
## Your Focus: User Experience
Check every page for: meaningful empty states, loading indicators, success/error feedback.
Verify labels and descriptions are clear and in proper language.
Check button labels match their actions.
Look for confusing navigation or dead ends.
Test that modals/dialogs have proper close buttons and cancel options.
Check for consistent styling and spacing.
```

---

### Performance

```
## Your Focus: Performance
After every navigation, check `browser_network_requests`:
- Flag API calls >500ms as SLOW
- Flag API calls >1000ms as PERFORMANCE_FAIL
Time how long pages take to become interactive.
Test with search/filter operations — do results appear promptly?
Check if large lists have pagination.
Record a performance summary at the end.
```

---

### Accessibility

```
## Your Focus: Accessibility
Test keyboard navigation: Tab through every page, check focus indicators.
Check that all interactive elements are reachable via keyboard.
Verify all images/icons have alt text or aria-labels.
Check color contrast of text and interactive elements.
Verify form fields have associated labels.
Check that error messages are accessible (not just color-coded).
Test that modals trap focus correctly.
```

---

### Security

```
## Your Focus: Security
Check `browser_console_messages` for errors on every page — look for leaked data.
Try accessing admin URLs as a non-admin user (if multiple accounts available).
Check for sensitive data in network responses that shouldn't be there.
Look for XSS vectors in text fields (try entering `<script>alert(1)</script>`).
Verify that CSRF tokens are present on forms.
Check that navigation doesn't expose internal IDs in exploitable ways.
```

---

### API

````
## Your Focus: API Quality
Use `browser_evaluate` to test API endpoints directly via fetch():
```javascript
const r = await fetch(url, { headers: { requesttoken: OC.requestToken } });
return { status: r.status, body: await r.json() };
```
Test all CRUD endpoints for the app's resources.
Verify error responses have proper status codes and messages.
Test with invalid/missing data — does the API return helpful errors?
Check pagination parameters (_limit, _offset, _page).
Verify that list endpoints return consistent data structures.
````
