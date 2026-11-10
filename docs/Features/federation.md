---
title: Federation
sidebar_position: 12
description: Share OpenRegister objects (registers, schemas, or individual objects) with an organisation on another Nextcloud instance, and read or edit them there.
keywords:
  - Open Register
  - Federation
  - OCM
  - Open Cloud Mesh
  - Cross-instance sharing
---

# Federation

Federation lets an organisation on one Nextcloud instance **share OpenRegister data with an organisation on another instance** — the way Nextcloud already federates files between servers. Share a whole register, a schema, a single object, or everything matching a rule; the receiving organisation reads (and, on read-write shares, edits) the shared objects as if they were local.

It is built on Nextcloud's **Open Cloud Mesh (OCM)** for trust and share exchange, and on OpenRegister's existing object-source machinery for the data path — so shared objects appear as native, filterable objects across the API, index pages, detail pages, map and relations.

## Concepts

- **FederatedShare** — one cross-instance sharing relationship (outgoing or incoming). It carries the scope, the target organisation (`slug@host`), the permissions, and a scoped bearer **share token**.
- **Scope** — what is shared: `register` (everything in a register), `schema` (e.g. all WOO publications), `object` (a single case), or `query` (everything matching a filter — used by rule-based sharing).
- **Permissions** — `read` or `read-write`.
- **Confidentiality** — register/schema/query shares never serve an object marked confidential; only `object` shares serve exactly what the sharer picked.

## How it works

**Trust** reuses Nextcloud's federation (trusted servers + Federated Cloud IDs) and OCM. OpenRegister registers a cloud-federation provider for the `openregister` resource type, so its instances advertise (`/ocm-provider`) that they accept `openregister` shares and route incoming ones to the provider.

**Data access** is *live proxy* by default: the receiving instance binds a shadow schema to the `federated` object-source provider, which proxies `find/findAll/count` to the sharing instance's token-scoped serving endpoint. No copy is stored; reads are always fresh. (A sync/copy mode reusing the Source engine is available for offline/performance.)

**Write-back** — on a `read-write` share the receiver's edits proxy to the serving endpoint, which mutates the source object (full schema validation applies).

## API

### Share management (authenticated, organisation-scoped)

| Method | URL | Purpose |
|---|---|---|
| `GET`  | `/apps/openregister/api/federation/shares` | List the org's shares (`?direction=outgoing\|incoming`). |
| `POST` | `/apps/openregister/api/federation/shares` | Create an outgoing share. Body: `scope`, `register`, `schema`, `objectUri?`, `queryFilter?`, `permissions`, `sharedWith`. Returns the share incl. its token. |
| `DELETE` | `/apps/openregister/api/federation/shares/{id}` | Revoke a share. |

### Serving (token-scoped, called by the remote instance)

| Method | URL | Purpose |
|---|---|---|
| `GET`    | `/apps/openregister/api/federation/{shareToken}/objects` | List the shared objects (paginated: `_limit`, `_offset`, `_search`). |
| `GET`    | `/apps/openregister/api/federation/{shareToken}/objects/{id}` | Read one shared object. |
| `GET`    | `/apps/openregister/api/federation/{shareToken}/meta` | Describe the share (scope, register/schema, permissions). |
| `POST`   | `/apps/openregister/api/federation/{shareToken}/objects` | Create (read-write shares only). |
| `PUT`    | `/apps/openregister/api/federation/{shareToken}/objects/{id}` | Update (read-write shares only). |
| `DELETE` | `/apps/openregister/api/federation/{shareToken}/objects/{id}` | Delete (read-write shares only). |

The serving endpoints are public by design — the caller is another server authenticated by the bearer share token, not a local session — and every request is re-scoped to exactly what the share grants (register/schema/object/query, the sharing organisation, and confidentiality).

## Consuming a share (shadow schema)

Bind a local schema to the `federated` provider so the remote objects appear as native objects:

```json
{
  "configuration": {
    "x-openregister-object-source": {
      "provider": "federated",
      "readOnly": true,
      "config": { "remoteUrl": "https://remote.example", "shareToken": "<token>" }
    }
  }
}
```

## Rule-based sharing (flows)

Instead of sharing by hand, a schema **flow** can share objects automatically. Add a `federate-share` action to the schema's `x-openregister-flows`; it fires on the matching lifecycle trigger and creates an idempotent object-scope share to the target organisation:

```json
{
  "x-openregister-flows": [
    {
      "name": "federate-published",
      "trigger": "updated",
      "actions": [
        { "type": "federate-share", "sharedWith": "partner-org@remote.example", "permissions": "read" }
      ]
    }
  ]
}
```

"Every case meeting condition X goes to organisation Y" becomes declarative configuration, not code.

## Security

- Share tokens are per-share 48-character secrets scoped to a register/schema/object/query, a single organisation, and a permission level.
- Register/schema/query shares filter out confidential objects at serve time; a schema share can never leak a case marked confidential.
- Federated writers can only write into the sharing organisation — an edit can never plant an object into another organisation.
- OCM trust means shares are only exchanged with instances you have federated with.

## Notes

The live-proxy path requires the sharing instance to be reachable. A localhost self-loop (single-instance testing) additionally needs `allow_local_remote_servers`; real federated hostnames do not.
