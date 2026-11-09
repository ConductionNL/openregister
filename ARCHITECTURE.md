# Architecture

OpenRegister is a Nextcloud app. It stores structured data as objects, validated against JSON Schema definitions and grouped in registers. Other Conduction apps (OpenCatalogi, IntegrIQ, Filinq and more) store their data here instead of in tables of their own.

This page is for new developers and for anyone who assesses the code, such as SIG. It names the functional components, says where each one starts and stops, and marks every top-level directory as production, test, generated or tooling.

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
| `src/` | Production front end (Vue 3, TypeScript and JavaScript). Files ending in `.spec.js` or `.spec.ts` are Vitest tests. |
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
| `docker/`, `docker-compose*.yml`, `exapps/`, `n8n-templates/`, `n8n-mcp/` | Local development and demo environments, and example workflow templates. |
| `patches/` | Patches applied to third-party Composer packages at install time. |

## Functional components

The directories under `lib/` are Nextcloud's layers (`lib/Controller`, `lib/Service`, `lib/Db`, `lib/Listener`, `lib/BackgroundJob`, `lib/Command`, `lib/Repair`, `lib/Event`, `lib/Exception`) plus a few feature directories. They stay where Nextcloud developers expect them. The components are functional: a controller, its service and its mapper for one capability belong to the same component, wherever their files sit.

Ten components are defined by file pattern over that layout ([ADR-012](openspec/architecture/adr-012-functional-components-over-the-nextcloud-layout.md)). A whole directory belongs to one component where it can; in a flat layer directory, one pattern per component lists its class names.

| Component | What it holds |
|---|---|
| Kernel | `lib/Kernel`, `lib/Contract`, `lib/Dto`, `lib/Support`, `lib/Formats`, `lib/Lifecycle`; the core entities `Db/ObjectEntity`, `Db/Schema` and `Db/Register` with `SchemaMapperInterface` and `RegisterMapperInterface`; events and exceptions used by more than one component |
| Core | Objects, schemas, registers, relations, merging, history, RBAC, organisations, credentials, sharing, delegation, the Magic mapper, object sources, vocabularies, views |
| Processes | Flows, BPMN and DMN, rules, calculations, tasks, cases, forms, decisions, lifecycle transitions, object actions, bulk actions, Workflow Engine operations |
| Configuration | Configuration packages and deployment, import and export, example sets, shipped baselines, migration packs, settings services, API versioning, repair steps |
| Compliance | Archival, e-Depot, retention, deletion, GDPR, consent, audit trail, anonymisation, quality |
| Integrations | `lib/Mcp`, `lib/AppHost`, integration registry, GraphQL and API clients, calendar, contacts, DAV, federation, Context Chat, Twig, webhooks, outbound |
| Files | `lib/Files`, `lib/Service/File` |
| Search | `lib/Search`, `lib/Service/Aggregation`, `Service/GraphQL`, `Service/Search`, `Service/Vectorization`, `Service/View`, search trail |
| Notifications | `lib/Notifications`, `lib/Notification`, `lib/Push`, `lib/Activity`, parties, interaction |
| Platform | `lib/AppInfo`, `lib/Middleware`, `lib/Capabilities`, `lib/Dashboard`, `lib/Reference`, `lib/Sections`, `lib/Settings`, operations console |

The front end in `src/` and the static scripts in `js/` are the web interface; the table covers the PHP code in `lib/`.

`sigrid.components.yaml` is the definition. It is generated by `scripts/component-patterns.py` from `scripts/component-map.csv`, the class map with one row per file and its component, and is never edited by hand. A class added to a flat layer directory gets a row in that map. The block moves into `sigrid.yaml`, replacing `component_base_dirs`, once the contract stage has landed; until then Sigrid rates the directory view.

### Size of the Kernel

