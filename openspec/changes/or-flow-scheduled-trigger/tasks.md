# Tasks: or-flow-scheduled-trigger

- [x] `FlowScheduleService.fireDueFlows()` — cron dueness + queue + last-fire in
      app config (never rewrites the flow object).
- [x] `FlowScheduleWorker` TimedJob (5-min), registered in info.xml.
- [x] `flow` schema gains `cron`; descriptor version bumped (fresh installs).
- [x] FIX `OpenRegisterFlowResolver::flowsForTrigger` findAll key (config.filters)
      — the wrong key silently disabled every event trigger.
- [x] FlowScheduleServiceTest (6): due fires, recent not due, disabled, non-
      schedule, invalid/missing cron, no store. phpcs clean.
- [x] Playwright e2e (flow-schedule.spec.ts, 2): due flow fired by the worker
      shows in history; non-schedule flow not fired. All 9 flow e2e pass.
- [x] Live-verified on 8080: worker execute queues a schedule run; findAll fix
      makes flows match.
- [ ] HermiqFlowResolver same findAll fix — its own repo.
