# Tasks: platform-provided-capabilities

## 1. Tags

- [ ] 1.1 Add `@spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-002` and `#REQ-PPC-004` to the docblock of `UserController::login()` in `lib/Controller/UserController.php`.
- [ ] 1.2 Add `#REQ-PPC-006` to the class docblocks of `lib/Service/FileService.php` and `lib/Service/File/FolderManagementHandler.php`, and `#REQ-PPC-009` to `lib/Service/Notification/EmailSender.php`.
- [ ] 1.3 Add an XML comment naming `#REQ-PPC-007` above `<dependencies>` in `appinfo/info.xml`.

## 2. Checks that keep the properties true

- [ ] 2.1 `tests/Unit/Platform/RoutePostureTest.php`: parse `appinfo/routes.php` and the controllers; assert every routed method carries a Nextcloud access attribute and that every `#[PublicPage]` method is in an allow-list naming the token, signature or published check it relies on (REQ-PPC-001, REQ-PPC-004).
- [ ] 2.2 `tests/Unit/Platform/PlatformPropertiesTest.php`: assert `lib/` contains no OpenID Connect, SAML or LDAP client, no own session or device table in `lib/Migration/`, no S3, Swift or SMB client, and that `appinfo/info.xml` declares only php, database and nextcloud dependencies (REQ-PPC-001, REQ-PPC-003, REQ-PPC-006, REQ-PPC-007).
- [ ] 2.3 Extend `tests/Unit/Controller/UserControllerTest.php`: the login route calls `IUserManager::checkPassword()`; for a user with a second factor enabled (`OCP\Authentication\TwoFactorAuth\IRegistry` reports a provider) it creates no session and answers that a second factor is required. Verify the current behaviour of `UserController::login()`, which calls `IUserSession::setUser()` after the password check, and change it if it creates a session without the factor (REQ-PPC-002, REQ-PPC-004).
- [ ] 2.4 Replace the literal `in_array('admin', ...)` group checks in `lib/` (33 hits on development, among them `lib/Controller/ObjectsController.php`, `lib/Controller/RegistersController.php`, `lib/Service/PropertyRbacHandler.php`, `lib/Service/ObjectService.php`) with `IGroupManager::isAdmin()` through one helper, so `allowed_admin_ranges` applies; unit test the helper with an address outside the range (REQ-PPC-005).
- [ ] 2.5 Render mail in `lib/Service/Notification/EmailSender.php` and `lib/Service/ScheduledReportService.php` with `IFactory::get('openregister', IFactory::getUserLanguage($user))`; unit test that a Dutch recipient gets the Dutch subject (REQ-PPC-009).

## 3. Interface language

- [ ] 3.1 Add a translation lint (`eslint-plugin-vue` `vue/no-bare-strings-in-template`, scoped to `src/**/*.vue`) and fix what it reports (REQ-PPC-008).

## 4. Matrix

- [ ] 4.1 When the tasks are done, archive this change and repoint the nine rows in `openspec/parity/capabilities.json` from `built.change` to `built.spec: openspec/specs/platform-provided-capabilities`.
