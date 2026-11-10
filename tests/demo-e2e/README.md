# Demo-environment end-to-end checks

Validates a running demo environment — the one a `<app>-compose.yaml` brings up —
against the steps its own documentation tells the reader to run.

## Why it lives outside `tests/e2e/`

`playwright.config.ts` at the repository root sets `testDir: './tests/e2e'`, so
anything placed there runs in CI. This suite needs an **already booted demo** to
point at, which CI does not have. Put here, CI never collects it, and there is no
skip to misread later: a suite that silently skips in CI looks identical to one
that ran and found nothing.

## Running it

Boot a demo first, then point the suite at it:

```bash
docker compose -f portaliq-compose.yaml up -d

DEMO_APP=portaliq \
DEMO_BASE_URL=http://localhost:8613 \
DEMO_HAS_PORTAL=1 \
npx playwright test --config tests/demo-e2e/playwright.config.ts
```

| Variable | Meaning |
| --- | --- |
| `DEMO_APP` | The app id under test. Default `portaliq`. |
| `DEMO_BASE_URL` | The booted demo. Default `http://localhost:8613`. |
| `DEMO_HAS_PORTAL` | Set to `1` when the demo installs `portaliq`; the two portal tests skip otherwise. |

`DEMO_HAS_PORTAL` is an explicit opt-in rather than an auto-detect, and that is
deliberate. A suite that decides for itself whether a portal *should* exist
cannot tell "this demo has no portal" from "the portal failed to seed", and
would report the second as a skip.

## What it asserts, and why none of it is a status code

Nextcloud serves its page shell before an app decides whether it has anything to
render, so an app URL returns HTTP 200 even when it resolves to nothing at all.
Two measurements from building this suite make the point:

- **The login page is served with HTTP 200.** Basic auth authenticates
  OpenRegister's API routes but not a browser *navigation* — Nextcloud redirects
  that to `/login`, which answers 200. A test asserting only on the status code
  passes while sitting on the login screen. That is why the page test logs in
  properly and then asserts on content and on the resulting URL.
- **An unauthenticated app URL answers 401 on a perfectly healthy demo.** The
  demo documentation used to describe exactly that request as a pass.

So the assertions are: `status.php` reports `installed:true` (an uninstalled
instance also answers 200); OpenRegister returns a non-empty register list (an
empty one means the configuration was never imported, which from outside is
indistinguishable from "nothing configured yet"); the app page renders its own
content; and, where a portal is installed, the portal API names a real site
rather than answering `{"error":"not_found"}` with a 200.

## It has been shown to fail

A suite that has only ever passed is not evidence. Both controls were run:

- pointed at an app that is not installed → the two app-reachability tests fail
- portal assertions forced on against a demo with no portal → both portal tests fail

Verified green against two independently booted demos: `portaliq` (6 passed) and
`shillinq` (4 passed, 2 correctly skipped).
