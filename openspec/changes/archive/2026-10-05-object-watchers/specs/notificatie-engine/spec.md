# notificatie-engine

## ADDED Requirements

### Requirement: A notification rule may address the object's watchers

A `recipients` block in `x-openregister-notifications` SHALL accept
`{"watchers": true}`. The dispatcher SHALL resolve it at dispatch time to
the users watching the triggering object, merged and deduplicated with the
other recipient blocks, subject to each user's preferences and to a read
check on the object. A watcher who may no longer read the object SHALL
receive nothing and SHALL be removed from the watcher list.

#### Scenario: a watcher is told about a status change

- **GIVEN** a schema whose `status-changed` rule declares `recipients: [{"watchers": true}]` and a user watching one object
- **WHEN** the object's status changes
- **THEN** the watcher receives the notification once, whether or not they are also the assignee
- @e2e exclude {delivery runs through the queue and a background job, so an e2e assertion would be a timing race; asserted by NotificationRecipientResolverWatchersTest::testWatchersAreResolvedToUids and ::testAWatcherWhoIsAlsoTheAssigneeIsToldOnce}

#### Scenario: a watcher who lost access hears nothing

- **GIVEN** a watcher whose group membership no longer grants read on the object
- **WHEN** a rule addressed to watchers fires
- **THEN** the user receives nothing and is no longer listed as a watcher
- @e2e exclude {same timing race as above; asserted by NotificationRecipientResolverWatchersTest::testAWatcherWhoLostReadIsSkippedAndDropped}

#### Scenario: the block is validated at schema save

- **GIVEN** a rule with `recipients: [{"watchers": "yes"}]`
- **WHEN** the schema is saved
- **THEN** the save fails with HTTP 422
- `@e2e tests/e2e/ci/object-watchers.spec.ts` and NotificationAnnotationValidatorWatchersTest
