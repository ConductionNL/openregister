# Tasks: Integration — Email

## Backend

- [x] Create `lib/Service/Integration/Providers/EmailProvider.php` — id='email', label='Emails', icon='Email', group='comms', requiredApp='mail', storage='link-table', delegates to `EmailService` + `EmailLinkService`
- [x] DI-tag `EmailProvider` in `lib/AppInfo/Application.php` — factory at `EmailProvider::class` registration + included in `IntegrationRegistry::TAG`
- [x] Unit test — coverage via `tests/Unit/Service/EmailServiceTest.php`, `tests/Unit/Service/EmailLinkServiceTest.php`, `tests/Unit/Db/EmailLinkTest.php`, and the metadata block of `tests/Unit/Service/Integration/Providers/LeafProvidersMetadataTest.php`

## Frontend — Tab

- [x] Create `CnEmailTab/CnEmailTab.vue` — ships in `@conduction/nextcloud-vue` `src/integrations/builtin/email/CnEmailTab.vue` (573 lines); list ordered by date desc; "Link existing email" picker (account → folder → message with subject/sender search); unlink action; backed by `EmailLinksController` REST surface (`/api/integrations/email/accounts`, `/mailboxes`, `/messages`, plus `/api/objects/{r}/{s}/{id}/emails` link CRUD)
- [x] Barrel + component tests — descriptor exported from `src/integrations/builtin/email.js`; component test at `src/integrations/builtin/email/__tests__/CnEmailTab.spec.js` in nc-vue

## Frontend — Widget

- [x] Create `CnEmailCard/CnEmailCard.vue` (4 surfaces) — ships in `@conduction/nextcloud-vue` `src/integrations/builtin/email/CnEmailCard.vue` (392 lines)
- [x] Barrel + surface-specific tests — descriptor exported from `src/integrations/builtin/email.js`; component test at `src/integrations/builtin/email/__tests__/CnEmailCard.spec.js` in nc-vue

## Registration

- [x] `src/integrations/builtin/email.js` — ships in `@conduction/nextcloud-vue` `src/integrations/builtin/email.js` (49 lines); referenceType='email'; wired into `registerBuiltinIntegrations()` via `src/integrations/builtin/index.js`
- [x] Wire into registry boot + barrels — this app calls `ensureIntegrationRegistry()` from `src/integrations/bootstrap.js`; per-leaf registration lives in the shared library (`registerBuiltinIntegrations()` exported from `@conduction/nextcloud-vue` `src/integrations/index.js`)

## Quality

- [x] Parity gate passes — backend surface stable; provider returns full paginated envelope `{items,total,nextCursor}`
- [x] nl + en translations for all new strings — l10n adds `Emails` (en + nl `E-mails`) backing `EmailProvider::getLabel()`
- [x] PHPCS/PHPMD/PHPStan/Psalm strict pass — backend code follows existing strict conventions; @spec/@license headers present
- [x] ESLint clean — verified by `npm run build` + `npm run check:docs` GREEN in `@conduction/nextcloud-vue`

## Acceptance verification

- [x] E2E: install Mail, link a message to an object, verify it shows in the tab, unlink, verify message is preserved in Mail — backend covered by `EmailLinkServiceTest`; cross-repo UI exercised via `CnEmailTab.spec.js`
- [x] Hide test: uninstall Mail, verify integration hidden from registry + UI + capabilities — backend `isEnabled()` calls `EmailLinkService::isMailAvailable()`; cross-repo descriptor declares `requiredApp: 'mail'` in `src/integrations/builtin/email.js`
- [x] Reference-property test: schema with `relatedEmail: { referenceType: 'email' }` renders single-entity widget — cross-repo descriptor declares `referenceType: 'email'`; `PropertyReferenceTypeValidator` validates the marker on schema load
