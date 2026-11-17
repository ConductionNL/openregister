---
title: "Maintainability questionnaire"
description: "Questions and answers on the technologies, components, scope and testing of OpenRegister, for a maintainability assessment."
sidebar_position: 2
---

# Maintainability questionnaire

Our answers to the high-level description questionnaire for a maintainability assessment. Each row quotes the question, answers it briefly and links where the subject is written up. The [overview](./index.md) explains the choices behind the answers.

Measurements are from 9 November 2026 (commit `30fbb6d5d676`) unless a row says otherwise.

## General information

| # | Question | Our answer | Where |
|---|---|---|---|
| 1 | "Date" | 9 November 2026. | |
| 2 | "Author" | Conduction B.V. The names are on the submitted form. | |
| 3 | "Company and Role/ Job title" | Conduction B.V., the developer and maintainer of OpenRegister. | |
| 4 | "System name" | OpenRegister, Nextcloud app id `openregister`. | `appinfo/info.xml`; [GitHub](https://github.com/ConductionNL/openregister); [Introduction](https://openregister.conduction.nl/docs/intro/) |
| 5 | "Version/ Snapshot date" | App version 2.1.35 at commit `30fbb6d5d676`, on `main`. The latest stable release is v2.1.0 (6 September 2026). | `appinfo/info.xml` `<version>`; git tags |
| 6 | "Date since in production/ to be released" | Since v0.1.2 on 6 September 2024 in the Nextcloud App Store. v1.0.0 followed on 8 May 2026. | git tags v0.1.2, v1.0.0, v2.1.0 |
| 7 | "Lifecycle phase (pick best fitting)" | Evolution. 11,009 commits landed between 9 August and 9 November 2026. | git log; `openspec/changes/` |
| 8 | "Business criticality (pick best fitting)" | High. It is the data layer of apps such as OpenCatalogi, Pipelinq and Dossiq, but an outage stays within one organisation's Nextcloud. | `ARCHITECTURE.md`; [Feature overview](https://openregister.conduction.nl/docs/features/openregister-overview/) |

## Technologies

| # | Question | Our answer | Where |
|---|---|---|---|
| 9 | "Main programming languages & frameworks – Backend" | PHP 8.3 or later on the Nextcloud App Framework, Nextcloud 32 to 35. Main libraries include opis/json-schema, webonyx/graphql-php, symfony/workflow, twig and guzzle. | `appinfo/info.xml` `<dependencies>`; `composer.json`; [Service architecture](https://openregister.conduction.nl/docs/development/services-architecture/) |
| 10 | "Main programming languages & frameworks – Frontend" | Vue 3.5 with TypeScript and JavaScript, Pinia, @nextcloud/vue and @conduction/nextcloud-vue, built with webpack. | `package.json`; [Frontend architecture](https://openregister.conduction.nl/docs/development/frontend/) |
| 11 | "Data storage technologies" | The Nextcloud database (PostgreSQL, MySQL, MariaDB or SQLite) and Nextcloud file storage. Solr and Elasticsearch are optional search back ends. | `appinfo/info.xml` `<database>`; `ARCHITECTURE.md`; [Object storage](https://openregister.conduction.nl/docs/features/object-storage/) |
| 12 | "Other technologies (deployment, testing, etc.)" | PHPUnit, Jest, Playwright and Newman for tests, with PHPCS, PHPMD, Psalm, PHPStan, ESLint and Stylelint for analysis. GitHub Actions builds and releases, with a CycloneDX SBOM per release. | `composer.json`; `package.json`; `.github/workflows/`; [Development pipeline](https://docs.conduction.nl/WayOfWork/development-pipeline/); [Test matrix](https://docs.conduction.nl/WayOfWork/development-pipeline/#the-test-matrix); [Development environment](https://docs.conduction.nl/development-environment/) |

## The system

| # | Question | Our answer | Where |
|---|---|---|---|
| 13 | "Please describe the overall functionality of the system: What does the system do in terms of business functionality? What are some main functional scenarios?" | Administrators define registers and JSON Schemas; users, apps and external systems store validated objects in them. Search, audit trail, retention, events and file extraction come with every register. | [Feature overview](https://openregister.conduction.nl/docs/features/openregister-overview/); [Overview: what OpenRegister is](./index.md#what-openregister-is) |
| 14 | "Please describe or depict the boundaries of the system. A diagram/picture showing the system as a black box is highly appreciated: What are the actors/systems this system interacts with?" | An app inside a Nextcloud server, used by people in the browser, API clients and other Conduction apps. It relies on Nextcloud core and calls optional external services. | `ARCHITECTURE.md` (system boundary); `appinfo/routes.php`; [Integrations](https://openregister.conduction.nl/docs/Integrations/) |
| 15 | "Please describe the history of the system. When did the development start? How many FTE/teams have been involved during the development in the past (a rough estimation is enough)? How many FTE/teams are currently maintaining it?" | Started 24 August 2024 with a team of about six developers. Today a core team of three directs and reviews AI coding agents, which wrote most 2026 changes. | git log, authors per year; `Assisted-by` trailers on 5,290 of 11,009 commits since 9 August 2026 |

## Scope for maintainability

| # | Question | Our answer | Where |
|---|---|---|---|
| 16 | "What are the components of this system? In other words, if you are drawing a boxes-arrows diagram of the system: What are the boxes? What arrows/connections are expected between those boxes? What kind of arrows/connections are those (REST/API, code level dependencies like calls, inheritance, etc.)? Please provide such a diagram and fill in the following table with the list of all components along with a short description of their functionality together with the corresponding source code path(s)." | The components are the top-level directories: `lib/`, `src/`, `appinfo/`, `templates/`, `js/`, `docker/` and the root files. The frontend calls the backend over REST, and other apps call it through `lib/Contract/`. | `ARCHITECTURE.md` (components, inside the backend); `sigrid.yaml`; `openspec/architecture/adr-013-component-dependency-rules.md`, `adr-014-sig-components-are-top-level-directories.md` |
| 17 | "Which parts/directories/files of the system are not maintained by the development team or are not directly part of production code (test code, internal tooling, etc.)? Third-party libraries directly included as source code (like JavaScript libraries, etc.) without modification, Generated code? And if they are, or not touched/modified following generation? Migration, pre- and/or past- installation scripts, configuration files, etc.? Any other code part which is not in the (maintainability) responsibility of the development team?" | Frozen migrations, shipped third-party vocabularies, generated `l10n/*.js`, build output, tooling, specs, docs, demo environments and tests. `ARCHITECTURE.md` marks every directory, and `sigrid.yaml` excludes the generated and tooling paths. | `ARCHITECTURE.md` (directory roles); `sigrid.yaml`; `REUSE.toml` |
| 18 | "What kind of testing is performed (think about unit, integration, regression, end-to-end)? How can the test code be identified (where is it located)?" | Unit, integration, regression and end-to-end tests, all PHP tests in `tests/` and front-end tests as `*.spec.js` or `*.spec.ts`. About 32,700 PHPUnit test methods, 79.64% statement coverage and a test code ratio of about 159% (8 November 2026). | `ARCHITECTURE.md` (test code); `phpunit.xml`; [Testing](https://openregister.conduction.nl/docs/testing/); [Overview: how we test and merge](./index.md#how-we-test-and-merge); [Test matrix](https://docs.conduction.nl/WayOfWork/development-pipeline/#the-test-matrix) |

## Other remarks

| # | Question | Our answer | Where |
|---|---|---|---|
| 19 | "Please use the space below for any additional remarks" | OpenRegister is one core for every register, so it is larger than a single-purpose component: about 392,000 lines of PHP outside migrations and 115,000 lines of front end. We reduce coupling and parameter lists under recorded decisions, and AI-written code passes the same gates as hand-written code. | [Overview: one application from a communal core](./index.md#one-application-from-a-communal-core); `ARCHITECTURE.md`; `openspec/architecture/` |
| 20 | "How much time was spent filling in this form? Other feedback?" | Given on the submitted form. | |

See also the [security questionnaire](./security.md).
