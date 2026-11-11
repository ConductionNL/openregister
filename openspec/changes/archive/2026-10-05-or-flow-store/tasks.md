# Tasks: or-flow-store

- [x] `lib/Settings/flow_register.json` — flows register + flow schema descriptor.
- [x] `ImportFlowRegister` repair step (importFromApp, idempotent, never throws).
- [x] Register in info.xml (install + post-migration).
- [x] Live-verified on 8080: the step runs cleanly ("Flow register imported");
      the flows register + flow schema are present.
- [x] Playwright e2e (api-direct/flow-engine.spec.ts): author a flow in the store
      and run it end-to-end via /api/flow-runs/test.
