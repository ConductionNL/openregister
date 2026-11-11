# Tasks

- [x] Retry a failed listed object once after the rest of the list (`ImportHandler::importListedObject()`).
- [x] Name failed objects in `failed.objects` (`ImportHandler::recordFailedObject()`).
- [x] Withhold the content hash when a listener refused a listed object.
- [x] Take a higher carried `@self.version` on update (`ObjectVersionHandler::advanceVersion()`, `SaveObject::prepareObjectForUpdate()`).
- [x] Read an array search result without calling `jsonSerialize()` on it.
- [x] Unit tests through `importFromJson()`: `ImportHandlerSeedObjectUpgradeTest`, `ObjectVersionHandlerTest`.
