# Tasks: or-flow-native-triggers

- [x] `NativeFlowTriggerListener` — file (created/written/deleted) + user
      (created/deleted) events -> triggers with a defensively-read payload, empty
      subject.
- [x] `FlowRunWorker` seeds a subjectless run's first item from context.payload.
- [x] Event catalog gains the File and User trigger groups.
- [x] Register the listener on the five events in Application.php.
- [x] NativeFlowTriggerListenerTest — file.created payload, file.deleted, user
      uid, empty subject, unrelated event, user attribution (6 tests). phpcs clean.
- [x] Live-verified on 8080: catalog lists file.*/user.*; listener DI; a
      subjectless payload-carrying run completed with the file path as item 1.
- [ ] Share / tag / calendar / scheduled triggers — follow-ups on the same mechanism.
