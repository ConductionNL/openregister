# Tasks: store-plane-publish

## 1. Descriptor

- [x] 1.1 Add optional `publishFields` and `publishGroups` lists to `StoreDescriptor`, both defaulting to `[]`, plus `isPublishable()`; verify with a unit test that a descriptor built with only the first three arguments is not publishable.

## 2. Service

- [x] 2.1 Extract a private `send()` from `fetch()` that takes the HTTP method and request options, keeping the SSRF guard, `allow_redirects: false`, 10 second timeouts and the Bearer header in one place; verify the existing `GenericStoreServiceTest` cases still pass unchanged.
- [x] 2.2 Add `GenericStoreService::publish(StoreDescriptor, array $payload)` with the `not_publishable`, `not_configured`, `too_large` refusals before any client is built, the `slug` + allowlist body with identity keys stripped, the POST, the D3 status mapping and the returned-slug check; verify with the publish unit tests in 4.1.
- [x] 2.3 Add the `OUTCOME_REJECTED`, `OUTCOME_TOO_LARGE`, `OUTCOME_NOT_PUBLISHABLE` constants, and move the pure body, slug and status rules into `lib/AppHost/Store/StorePublishRules.php` (public `SLUG_PATTERN`) so the service stays under phpmd's class complexity threshold; verify `php -l`, phpcs and phpmd on the files and `vendor/bin/phpunit --filter StorePublishRulesTest`.

## 3. Authorizer

- [x] 3.1 Add `StoreActionAuthorizer::canPublish(StoreDescriptor, IUser)` with the `IGroupManager` dependency: refuse and log at ERROR when no group is named, admit an administrator or the `@authenticated` entry only when a group is named, otherwise require membership, and log a named group that does not exist; verify with the tests in 4.2.

## 4. Tests (fake client, no network)

- [x] 4.1 Extend `tests/Unit/AppHost/GenericStoreServiceTest.php` with a case per scenario of the publish requirements (read-only descriptor, no group, unconfigured, allowlist, identity keys, invalid slug, URL, private address, redirects and Bearer, oversized body, transport failure, status mapping, unparseable body, verified and mismatched slug); verify `vendor/bin/phpunit --filter GenericStoreServiceTest` is green.
- [x] 4.2 Extend `tests/Unit/AppHost/StoreActionAuthorizerTest.php` with the canPublish cases and update the existing constructor calls for the new dependency; verify `vendor/bin/phpunit --filter StoreActionAuthorizerTest` is green.

## 5. Spec and verification

- [x] 5.1 Mark `openspec/specs/apphost-store-plane/spec.md` as in progress, and run `openspec validate store-plane-publish`; verify it reports valid.
- [ ] 5.2 Run `composer check:strict` once, `npm run lint`, and the hydra gates; record each exit code in the PR body, with the learniq adoption steps from design.md.

Acceptance criteria (plain reminders, not tasks):
- No leaf app needs `IClientService` or an objects-API URL to publish.
- Every existing descriptor stays read-only.
- The token never appears in a URL, a body, a log line or a return value.
