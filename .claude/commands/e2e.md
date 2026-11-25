---
description: Run the Playwright end-to-end suite against a running Nextcloud with OpenRegister
argument-hint: "<base url> [spec path]"
---
Read `tests/e2e/README.md` first. The suite drives a live Nextcloud and writes fixtures into it.

1. The suite has no default address. Take the base URL from $ARGUMENTS, or ask for it. It refuses the shared development instance on `localhost:8080` unless the run opts in (`tests/e2e/shared-instance.ts`); do not opt in without being told to.
2. Install the browser once, then run:

   ```bash
   npm run test:e2e:install
   PLAYWRIGHT_BASE_URL=<base url> npm run test:e2e -- <spec path>
   ```

   Leave out the spec path to run the whole `chromium` project. `npx playwright test --list` shows the specs.
3. Report passed, failed, flaky and skipped counts. A skip is not a pass: name each skipped spec and why it skipped.
