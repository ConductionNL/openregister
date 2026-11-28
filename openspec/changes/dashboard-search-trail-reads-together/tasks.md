# Tasks

## 1. Reads

- [x] 1.1 `DashboardIndex.loadSearchTrailData()` sends both reads together (`Promise.allSettled`) and falls back to the empty defaults after both settled when either failed.

## 2. Verification

- [x] 2.1 Jest: `DashboardIndex.methods.spec.js` (both sent before the statistics answer; fallback only after both settled; popular-terms failure falls back too); golden snapshot updated for the statistics-failure path.
- [x] 2.2 Live on the sandbox reference stack: OR Dashboard total before and after, in the PR body.
