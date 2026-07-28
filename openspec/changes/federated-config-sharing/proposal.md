# Federated configuration sharing — one fleet standard

## Problem

Sharing configuration over GitHub has grown up three times in the fleet, and
none of it is reusable or user-facing:

1. **OpenRegister's `ConfigurationService`** already bundles heterogeneous OR
   entities (registers, schemas, objects, views, agents, sources, mappings,
   applications) as an OAS 3.0 + `x-openregister` document, and already
   **publishes to and installs from GitHub** (`GitHubHandler::publishConfiguration`,
   the contents API), with version tracking, preview-diff and cron re-check. But
   every publish/import/export is **hard-gated to Nextcloud admins**, uses a
   single shared app-level token, and the export is **register/schema-centric** —
   an app cannot declare its own shareable object types.
2. **OpenBuild** built a cleaner object→GitHub model: `AppRepoSerializer`
   (object + companion schemas → canonical repo files), broker-routed push
   (`GitHubPushService`, token via the credential broker so it can be **per
   user**), topic discovery (`GitHubCatalogService`, `topic:openbuild-app`), and
   a `link / push / pull / status` controller.
3. **hermiq copied OpenBuild's** for agent templates and skills
   (`GitHubTemplatePushService`, `topic:hermiq-skill`), and says so in its own
   spec.

So the mechanism is duplicated, admin-only, and locked to specific object types —
while **10 of 11 apps store their shareable config as OpenRegister objects** and
several more (procest, shillinq, softwarecatalog, nldesign) already have local
OTAP file export that is a natural serialisation seam. There is no one way for a
**user** to share a flow, a case type, a payroll pack, a publication type, an NL
Design theme, or a register — over GitHub — and no trust model for installing
what someone else shared.

## Solution

One OpenRegister-owned service that any app plugs into, unifying the two proven
mechanisms and opening them to users. It is deliberately **not** built on
openconnector — the engine is OpenRegister's own `ConfigurationService` plus the
credential broker; openconnector remains an optional peer that only adds its own
resource types.

- **`FederatedConfigService`** (OpenRegister) — the engine, extracted from
  OpenBuild's proven serialiser + broker-routed push + topic discovery, backed by
  `ConfigurationService`'s existing bundle / version / preview-diff spine.
- **`IShareableConfigType` + `RegisterShareableConfigTypesEvent`** — the same
  contribution idiom as flow nodes (#2074) and (soon) MCP tools (#2077). Each app
  declares a shareable type: how to **select** the config, **serialise** it to
  canonical files, **deserialise** it on install, **exclude secrets**, and its
  discovery **topic**. OpenBuild's `AppRepoSerializer` is the reference
  implementation.
- **Storage-agnostic by contract.** A type owns serialise/deserialise, so the
  standard works for OR-object config *and* app-specific storage. **NL Design
  themes** (`CustomTokenSet`, stored in `IConfig`, exported today by
  `ConfigBundleService`) are a first-class shareable type that wraps its existing
  JSON bundle as the payload — no migration onto OR objects required.
- **User &amp; org sharing, GitHub credentials in Doriath.** Replace the flat
  NC-admin gate with **per-org RBAC**. GitHub tokens are never a shared app-level
  secret: they are custodied by the credential broker's **Doriath** vault leaf
  (per-application EncryptionSuites, ciphertext-only `rsa-oaep-sha256-chunked-v1`,
  audit on every mutation, rotation), resolved per user/org at publish/pull time.
  Where Doriath is not installed, the broker's NC-vault leaf is the unchanged
  fallback. So a normal user can share and install within their org's governance
  without any shared token.
- **Trust (v1): org allowlist + version pinning.** An org admin allowlists which
  GitHub sources/orgs may be installed from; versions are pinned per instance; a
  type declares and enforces its own secret exclusion; an installed config cannot
  widen its own grant. Signing/verification is a later hardening, not v1.
- **Discovery.** GitHub topic search (per type's topic) merged with the existing
  `x-openregister` code search, plus an optional org-curated source index.

The result: a flow, an OpenBuild app, a procest case type, a shillinq payroll
pack, an opencatalogi publication type, a docudesk template, a hermiq agent/skill,
an NL Design theme, or a register/schema — all shared, discovered and installed
through **one declared seam**. #2065's "flow integration network" becomes the
first flow-shaped consumer of this, not a separate subsystem.

## Consumers (declared types, phased adoption)

| App | Type(s) | Today |
|---|---|---|
| openbuild | Application / Version / Template | Already on GitHub — becomes the reference; migrate onto the shared service |
| openregister | Registers &amp; schemas, Configurations, **Flows** (#2065) | Admin-only ConfigurationService today |
| hermiq | Agent / Agentflow / Template / Skill | Already on GitHub (copied OB) — folds onto the shared service |
| nldesign | **NL Design theme token sets** (`CustomTokenSet`) | `ConfigBundleService` JSON bundle → wrapped as the payload (IConfig storage) |
| procest | Case types, workflow / decision templates | OTAP file export → redirect at the shared service |
| shillinq | Payroll / tax / subsidie packs, catalogs | OTAP file export → redirect |
| opencatalogi | Publication types, DCAT/TOOI waardelijsten | None |
| docudesk | Document templates, dictionaries | None |
| softwarecatalog | GEMMA / AMEF compliance definitions | ArchiMate export → seam |
| pipelinq | Pipeline / deal-stage config, catalogs | None |
| larpingapp | Character / game templates | None |

## Out of scope (v1)

- **Signing / cryptographic verification** of published configs — v1 relies on org
  allowlist + pinning; signing is a follow-up hardening.
- **Deep secret handling beyond exclusion** — secrets stay in the credential
  broker; a shared config carries credential *requirements*, never values.
- **A visual sharing UI** beyond the existing Configuration modals — the engine and
  API first; per-app share buttons layer on.
- **Migrating nldesign off `IConfig`** — the adapter keeps its bundle as-is.
