# Tasks: Resilient Per-Entity Config Import + No-User-Context Fallback

- [x] Implement: Per-register import resilience
- [x] Implement: Per-object import resilience for the main object loop
- [x] Implement: Seed-data import resilience
- [x] Implement: No-user-context acting-user fallback
- [x] Implement: Skipped-entity observability
- [x] Wire IGroupManager/IUserManager fallback-user resolver into ImportHandler DI
- [x] Add unit tests: bad seed object does not abort sibling register/schema import
- [x] Add unit tests: name-missing entity is skipped not fatal
- [x] Add unit tests: import under no user session still creates registers + schemas
- [x] Verify: php -l, openspec validate --strict, hydra gates, PHPUnit
