# Test Scenario File Format

---
id: TS-NNN
title: "<TC title>"
priority: <high|medium|low>
category: <category>
personas:
- <persona-slug>
test-commands:
- <test command>
tags:
- <category>
- regression
status: active
created: YYYY-MM-DD
spec-refs:
- <spec_ref>
---

# TS-NNN: <TC title>

**Goal**: <derived goal>

## Preconditions

- <preconditions from TC>

## Scenario

- GIVEN <preconditions>
- WHEN <steps>
- THEN <expected result>

## Test Data

_(no specific test data required — use default dev environment)_

## Acceptance Criteria

- <THEN clause as checkbox>

## Notes

_Converted from test-plan.md TC-N during archive of `<change-name>` change._
