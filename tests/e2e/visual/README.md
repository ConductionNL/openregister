# Visual-regression layer (GAP-5)

Playwright visual baselines for this app's key surfaces.

## Run

```bash
# Verify current UI against the committed baselines
npx playwright test --project visual

# Re-baseline (after an intentional UI change)
npx playwright test --project visual --update-snapshots
```

Baselines are committed PNGs under `*.visual.spec.ts-snapshots/` and are
**intentionally tracked reference images** — they are the source of truth the
assertion compares against.

## Determinism

Every shot (see `_visual-helpers.ts`) is taken with:

- fixed `1280x800` viewport (set on the `visual` project in `playwright.config.ts`),
- an authenticated session reused from the app's `globalSetup` / storageState,
- CSS animations / transitions / caret blink disabled,
- the auto-opening `cn-support-dialog` dismissed and hidden,
- dynamic regions masked (dates, ids, avatars, live counts, relative times),
- a wait for *content* (no spinners / skeletons / "Loading…" text) before the shot,
- `maxDiffPixelRatio: 0.02` to absorb sub-pixel font hinting.

A baseline that does not reproduce a 0-diff on a clean re-run is made
deterministic or dropped — a flaky baseline is worse than none.

## CI wiring + PLATFORM CAVEAT (honest)

The visual project is wired into the live-NC CI workflow
(`.forgejo/workflows/tests-live.yml`, `run-visual` input) as a **NON-GATING**
step (`continue-on-error: true`).

PNG baselines are rendered by the host's font stack + GPU. A baseline shot
against the **local dev container** will **not** byte-match the same page
rendered on a **CI Linux runner**. The committed baselines here are
dev-container native. Therefore, before the CI visual step can gate:

1. Run the workflow with `PLAYWRIGHT_UPDATE=1` so the step regenerates
   CI-native baselines (`--update-snapshots`).
2. Download the uploaded `visual-snapshots-<app>` artifact and commit the
   CI-native PNGs.
3. Drop `continue-on-error` on the visual step to make it gating.

Until then the CI step only surfaces diffs as an artifact and never blocks a PR.
