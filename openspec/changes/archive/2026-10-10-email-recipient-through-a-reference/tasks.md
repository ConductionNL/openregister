# Tasks: an email recipient reads its address through a reference

- [x] 1. `lib/Service/Notification/ReferencedAddressNotifier.php`: path resolution through references (system read, tenant-bounded, max three hops), integriq question, send. Test: `tests/Unit/Service/Notification/ReferencedAddressNotifierTest.php`.
- [x] 2. `AnnotationNotificationDispatcher::dispatchToAddresses()`: sends `email` entries on rules with the email channel, records history, counts reach. Test: `AnnotationNotificationDispatcherTest::testAnEmailRuleIsSentThroughTheReferencedAddressNotifier`, `::testAnEmailEntryWithoutTheEmailChannelIsNotSent`.
- [x] 3. `NotificationAnnotationValidator`: kind `email` accepted; four refusal codes. Test: `NotificationAnnotationValidatorTest::testAnEmailRecipientNeedsADeclaredFieldAndTheEmailChannel`.
- [x] 4. Spec: REQ-ERO-007 in `openspec/specs/external-recipient-opt-out/spec.md`.
