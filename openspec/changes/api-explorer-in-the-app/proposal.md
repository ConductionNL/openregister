---
kind: code
depends_on: []
---

# Proposal: api-explorer-in-the-app

## Summary

A developer opens the API explorer from the register screen, tries a REST call or a GraphQL query against their own data, and copies a curl or JavaScript sample. The GraphQL explorer exists but loads its code from unpkg.com and is linked from nowhere; the REST documentation opens in an outside read-only viewer.

## The rows this closes

Source matrix: openregister `openspec/parity/capabilities.json` (comparedOn 2026-09-25). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### api-try-docs, try the API from an interactive documentation page with example code

Own rating `partial`, built.state `building`, owner `ConductionNL/openregister`, area `api`, source `competitor-derived`.

Matrix evidence, verbatim:

> GraphiQL explorer lib/Controller/GraphQLController.php:155 route appinfo/routes.php:1991 lets you run queries; REST docs open in external read-only Redoc (src/components/cards/RegisterSchemaCard.vue:910). No REST try-it, no generated code samples

Matrix note, verbatim:

> GraphiQL assets load from unpkg.com; no UI link to the explorer found in src/.

Competitor cells rated `yes`, verbatim:

- strapi: source read at v5.55.1, not driven: strapi:packages/plugins/documentation/server/src/public/index.html:44 Swagger UI bundle rendering the generated spec (:49 spec), served by the documentation plugin routes strapi:packages/plugins/documentation/server/src/routes/index.ts:6; Swagger UI gives try it out, but no generated client code snippets
- nocodb: source read at 2026.09.0, not driven: nocodb:packages/nocodb/src/controllers/api-docs/api-docs.controller.ts:81 swagger UI and :93 redoc per base; API snippets in the GUI (docs https://nocodb.com/docs/product/account-settings/cloud-enterprise-edition/community-vs-paid-editions lists API snippets and Swagger in Community Edition)

## Why

Two competitors ship an interactive API page with example code. OpenRegister generates an OpenAPI document per register and has a GraphQL explorer, but a developer has to know the explorer URL, the explorer breaks on an instance whose content security policy blocks unpkg.com, and the REST docs cannot run a call.

## What is built today

- `lib/Controller/GraphQLController.php:155` explorer loads GraphiQL, React and ReactDOM from unpkg.com (CSP allows it).
- REST docs open in an external Redoc from `src/components/cards/RegisterSchemaCard.vue`.
- OpenAPI generation per register (spec `oas-generation`).

## What changes

1. Serve the explorer assets from the app bundle and drop unpkg.com from the CSP.
2. Add an "API" entry on the register screen that opens an in-app page with a try-it panel over the register OpenAPI document (as the signed-in user) and the GraphQL explorer.
3. Each operation shows a curl and a JavaScript fetch sample.

## Out of scope

- SDK generation (change api-client-libraries).
