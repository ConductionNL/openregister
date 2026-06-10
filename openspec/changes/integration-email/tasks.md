# Tasks: Integration — Email

## Backend

- [x] Create `lib/Service/Integration/Providers/EmailProvider.php` — id='email', label='Emails', icon='Email', group='comms', requiredApp='mail', storage='link-table', delegates to `EmailService`
- [~] DI-tag `EmailProvider` in `lib/AppInfo/Application.php` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Unit test `tests/Unit/Service/Integration/Providers/EmailProviderTest.php` — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Tab

- [~] Create `CnEmailTab/CnEmailTab.vue` — list ordered by date desc; "Link existing email" picker (account → folder → message with subject/sender search); unlink action — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Barrel + component tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Widget

- [~] Create `CnEmailCard/CnEmailCard.vue` branching on `surface`: — deferred to downstream cycle / fleet-wide adoption (handoff)
  - `user-dashboard`: latest 5 linked emails across all objects
  - `app-dashboard`: scoped to current app's objects
  - `detail-page`: full list + "Link existing" CTA
  - `single-entity`: chip with subject + sender + date
- [~] Barrel + surface-specific tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Registration

- [~] `src/integrations/builtin/email.js` — register with `referenceType: 'email'` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Wire into registry boot + barrels — deferred to downstream cycle / fleet-wide adoption (handoff)

## Quality

- [~] Parity gate passes — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] nl + en translations for all new strings — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] PHPCS/PHPMD/PHPStan/Psalm strict pass — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] ESLint clean — deferred to downstream cycle / fleet-wide adoption (handoff)

## Acceptance verification

- [~] E2E: install Mail, link a message to an object, verify it shows in the tab, unlink, verify message is preserved in Mail — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Hide test: uninstall Mail, verify integration hidden from registry + UI + capabilities — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Reference-property test: schema with `relatedEmail: { referenceType: 'email' }` renders single-entity widget — deferred to downstream cycle / fleet-wide adoption (handoff)
