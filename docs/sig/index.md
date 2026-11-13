---
title: "Assessment questionnaires"
description: "How OpenRegister is built, specified and tested, and our answers to the maintainability and security questionnaires of an independent code assessment."
sidebar_position: 1
---

# Assessment questionnaires

An independent code assessment starts with two questionnaires that describe the system at a high level. This page tells the story behind our answers. The [maintainability questionnaire](./maintainability.md) and the [security questionnaire](./security.md) give the answers themselves, one table row per question.

## What OpenRegister is

OpenRegister stores structured data inside Nextcloud. Administrators define registers and JSON Schemas, and users and other apps store validated objects in them. The [feature overview](https://openregister.conduction.nl/docs/features/openregister-overview/) shows what it does.

## One application from a communal core

We build and maintain one application for any register, instead of an application per register. One abstract core costs less to maintain than many specific ones. [One core, many registers](https://docs.conduction.nl/strategy/one-core-many-registers/) sets out this principle for all our apps.

That makes OpenRegister larger than a single-purpose component such as Open Zaak. It is not larger than the stack it replaces, such as Open Zaak with Open Objecten, Open Notificaties and the other components that stack needs. We count every line of code as something to maintain, so the smallest footprint is the fewest lines across the whole stack.

## Nextcloud is the framework

We chose Nextcloud as the framework on purpose. It gives us authorisation, notifications, files and background jobs, so the stack needs no separate sidecar components for them. Sidecars such as search engines are never required: OpenRegister searches through the Nextcloud database.

We trust Nextcloud's security more than anything we would write ourselves, so we build on it. Login, sessions, single sign-on, LDAP accounts, second factor, CSRF tokens, controller attributes such as `#[NoAdminRequired]` and `#[PublicPage]`, rate limits, brute-force protection, `ICrypto` and `ICredentialsManager` all come from Nextcloud. On top we add schema and register authorisation, multi-tenancy by organisation and the audit trail. These use Nextcloud users and groups, never a user store of our own. [Building on Nextcloud's security](https://docs.conduction.nl/iso/security/#we-build-on-nextclouds-security) states this for every Conduction app, and the [security questionnaire](./security.md) names the code for each.

## Registers are configuration

A new register, for government or anyone else, is configuration on top of OpenRegister. Its registers, schemas, mappings and seed objects ship as a [configuration](https://openregister.conduction.nl/docs/Features/configurations/), not as a new application.

## How we specify and decide

Company-wide architecture decisions (ADRs) apply to every Conduction app. They live in Conduction's internal hydra repository, which is not public, and these pages cite them by number. ADR-005, for example, says apps use Nextcloud authentication only, and ADR-022 says apps reuse OpenRegister instead of building their own data layer. [Architecture decisions](https://docs.conduction.nl/WayOfWork/architecture-decisions/) lists every company-wide ADR with its status, and [Writing ADRs](https://docs.conduction.nl/claude/writing-adrs/) explains what goes in one.

Each app keeps its own decisions and specifications in its `openspec/` folder. OpenRegister's ADRs are in [`openspec/architecture/`](https://github.com/ConductionNL/openregister/tree/main/openspec/architecture), for example ADR-003 on the hash-chained audit trail, ADR-011 on parameter objects and ADR-014, which makes the top-level directories the components of the system.

A change starts as a proposal with specs, then becomes tasks, an implementation, a verification and finally an archived change. [Spec-driven development](https://docs.conduction.nl/WayOfWork/spec-driven-development/) describes this OpenSpec workflow, and [the OpenSpec commands](https://docs.conduction.nl/claude/commands-openspec/) list each step.

## How we test and merge

Every change runs the static analysis, unit tests and Hydra gates before it reaches `main`, where a review and six required checks guard the merge. [The development pipeline](https://docs.conduction.nl/WayOfWork/development-pipeline/), with [the test matrix](https://docs.conduction.nl/WayOfWork/development-pipeline/#the-test-matrix), and [the Hydra pipeline](https://docs.conduction.nl/hydra/pipeline-overview/) describe the steps, and [Testing](https://openregister.conduction.nl/docs/testing/) covers the suites in this repository. When hosted CI is unavailable, the same suite runs locally before a merge.

## Where to read more

Start with the [maintainability questionnaire](./maintainability.md) and the [security questionnaire](./security.md). [ARCHITECTURE.md](https://github.com/ConductionNL/openregister/blob/main/ARCHITECTURE.md) gives the full component description, and the answers describe the code as of 9 November 2026.
