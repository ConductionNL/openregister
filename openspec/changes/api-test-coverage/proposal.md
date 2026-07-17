# API Integration Test Coverage to 100%

## Problem
Achieve 100% API route coverage with Newman integration tests and measure server-side code coverage from those tests using PCOV. Every API route defined in `appinfo/routes.php` SHALL have at least one Newman test covering the success path and one covering the error path.

## Proposed Solution
Achieve 100% API route coverage with Newman integration tests and measure server-side code coverage from those tests using PCOV. Every API route defined in `appinfo/routes.php` SHALL have at least one Newman test covering the success path and one covering the error path. The app defines **386 API routes** across 50 controllers (including 12 Settings sub-controllers) and 9 resource controllers. Existing coverage stands at ~18.9% (71 requests out of 386 routes). This spec defines the full test mat
