# Architecture

OpenRegister is a Nextcloud app. It stores structured data as objects, validated against JSON Schema definitions and grouped in registers. Other Conduction apps (OpenCatalogi, IntegrIQ, Filinq and more) store their data here instead of in tables of their own.

This page is for new developers and for anyone who assesses the code, such as SIG. It names the components, says how they connect, and marks every top-level directory as production, test, generated or tooling.

## System boundary

OpenRegister runs inside a Nextcloud server as an app, in PHP on the server and Vue in the browser. It has no database of its own: it uses the Nextcloud database and file storage.

| Talks to | How |
|---|---|
| Nextcloud server | The public `OCP` API (users, groups, files, events, background jobs, notifications) |
| Other Conduction apps | They call `OCA\OpenRegister\...` services in-process, or the REST API under `/apps/openregister/api` |
| Search back ends (Solr, Elasticsearch), LLM and text extraction services | HTTP, all optional and configured by an admin |

## Directory roles

| Directory | Role |
|---|---|
| `lib/` | Production PHP. One class per file, PSR-4 under `OCA\OpenRegister`. |
| `src/` | Production front end (Vue 3, TypeScript and JavaScript). Files ending in `.spec.js` or `.spec.ts` are Jest tests. |
| `js/` | Three hand-written static scripts (web push client, its service worker, the flow operator). Webpack output also lands here but is gitignored. |
| `appinfo/` | App manifest and route table. |
| `templates/` | PHP page templates that mount the front end. |
| `tests/` | Test code only: PHPUnit (`Unit/`, `Integration/`, `Service/`, `Db/`), Playwright (`e2e/`), Newman and Postman collections, fixtures and stubs. |
| `l10n/` | Translation catalogues. `<locale>.json` is the catalogue, `<locale>.js` is generated from it. Not logic. |
| `lib/Migration/` | Database migrations. Production code, kept apart because each file is frozen once released. |
| `lib/Resources/`, `lib/Service/Flow/Bpmn/schema/` | Third-party specifications and vocabularies shipped unchanged (schema.org, TOOI, MDTO, BPMN, GGM). `REUSE.toml` names their owners. |
| `scripts/`, `tools/`, `vendor-bin/` | Developer tooling: translation, quality and release scripts. Not shipped as app logic. |
| `openspec/` | Specifications and change proposals (Markdown). |
| `docs/` | Documentation site (Docusaurus). |
| `docker/`, `docker-compose*.yml`, `exapps/`, `n8n-templates/` | Local development and demo environments, and example workflow templates. |
| `patches/` | Patches applied to third-party Composer packages at install time. |

## Components

A component is a top-level directory of the repository, as developers see it. This follows SIG's guidance for producers (section 2.2). ADR-014 records the decision, and `sigrid.yaml` declares it with `component_base_dirs: [""]`.

| Component | What it is |
|---|---|
| `lib/` | The backend: all production PHP. |
| `src/` | The frontend: the Vue single-page app that runs in the browser. |
| `appinfo/` | The app manifest (`info.xml`) and the route table (`routes.php`). |
| `templates/` | PHP page templates that mount the frontend. |
| `js/` | Three hand-written static scripts: the web push client, its service worker and the flow operator. |
| `docker/` | Entry points and seed scripts for the development and demo containers. Not shipped. |
| Root files | Build and test configuration in the repository root (`webpack.config.js`, `jest.config.js`, `playwright.config.ts`, `stylelint.config.js`). Sigrid groups them into one component. |

Other top-level directories hold no code that Sigrid analyses. `tests/` is test code. `scripts/`, `tools/`, `vendor-bin/`, `.claude/` and `openspec/changes/` are excluded in `sigrid.yaml`. `docs/` is excluded by default. `l10n/`, `css/`, `img/`, `schemas/`, `data/` and the n8n directories hold data, styles and templates.

### How the components connect

- The frontend in `src/` runs in the browser. It calls the backend's controllers over REST with JSON, on the routes in `appinfo/routes.php`.
- `templates/` renders the page that loads the frontend.
- Other Nextcloud apps call the backend in-process, through the PHP contracts in `lib/Contract/`.
- The backend reaches the Nextcloud server through the public `OCP` API.

