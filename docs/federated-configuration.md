# Federated configuration sharing

OpenRegister gives every Conduction app **one** way to share its configuration
over GitHub — flows, registers and schemas, whole configuration sets, NL Design
themes, agent templates and skills, case types, payroll/tax packs, publication
types and more. You publish a configuration to a GitHub repository; another
instance discovers it, previews it, and installs it — with the GitHub token
custodied by the credential broker (never handled by an app), the bundle
cryptographically signed, and installation governed by your organisation's trust
rules.

## Concepts

- **Shareable type** — a kind of configuration that can be shared. Apps
  contribute types either with a one-line schema marker (`x-openregister-shareable`
  on a schema's configuration) or, for non-object storage, a small
  `IShareableConfigType`. List them at `GET /api/federated-config/types`.
- **Bundle** — the portable, instance-independent form of a selection. Instance
  fields (id, uuid, owner, organisation, timestamps) are stripped; secrets are
  never included.
- **Topic** — every type has a GitHub discovery topic (e.g. `openregister-flow`),
  so published repositories are found by topic search.
- **Provenance** — each published bundle carries an Ed25519 signature over its
  canonical form, plus the publisher's public key.

## Choose which GitHub credential the store uses

The store never assumes your only GitHub key is the one you meant to use. In the
app's **Configuration store** settings pane (user settings), pick which of
your GitHub credentials the store should publish and browse with. The choice is
saved as a per-user preference; the credential itself is custodied by the broker.

A credential used for the store must permit the `openregister` app (the store
calls the broker as `openregister`).

## Publish a configuration

`POST /api/federated-config/publish` with `{type, selection, repo, path}` (and
optional `visibility: "private"`). The engine:

1. ensures the repository exists (creating it — public by default — when absent),
2. tags it with the type's discovery topic,
3. **signs** the bundle with this instance's key,
4. writes the bundle file at `path`.

The credential is the one you selected in settings — a caller can never publish
with a credential they did not choose.

## Discover, fetch and install

- `GET /api/federated-config/discover?topic=<topic>` — find published
  repositories by a type's topic (anonymous, or through your credential).
- `GET /api/federated-config/fetch?repo=<owner/repo>&path=<path>` — read a
  published bundle back.
- `POST /api/federated-config/install` with `{type, bundle, source}` — install it.
  Install **verifies the signature** (a tampered bundle is always refused) and is
  governed by your organisation's trust rules.

## Trust and governance (admin)

An administrator manages trust at `GET`/`PUT /api/federated-config/trust`:

- **`sourceAllowlist`** — comma-separated GitHub sources you install from. Empty
  means "not yet enforced"; once set, an install from a source not on the list is
  refused. A bare org (e.g. `ConductionNL`) covers all its repositories.
- **`trustedKeys`** — comma-separated base64 publisher public keys you trust. Empty
  means signatures are not yet enforced; once set, an unsigned or untrusted-key
  bundle is refused. Append a key with `PUT {trustKey: "<base64>"}`.
- **`publishGroups`** — Nextcloud groups permitted to publish. Empty means any
  signed-in user; admins are always allowed.
- **`installGroups`** — Nextcloud groups permitted to install. Empty means
  administrators only. A member of an install group may install a bundle only
  when it seeds no objects (`components.objects`, top-level `objects` or
  `x-openregister.seedData.objects`) and only overwrites registers and schemas
  they may manage; a bundle that carries objects (for example a register export
  made with `includeObjects`) is refused with 403 and must be installed by an
  administrator.

Share this instance's public key (`GET /api/federated-config/public-key`) with
organisations that want to trust the configuration you publish.

The trust write and the install are protected against cross-site requests. An
API client, Basic auth and app passwords included, must send the
`OCS-APIRequest: true` header, or Nextcloud answers 412:

```bash
curl -u admin:app-password -X PUT \
  -H 'OCS-APIRequest: true' -H 'Content-Type: application/json' \
  -d '{"field": "sourceAllowlist", "value": "ConductionNL"}' \
  https://cloud.example.org/index.php/apps/openregister/api/federated-config/trust
```

The same header applies to `POST /api/federated-config/install`. Scripts written
for an earlier version without it stop working on upgrade.

Every install, refused install and trust change is written to the Nextcloud log
with the user who did it; a trust change also lands on the audit trail.

## Configuration sets and installable apps

A whole app's worth of configuration — registers, schemas, objects, views, flows,
sources and mappings — can be shared as one **configuration set**
(`openregister.configset`). A repository may hold many such files. When an app is
published through OpenBuild, the same repository can additionally carry the files
that make it an app-store-installable, standalone Nextcloud app (an `info.xml`
declaring OpenRegister a dependency, the packaged Vue runtime), so one repository
is both the configuration store and the installable app.
