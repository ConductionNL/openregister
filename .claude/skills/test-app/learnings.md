# Learnings — test-app

## Patterns That Work
<!-- Testing approaches and configurations that consistently find real bugs -->

- 2026-04-14: planix — Several agents (UX, accessibility, performance) fell back to code analysis instead of live browser interaction. This still produced valuable findings (UX agent found hardcoded sample data in Dashboard.vue, accessibility agent found WCAG 3.3.2 violation in Settings.vue column inputs) but misses runtime behavior. Code review + browser testing together would be ideal.
- 2026-04-14: planix — Checking admin settings separately from main app revealed that default columns editor and register setup work correctly even though the main board view is not yet implemented. Testing admin features early catches configuration issues before feature implementation.

## Mistakes to Avoid
<!-- Testing errors and false positives encountered -->

- 2026-04-14: planix — Haiku agents frequently fall back to source code analysis rather than actually navigating the browser. They read Vue files instead of loading the app in the headless browser. For reliable browser-based testing, consider Sonnet or Opus models, or add explicit instructions that code reading alone is insufficient.
- 2026-04-14: planix — Performance agent reported timing estimates from code review rather than actual measured timings from browser network requests. Performance numbers from code review are speculative, not empirical. Flag these clearly in results.

## Domain Knowledge
<!-- Facts about Nextcloud app testing, browser automation, or test infrastructure -->

- 2026-04-14: planix — App located at `/home/wilco/nextcloud-docker-dev/workspace/server/apps-extra/planix`, not in the hydra workspace. Apps may be in different workspace locations — search broadly if app directory not found in primary workspace.
- 2026-04-14: planix — Many documented features (kanban board, tasks, time tracking, My Work) show "coming soon" placeholders. The foundation (projects, admin settings, register schemas) is solid. Test scenarios requiring error simulation (TS-011-014) need API mocking or environment manipulation that Haiku agents didn't attempt.

## Open Questions
<!-- Unresolved testing challenges for future investigation -->

## Consolidated Principles
<!-- Promoted from patterns after 3+ confirmations — these become standing rules -->
