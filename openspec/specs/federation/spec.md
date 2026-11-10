---
status: done
---

# federation Specification

## Purpose

The canonical home for what OpenRegister's federated-share serving surface
(`FederationController`, `/api/federation/{shareToken}/...`) is allowed to hand
to a remote instance.

Two guards sit on that surface and they are independent: a share's SCOPE decides
which objects the token addresses at all, and CONFIDENTIALITY decides which of
the objects inside that scope may leave the instance. This spec owns both,
because both have already failed in the direction that serves too much, and both
failed silently — nothing errored and nothing logged.

Related change history: `openspec/changes/federation-scope-enforcement`
(scope, REQ-FSE-001) and `openspec/changes/federated-config-sharing`
(the configuration-sharing transport, a separate surface).

## Requirements

### Requirement: Confidentiality is honoured under every property name it is stored under

One concept — how confidential an object is — is written by three different
producers under three different property names:

- `confidentiality` — OpenRegister's own name;
- `confidentialityLevel` — what the ZGW migration mapping pack writes
  (`SeedZgwZakenMigrationPack` maps `/vertrouwelijkheidaanduiding` onto it);
- `vertrouwelijkheidaanduiding` — the ZGW/GGM schema property itself.

The visibility filter SHALL read the FIRST of those names that is present AND
non-empty, lowercased and trimmed, and SHALL treat the object as public only
when that value is one of `''`, `openbaar`, `public`, `open`.

"Present and non-empty", not merely present, is load-bearing: a schema sync can
add an empty column for a property before anything writes to it, and an empty
`confidentiality` sitting in front of a populated `confidentialityLevel` would
reinstate the fail-open this requirement exists to close.

This guard FAILS OPEN when it reads the wrong name, which is why the alias list
is a requirement rather than a convenience. An absent key coalesces to the empty
string, and the empty string is a PUBLIC value — deliberately, because an object
that never had a level set is public. So an object marked `zeer_geheim` under a
name the guard does not read is served as public rather than refused. The
failure is invisible to a response-shape assertion, because the field is absent
rather than wrong.

Adding a spelling to the alias list SHALL be safe; removing one SHALL NOT be
done without evidence that no producer writes it.

@e2e exclude covered by tests/Unit/Controller/FederationControllerConfidentialityTest.php
(7 data-provider cases through the private `applyShareVisibility()` filter); the
filter is reachable only by a REMOTE instance presenting a share token, so there
is no browser path in this repo's Playwright suite that can reach it.

#### Scenario: A level stored under the ZGW pack's name is honoured

- **GIVEN** an accepted outgoing share of scope `schema`
- **AND** an object carrying `confidentialityLevel: zeer_geheim` and no `confidentiality`
- **WHEN** the share's visibility filter runs over it
- **THEN** the object MUST NOT be served

#### Scenario: A level stored under the ZGW schema property is honoured

- **GIVEN** the same share
- **AND** an object carrying `vertrouwelijkheidaanduiding: vertrouwelijk`
- **WHEN** the share's visibility filter runs over it
- **THEN** the object MUST NOT be served

#### Scenario: An empty canonical name does not shadow a populated alias

- **GIVEN** the same share
- **AND** an object carrying `confidentiality: ''` AND `confidentialityLevel: zeer_geheim`
- **WHEN** the share's visibility filter runs over it
- **THEN** the object MUST NOT be served

#### Scenario: The value is compared case-insensitively

- **GIVEN** the same share
- **AND** an object carrying `confidentialityLevel: ZEER_GEHEIM`
- **WHEN** the share's visibility filter runs over it
- **THEN** the object MUST NOT be served

#### Scenario: A public level is still served

- **GIVEN** the same share
- **AND** an object carrying `confidentiality: openbaar`
- **WHEN** the share's visibility filter runs over it
- **THEN** the object MUST be served

#### Scenario: An object with no level set at all is public

- **GIVEN** the same share
- **AND** an object carrying none of the confidentiality property names
- **WHEN** the share's visibility filter runs over it
- **THEN** the object MUST be served

---

### Requirement: An object-scope share serves its one object regardless of level

A share whose `scope` is `object` names exactly one object, chosen by the
sharing party. The confidentiality filter SHALL NOT be applied to it: the sharer
has already made the per-object decision that the filter exists to make on their
behalf for the broader scopes.

The filter therefore applies to `register`, `schema` and `query` scopes only,
where breadth IS the grant and confidentiality is the only remaining guard.

@e2e exclude covered by
tests/Unit/Controller/FederationControllerConfidentialityTest.php::testObjectScopeShareStillBypassesTheGuard;
same reason as above — the serving surface answers a remote instance holding a
share token, not a browser session.

#### Scenario: An object-scope share serves a non-public object

- **GIVEN** an accepted outgoing share of scope `object` naming one object
- **AND** that object carries a non-public confidentiality level
- **WHEN** the share's visibility filter runs over it
- **THEN** the object MUST be served
