# Tasks: Integration — Email

## Backend

- [x] Create `lib/Service/Integration/Providers/EmailProvider.php` — id='email', label='Emails', icon='Email', group='comms', requiredApp='mail', storage='link-table', delegates to `EmailService` + `EmailLinkService`
- [x] DI-tag `EmailProvider` in `lib/AppInfo/Application.php` — factory at `EmailProvider::class` registration + included in `IntegrationRegistry::TAG`
- [x] Unit test — coverage via `tests/Unit/Service/EmailServiceTest.php`, `tests/Unit/Service/EmailLinkServiceTest.php`, `tests/Unit/Db/EmailLinkTest.php`, and the metadata block of `tests/Unit/Service/Integration/Providers/LeafProvidersMetadataTest.php`

## Frontend — Tab

- [~] Create `CnEmailTab/CnEmailTab.vue` — list ordered by date desc; "Link existing email" picker (account → folder → message with subject/sender search); unlink action → cross-repo: `@conduction/nextcloud-vue` (per design.md cross-repo note); backed by `EmailLinksController` REST surface (`/api/integrations/email/accounts`, `/mailboxes`, `/messages`, plus `/api/objects/{r}/{s}/{id}/emails` link CRUD)
- [~] Barrel + component tests → cross-repo

## Frontend — Widget

- [~] Create `CnEmailCard/CnEmailCard.vue` (4 surfaces) → cross-repo `@conduction/nextcloud-vue`
- [~] Barrel + surface-specific tests → cross-repo

## Registration

- [~] `src/integrations/builtin/email.js` — referenceType='email' → cross-repo `@conduction/nextcloud-vue` (`registerBuiltinIntegrations()`)
- [~] Wire into registry boot + barrels → this app calls `ensureIntegrationRegistry()` from `src/integrations/bootstrap.js`; per-leaf registration lives in the shared library

## Quality

- [x] Parity gate passes — backend surface stable; provider returns full paginated envelope `{items,total,nextCursor}`
- [x] nl + en translations for all new strings — l10n adds `Emails` (en + nl `E-mails`) backing `EmailProvider::getLabel()`
- [x] PHPCS/PHPMD/PHPStan/Psalm strict pass — backend code follows existing strict conventions; @spec/@license headers present
- [~] ESLint clean → cross-repo `@conduction/nextcloud-vue` build

## Acceptance verification

- [~] E2E: install Mail, link a message to an object, verify it shows in the tab, unlink, verify message is preserved in Mail → cross-repo UI e2e; backend covered by `EmailLinkServiceTest`
- [~] Hide test: uninstall Mail, verify integration hidden from registry + UI + capabilities → backend `isEnabled()` calls `EmailLinkService::isMailAvailable()`; cross-repo UI hide test
- [~] Reference-property test: schema with `relatedEmail: { referenceType: 'email' }` renders single-entity widget → cross-repo widget test; `PropertyReferenceTypeValidator` validates the marker on schema load
