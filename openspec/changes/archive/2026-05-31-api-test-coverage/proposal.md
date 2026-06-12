# API Integration Test Coverage to 100%

## Why

OpenRegister exposes ~455 API routes across 85 controllers, but only ~71 (≈18.9%) are exercised by Newman/Postman collections today. Tender requirements (DON, MDTO, e-Depot consumers) demand demonstrable API stability, and downstream apps (opencatalogi, softwarecatalog, docudesk) regress silently when controller behaviour drifts. Without server-side coverage measured by PCOV during integration runs, regressions in error paths, auth matrices, and pagination land in production undetected. This change brings the integration suite to full route coverage and ties it to the CI quality gate.

## What Changes

- New Newman collections per API resource group covering full CRUD lifecycles (success + error paths, all HTTP error codes 400/401/403/404/409/422/500).
- Coverage of the 12 Settings sub-controllers (~90 routes) which currently have zero integration tests.
- GraphQL, MCP, webhook delivery/lifecycle, multi-tenancy isolation, and search/faceting/vector tests added.
- Authentication matrix tests (admin, regular user, public, no-auth) per route.
- Pagination, sorting, filtering tests on every list endpoint.
- File operations (upload, download, extraction, search, anonymization) and concurrency/race tests added.
- CI pipeline: PCOV-instrumented Newman runs producing server-side coverage reports; coverage gate added.
- Idempotent test data setup/teardown so suite is rerunnable; modular collection structure aligned with API domains.
- New composer scripts to run API coverage locally.

## Problem
Achieve 100% API route coverage with Newman integration tests and measure server-side code coverage from those tests using PCOV. Every API route defined in `appinfo/routes.php` SHALL have at least one Newman test covering the success path and one covering the error path.

## Proposed Solution
Achieve 100% API route coverage with Newman integration tests and measure server-side code coverage from those tests using PCOV. Every API route defined in `appinfo/routes.php` SHALL have at least one Newman test covering the success path and one covering the error path. The app defines **386 API routes** across 50 controllers (including 12 Settings sub-controllers) and 9 resource controllers. Existing coverage stands at ~18.9% (71 requests out of 386 routes). This spec defines the full test mat
