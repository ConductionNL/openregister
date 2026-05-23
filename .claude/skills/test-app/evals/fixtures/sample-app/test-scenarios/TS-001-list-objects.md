---
id: TS-001
title: List objects on the dashboard
status: active
priority: HIGH
perspective: functional
test-commands: [test-app, test-functional]
personas: [admin]
---

# TS-001: List objects on the dashboard

## Given
- The user is logged in as admin
- At least one object exists in the default register

## When
- The user navigates to the sample-app dashboard

## Then
- A list of objects is rendered
- Each row shows the object's title, created date, and status
