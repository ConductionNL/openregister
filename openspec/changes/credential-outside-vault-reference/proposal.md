---
kind: code
depends_on: []
---

# Proposal: credential-outside-vault-reference

## Summary

An administrator whose organisation keeps its secrets in HashiCorp Vault (or
OpenBao) points a source's credential at a path in that vault instead of
typing the secret into Nextcloud. The credential broker reads the secret from
the vault each time a call needs it, and never stores it. Everything else
about the credential stays the same: who may use it, which apps may, and the
audit trail.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| integriq | `src-secrets-manager` | Keep source credentials in an outside secrets manager such as HashiCorp Vault instead of in the platform's own database. | partial |

Row `src-secrets-manager` sits in integriq's matrix with `built.owner`
ConductionNL/openregister. The integriq lane's note: "ADR-064 decision 2:
OpenRegister is the credential broker with Doriath as custody leaf, and apps
must not build their own; an outside secrets manager is another custody leaf
behind CredentialStore, not integriq code." The row is in integriq's core
area (`sources`).

Demand row: featureRequest, https://github.com/apache/apisix/issues/12755
(an open APISIX request for OCI Vault). Three competitors rate it `yes`:

- Tyk: "config/config.go:1378-1381 kv holds Consul, Vault, file and the new stores list; gateway/kv.go:91 resolves vault:// and other references in config and API definitions"
- APISIX: "apisix/secret/vault.lua:33 uri, :34 prefix and :37 token read secrets from HashiCorp Vault ... plugin fields refer to them as $secret://vault/..."
- Frank!Framework: "credentials can live outside Frank in Delinea Secret Server (credentialProvider/.../DelineaCredentialFactory.java:86), Kubernetes secrets"

## What changes

- A brokered credential may declare `custody: "outside"` with a `vaultRef`:
  the vault connection it reads from and the secret path and key.
- A vault connection is itself a brokered credential of kind
  `outside-vault` at `organisation` scope: base URL, auth method (token or
  AppRole) and its own secret, which lives in the normal custody leaf.
- `CredentialBrokerService` reads an outside credential's secret from the
  vault on each `request()`, `resolveInjectable()` or proxy call, with a short
  in-memory cache per request, and never writes it to any store.
- The credential page shows where the secret lives and when it was last read,
  never the secret.

## Out of scope

- Writing or rotating secrets in the outside vault.
- Cloud secret managers (AWS, Azure, GCP). The reader is one class per vault
  kind; HashiCorp Vault KV v2 and OpenBao come first.
- Moving the whole custody leaf to an outside vault. Doriath stays the custody
  leaf for secrets the instance holds (ADR-064 decision 2).

## Impact

- `lib/Service/Credential/CredentialBrokerService.php` (secret reads at `:396`
  and `:1178`, mint at `:531`).
- New `lib/Service/Credential/OutsideVault/` reader and client.
- `lib/Settings/credential_broker_register.json` (`brokeredcredential`
  properties `custody`, `vaultRef`).
- `lib/Settings/credential-providers.json` (the `outside-vault` kind).
- Hydra ADR-064 gets one paragraph naming outside references.
