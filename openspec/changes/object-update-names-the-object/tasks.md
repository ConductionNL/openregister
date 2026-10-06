# Tasks: object-update-names-the-object

## 1. Register events

- [x] 1.1 `RegisterMapper::update()` dispatches `RegisterUpdatedEvent` only when the stored register changed (timestamp excluded).
- [x] 1.2 Unit test driving the real `update()` with real `Register` and `RegisterUpdatedEvent`: unchanged save dispatches nothing, a title change dispatches one event. Fails on the old code.

## 2. Object activity and notification text

- [ ] 2.1 `ActivityService` adds the schema title to object activity parameters.
- [ ] 2.2 `ProviderSubjectHandler` renders `{schema} {title} updated` (and created, deleted) when the schema is known.
- [ ] 2.3 `AnnotationNotifier` drops the register clause when no register name is known.
- [ ] 2.4 en and nl strings; unit tests for 2.1 to 2.3.
