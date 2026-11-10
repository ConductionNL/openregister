# Integration: Cospend (Costs)

## Problem

Project costs (expenses per case, distributed bills, reimbursements) live in NC Cospend but aren't visible from the object they pertain to. Case cost tracking requires app-switching.

## Context

- **Backend:** greenfield — wrap NC Cospend REST API
- **Required NC app:** `cospend`
- **Storage:** `link-table` (project/bill linked to object)
- **Depends on:** `pluggable-integration-registry`

## Proposed Solution

`CospendService` + `CospendController` + `CospendProvider` + `CnCospendTab` + `CnCospendCard`. Tab shows linked Cospend projects and bills with total amount, currency, split. Widget on detail-page shows "Total spent" summary.

## Scope

**In scope:** Backend service wrapping Cospend, link table, provider, tab, widget, registration, tests, nl+en.

**Out of scope:** Bill editing (Cospend owns); currency conversion; settlement workflow.

## Acceptance criteria

- [ ] Cospend tab appears when Cospend installed + schema has `cospend` in linkedTypes
- [ ] Tab lists linked projects/bills with totals
- [ ] Widget shows total spent on all 4 surfaces
- [ ] Reference-property `referenceType: 'cospend'` renders amount chip
- [ ] Parity gate passes; nl+en done
