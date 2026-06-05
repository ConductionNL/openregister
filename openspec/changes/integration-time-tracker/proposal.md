# Integration: Time Tracker

## Problem

Billable hours per case/project are a common government-consulting requirement. Handlers log time today in disconnected systems or spreadsheets. A first-class time integration keeps hours attached to the object.

## Context

- **Backend:** greenfield — wrap NC Time Manager (or similar — there are a few NC time-tracking apps; leaf uses `timemanager` as default, configurable)
- **Required NC app:** `timemanager`
- **Storage:** `link-table` (time entries linked to object/user)
- **Depends on:** `pluggable-integration-registry`

## Proposed Solution

`TimeEntryService` + `TimeController` + `TimeProvider` + `CnTimeTab` + `CnTimeCard`. Tab: quick-log form (duration + description), list of entries grouped by user/date, total hours on object. Widget on dashboard: "Today's hours," on detail-page: breakdown by user/week.

## Scope

**In scope:** Backend service wrapping time-tracker, link table, provider, tab with quick-log, widget, registration, tests, nl+en.

**Out of scope:** Invoicing; approval workflows (those belong in a separate billing app); rate management.

## Acceptance criteria

- [ ] Time tab appears when Time Manager installed + schema has `time-tracker` in linkedTypes
- [ ] User can log time quickly (duration + description)
- [ ] Total hours visible per object; breakdown by user
- [ ] Widget renders on all 4 surfaces
- [ ] Reference-property `referenceType: 'time-tracker'` renders hours chip
- [ ] Parity gate passes; nl+en done