The Kernel holds the contracts, DTOs, core entities and the events and exceptions that several components share. Its budget is about 8,000 lines of code (ADR-012 Rule 3). The code that crosses a component boundary has a budget of at most 11,000 lines for the whole application, with 8,000 as the target.

## Dependency rules

The rules are in [ADR-013](openspec/architecture/adr-013-component-dependency-rules.md). In short:

- **A star through the Kernel.** A class in one component references a class in another only when that class is in the Kernel. A functional component may use `lib/Kernel`, `lib/Contract`, `lib/Event`, `lib/Exception`, `lib/Support`, the core entities and OCP, and no other functional component.
- **Mappers dispatch through `EntityEventsInterface`.** A mapper in `lib/Db` names no event class. It calls `Kernel\Contracts\EntityEventsInterface`, whose implementation in Core names the events, so the events still fire when another app writes through the mapper.
- **Nothing names `lib/AppInfo`.** Code outside `lib/AppInfo` never references `OCA\OpenRegister\AppInfo\`. The app id is `Kernel\Contracts\AppIdentity::APP_ID`.
- **Events and exceptions name Kernel types only.** An event carries an id, a Kernel DTO or a core entity.
- **Inside a component, controller to service to mapper.** A controller calls services, never a mapper; a mapper reads and writes rows and holds no business rule.

### Where contracts live

| What | Where |
|---|---|
| An interface, DTO or value type that one component offers another | `lib/Kernel/Contracts` |
| Request context shared by several components (such as `WriteCause`, `OrganisationContext`) | `lib/Kernel/Context` |
| Base classes for background jobs shared by several components | `lib/Support/Jobs` |
| Controller base classes and traits shared by several components | `lib/Support/Http` |
| The contract other apps consume (ADR-084) | `lib/Contract` |

Both `lib/Contract` and `lib/Kernel/Contracts` are leaves of the dependency graph. A contract in `lib/Kernel/Contracts` names only Kernel types, and names a core entity through `ObjectEntityInterface`, `SchemaInterface` or `RegisterInterface`. `lib/Contract` must stay byte-identical to `hydra-gates/contracts/` (hydra gate 67), so a file goes there only when it is published there too.

A component binds its implementations to the Kernel interfaces in its own registrar, never in `lib/AppInfo/Application.php`. Callers in other components type-hint the interface. The concrete class keeps its name and place, because other apps inject it that way. When an interface moves into `lib/Kernel` or `lib/Support`, `appinfo/class-aliases.json` keeps the old name working.

`tests/Unit/Architecture/` enforces these rules; `ContractIsALeafTest` checks the contract directories.

## How a request flows

1. The browser calls a route from `appinfo/routes.php`.
2. A controller in `lib/Controller/` checks the user's rights and hands over to a service.
3. Services in `lib/Service/<Capability>/` hold the logic and use mappers in `lib/Db/` to read and write.
4. Changes fire typed events (`lib/Event/`). Listeners in other components react to them.
5. Background jobs (`lib/BackgroundJob/`) run slow work, such as text extraction and retention sweeps, outside the request.

## Test code

All PHP test code lives in `tests/`. Front-end unit tests sit next to the code they test as `*.spec.js` or `*.spec.ts`. End-to-end tests are Playwright specs in `tests/e2e/`. Nothing under `lib/` or `src/` without a `.spec.` suffix is test code.

## What a default scan picks up

SIG's Sigrid excludes `vendor/`, `node_modules/`, `docs/`, migrations, mocks and stubs by default. Two things it does not exclude by default:

- `l10n/*.js`: generated translation catalogues that would count as JavaScript.
- `scripts/` and `tools/`: developer tooling that would count as production code.

`sigrid.yaml` excludes both and pins the model version. Its `component_base_dirs` makes every directory under `lib/` a component for now; the functional components above replace it once the contract stage has landed.

## Licence

OpenRegister is licensed under EUPL-1.2. Third-party files keep their own licence, recorded in `REUSE.toml` and `LICENSES/`.
