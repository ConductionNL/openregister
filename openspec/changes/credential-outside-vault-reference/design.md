# Design: credential-outside-vault-reference

Read at openregister development 555af7212 and hydra ADR-064.

## Context

- `CredentialStore` (`lib/Service/Credential/CredentialStore.php:37-79`) is
  `put()`, `get()` and `delete()` by credential uuid and scope. The resolver
  binds one leaf for the whole instance: Doriath when eligible, the Nextcloud
  vault otherwise (`lib/Service/Credential/CredentialStoreResolver.php:148-155`,
  bound in `lib/AppInfo/Application.php:436-445`).
- `CredentialBrokerService` reads the secret through that leaf in
  `resolveInjectable()` (`:396`) and in the proxy path (`:1178`), and writes it
  in `mint()` (`:531`).
- The `brokeredcredential` schema (`lib/Settings/credential_broker_register.json`)
  carries metadata only: provider, owner, scope, organisation, allowed apps,
  sharing, kind, status and OAuth fields.
- ADR-064 decision 2: OpenRegister owns the credential object and all
  authorization; Doriath holds the secret behind `CredentialStore`; apps must
  not call Doriath directly.

## D-1: a reference per credential, not a second custody leaf

Swapping the instance's custody leaf for an outside vault would move every
secret, including OAuth token sets the broker refreshes and writes. Tyk and
APISIX solve the reported need differently: a field refers to a path in the
vault. So a credential declares where its secret lives. The broker keeps one
custody leaf for what it holds, and reads outside references on demand. This
keeps ADR-064 intact: the broker is still the only door, and authorization is
still decided before any secret is read.

## D-2: the vault's own secret is an ordinary credential

A vault connection is a `brokeredcredential` of kind `outside-vault`, scope
`organisation`: `instanceBaseUrl`, auth method, role id, and a secret (token or
AppRole secret id) minted into the normal custody leaf. An outside credential's
`vaultRef` is `{ connection: <uuid>, path, key }`. So there is no bootstrap
secret outside the broker.

## D-3: read on use, cache per request only

`OutsideVaultReader::read(vaultRef)` logs in with the connection's secret
(AppRole or token), reads KV v2 `GET /v1/<mount>/data/<path>`, and returns the
named key. The value is kept in a request-scoped array so one proxy call with
retries reads once, and is never persisted, logged or returned. A read failure
raises `CredentialUpstreamException` with the vault's status, not its body,
and sets the credential's `lastError` to a fixed sentence.

## D-4: authorization first, unchanged

`request()`, `resolveInjectable()` and the proxy run Guard 1 (owner or
membership) and Guard 2 (`assertAppAllowed`) exactly as today, before the
branch on `custody`. An outside credential cannot be read by an app that a
held credential would refuse.

## D-5: the ADR gets one paragraph

Hydra ADR-064 gains a paragraph: a credential may reference a secret in an
outside vault; the broker reads it on use; the custody leaf stays Doriath for
secrets the instance holds. Task 3.2 opens that PR.

## Risks

- A slow vault slows every call that needs the secret. The reader has a two
  second timeout and the proxy reports the vault, not the target, as the
  failure.
