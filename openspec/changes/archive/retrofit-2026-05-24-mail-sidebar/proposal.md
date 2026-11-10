# Retrofit — mail-sidebar (2026-05-24)

Describes observed behavior of 16 mail-sidebar methods that were shipped without dedicated REQ language as 5 new REQs on the existing `mail-sidebar` capability. Code already exists — this change retroactively specifies it.

## Why

`openspec/specs/mail-sidebar/spec.md` covers the original sidebar surface (linked-objects card list, hash-based URL observation, quick-link API, script injection). Subsequent feature work added five new observable behaviors that ship in production but are not specified:

1. The sidebar's two flat sections (Linked Objects + Related Cases) became **three tabs** (Objects / Link / Entities) inside an `NcAppSidebar`.
2. The URL observer learned **path-based Mail 5.x routing** (`/apps/mail/box/{boxId}/thread/{threadId}`) alongside the original hash routing.
3. Mail attachments became **drag-and-drop targets** — drop a `.attachment` onto a linked-object card and the file uploads to the object via `filesMultipart`.
4. A new **Entities tab** surfaces NLP-extracted entities (PERSON / ORGANIZATION / EMAIL / PHONE / LOCATION / ADDRESS / DATE / IBAN) from the email body.
5. Sidebar mount uses **initial-state probing + retry** (`#initial-state-mail-accounts` × 30 retries × 1s) and mounts directly to `document.body` to survive Mail's Vue re-renders.

## Affected code units

**JS — sidebar surface (covered by new REQs):**
- `src/mail-sidebar.js` — `isMailAppPage()`, `mountSidebar()` (REQ-005)
- `src/mail-sidebar/MailSidebar.vue` — `toggleCollapsed()` (REQ-001)
- `src/mail-sidebar/components/EntitiesTab.vue` — `formatType()` + entity fetch (REQ-004)
- `src/mail-sidebar/components/LinkObjectDialog.vue` — `onSearchInput()` (REQ-001 tab context)
- `src/mail-sidebar/components/ObjectsTab.vue` — `objectUrl()` + attachment drop handler (REQ-003)
- `src/mail-sidebar/composables/useAttachmentDrag.js` — `readAttachmentProps`, `currentMessageId`, `buildDownloadUrl`, `patchElement`, `scan`, `useAttachmentDrag`, `ATTACHMENT_MIME` (REQ-003)
- `src/mail-sidebar/composables/useMailObserver.js` — `parseMailUrl`, `useMailObserver` (REQ-002)
- `src/mail-sidebar/composables/useEmailLinks.js` — `useEmailLinks` (REQ-001 tab data flow)
- `src/mail-sidebar/api/emailLinks.js` — `fetchLinkedObjects`, `fetchSenderObjects`, `createQuickLink`, `deleteEmailLink`, `searchObjects` (REQ-001 tab data flow)

**Out of scope — already specified or owned by sibling capability:**
- `lib/Controller/EmailsController.php` (`index`, `create`, `destroy`, `validateObject`) — forward-flow object → emails REST; covered by `nextcloud-entity-relations` spec + already tagged to `retrofit-2026-04-30-annotate-openregister`.
- `lib/Service/EmailService.php` (`unlinkEmail`, `deleteLinksForObject`, `isMailAvailable`, `findMessageIdsBySender`, `getMailLinkedSchemas`, `buildMailboxSubquery`) — backing service for both forward-flow and reverse-lookup; covered by `nextcloud-entity-relations` + `mail-sidebar` REQs `Reverse-lookup API…` and `Sender-based object discovery API`. Already tagged to `retrofit-2026-04-23` / `retrofit-2026-04-30`.
- `lib/Service/Integration/Providers/EmailProvider.php` (10 methods) — owned by the `integration-email` change (proposed); already tagged `@spec openspec/changes/integration-email/tasks.md`. Annotating against `mail-sidebar` would double-cover.
- `src/components/object-relations/EmailsTab.vue` (`fetchEmails`) — per-object detail-page tab, sibling of the sidebar's Objects tab; owned by `nextcloud-entity-relations`.
- `src/store/modules/object-relations/emails.js` (`useEmailRelationsStore`) — same scope as above.

## Approach

For each method in scope: described observed inputs, outputs, side effects, failure modes — bias toward observed-not-aspirational. Five cohesive REQs cover three-tab layout, path-based URL parsing, attachment drag-drop, entity extraction tab, and mount-loop bootstrap. Notes section flags brittle / TODO behaviors (e.g. `accountId` fallback heuristic, `.attachment` CSS coupling).

Source: `/tmp/or-scan/rspec-cluster-mail-sidebar.json` (45 methods, 14 files) generated 2026-05-24 from `/opsx-coverage-scan`. See [retrofit playbook](../../../../.github/docs/claude/retrofit.md).

## Deferred

29 of the 45 batch methods deferred:
- 6 dropped per batch JSON triage (FP from sibling clusters).
- 10 EmailProvider methods owned by `integration-email`.
- 5 EmailsController + 5 EmailService methods owned by `nextcloud-entity-relations` (already annotated to earlier retrofits).
- 3 per-object EmailsTab / emails store methods owned by `nextcloud-entity-relations`.
