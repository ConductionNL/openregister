---
title: Discovery and federation
sidebar_position: 40
---

# Discovery and federation

This page explains how an instance tells other servers which standards its apps speak, and how to declare that in an app.

Another Nextcloud instance only sees what this instance tells anybody without logging in. That is three endpoints:

| Endpoint | What it tells |
|---|---|
| `GET /status.php` | That this is Nextcloud, and its version |
| `GET /ocs/v2.php/cloud/capabilities?format=json` (header `OCS-APIRequest: true`) | One block per app. Anonymous callers only get capabilities that implement `IPublicCapability`. |
| `GET /.well-known/ocm` (legacy `/ocm-provider/`) | Open Cloud Mesh: which resource types this server accepts as federated shares |

OpenRegister turns the `discovery` block of every enabled app's `src/manifest.json` into answers on the last two. The block also generates each app's "Standards & federation" docs page, so the docs and what the server publishes cannot disagree.

## What a remote server sees

```bash
curl -s -H 'OCS-APIRequest: true' \
  'https://cloud.example.org/ocs/v2.php/cloud/capabilities?format=json' \
  | jq '.ocs.data.capabilities | {discovery, decidiq, opencatalogi}'
```

```json
{
  "discovery": {"contractVersion": 1, "provider": "openregister", "apps": ["decidiq", "opencatalogi", "openregister"]},
  "decidiq": {"discovery": {
    "contractVersion": 1,
    "standards": [
      {"id": "ori", "name": "Open Raadsinformatie", "role": "provides", "access": "public", "endpoint": "/apps/decidiq/api/ori/v1"}
    ]
  }},
  "opencatalogi": {"discovery": {
    "contractVersion": 1,
    "standards": [{"id": "dcat-ap-nl", "name": "DCAT-AP-NL", "version": "3.0", "role": "provides", "access": "public", "endpoint": "/apps/opencatalogi/api/dcat"}],
    "links": {"directory": "/apps/opencatalogi/api/directory"}
  }}
}
```

To decide whether it can connect, a peer reads these steps in order and stops when one fails:

1. `status.php`: is this Nextcloud?
2. `discovery.apps`: is the app I need here?
3. `<app>.discovery.standards`: does it speak the standard and version I need?
4. `endpoint` / `links`: where do I go?

Paths are relative to the instance root. Prefix `/index.php` when the core capability `core.mod-rewrite-working` is false.

## Declaring it in an app

Add a `discovery` key to `src/manifest.json`:

```json
"discovery": {
  "public": true,
  "standards": [
    {
      "id": "ori",
      "name": "Open Raadsinformatie",
      "version": "1",
      "role": "provides",
      "access": "public",
      "endpoint": "/apps/decidiq/api/ori/v1",
      "specUrl": "https://github.com/openstate/open-raadsinformatie"
    },
    {"id": "popolo", "name": "Popolo", "role": "provides", "access": "public", "specUrl": "https://www.popoloproject.com/"}
  ],
  "links": {"openapi": "/apps/decidiq/api/openapi.json"},
  "ocmResourceTypes": [
    {"name": "dossiq-case", "shareTypes": ["user", "group"], "protocols": {"dossiq": "/apps/dossiq/api/federation"}}
  ]
}
```

| Field | Rules |
|---|---|
| `public` | Default `true`. `false` keeps the app out of the public capability; its OCM types are still announced. |
| `standards[].id` | Lower-case slug. Use an id from the vocabulary below so peers can match on it. |
| `standards[].role` | `provides` (we serve it) or `consumes` (we are a client of it). Declare `consumes` too: it tells a peer what we can connect *to*. |
| `standards[].access` | `public`: no credential. `token`: no Nextcloud login, but a credential the caller already holds (ZGW JWT, share token, LTI launch). `authenticated`: Nextcloud login. **Default `authenticated`, which is never published with its endpoint.** |
| `endpoint`, `links`, `protocols` | Paths on this instance starting with `/`. URLs, `//host` and `..` are rejected. |
| `specUrl` | `https://` link to the standard. |
| `ocmResourceTypes[]` | Only declare a type your app handles with an `ICloudFederationProvider`. A type without a provider is not announced. |

