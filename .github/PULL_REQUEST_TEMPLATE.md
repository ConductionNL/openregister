## Summary

<!-- Describe what was implemented and why (2–5 sentences) -->

## Changes

<!-- List modified files and what changed -->
- `path/to/file` — what changed and why

## Test Coverage

<!-- Describe what tests cover the changes -->
- `tests/Unit/...` — what is covered

## Quality checklist

- [ ] `composer check:strict` passes locally (phpcs + phpmd + phpstan + psalm + tests)
- [ ] Hydra gates clean: `/hydra-gates` shows zero `FAIL` lines
- [ ] New `lib/**/*.php` classes have a matching `tests/Unit/**/*Test.php`
- [ ] Burn-down PR? Cite cluster + before/after counts: phpcs __ → __, phpmd __ → __, phpstan __ → __
