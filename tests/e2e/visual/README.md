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

The visual project is **not wired into CI**. Its only wiring was the
Forgejo/Codeberg `tests-live.yml` `run-visual` input — a non-gating
(`continue-on-error: true`) step that GitHub Actions never executed
(`.forgejo/**` is ignored there) and that has been removed now that Codeberg
is retired. Run the project locally instead.

PNG baselines are rendered by the host's font stack + GPU. A baseline shot
against the **local dev container** will **not** byte-match the same page
rendered on a **CI Linux runner**. The committed baselines here are
dev-container native. Therefore, before a GitHub-side visual step could gate:

1. Run it with `PLAYWRIGHT_UPDATE=1` so the step regenerates CI-native
   baselines (`--update-snapshots`).
2. Download the uploaded snapshot artifact and commit the CI-native PNGs.
3. Make the step gating (drop `continue-on-error`).

Until such a job exists on GitHub Actions, nothing surfaces these diffs in CI.
