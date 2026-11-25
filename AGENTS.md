# AGENTS.md

Instructions for coding agents working in this repository. People can read it too: it only names commands and paths that exist here. Translation work has its own rules in [CLAUDE.md](CLAUDE.md); read them before you touch `l10n/` or wrap a user-visible string.

## What this is

OpenRegister is a Nextcloud app for building structured data registers: you define data models with JSON Schema, store objects in registers, and expose them through REST APIs. It is the shared data backbone for other Conduction apps such as OpenCatalogi, Procest, Pipelinq and Software Catalogus ([README.md](README.md), [appinfo/info.xml](appinfo/info.xml)).

## Setup

Install the PHP and JavaScript dependencies. Both installs point git at the committed hooks in `.githooks/`.

```bash
composer install
npm ci
```

Developer environment with the local code mounted into Nextcloud (PostgreSQL with pgvector, Nextcloud on <http://localhost:8080>, `admin` / `admin`). See [docs/development/docker-setup.md](docs/development/docker-setup.md) and [docs/development/docker-profiles.md](docs/development/docker-profiles.md):

```bash
docker compose -f docker-compose.dev.yml up -d
```

Evaluation image (the app installed from this repository, no live editing):

```bash
docker compose -f docker/docker-compose.yml up -d --build
docker compose -f docker/docker-compose.yml down -v
```

## Build

```bash
npm run build   # production bundle (webpack), then checks there is one Dexie copy
npm run dev     # development bundle
npm run watch   # rebuild on change
```

## Test

PHP (PHPUnit, suites in `phpunit.xml`):

```bash
composer test:unit          # Unit Tests suite, needs no Nextcloud
composer test:integration   # boots Nextcloud: run inside the Nextcloud container, skips itself otherwise
composer test:db            # needs a booted Nextcloud and a live database
composer test:service       # needs a booted Nextcloud server container
composer test:all           # every suite
composer test:newman        # Postman collections in tests/newman against a running instance
```

JavaScript (Jest, `jest.config.js`):

```bash
npm test
```

End to end (Playwright, `playwright.config.ts`, specs in `tests/e2e/`), against a running Nextcloud with the app. The suite has no default address: set `PLAYWRIGHT_BASE_URL` (or `NEXTCLOUD_URL`). It refuses the shared development instance on `localhost:8080` unless the run opts in; [tests/e2e/README.md](tests/e2e/README.md) and `tests/e2e/shared-instance.ts` explain why.

```bash
npm run test:e2e:install   # once: installs Chromium
PLAYWRIGHT_BASE_URL=http://localhost:8081 npm run test:e2e
```

## Lint and quality

The authoritative gate, the same as CI. Run it once before you push:

```bash
composer check:strict   # lint, migration version, modularity, phpcs, phpmd, psalm, phpstan, all PHPUnit suites
```

Faster loops while you work:

```bash
composer check          # lint, phpcs, psalm, unit tests
composer lint           # php -l on every PHP file
composer phpcs          # coding standard (phpcs.xml); composer phpcs:fix to fix
composer cs:check       # php-cs-fixer dry run; composer cs:fix to fix
composer phpstan
composer psalm
composer phpmd
npm run lint            # ESLint on src, tests and scripts
npm run stylelint
npm run format          # Prettier check
npm run check:specs     # strict JSON, src/manifest.json and register files
npm run test:l10n       # every t()/n() call has a key in l10n/en.js
```

## OpenSpec flow

Behaviour is specified in `openspec/` ([openspec/config.yaml](openspec/config.yaml)):

1. A change starts as a folder in `openspec/changes/<change-name>/` with `proposal.md`, `tasks.md`, a `design.md` when there is a design choice, and delta specs under `specs/`.
2. Build it, ticking `tasks.md` as you go. Code carries `@spec` tags that point at the requirement it implements, for example `@spec openspec/specs/openregister-app-manifest/spec.md#REQ-OR-MAN-007` in `tests/validate-manifest.js`.
3. When it ships, its deltas are merged into `openspec/specs/<capability>/spec.md` and the change folder moves to `openspec/changes/archive/`.

## Architecture decisions

Numbered decision records live in [openspec/architecture/](openspec/architecture/). [ARCHITECTURE.md](ARCHITECTURE.md) describes the components and how a request flows.

## Conventions you can check in the code

- Code is generic and named by what it does: one validator per rule in `lib/Formats/` (ADR-008), services in `lib/Service/` named after their job. Search there before you write a new helper.
- Registers and schemas are configuration, not code: register descriptors are JSON in `lib/Settings/`. OpenRegister's own registers are seeded by idempotent repair steps in `lib/Repair/` (ADR-005).
- Procedures are configuration: a flow is a definition of nodes and edges stored as data (`lib/Settings/flow_register.json`), walked by the flow engine in `lib/Service/Flow/`.
- Pages are manifest configuration: `src/manifest.json` declares the pages and menu and is schema-checked by `npm run check:manifest`.
- `ObjectService::saveObject()` takes an entity or an array as its first argument, not a type string. Specific routes in `appinfo/routes.php` come before wildcard `{slug}` routes.
- Every user-visible string is wrapped in `t('openregister', ...)` or `n(...)`. See [CLAUDE.md](CLAUDE.md).

## Commits

Use [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/) (`feat:`, `fix:`, `chore:`, `docs:`, `refactor:`), present tense, first line at most 72 characters. Branch rules and the release flow are in [CONTRIBUTING.md](CONTRIBUTING.md).
