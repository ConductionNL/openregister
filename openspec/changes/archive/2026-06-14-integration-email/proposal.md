# Integration: Email

## Problem

`EmailService` + `EmailsController` (326 LOC, shipped in `nextcloud-entity-relations`) link Nextcloud Mail messages to OR objects. Users cannot see or manage linked emails — no UI exists.

## Context

- **Backend already shipped:** [EmailService.php](openregister/lib/Service/EmailService.php), [EmailsController.php](openregister/lib/Controller/EmailsController.php) — link existing messages (read-only references), cache subject/sender/date, reverse lookup by sender
- **Required NC app:** `mail`
- **Storage:** `link-table` (`openregister_email_links`)
- **Key constraint:** Email is link-only. Sending is out of scope (handled by n8n workflows per AD-2 of `nextcloud-entity-relations`).
- **Depends on:** `pluggable-integration-registry`

## Proposed Solution

Ship the full vertical slice: `EmailProvider` (PHP), `CnEmailTab` + `CnEmailCard` (Vue), registration on both sides.

**Key UX difference from calendar**: no inline create form. The Mail app owns compose. The tab offers a "Link existing email" flow that opens a picker (account → folder → message) plus quick-link via forwarded message header if the user has opened the object from an email.

## Scope

**In scope:** `EmailProvider`, `CnEmailTab` (list + link picker), `CnEmailCard` (4 surfaces), DI registration, frontend registration, tests, nl+en translations, spec delta.

**Out of scope:** Email compose/send; search indexing of email bodies; attachment preview beyond what Mail exposes.

## Acceptance criteria

- [ ] Emails tab appears when Mail app installed + schema has `email` in linkedTypes
- [ ] User can link an existing email via the picker
- [ ] User can unlink (link removed, email preserved in Mail)
- [ ] Widget renders on all 4 surfaces
- [ ] Reference-property `referenceType: 'email'` renders single-entity widget
- [ ] Parity gate passes
- [ ] nl + en translations complete