Only list a standard the app **actually implements**. A data model that merely follows a standard's vocabulary is `role: provides` with no endpoint. Planned work belongs in the roadmap, not here.

An invalid entry is dropped with a warning in the Nextcloud log (`[AppHost\DiscoveryCatalog] <app> manifest discovery.standards[2]: ...`). The rest of the block still counts.

## Standard ids

Reuse these ids. To add one, open a PR on this page.

| Id | Standard |
|---|---|
| `zgw-zaken`, `zgw-documenten`, `zgw-catalogi`, `zgw-besluiten`, `zgw-notificaties`, `zgw-autorisaties` | VNG ZGW APIs |
| `objecten-api`, `objecttypen-api`, `klantinteracties` | VNG Common Ground APIs |
| `stuf-zkn`, `stuf-bg` | StUF (SOAP) |
| `cmmn`, `dmn`, `bpmn` | OMG case, decision and process models |
| `ori`, `popolo`, `akoma-ntoso` | Open Raadsinformatie, Popolo, Akoma Ntoso |
| `dcat-ap-nl`, `ooapi`, `schema-org`, `diwoo`, `tooi` | Open data and publication |
| `ubl`, `en16931`, `nlcius`, `peppol`, `factur-x`, `sepa`, `xbrl`, `xaf` | Invoicing, payments and reporting |
| `mdto`, `tmlo`, `pdf-a` | Archiving |
| `haal-centraal-brp`, `haal-centraal-brk`, `haal-centraal-woz`, `bag`, `kvk`, `pdok`, `dso` | Dutch base registries and Omgevingswet |
| `berichtenbox` | MijnOverheid Berichtenbox |
| `fsc`, `digikoppeling`, `cloudevents` | Connectivity and events |
| `lti`, `open-badges`, `w3c-vc`, `qti`, `elm`, `xapi`, `cmi5`, `common-cartridge`, `edukoppeling`, `oso`, `uwlr` | Education |
| `oidc`, `openid4vci`, `oauth-jwt-bearer`, `webauthn`, `fido-cxf`, `fido-cxp`, `x509` | Identity and credentials |
| `openapi`, `json-schema`, `json-ld`, `skos`, `graphql`, `urn`, `mcp`, `ocm` | Generic API and data |
| `nl-design-system`, `dtcg`, `wcag` | Design tokens and accessibility |
| `icalendar`, `rss`, `atom` | Calendars and feeds |
| `openai-api` | LLM provider APIs (OpenAI-compatible) |
| `opencatalogi-federation` | OpenCatalogi directory and federated publications protocol |
| `archimate`, `cyclonedx`, `spdx` | Architecture and software inventory |

## Admin controls

| Setting | Effect |
|---|---|
| `occ config:app:set openregister discovery_public --value=no` | Publish nothing (OCM types still follow Nextcloud's own federation settings) |
| `occ config:app:set openregister discovery_hidden_apps --value=hermiq,keepiq` | Never list these apps |

Both take effect immediately.

What is never published:
- app versions (`status.php` already shows the server version; per-app versions would make this a vulnerability index);
- endpoints of login-only entries;
- register or schema names;
- object counts.

## Open Cloud Mesh

Nextcloud serves `/.well-known/ocm` itself. Apps only add resource types to it.

OpenRegister announces:
- its own `openregister` type;
- every `ocmResourceTypes[]` entry whose name has a cloud federation provider.

It listens to `ResourceTypeRegisterEvent`, which Nextcloud 33 deprecates in favour of `LocalOCMDiscoveryEvent`. Nextcloud 33 and 34 still dispatch both events, so do not add a second listener for the new event, or every type is registered twice. The switch happens when the minimum supported version reaches 33.

## Checking an instance

- **[Nextcloud Peek](https://genhttp.dev/lambda/nextcloud-peek/)** shows each app's `discovery` block as a card.
- **`curl`** with the command above.
