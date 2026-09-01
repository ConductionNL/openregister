# Tasks — or-flow-object-write-skip-when

- [x] 1.1 `skipWhen` in `configKeys()` and `configForm()`.
- [x] 1.2 `isSkipped()` — narrow truthiness: `true` or the string `skip`.
      "false"/"0" are truthy in PHP and must NOT silence a write.
- [x] 1.3 Per-item path: emit unchanged, no write, no cap.
- [x] 1.4 Bulk path: contribute no row, emit in place; an all-skipped page
      calls `saveObjects()` not at all.
- [x] 1.5 Tests, including that `skipWhen` unset changes nothing.
