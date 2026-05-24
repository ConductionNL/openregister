# Integration: Contacts

## Problem

`ContactService` (381 LOC) manages vCard links with a first-class `role` field and reverse lookup. No UI. Case handlers can't see which people are associated with an object without leaving OR. Today this leaf is **partial** per the 2026-05-24 registry audit — the backend `ContactService` delegate works and returns real linked vCards, but the frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` uses the generic `leaf()` factory with no bespoke `CnContactsTab` / `CnContactsCard` (vCard render). This blocks ADR-022 enforcement: while this leaf is incomplete, consuming apps like Zaakafhandelapp and PipelinQ have no working integration UI path and reinvent person-attachment locally.

## Context

- **Audit bucket**: partial (2026-05-24)
- **Current backend**: Uses OR's `ContactService` delegate — returns real linked vCards with role field; reverse lookup works
- **Current frontend**: generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js` — no bespoke tab/widget (vCard render)
- **Target NC class(es)**: existing `OCA\OpenRegister\Service\ContactService` (CardDAV-backed) — no new NC class import required
- **Storage strategy**: `link-table` + CardDAV X-OPENREGISTER-* properties (dual)
- **Depends on**: `pluggable-integration-registry` (umbrella mechanism — registry code is done; umbrella issue #1307 stays open until OCS capability + useRegistry default flip land; this leaf does not need to wait for those)
- **Related ADRs**: ADR-019 (mechanism), ADR-022 (consumption principle)
- **Key feature:** Role ("applicant", "handler", "advisor") is a first-class field per AD-4 of `nextcloud-entity-relations`

## Proposed Solution

`ContactsProvider` + `CnContactsTab` (grouped by role, inline link/create) + `CnContactCard` widget. The `single-entity` surface is heavily used — this is the integration that enables reference-property rendering for ANY schema property typed as a person reference. Provider wraps the existing `ContactService` (which already imports CardDAV plumbing) and falls back to `IntegrationHealth::missingApp('contacts')` when NC Contacts is not installed.

## Scope

**In scope:** Provider, tab with role-grouped display, widget with 4 surfaces, registration, tests, nl+en, spec delta.

**Out of scope:** Contact editing beyond what `ContactService` already exposes; contact merging; vCard field extensions.

## Acceptance criteria

- [ ] Contacts tab appears when Contacts installed + schema has `contacts` in linkedTypes
- [ ] Contacts grouped visually by role in the tab
- [ ] User can link an existing contact with role selection
- [ ] User can create a new contact with role
- [ ] Reverse lookup: from a contact, see all linked OR objects
- [ ] Reference-property `referenceType: 'contacts'` renders contact chip (THE key enabler for person-reference properties everywhere)
- [ ] Provider has zero references to MarkerLookupTrait UNLESS storage strategy is `query-time` AND the marker column is verified to exist in the target NC app
- [ ] Real `ContactService` delegation for the backing NC app (skip for `query-time` providers that genuinely should DB-query only)
- [ ] `health()` returns `IntegrationHealth::missingApp('contacts')` when NC Contacts absent; never throws
- [ ] PHPUnit tests cover: happy-path (app installed + linked), absent-app (graceful empty), empty-result (app installed, no links)
- [ ] Frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` wires the new bespoke `CnContactsTab` + `CnContactsCard` components
- [ ] `nextcloud-vue/scripts/check-integration-parity.js` exit 0
- [ ] SPDX-License-Identifier + SPDX-FileCopyrightText inside the file docblock (ADR-014)
- [ ] nl + en translations complete (ADR-007)
