# Tasks: publication-tells-its-source

## 1. The payload

- [x] 1.1 `WebhookEventListener` adds `newObject` and `oldObject` to the
      `ObjectUpdatedEvent` payload; `object` is unchanged. Verify:
      `tests/Unit/Listener/WebhookEventListenerTest.php::testObjectUpdatedEventCarriesBothStates`
      and the regenerated `webhook-payload-golden.json`.
- [x] 1.2 A filter on both states passes only the changing update. Verify:
      `tests/Unit/Service/WebhookServiceTest.php::testPassesFiltersOnBothStatesMatchesOnlyTheChangingUpdate`.
