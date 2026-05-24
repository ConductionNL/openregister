---
retrofit_extensions: [REQ-cache-warmup-jobs]
---

### Requirement: UUID-to-name distributed cache MUST be pre-warmed via background jobs

The system MUST pre-populate the distributed UUID-to-name label cache (used by `MagicFacetHandler` for facet label resolution) via two complementary `TimedJob` background jobs, to eliminate cold-start delays on the first facet query of the day. Cache warmup MUST be implemented as calls to `CacheHandler::warmupNameCache()`, which loads names from organisations, the objects table, and magic tables into the distributed cache.

The two jobs MUST coexist with distinct cadences:

1. A **configurable recurring** warmup (`CacheWarmupJob` extending `TimedJob`) that reads its interval from `IAppConfig` key `cache_warmup_interval` (default `3600` seconds; value `0` MUST disable the job by setting an effectively infinite interval). The job MUST write the last-run timestamp to `IAppConfig` key `cache_warmup_last_run` after a successful pass. The job MUST log execution time and number of names loaded.
2. A **fixed nightly** warmup (`NameCacheWarmupJob` extending `TimedJob`) with a hard-coded interval of 24 hours. Not user-configurable; provides the daily floor.

Both jobs MUST catch exceptions during warmup and log them as errors without crashing the cron worker. Neither job MUST throw — a failing warmup MUST NOT prevent the next interval's attempt.

#### Scenario: Configurable warmup runs at the configured interval

- **GIVEN** `cache_warmup_interval` is set to `3600` (1 hour) in `IAppConfig`
- **WHEN** the Nextcloud cron triggers `CacheWarmupJob`
- **THEN** the job MUST call `CacheHandler::warmupNameCache()` to populate the distributed cache
- **AND** the job MUST write `cache_warmup_last_run` with the current timestamp on success
- **AND** the job MUST log `[CacheWarmupJob] Cache warmup completed` with `names_loaded` and `execution_time` context

#### Scenario: Configurable warmup is disabled when interval is zero

- **GIVEN** `cache_warmup_interval` is set to `0`
- **WHEN** the cron worker would normally invoke `CacheWarmupJob::run`
- **THEN** the job MUST short-circuit with `[CacheWarmupJob] Cache warmup is disabled (interval set to 0), skipping`
- **AND** MUST NOT call `CacheHandler::warmupNameCache()`
- **AND** MUST NOT update `cache_warmup_last_run`

#### Scenario: Nightly warmup runs unconditionally every 24 hours

- **GIVEN** `NameCacheWarmupJob` is registered as a `TimedJob` with 86400-second interval
- **WHEN** the Nextcloud cron triggers the job
- **THEN** it MUST call `CacheHandler::warmupNameCache()` regardless of the `cache_warmup_interval` setting
- **AND** MUST log `[NameCacheWarmupJob] 🌙 Name Cache Nightly Warmup Job Started` with `scheduled_time` and `timezone` context
- **AND** on success MUST log `[NameCacheWarmupJob] ✅ Name Cache Nightly Warmup Job Completed` with `names_loaded`

#### Scenario: Warmup failures do not crash the cron worker

- **GIVEN** `CacheHandler::warmupNameCache()` throws an exception (e.g. database unavailable)
- **WHEN** either warmup job runs
- **THEN** the job MUST catch the exception and log it as an error with `error` and `execution_time` context
- **AND** MUST NOT re-throw
- **AND** the next scheduled invocation MUST proceed normally
