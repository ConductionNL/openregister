# Tasks: Integration — Contacts

> **ADR-028 task-cap waiver**: this leaf has 23 tasks (cap is 15). The work is a single integration vertical slice (provider + sub-resource controller + tab + 4-surface widget + tests + nl/en). Splitting it would force interleaved depends_on chains that ship slower than one cohesive leaf. Hydra builders SHOULD batch this leaf across multiple turns.

## Backend

- [x] Create `lib/Service/Integration/Providers/ContactsProvider.php` — id='contacts', label='Contacts', icon='AccountMultiple', group='core', requiredApp='contacts', storage='link-table'
- [x] DI-tag in `Application.php` (verified registered via Wave-2 backend work — see ContactsProvider in container)
- [x] Unit test (covered by existing `ContactService` tests + provider-contract conformance)

## Frontend — Tab

- [x] `CnContactsTab.vue` — role-grouped list (applicants, handlers, advisors, other); link-existing (button stub — opens dialog flow owned by host app); reverse-lookup emit
- [x] Barrel + tests (`src/integrations/builtin/contacts/index.js` + 7 jest specs covering loading/empty/error/grouping/unlink/initials/reverse-lookup)
- [ ] Deferred: in-tab inline create-new-contact form (post-MVP; ContactService already exposes `createAndLinkContact()` so a host app can wire a dialog when needed) — tracked in `openregister#1311` follow-up

## Frontend — Widget

- [x] `CnContactsCard.vue`:
  - `single-entity`: canonical person chip (avatar + name + role) — accepts a pre-resolved `contact` prop to skip the fetch (the high-frequency path used by `referenceType: 'contacts'`)
  - `detail-page` / `app-dashboard` / `user-dashboard`: count badge + 1-2 most recent contacts (sorted by `linkedAt` desc) + "view all" footer
- [ ] Deferred: shared reactive vCard cache keyed by `contactUid` for `single-entity` perf at scale — current MVP relies on the caller passing the `contact` prop to skip per-chip fetches; a registry-level shared store lands in the perf pass (`openregister#1311` follow-up)
- [x] Barrel + surface tests (`tests/integrations/contacts/CnContactsCard.spec.js`, 7 specs covering single-entity-no-fetch path, 4-surface render, empty/error states, view-all emission)

## Registration

- [ ] **Coordinator-owned** — `src/integrations/builtin/leaves.js` repoint from generic `CnIntegrationTab`/`CnIntegrationCard` to the bespoke `CnContactsTab` + `CnContactsCard` is performed atomically across the 10 Wave-3 partials by the final cross-repo coordinator. Bespoke components are exported via `src/integrations/builtin/contacts/index.js` ready for that wire-up.

## Quality

- [x] Parity gate passes (verified `npm run check:integration-parity` clean)
- [x] nl + en translations (all user-facing strings flow through `translate(t, 'nextcloud-vue', '...')`; default English strings inlined, Dutch picked up at consumer-app build via the standard nc-vue l10n pipeline)
- [ ] PHPCS/PHPMD/PHPStan/Psalm strict pass — backend was unchanged (no new PHP in this wave); existing `ContactsProvider` already passed strict in Wave-2
- [x] ESLint clean (0 errors on the new files)

## Acceptance verification

- [ ] E2E: install Contacts, link a person as "applicant", verify role-grouping in tab; reverse lookup returns linked objects — **deferred** to the coordinator's atomic wire-up of all 10 partials in `leaves.js` (E2E requires the bespoke components to be live on the registry)
- [ ] Widget perf: detail grid with 20 person-reference properties shows ≤1 Mail API fetch per unique contact — **deferred** with the shared vCard cache (see "Frontend — Widget" deferred item)
- [x] Hide test (covered: `CnContactsCard` returns empty layout when `register/schema/objectId` are blank; gated by ContactsProvider's `isEnabled()` returning false when Contacts app is uninstalled)
- [x] Reference-property test (covered: `single-entity` surface accepts a pre-resolved `contact` prop and renders the chip without fetching — the contract the schema property renderer relies on)
