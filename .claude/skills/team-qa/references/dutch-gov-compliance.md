### Step 8: Dutch Government Compliance Testing

Verify compliance with legally required Dutch government standards:

#### Accessibility Testing (WCAG 2.1 AA — Legally Required since 2018, EAA since June 2025)

**Legal context:** The Besluit digitale toegankelijkheid overheid mandates WCAG 2.1 Level AA (50 success criteria) via EN 301 549. Automated tools catch only 30-40% of issues — manual testing is essential.

**Toegankelijkheidsverklaring (Accessibility Declaration) Levels:**
- **Status A**: Fully compliant (all 50 WCAG 2.1 AA criteria met)
- **Status B**: Partially compliant (improvement plan exists)
- **Status C**: Not compliant (no or insufficient examination)
- Audit reports are valid for **3 years** as supporting evidence
- Declaration must be signed off by higher management

**Testing methodology:** Follow WCAG-EM (Website Accessibility Conformance Evaluation Methodology) — a structured 5-step process: Define scope → Explore website → Select sample → Audit sample → Report findings.

Run automated accessibility checks during browser verification:

**Using browser evaluation:**
```javascript
// Inject axe-core for automated WCAG checks (via browser_evaluate)
const script = document.createElement('script');
script.src = 'https://cdnjs.cloudflare.com/ajax/libs/axe-core/4.9.1/axe.min.js';
document.head.appendChild(script);
```

Then run the scan:
```javascript
// After axe-core loads
const results = await axe.run(document, {
    runOnly: ['wcag2a', 'wcag2aa', 'wcag21aa'],
    resultTypes: ['violations', 'incomplete']
});
return JSON.stringify({
    violations: results.violations.length,
    details: results.violations.map(v => ({
        id: v.id,
        impact: v.impact,
        description: v.description,
        nodes: v.nodes.length,
        help: v.helpUrl
    }))
});
```

**Manual accessibility checks (per page):**
- [ ] Tab through all interactive elements — logical order, no traps
- [ ] All focused elements have visible focus indicator
- [ ] Forms have visible labels (not just placeholders)
- [ ] Error messages reference the field and suggest correction
- [ ] Loading states announced to screen readers (`aria-live="polite"`)
- [ ] Modals trap focus and return focus on close
- [ ] Tables have `<caption>` and `<th scope="col/row">`

**Report accessibility findings in QA report:**
```markdown
### Accessibility (WCAG 2.1 AA)

| Page | axe Violations | Critical | Serious | Moderate | Minor |
|------|---------------|----------|---------|----------|-------|
| {page} | {count} | {n} | {n} | {n} | {n} |

#### Critical Violations (MUST FIX — legal requirement)
1. {violation id}: {description} — {count} instances — {help URL}

#### Keyboard Navigation
- [ ] All pages fully keyboard-navigable
- [ ] Focus order logical
- [ ] No keyboard traps
```

#### NLGov API Design Rules Validation

For each API endpoint tested, verify:
- [ ] Pagination response includes `results`, `total`, `page`, `pageSize`
- [ ] Error responses include `type`, `title`, `status`, `detail`
- [ ] Collection endpoints accept `?sort=`, `?filter[]=`, `?fields=`
- [ ] Response headers include `Content-Type: application/json`
- [ ] CORS headers present on public endpoints

#### NL Design System Theme Compatibility

If UI changes are involved, test with multiple themes:
```
1. Default Nextcloud theme (no nldesign)
2. Rijkshuisstijl tokens (via nldesign app)
3. At least one gemeente token set (Utrecht, Amsterdam, etc.)
```

Verify:
- [ ] No hardcoded colors visible
- [ ] Text remains readable under all themes
- [ ] Interactive elements visible and distinguishable
- [ ] Contrast ratios maintained

#### Multi-Tenancy Isolation Testing

Critical for municipal SaaS serving 342 municipalities:
- [ ] One municipality cannot access another municipality's data through any endpoint
- [ ] Cross-tenant data leakage tested through APIs, search, reports, exports
- [ ] Configuration changes in one tenant do not affect others
- [ ] RBAC is tenant-scoped — roles from org A cannot access org B's context
- [ ] The `organisation` system field correctly stamps data to the user's active org

#### Data Privacy Verification (AVG/GDPR)

- [ ] No PII visible in browser console logs
- [ ] No BSN or personal identifiers in network request URLs
- [ ] Personal data not cached in localStorage/sessionStorage without justification
- [ ] API responses don't leak data from other organizations (multi-tenancy isolation)
- [ ] Data subject rights testable: access, rectification, erasure, portability
- [ ] Data retention: verify old data can be purged per configured retention periods

#### Security Testing (BIO2 / ENSIA)

- [ ] CCV Keurmerk Pentesten: recommend CCV-certified penetration testing providers for formal assessments
- [ ] For DigiD-connected systems: annual ICT security assessment by RE auditor (NOREA Guideline 3000) required
- [ ] ENSIA audit cycle: self-evaluation July 1 – Dec 31; ensure software supports compliance evidence gathering
- [ ] GIBIT ICT-kwaliteitsnormen: acceptance testing must prove software contains no defects per municipal ICT quality standards
