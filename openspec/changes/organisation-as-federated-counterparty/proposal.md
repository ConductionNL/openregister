---
kind: code
---

# Proposal: organisation-as-federated-counterparty

## Summary

Let an `Organisation` say that it is **not** a tenant of this installation — that
it is a counterparty, a tenant somewhere else, reachable across the federation.
Then make every tenant enumeration consult that, so the tenant background jobs
stop being able to see one.

## Motivation

Consuming apps want to put their partner organisations here. dossiq carries a
`partnerOrganization` schema — name, slug, oin, contactEmail, groupId, isActive
— every field of which `Organisation` already has, plus a `type` discriminator
whose values include `collaboration` and `vendor`. A ketenpartner obviously IS
an organisation.

The reason it cannot simply move is that this table is also the tenant table.
`Organisation`'s own docblock says every row "is still a full organisation and
still a valid tenant", and ADR-002 Rule 1 makes the organisation UUID the only
tenant key. `type` cannot carry the distinction: it is deliberately NOT an
authorization input.

## 🔴 The gap is destructive, not cosmetic

Three background jobs enumerate organisations, and each selects on `status`
alone:

| Job | Selects | Does |
|---|---|---|
| `TenantUsageSyncJob` | `status = active` | meters usage |
| `TenantDeprovisionJob` | `status = deprovisioning` | tears the tenant down |
| `TenantPurgeJob` | `status = archived` | **permanently deletes the row** |

So the moment ketenpartners live in this table, an archived partner is
indistinguishable from an archived tenant and is deleted with it. Nothing
throws; the job reports a successful purge.

That is why this change leads with tests. `TenantJobsScopeTest` was written
against the OLD behaviour first and two of its assertions failed when the
distinction landed, which is the only evidence that it pins anything.

## Scope

### In Scope

1. **`isLocalTenant`** on `Organisation`, defaulting to TRUE so every existing
   row keeps exactly the meaning it has today. Unlike `type`, this one IS
   consulted by tenancy.
2. **`remoteInstanceUrl`** — the peer OpenRegister base URL a counterparty is a
   tenant of. It is the same value `FederatedShare.remoteInstanceUrl` carries,
   which is what lets a share and the organisation it is with resolve to each
   other.
3. **`OrganisationMapper::findLocalTenants()`** — a named method whose contract
   is the guarantee, used by all three jobs.

### Out of Scope

- **Moving dossiq's `partnerOrganization`.** That is dossiq's change, and it can
  only start once this exists.
- **Narrowing `findAll()`.** The admin organisation list must keep showing
  counterparties — they are the ketenpartners the federation exists to work
  with.

## The NULL that would have broken every tenant

`is_local_tenant` arrives by migration, so every row written before it holds
NULL. A plain `is_local_tenant = true` filter would therefore make **every
pre-existing tenant invisible to all three jobs at once** — tenants would
silently stop being deprovisioned, purged and metered, and each job would report
success over an empty list.

`findLocalTenants()` treats NULL as a tenant. Only a row explicitly marked false
is excluded, and `OrganisationTenantScopeIntegrationTest` asserts exactly that
against a real database, because a mocked mapper cannot show it.

## Risks

- 🔴 **A named method can be forgotten.** The next job someone writes could call
  `findAll()`. Mitigated by the name being the contract and greppable, and by
  the job tests asserting the tenant-scoped path is the one used. A stronger
  guard — making `findAll()` itself default to local tenants — was rejected
  because it would silently hide counterparties from the admin surface.
- ⚠️ **`isLocalTenant` is an authorization-adjacent input and `type` is not.**
  Two discriminators on one entity invites confusion; each one's docblock says
  which question it answers and which it does not.
