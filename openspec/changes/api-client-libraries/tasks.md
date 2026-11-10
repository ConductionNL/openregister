# Tasks: api-client-libraries

## 1. Open Register side

- [ ] 1.1 Migration adding `client` (string 48, not null, default `''`) to `openregister_api_calls` and rebuilding `idx_or_apicall_unique` over principal, route, method, api_version and client; `ApiCallRecord` and `ApiCallRecordMapper::count()` take `client`. Verify: `tests/Unit/Db/ApiCallRecordMapperTest.php` counts two clients on one route as two rows; `occ migrations:status openregister` shows the migration applied on a copy of development data.
- [ ] 1.2 `ApiCallerMiddleware` parses `client` from `User-Agent` with the anchored pattern in design D-4 and passes it to the recorder; `GET /api/callers` returns it. Verify: `tests/Unit/Middleware/ApiCallerMiddlewareTest.php` records `openregister-client-ts/1.4.0` and records `''` for a browser user agent.
- [ ] 1.3 Add the contract-suite step to `.github/workflows/api-test-coverage.yml` that installs the latest published release of each library per served contract major and runs `contract-test` against the booted instance. Verify: the step is skipped with a named reason until the first release exists, then passes on development.

## 2. TypeScript library

- [ ] 2.1 Create `ConductionNL/openregister-client-ts` with the client over the surface in design D-2, the version handling in D-3 and credential handling in D-7. Verify: `npm test` runs unit tests for the `API-Version` header, the one-time deprecation warning and `ContractWithdrawn` on a 410.
- [ ] 2.2 Add the `generate` command writing register types with `openapi-typescript`. Verify: a test generates types for a fixture document and `tsc --noEmit` compiles a typed `objects.get<Zaak>()` call.
- [ ] 2.3 Add the `contract-test` command, the daily run against Open Register `development`, and the tagged publish to npm with provenance. Verify: the contract suite passes against a local instance; a dry-run publish prints the provenance statement.

## 3. Python library

- [ ] 3.1 Create `ConductionNL/openregister-client-python` with the same surface, version and credential handling. Verify: `pytest` covers the same three behaviours as 2.1; `ruff` and `mypy --strict` pass.
- [ ] 3.2 Add `generate` writing pydantic models with `datamodel-code-generator`, `contract-test`, the daily run and trusted publishing to PyPI. Verify: the contract suite passes against a local instance; a TestPyPI release installs and imports.

## 4. Docs and end-to-end test

- [ ] 4.1 Add `docs/api/client-libraries.md`: install, authenticate, the version rule in design D-3, typed generation, and the `openapi-generator` recipe for Java, C# and PHP. Verify: `npm run build` in `docs/` succeeds; the Java recipe is run in the TypeScript repository's CI and compiles.
- [ ] 4.2 Add `tests/e2e/ci/api-client-libraries.spec.ts`: a call with the TypeScript client's `User-Agent` shows its client name and version in the caller record read by an administrator, and a deprecated contract answers with `Deprecation`. Verify: the spec runs green in the Playwright CI project.

## Acceptance

- A developer can install either library, point it at an instance, and list objects of a schema in under ten lines.
- An administrator can read which client version each principal uses.
