# Tasks: api-explorer-in-the-app

Spec: `specs/graphql-api/spec.md#requirement-req-apix-001-the-api-can-be-tried-from-inside-the-app` for every task.

## 1. Bundle the GraphQL explorer and drop unpkg.com

- [ ] 1.1 Add `graphiql` 5.x with its peers `react` and `react-dom` 18 and `graphql` 16 to `package.json` (versions checked with `npm view` before adding), and a webpack entry `graphqlExplorer` in `webpack.config.js` pointing at a new `src/graphqlExplorer.js`. That file mounts GraphiQL with a fetcher that posts to `generateUrl('/apps/openregister/api/graphql')` through `@nextcloud/axios`, so the session and `requesttoken` are sent.
- [ ] 1.2 In `lib/Controller/GraphQLController.php`, make `explorer()` return a `TemplateResponse` on a new `templates/graphqlExplorer.php` that loads the bundled entry with `Util::addScript('openregister', 'openregister-graphqlExplorer')`. Remove the inline HTML (the `graphiql@3` stylesheet and scripts from unpkg.com) and the `addAllowedScriptDomain('https://unpkg.com')`, `'unsafe-inline'`, style and font domain entries.
- [ ] 1.3 Red first: extend `tests/Unit/Controller/GraphQLControllerExplorerTest.php` so the explorer response's CSP holds no `unpkg.com` and no `'unsafe-inline'`.
- [ ] 1.4 `tests/e2e/ci/api-explorer.spec.ts`: open `/apps/openregister/api/graphql/explorer`, run `{ __typename }`, read the result, and assert no request left the instance.

## 2. API page with try-it and samples

- [ ] 2.1 Register a page in `src/manifest.json`: `id: registerApi`, `route: /registers/:id/api`, `type: custom`, `component: RegisterApi`, with a `_note` naming REQ-APIX-001.
- [ ] 2.2 `src/views/register/RegisterApi.vue`: read `/api/registers/{id}/oas` and list each schema's operations (list, read, create, update, patch, delete). "Try it" fills the path and body, runs the call as the signed-in user through `@nextcloud/axios`, and shows the status and JSON response. A link opens the GraphQL explorer from task 1.
- [ ] 2.3 `src/services/apiSamples.js`: a pure function from an operation, its parameters and the instance URL to a curl sample (with an app password placeholder) and a `fetch` sample. `RegisterApi.vue` shows both under each operation.
- [ ] 2.4 Add an "API" action to the register screen header in `src/views/register/RegisterDetail.vue` that routes to `registerApi`.
- [ ] 2.5 Red first: `src/services/apiSamples.spec.js` asserts the curl and fetch samples for a list and a create call.
- [ ] 2.6 Extend `tests/e2e/ci/api-explorer.spec.ts`: from the register screen open API, run the list operation on a seeded schema, see the seeded object in the response and the curl sample for the same call.

## 3. Verification

- [ ] 3.1 Each task above has a PHPUnit or jest test that is red before the code and green after.
- [ ] 3.2 Run `composer check:strict`, `npm run lint`, `stylelint`, `test:l10n`, `check:specs` and the hydra gates once before push.
- [ ] 3.3 `openspec validate api-explorer-in-the-app --strict`.
