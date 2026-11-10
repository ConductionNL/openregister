# Tasks: example-set-daily-refresh

- [x] `ExampleSetDailyRefresh`: the marked sets (app config `example_set_daily_refresh`), mark/unmark, run (refresh every marked installed set)
- [x] `ExampleSetDailyRefreshJob` (TimedJob, daily) in info.xml; `--daily` / `--no-daily` on the install command
- [x] Tests (failing first): a marked set refreshed against the run day, an unmarked one left, a removed one skipped; the command marks and unmarks; the job logs each set
