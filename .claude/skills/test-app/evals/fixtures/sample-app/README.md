# sample-app fixture (for test-app eval-3)

This fixture exists so eval-3 (`with-scenarios`) has something to load. It is NOT a real Nextcloud app — only the `test-scenarios/` directory matters.

The 4 scenarios are designed to exercise the skill's filter logic:

| ID | status | test-commands | Should appear in skill output? |
|----|--------|---------------|--------------------------------|
| TS-001 | active | test-app, test-functional | ✅ yes |
| TS-002 | active | test-app, test-security | ✅ yes |
| TS-003 | draft | test-app | ❌ no — wrong status |
| TS-004 | active | test-regression | ❌ no — test-app not in commands |

Eval-3's expectations check that the skill (a) finds these scenarios via the relative path, (b) correctly filters to TS-001 and TS-002, (c) asks the user whether to include them, and (d) reports per-scenario results with the scenario ID.

Paths inside this fixture are relative to the hydra repo root so the eval works for anyone with the repo cloned — no machine-specific absolute paths.
