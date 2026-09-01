# organisation-tenancy-scope Specification

## Purpose

Distinguish an organisation that is a tenant of THIS installation from one that
is a counterparty — a tenant elsewhere, reachable across the federation — and
make every tenant enumeration honour the distinction.

## ADDED Requirements

### Requirement: REQ-OTS-001 An organisation states whether it is a local tenant

`Organisation` SHALL carry `isLocalTenant`, defaulting to true, and
`remoteInstanceUrl` naming the peer instance a counterparty belongs to.

`isLocalTenant` IS an authorization and lifecycle input, unlike `type`, which
ADR-002 keeps out of that role deliberately.

#### Scenario: Existing rows keep their meaning

- **GIVEN** an organisation stored before the column existed
- **WHEN** it is read
- **THEN** it is treated as a tenant of this installation

#### Scenario: A counterparty carries its peer instance

- **GIVEN** an organisation marked `isLocalTenant: false` with a
  `remoteInstanceUrl`
- **WHEN** it is read back
- **THEN** both values round-trip

### Requirement: REQ-OTS-002 Tenant enumeration excludes counterparties

`OrganisationMapper` SHALL expose `findLocalTenants()`, which returns only
organisations that are tenants of this installation, and the tenant background
jobs SHALL read through it.

A NULL `isLocalTenant` SHALL count as a tenant. The column arrives by migration,
so a plain equality filter would make every pre-existing tenant invisible to
every tenant job at once — and each would report success over an empty list.

#### Scenario: A counterparty is not a tenant

- **GIVEN** a tenant, a pre-migration row, and a counterparty, all active
- **WHEN** `findLocalTenants()` is asked for active organisations
- **THEN** the tenant and the pre-migration row are returned and the counterparty is not

#### Scenario: The purge job cannot see a counterparty

- **GIVEN** the purge job
- **WHEN** it selects archived organisations
- **THEN** it reads through the tenant-scoped path, so a counterparty is never
  among the rows it permanently deletes

### Requirement: REQ-OTS-003 The general listing still shows counterparties

`findAll()` SHALL keep returning every organisation regardless of tenancy.

The narrowing is confined to the tenant path on purpose: an organisation list
that hid counterparties would hide the ketenpartners the federation exists to
work with.

#### Scenario: A counterparty appears in the general listing

- **GIVEN** a counterparty organisation
- **WHEN** `findAll()` is called
- **THEN** it is returned
