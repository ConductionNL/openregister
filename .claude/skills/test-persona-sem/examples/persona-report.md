<!-- Example output — test-persona-sem for OpenRegister (Nextcloud app) -->

## Persona Test Report: Sem de Jong (Young Digital Native)

**App:** OpenRegister  
**Date:** 2026-04-10  
**Tester model:** claude-sonnet-4-6

### Would Sem keep using this app daily? YES — with some performance gripes

---

### Performance

| Check | Metric | Status |
|-------|--------|--------|
| Register list load time | 340ms | PASS (< 500ms) |
| Schema detail page load | 890ms | WARN (500ms–1500ms) |
| Object search (1000 objects) | 2.1s | FAIL (> 1500ms) |
| API response (GET /registers) | 110ms | PASS |
| JS bundle size | 2.4MB | FAIL (> 2MB) |

### Keyboard Navigation

| Check | Status | Notes |
|-------|--------|-------|
| Tab order logical | PASS | Moves left-to-right, top-to-bottom |
| Create register reachable by keyboard | PASS | Tab to button, Enter activates |
| Modal closes with Escape | PASS | ✓ |
| Dropdown navigable with arrow keys | PARTIAL | Schema type dropdown works; status filter does not |
| Focus visible after modal close | PASS | Returns to trigger button |

### Dark Mode / Theme

| Check | Status | Notes |
|-------|--------|-------|
| Dark mode enabled via Nextcloud | PASS | Follows system preference |
| No hardcoded white backgrounds | PARTIAL | One card component uses `background: white` |
| Contrast in dark mode | PASS | Checked against 4.5:1 requirement |
| NL Design System tokens applied | PARTIAL | Some components still use `var(--color-main)` not NLD tokens |

### Mobile / Responsive

| Check | Status | Notes |
|-------|--------|-------|
| 375px viewport usable | PARTIAL | List view overflows at 375px |
| Touch targets ≥ 44px | PASS | Buttons are large enough |
| Horizontal scrolling | FAIL | Register list table scrolls horizontally without wrapper |

---

### Issues Found

| # | Category | Issue | Severity | Sem would say... |
|---|----------|-------|----------|-----------------|
| 1 | Performance | Object search takes 2.1s for 1000 objects | HIGH | "I'd switch to the API directly rather than use the search. Fix the debounce or add server-side search." |
| 2 | Mobile | Register list table overflows at 375px | MEDIUM | "Half the team uses their phone to quickly check registers. It's completely broken on mobile." |
| 3 | Performance | 2.4MB JS bundle | MEDIUM | "Code splitting would fix this in an afternoon. Makes first load noticeably slow on slower connections." |

---

### Sem's Verdict

"Generally good — keyboard navigation mostly works, dark mode looks decent. The search performance is genuinely bad though. 2 seconds for a search is unacceptable in 2026. And the mobile table overflow is embarrassing. Otherwise I'd use this daily without thinking about it."

### Recommendations for Performance/UX Improvement

1. Implement server-side search for objects with pagination — remove client-side filtering of large datasets
2. Add responsive CSS to the register list table: `overflow-x: auto` wrapper or switch to card layout at <768px
3. Apply code splitting to the Vue app — lazy load the schema editor (heaviest module) to cut initial bundle under 1MB
