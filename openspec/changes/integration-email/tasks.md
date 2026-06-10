# Tasks: Integration — Email

## Backend

- [x] Create `lib/Service/Integration/Providers/EmailProvider.php` — id='email', label='Emails', icon='Email', group='comms', requiredApp='mail', storage='link-table', delegates to `EmailService`
- [ ] DI-tag `EmailProvider` in `lib/AppInfo/Application.php`
- [ ] Unit test `tests/Unit/Service/Integration/Providers/EmailProviderTest.php`

## Frontend — Tab

- [ ] Create `CnEmailTab/CnEmailTab.vue` — list ordered by date desc; "Link existing email" picker (account → folder → message with subject/sender search); unlink action
- [ ] Barrel + component tests

## Frontend — Widget

- [ ] Create `CnEmailCard/CnEmailCard.vue` branching on `surface`:
  - `user-dashboard`: latest 5 linked emails across all objects
  - `app-dashboard`: scoped to current app's objects
  - `detail-page`: full list + "Link existing" CTA
  - `single-entity`: chip with subject + sender + date
- [ ] Barrel + surface-specific tests

## Registration

- [ ] `src/integrations/builtin/email.js` — register with `referenceType: 'email'`
- [ ] Wire into registry boot + barrels

## Quality

- [ ] Parity gate passes
- [ ] nl + en translations for all new strings
- [ ] PHPCS/PHPMD/PHPStan/Psalm strict pass
- [ ] ESLint clean

## Acceptance verification

- [ ] E2E: install Mail, link a message to an object, verify it shows in the tab, unlink, verify message is preserved in Mail
- [ ] Hide test: uninstall Mail, verify integration hidden from registry + UI + capabilities
- [ ] Reference-property test: schema with `relatedEmail: { referenceType: 'email' }` renders single-entity widget
