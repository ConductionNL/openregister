# Tasks — listener bundle reverse-spec

Each task back-annotates one listener method against its target REQ. Tasks 1–6 extend `computed-fields`; tasks 7–12 extend `realtime-updates`; tasks 13–14 extend `register-i18n`.

## computed-fields-on-save → computed-fields

- [x] task-1: computed-fields#Save-Time Materialisation of Declared Calculations — `CalculationOnSaveListener::__construct` wires SchemaMapper + CalculationEvaluator + LoggerInterface (retroactive annotation)
- [x] task-2: computed-fields#Save-Time Materialisation of Declared Calculations — `CalculationOnSaveListener::handle` dispatches ObjectCreatingEvent / ObjectUpdatingEvent into the materialiser (retroactive annotation)
- [x] task-3: computed-fields#Save-Time Materialisation of Declared Calculations — `CalculationOnSaveListener::process` injects synthetic `@self`, evaluates each `materialise: true` calc, patches changed values into the payload before persist (retroactive annotation)
- [x] task-4: computed-fields#Save-Time Materialisation of Declared Calculations — `CalculationOnSaveListener::serialise` renders DateTimeInterface results to ATOM for JSON storage (retroactive annotation)
- [x] task-5: computed-fields#Save-Time Materialisation of Declared Calculations — `CalculationOnSaveListener::loadSchema` resolves the object's schema (multitenancy-bypass lookup) (retroactive annotation)
- [x] task-6: computed-fields#Save-Time Materialisation of Declared Calculations — `CalculationOnSaveListener::getCalculations` reads the `x-openregister-calculations` config block (retroactive annotation)

## realtime-updates-fanout → realtime-updates

- [x] task-7: realtime-updates#The system MUST manage NotifyPushListener static state and resolve slugs at system level — `NotifyPushListener::resetStaticState` resets all four static accumulators for test isolation (retroactive annotation)
- [x] task-8: realtime-updates#The system MUST manage NotifyPushListener static state and resolve slugs at system level — `NotifyPushListener::resolveQueue` lazily resolves IQueue with a per-request single-DEBUG soft-fail latch (retroactive annotation)
- [x] task-9: realtime-updates#The system MUST manage NotifyPushListener static state and resolve slugs at system level — `NotifyPushListener::resolveRegisterSlug` resolves register UUID→slug bypassing RBAC + multitenancy (issue #1454) (retroactive annotation)
- [x] task-10: realtime-updates#The system MUST manage NotifyPushListener static state and resolve slugs at system level — `NotifyPushListener::resolveSchemaSlug` resolves schema UUID→slug bypassing RBAC + multitenancy (issue #1454) (retroactive annotation)
- [x] task-11: realtime-updates#The system MUST record object lifecycle events as CloudEvents in the realtime log — `RealtimeEventListener::__construct` wires the RealtimeService recorder (retroactive annotation)
- [x] task-12: realtime-updates#The system MUST record object lifecycle events as CloudEvents in the realtime log — `RealtimeEventListener::handle` records Created/Updated/Deleted/Transitioned events (with transition metadata) via RealtimeService::record() (retroactive annotation)

## translation-projection-on-write → register-i18n

- [x] task-13: register-i18n#The system MUST project translatable content into the sidecar on object lifecycle events — `TranslationProjectionListener::__construct` wires the TranslationProjectionService (retroactive annotation)
- [x] task-14: register-i18n#The system MUST project translatable content into the sidecar on object lifecycle events — `TranslationProjectionListener::handle` projects on Created/Updated/Transitioned and purges on Deleted (retroactive annotation)
