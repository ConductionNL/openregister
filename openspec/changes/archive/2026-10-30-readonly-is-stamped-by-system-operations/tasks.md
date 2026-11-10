# Tasks

- [x] 1.1 `ObjectService::enforceReadOnlyOnUpdate` skips the readOnly rule inside a SystemOperationContext, keeps immutable
- [x] 2.1 Tests: a client update is refused, the same write inside `SystemOperationContext::run()` is allowed, immutable still holds
