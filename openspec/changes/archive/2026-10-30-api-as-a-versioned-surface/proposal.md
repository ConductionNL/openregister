---
kind: code
depends_on: []
---

# Proposal: api-as-a-versioned-surface

## Summary

A gemeente has five leveranciers on one API and cannot move them on the
same day. Today a breaking change is a coordinated outage, nobody can see
which client still calls an endpoint, and a client discovers the upload
limit by hitting it. This change serves two contract versions at once with
a deprecation window, publishes the instance's own capabilities and
limits, records who called what, and lets a schema declare the links out
to the systems a caseworker actually has to open.

## Candidates and cluster

Cluster 5 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "The API as a described,
versioned surface". Owner openregister, size M, fourteen candidates:
C-integrations-2, -5, -13, -14, -15, -17, -18, -25, -26, -33, -35, -41,
-48 and -49. One is a `must` and a matrix hole: C-integrations-13.
Passers: 18, fifteen driven and three documented. dossiq rates `partial`
on five and `no` on nine.

## The decisions this rests on

**D6, relevance-led promotion.** C-integrations-13 is a `must` with three
driven passers and no row in the corpus to hold it.

**D21, documented candidates admitted and labelled.** C-integrations-33
(youtrack) and C-integrations-25 (mozard) have no driven passer. The first
is in scope as documented; the second is integriq's.

## Why

The proving system is nextcloud-deck, cited by the integrations lane at
`integrations.tsv:19`: "board_import_api#getConfigSchema
/api/v{v}/boards/import/config/schema/{name}, getAllowedSystems". A client
asks the instance what it supports before it tries. That is
C-integrations-5, with forgejo, gitea and nextcloud-deck driven, and the
candidate clause is exact: "every integrator currently discovers our
upload limit by hitting it".

The rest, and their driven passers:

- **Administered links out of a record, built from its own field values**
  (C-integrations-13, `must`, a hole): glpi, "External links (Setup,
  External links, front/link.php, manuallink.php, src/Link.php:426
  generateLinkContents)", with itop and otobo. The case must open the same
  object in the GIS, the BAG viewer and the financial system. dossiq's
  lane: "src/menu-layout.json holds app links; no per-record
  substitution".
- **An old and a new API contract served at once** (C-integrations-18):
  vikunja, "Two API versions served at once, 168 v1 routes beside 40 v2
  route files".
- **A rate limit per caller, administered** (C-integrations-35): taiga,
  "throttling.py per app, and projects/throttling.py". A gemeente hands a
  token to a leverancier and has no way to bound it.
- **An API key restricted to the addresses it may be called from**
  (C-integrations-14): osticket, "Admin, API keys (scp/apikeys.php,
  include/class.api.php, a key bound to an IP address)".
- **Which external client still calls an endpoint** (C-integrations-33,
  documented): youtrack, "Monitor REST API Traffic". A gemeente
  deprecating a StUF endpoint has no idea who still calls it.
- **The standard well-known discovery paths, security.txt included**
  (C-integrations-41): glpi and tuleap, "Well-known
  (src/Glpi/Controller/WellKnownController.php)".
- **Outbound calls through the organisation's proxy** (C-integrations-48):
  zammad, "Proxy (config/routes/proxy.rb, area System::Network)". Most
  gemeenten have no direct egress.

**What exists here and does not close it.** `openapi-generation` generates
an OpenAPI 3.1.0 description from the registers and schemas, serves
Swagger UI, versions the spec and tracks schema changes.
`graphql-api` gives a caller exactly the fields it asks for, which answers
C-integrations-2 outright. `runtime-schema-api` makes every
administrator-defined type reachable through one uniform API, which is why
dossiq's own lane says C-integrations-26 is "exactly this shape".
`deep-link-registry` lets an app register how OpenRegister should link
*into* it. `tenant-quotas` bounds requests per organisation. None of them
serves two contract versions at once, none records the caller of an
endpoint, none publishes the instance's limits, and none links *out* of a
record into another system.

## What changes

- **A schema declares links out of its objects.** A declared external link
  carries a title, a URL template with placeholders filled from the
  object's own values, and the condition under which it shows. A
  placeholder that cannot be filled hides the link rather than producing a
  broken URL.
- **Two contract versions are served at once.** A version is declared
  supported, deprecated with an end date, or withdrawn. A deprecated
  version answers, and answers with a header naming its end date. A
  withdrawn version answers 410 naming the successor.
- **The instance publishes its capabilities and its limits.** Supported
  versions, upload limit, page size, rate limits and the features that are
  switched on, readable without authentication where they carry no
  secrets.
- **Every API call records its caller and its endpoint.** An administrator
  can read which principals called which endpoint over a period, which is
  what turns a deprecation into a conversation rather than an outage. The
  record carries no payload.
- **A rate limit is set per caller.** Administered on the token or the
  consumer, refused with the limit and the reset time named.
- **A token may be bound to the addresses it is accepted from.** The
  binding lives on the token, beside the grant `scoped-api-tokens`
  carries.
- **The instance answers the well-known paths.** `security.txt` first,
  because the NCSC responsible-disclosure expectation is a BIO question
  with a one-file answer.
- **Outbound calls honour an administered proxy.** One setting, used by
  every outbound path in the app, so a gemeente with no direct egress can
  run it at all.

## Consumers

- **integriq**: the gateway and the source adapters read the published
  capabilities instead of probing, and the caller record tells it which of
  its own connectors still use a deprecated route.
- **dossiq**: declares the links out to the GIS, the BAG viewer and the
  financial system, per case type, and adds nothing else of its own.
- **portaliq, stackiq, opencatalogi**: a published limit a client can read
  before it uploads.

## ADRs

- ADR-022: the API surface is the platform's, described once, consumed by
  every leaf app.
- ADR-031: the links out of a record are declared on the schema, not coded
  in a menu file.
- ADR-005: an unfillable placeholder hides the link, and a withdrawn
  version refuses rather than falling back to the current one.
- ADR-091 section 6: ZGW, StUF, DSO and Notificaties endpoints stay
  integriq's. This change describes our own surface, not theirs.

## Impact

- Extends: a new `api-surface-governance` capability, and
  `openapi-generation` with the version lifecycle.
- Affected code: the routing layer and its version prefix, a call record
  table and its mapper, the schema validator for declared links, the
  outbound HTTP client, the capabilities response.
- Backwards compatible: the current version stays the current version, and
  a schema that declares no links renders none.
- Size: M.

## Out of scope

- Field selection in one round trip (C-integrations-2), which `graphql-api`
  already specifies.
- One gateway for every integration (C-integrations-25, documented,
  mozard) and restoring an earlier version of an integration's
  configuration (C-integrations-15). Both are integriq's under ADR-091.
- An integration storing its own key and value with no schema
  (C-integrations-17). A schema is the point; the lane's own note says so.
- Publishing the reference lists over the same API (C-integrations-49),
  which `code-list-lifecycle-and-hierarchy` carries.
