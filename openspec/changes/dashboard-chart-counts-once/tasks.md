# Tasks

## 1. Server

- [x] 1.1 `MagicStatisticsHandler::countedPairs()` preloads the pairs' schemas in one query (`preloadUnfiltered`), failure falls back to single reads.
- [x] 1.2 `countedPair()` reuses a successful count inside a `rememberPairCounts()` window (own `chart:` key; failures stay out).
- [x] 1.3 `DashboardService::countingPairsOnce()`; `DashboardController::getPanels()` runs its panels in one window (`panelReads()` split out).

## 2. Verification

- [x] 2.1 PHPUnit: `MagicStatisticsHandlerPairWalkTest` (one schema read; failed bulk read; each table counted once for both charts in a window, again outside; a failing pair out of both charts), `DashboardControllerTest`.
- [x] 2.2 Live on the sandbox reference stack: chart and panels TTFB before and after, answers identical, in the PR body.
