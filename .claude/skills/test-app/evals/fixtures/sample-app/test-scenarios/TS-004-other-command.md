---
id: TS-004
title: Run regression suite
status: active
priority: LOW
perspective: regression
test-commands: [test-regression]
personas: [admin]
---

# TS-004: Run regression suite

> Active but `test-commands` does NOT include `test-app` — should be filtered out by the skill's command filter.

## Given
- The regression suite is configured

## When
- The runner is invoked

## Then
- All previous scenarios re-execute and pass
