# Integration: Forms

## Problem

Case intake, surveys, and feedback collection happen via NC Forms today, disconnected from OR objects. Form responses should be first-class linked artefacts on the case/object they pertain to.

## Context

- **Backend:** greenfield — NC Forms has a REST API we'll wrap in `FormResponseService`
- **Required NC app:** `forms`
- **Storage:** `link-table` (`openregister_form_links` mapping object ↔ form + response)
- **Depends on:** `pluggable-integration-registry`

## Proposed Solution

Build `FormResponseService` + `FormResponsesController` + `FormsProvider` + `CnFormsTab` + `CnFormsCard`. Tab displays linked responses (read-only), with "Link an existing response" and "Link a form for future responses" flows. Widget on detail-page shows response count and quick access to the most recent.

## Scope

**In scope:** Backend service + controller, link table + migration, provider, tab, widget, registration, tests, nl+en.

**Out of scope:** Form authoring (lives in Forms app); response editing (responses are immutable in Forms); PII redaction.

## Acceptance criteria

- [ ] Forms tab appears when Forms installed + schema has `forms` in linkedTypes
- [ ] User can link existing response OR configure form-for-future-responses mapping
- [ ] Response viewer renders questions + answers inline (read-only)
- [ ] Widget renders on all 4 surfaces
- [ ] Reference-property `referenceType: 'forms'` works
- [ ] Parity gate passes; nl+en done
