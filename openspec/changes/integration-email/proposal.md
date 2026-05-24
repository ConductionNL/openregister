# Integration: Email

## Problem

`EmailService` + `EmailsController` (326 LOC, shipped in `nextcloud-entity-relations`) link Nextcloud Mail messages to OR objects. Users cannot see or manage linked emails — no UI exists. Today this leaf is **partial** per the 2026-05-24 registry audit — the backend `EmailService` delegate works with paging and returns real linked messages, but the frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` uses the generic `leaf()` factory with no bespoke `CnEmailTab` / `CnEmailCard` (thread view). This blocks ADR-022 enforcement: while this leaf is incomplete, consuming apps like Zaakafhandelapp and Decidesk have no working integration UI path and reinvent email-attachment locally.

## Context

- **Audit bucket**: partial (2026-05-24)
- **Current backend**: Uses OR's `EmailService` delegate with paging — returns real linked NC Mail messages
- **Current frontend**: generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js` — no bespoke tab/widget (thread view)
- **Target NC class(es)**: existing `OCA\OpenRegister\Service\EmailService` (NC Mail-backed) — no new NC class import required
- **Storage strategy**: `link-table` (`openregister_email_links`)
- **Depends on**: `pluggable-integration-registry` (umbrella mechanism — registry code is done; umbrella issue #1307 stays open until OCS capability + useRegistry default flip land; this leaf does not need to wait for those)
- **Related ADRs**: ADR-019 (mechanism), ADR-022 (consumption principle)
- **Key constraint:** Email is link-only. Sending is out of scope (handled by n8n workflows per AD-2 of `nextcloud-entity-relations`).

## Proposed Solution

Ship the full vertical slice: `EmailProvider` (PHP), `CnEmailTab` + `CnEmailCard` (Vue), registration on both sides.

**Key UX difference from calendar**: no inline create form. The Mail app owns compose. The tab offers a "Link existing email" flow that opens a picker (account → folder → message) plus quick-link via forwarded message header if the user has opened the object from an email.

Provider wraps the existing `EmailService` and falls back to `IntegrationHealth::missingApp('mail')` when NC Mail is not installed.

## Scope

**In scope:** `EmailProvider`, `CnEmailTab` (list + link picker), `CnEmailCard` (4 surfaces), DI registration, frontend registration, tests, nl+en translations, spec delta.

**Out of scope:** Email compose/send; search indexing of email bodies; attachment preview beyond what Mail exposes.

## Acceptance criteria

- [ ] Emails tab appears when Mail app installed + schema has `email` in linkedTypes
- [ ] User can link an existing email via the picker
- [ ] User can unlink (link removed, email preserved in Mail)
- [ ] Widget renders on all 4 surfaces
- [ ] Reference-property `referenceType: 'email'` renders single-entity widget
- [ ] Provider has zero references to MarkerLookupTrait UNLESS storage strategy is `query-time` AND the marker column is verified to exist in the target NC app
- [ ] Real `EmailService` delegation for the backing NC app (skip for `query-time` providers that genuinely should DB-query only)
- [ ] `health()` returns `IntegrationHealth::missingApp('mail')` when NC Mail absent; never throws
- [ ] PHPUnit tests cover: happy-path (app installed + linked), absent-app (graceful empty), empty-result (app installed, no links)
- [ ] Frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` wires the new bespoke `CnEmailTab` + `CnEmailCard` components
- [ ] `nextcloud-vue/scripts/check-integration-parity.js` exit 0
- [ ] SPDX-License-Identifier + SPDX-FileCopyrightText inside the file docblock (ADR-014)
- [ ] nl + en translations complete (ADR-007)
