# Tasks: App-declared credential providers

## 1. Declaration model and register

- [ ] 1.1 Add the `credentialProviderApproval` schema to `lib/Settings/credential_broker_register.json` (properties per design D7, register version bump) and add the two `example-` prefixed, `status: revoked`, nil-digest seed rows under `components.objects[]` per the Seed Data section.
- [ ] 1.2 Document the app declaration file format (path, entry shape, `extends`, the `inject_only` prohibition, namespacing) in `docs/Features/credential-broker.md`, with the hydra-console `codeberg` entry as the worked example.

## 2. Discovery, validation and admission

- [ ] 2.1 Add `lib/Service/Credential/DeclaredProviderLoader.php` walking `IAppManager::getInstalledApps()` + `getAppPath()`, reading each app's `lib/Settings/credential-providers.json`, caching per request and failing soft to empty on read/parse error.
- [ ] 2.2 Add `lib/Service/Credential/DeclarationValidator.php` rejecting: a colon-free (unnamespaced) identifier, `inject_only: true`, a missing `baseUrl`, an empty `allowRules[]`, and any unknown/extra field.
- [ ] 2.3 Implement the narrowing test in the validator — same `method`, and the base `pathPattern` must match every path the declared pattern can produce; anything not provably narrower is rejected, never escalated to pending.
- [ ] 2.4 Compute the per-entry `declarationDigest` over a canonical serialisation of the single entry (not the whole file), so an unrelated entry change never invalidates a standing approval.
- [ ] 2.5 Add `lib/Service/Credential/DeclaredProviderResolver.php` returning the admission state (`admitted` via narrowing, `admitted` via matching approval, `pending`, `rejected`, `revoked`, `invalid`) and a catalogue-shaped entry array only when admitted.

## 3. Approval plane

- [ ] 3.1 Add an approval service writing `credentialProviderApproval` objects through `ObjectService::saveObject()` (entity/array first arg) recording `decidedBy`, `decidedAt`, `declarationDigest`, `baseUrl` and `allowRulesSnapshot`.
- [ ] 3.2 Add admin-only approve / reject / revoke routes on `CredentialController` (or a dedicated controller) with the correct Nextcloud auth attributes and registered entries in `appinfo/routes.php`.
- [ ] 3.3 Keep approval records on app disable/uninstall and re-apply a kept approval on re-enable only when the digest still matches.

## 4. Broker and controller wiring

- [ ] 4.1 Change `CredentialBrokerService::resolveProvider()` to try `ProviderCatalogue` first and the declared resolver second, denying on anything not admitted — leaving `isInjectOnly()`, `assertRuleAllowed()`, `resolveAndLockUrl()`, `injectAuth()` and the guard order untouched.
- [ ] 4.2 Make `resolveInjectable()` return `null` for every declared provider, so a declared credential is always a `request()` credential.
- [ ] 4.3 Extend `CredentialController::create()` to accept an admitted namespaced identifier, refuse a non-admitted one, and force `allowedApps` to the declaring app; block widening `allowedApps` for such credentials in the update path.
- [ ] 4.4 Extend `GET /api/credentials/providers` to return `origin` and `status` alongside `identifier` and `title`, and verify no `allowRules` or `baseUrl` leaks into that response.

## 5. Admin UI

- [ ] 5.1 Add a "Declared providers" section to the credential admin settings listing declared providers with origin, status, resolved host and the full method+path rule set, plus approve / reject / revoke actions and an auto-admitted (narrowing) label.

## 6. Tests and verification

- [ ] 6.1 Unit-test the validator and the narrowing test, including the adversarial cases (`*` at the pattern head, `/repos/*` vs `/repos/*/../../admin`, method mismatch, wider host).
- [ ] 6.2 Unit-test every deny path on the broker: pending, rejected, revoked, digest drift, disabled declaring app, cross-app borrow, shadowing attempt, `inject_only` declaration, mint against a non-admitted declaration.
- [ ] 6.3 Assert no regression for reviewed providers — existing `CredentialBrokerServiceTest`, `ProviderCatalogueTest`, `DoffinProviderTest` and the organisation-scope tests stay green, and `generic-*` inject_only behaviour is unchanged.
- [ ] 6.4 Live-verify end to end on the dev instance with a real declaration: pending denies, approval admits, an edited declaration re-pends; confirm opencatalogi and softwarecatalog show no regression and run `composer check:strict`.

## Acceptance criteria

- No app can reach a host or a path the reviewed catalogue does not already permit without an administrator approval recorded against a named user.
- A narrowing declaration grants strictly less than its base provider and is admitted without a click; anything not provably narrower is rejected outright.
- Editing an approved declaration returns it to `pending` and denies until re-approved.
- A declared provider is usable only by the app that declared it, and cannot shadow or override any reviewed catalogue entry.
- `inject_only` remains reviewed-catalogue-only; `request()` still refuses `inject_only` and `resolveInjectable()` returns `null` for every declared provider.
- The four broker guards run unchanged, in the same order, for declared and reviewed providers alike, and every denial is a static 403 with a secret-free reason.
- Every approve, reject and revoke is retrievable from the audit trail with the deciding user, the time, and the rule set that was approved.
- With no app shipping a declaration, broker behaviour is byte-identical to today.
