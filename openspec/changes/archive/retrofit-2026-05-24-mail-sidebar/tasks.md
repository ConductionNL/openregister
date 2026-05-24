# Tasks — Retrofit mail-sidebar (2026-05-24)

All tasks are `[x]` because the code already exists. Annotating retroactively.

## Spec drafting (retroactive annotation)

- [x] task-1: mail-sidebar#REQ-001 — Three-tab sidebar layout (Objects / Link / Entities) (retroactive annotation)
- [x] task-2: mail-sidebar#REQ-002 — Path-based Mail-app URL routing (Mail 5.x+) (retroactive annotation)
- [x] task-3: mail-sidebar#REQ-003 — Drag-and-drop email attachments onto linked objects (retroactive annotation)
- [x] task-4: mail-sidebar#REQ-004 — Email-body entity extraction tab (retroactive annotation)
- [x] task-5: mail-sidebar#REQ-005 — Mail-app page detection and retry-mount bootstrap (retroactive annotation)

## Map: REQ-ID → code units

- REQ-001 (3-tab layout): `src/mail-sidebar/MailSidebar.vue::toggleCollapsed`, `src/mail-sidebar/components/LinkObjectDialog.vue::onSearchInput`, `src/mail-sidebar/composables/useEmailLinks.js::useEmailLinks`, `src/mail-sidebar/api/emailLinks.js` (`fetchLinkedObjects`, `fetchSenderObjects`, `createQuickLink`, `deleteEmailLink`, `searchObjects`)
- REQ-002 (path-based URL): `src/mail-sidebar/composables/useMailObserver.js::parseMailUrl`, `useMailObserver`
- REQ-003 (attachment drag/drop): `src/mail-sidebar/composables/useAttachmentDrag.js::readAttachmentProps`, `currentMessageId`, `buildDownloadUrl`, `patchElement`, `scan`, `useAttachmentDrag`, `ATTACHMENT_MIME`; `src/mail-sidebar/components/ObjectsTab.vue::objectUrl` (drop target)
- REQ-004 (entities tab): `src/mail-sidebar/components/EntitiesTab.vue::formatType`
- REQ-005 (mount bootstrap): `src/mail-sidebar.js::isMailAppPage`, `mountSidebar`
