# Design: api-client-libraries

Read at openregister development c53dd0685c.

## D-1: two languages, chosen by who calls from outside

TypeScript and Python are official. TypeScript because all three competitors rated yes ship a JavaScript or TypeScript client first (directus, strapi, pocketbase), and because a browser portal or a Node integration is the most common outside caller. Python because data teams load and read registers from notebooks and scripts, and the fleet's own sidecars (the Python ExApps under `make check-strict`) are written in it.

Java, C# and PHP get a documented recipe, not a library: `openapi-generator` against `GET /api/versions/{version}/oas`. The recipe is tested in the TypeScript repository's CI for Java only, so the docs never describe a command that does not work. A third official library is a later change with its own demand row.

## D-2: a hand-written generic client, typed per register by generation

The generated OpenAPI documents are per register: `OasService::createOas()` (`lib/Service/OasService.php:222`) adds object paths per schema (`addCrudPaths`, `:813`). A client generated wholesale from them would be a different package per register. So each library is a small hand-written client over a fixed platform surface, with the register and schema as arguments, like the directus and pocketbase SDKs:

| method | route |
|---|---|
| `versions()` | `GET /api/versions` (`appinfo/routes.php:1684`) |
| `capabilities()` | `GET /api/capabilities` (`:1683`) |
| `objects.list(register, schema, query)` | `GET /api/objects/{register}/{schema}` (`:1167`) |
| `objects.get / create / update / patch / delete` | `:1179`, `:1174`, `:1180`, `:1181`, `:1183` |
| `objects.search(query)` | `GET /api/objects` (`:608`) |
| `files.list / upload / download` | `GET` and `POST /api/objects/{register}/{schema}/{id}/files` (`:1456-1458`), `GET /api/files/{fileId}/download` (`:1484`) |
| `audit.forObject(register, schema, id)` | `GET /api/objects/{register}/{schema}/{id}/audit-trails` (`:1344`) |
| `graphqlUrl()` | returns the URL of `POST /api/graphql` (`:1993`), no client |

Typing comes from a `generate` command in each library. `npx @conduction/openregister-client generate --url <base> --register zaken --out src/or-types.ts` reads `GET /api/versions/{N}/oas?register=zaken` (the `register` filter is read at `lib/Controller/ApiSurfaceController.php:241-249`) and writes types with `openapi-typescript`. The Python equivalent writes pydantic models with `datamodel-code-generator`. The generic methods take a type parameter, so `objects.get<Zaak>('zaken', 'zaak', id)` is typed without a package per register.

## D-3: library major N speaks contract N

The contract version is a digits-only major (`lib/Service/ApiVersion/ApiVersion.php:165`), negotiated through the `API-Version` request header (`lib/Service/ApiVersion/ApiVersionNegotiator.php:61`). The rule:

- Library major N sends `API-Version: N` on every request. It never omits it, so a server moving its default cannot move the client.
- Minor and patch releases add methods or fix bugs within contract N.
- On first use the client reads `GET /api/versions` once. If N is not listed as `supported` or `deprecated` (`lib/Controller/ApiSurfaceController.php:146-157`), it throws `UnsupportedContractVersion` naming the versions the instance serves.
- When a response carries `Deprecation` (added by `lib/Middleware/ApiVersionMiddleware.php:217-238`), the client warns once per process with the `Sunset` date and the successor from `Link`. The warning goes through the language's standard channel (`console.warn` or an `onDeprecation` callback; Python `warnings.warn` with `DeprecationWarning`).
- On a 410 the client throws `ContractWithdrawn` carrying `successorVersion` from the body. The middleware's refusal sets that key (`lib/Middleware/Exception/ApiVersionRefusedException.php:152-167`) and so does the document route (`lib/Controller/ApiSurfaceController.php:203-211`).
- Library major N keeps receiving security fixes until the sunset date of contract N in the catalogue Open Register ships.

## D-4: the caller record learns the client, from a closed pattern only

The caller record (`lib/Service/ApiCaller/ApiCallRecorder.php:152-178`) counts calls per principal, route, method and version in `openregister_api_calls` (created in `lib/Migration/Version1Date20260916070000.php:74-103`, unique index `idx_or_apicall_unique` over the four keys). This change adds a `client` column (string, 48, not null, default `''`) and rebuilds the unique index over the five keys in a new migration.

`ApiCallerMiddleware::afterController()` (`lib/Middleware/ApiCallerMiddleware.php:167-183`) passes a `client` value parsed from `User-Agent`, but only when the header matches `^openregister-client-(ts|python)/(\d+\.\d+\.\d+)`. Any other `User-Agent` records `''`. Recording the raw header would add a row per browser build and turn a caller record into a fingerprint store. The `GET /api/callers` answer (`appinfo/routes.php:1697`) gains the `client` field.

## D-5: Open Register's CI runs the published clients' contract suites

Each library publishes its contract suite in the package (`openregister-client contract-test --base-url <url> --user <user> --password <app password>`). `.github/workflows/api-test-coverage.yml` already boots an instance for the Newman suite. After Newman it installs the latest published release of each library for every contract major the instance serves and runs the suite. A pull request that breaks a published client fails there, before it merges. The library repositories run the same suite against Open Register's `development` branch on a daily schedule.

## D-6: repositories, packages and releases

- `ConductionNL/openregister-client-ts`, published to npm as `@conduction/openregister-client`, ESM and CommonJS builds, no runtime dependency beyond `fetch`.
- `ConductionNL/openregister-client-python`, published to PyPI as `openregister-client`, one runtime dependency (`httpx`), typed with `py.typed`.
- Both publish from a tag through GitHub Actions with provenance: npm `--provenance`, PyPI trusted publishing. No long-lived registry token lives in a repository secret.
- Both are EUPL-1.2 and carry an SBOM in the release.

## D-7: credentials stay with the caller

The client takes an app password (basic auth, `basicAuth` in `lib/Service/Resources/BaseOas.json`) or a bearer token (`oauth2`, or a scoped token once `scoped-api-tokens` lands) as a constructor argument. It never writes a credential to disk, never logs one, and redacts the `Authorization` header from any error it raises.

## Risks

- **Contract drift.** A library can call a route a later Open Register renames. D-5 makes that a red pull request in Open Register rather than a broken integrator.
- **Cardinality.** D-4 limits `client` to a closed pattern, so the caller record grows by at most the number of released library versions per principal and route.
- **Security.** The libraries add no server surface. The `client` value is parsed with an anchored pattern and length-capped before it reaches SQL through the mapper's parameter binding.
- **Maintenance cost.** Two libraries are two release trains. Keeping the surface to the table in D-2 is what makes that affordable; a method outside it needs a change like this one.