## Inside the backend

`lib/` is one component. Inside it the code keeps the Nextcloud layout: controllers, services, mappers, events and listeners. ADR-013 sets the rules for that layering: a controller calls services, a service calls mappers, nothing calls back up a layer, and events replace upward calls. The architecture tests in `tests/Unit/Architecture/` check these rules, and ADR-013 adds one test that checks the whole dependency graph.

The table groups the backend by what the code does. A controller, its service and its mapper for one capability belong to the same area. These areas are an internal view, not SIG components.

| Area | What it covers | PHP lines |
|---|---|---|
| Processes and rules | Flows, BPMN and DMN, business rules, calculations, tasks, cases, forms | 60k |
| Objects | Object storage and validation, relations, history, merging, bulk actions | 56k |
| Configuration and import | Configuration packages, import and export, example sets, shipped baselines, settings | 36k |
| Schemas and registers | Schema and register definitions, vocabularies, object sources, views | 31k |
| Retention and compliance | Archiving, e-Depot, lifecycle and deletion, GDPR requests, audit trail, anonymisation | 29k |
| Access control | RBAC, authorisation, organisations, credentials, sharing, request middleware | 23k |
| Integrations | MCP, GraphQL, DAV, calendar, contacts, chat, federation, app hosting | 23k |
| Files and text | File storage, text extraction, chunking and vectorisation | 22k |
| Search and aggregation | Search, facets, aggregations, Solr and Elasticsearch back ends | 21k |
| Notifications and events | Events and listeners, notifications, webhooks, web push, activity | 20k |
| Application wiring | Bootstrap, repair steps, commands, shared exceptions and helpers | 43k |

Counts exclude `lib/Migration/`, which SIG leaves out by default.

### Boundaries

Every area depends on **Objects** and **Schemas and registers**: they are the data model. Every area also uses **Application wiring** for shared exceptions and helpers. Those dependencies are intended.

Decoupling between areas is in progress. The aim is that each area reaches the data model through a small interface of its own.

### Contracts

Two directories hold interfaces and value types, and both are leaves of the dependency graph: a contract may name another contract, an entity in `lib/Db/`, an event or an exception, and no other OpenRegister class.

- `lib/Contract/` is the published contract that other apps consume (ADR-084). Hydra gate 67 requires it to be byte-identical to `hydra-gates/contracts/`, so a file goes here only when it is published there too.
- `lib/Kernel/Contracts/` holds the interfaces that one area of the backend offers another. Other apps do not use them.

`tests/Unit/Architecture/ContractIsALeafTest.php` enforces both rules.

A class that other areas call implements its contract, and those callers type-hint the contract instead of the class. The concrete class stays resolvable by its own name, because other apps inject it that way.

## How a request flows

1. The browser calls a route from `appinfo/routes.php`.
2. A controller in `lib/Controller/` checks the user's rights and hands over to a service.
3. Services in `lib/Service/<Capability>/` hold the logic and use mappers in `lib/Db/` to read and write.
4. Changes fire typed events (`lib/Event/`). Listeners in other areas react to them.
5. Background jobs (`lib/BackgroundJob/`) run slow work, such as text extraction and retention sweeps, outside the request.

## Test code

All PHP test code lives in `tests/`. Front-end unit tests sit next to the code they test as `*.spec.js` or `*.spec.ts`. End-to-end tests are Playwright specs in `tests/e2e/`. Nothing under `lib/` or `src/` without a `.spec.` suffix is test code.

## What a default scan picks up

SIG's Sigrid excludes `vendor/`, `node_modules/`, `docs/`, migrations, mocks and stubs by default. Two things it does not exclude by default:

- `l10n/*.js`: generated translation catalogues that would count as JavaScript.
- `scripts/` and `tools/`: developer tooling that would count as production code.

`sigrid.yaml` excludes both, pins the model version and makes every top-level directory a component.

## Licence

OpenRegister is licensed under EUPL-1.2. Third-party files keep their own licence, recorded in `REUSE.toml` and `LICENSES/`.
