# Design: api-explorer-in-the-app

Read at openregister development `b876628280`.

## What exists

| Piece | Where |
|---|---|
| GraphQL explorer | `lib/Controller/GraphQLController.php` explorer |
| Register card | `src/components/cards/RegisterSchemaCard.vue` |

## Approach

1. Bundle a Swagger-UI style try-it component and GraphiQL through webpack as a separate lazy chunk; the explorer page loads it from the app.

## Declarative or imperative

Imperative UI.

## Tests

- PHPUnit: the explorer response CSP has no unpkg.com.
- vitest: the register screen links to the API page; a sample is rendered per operation.
