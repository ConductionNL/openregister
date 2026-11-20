# Contract design: the Notifications area

Wave 5 design for the Notifications area (rank 2, directly above Core). It changes nothing in `lib/`; it is the plan
the wave 5 build packages for this area carry out.

Measured on main `e918c58dd1` with `modularity_check.py` (whole app: 158,870 of 459,141 lines exposed,
Kernel 10,282, violation degree 0.1121, 951 references against the order) and the module graph of
the maintainability checker. Line counts are code lines as the scorer counts them (no comments,
no blank lines).

## Summary

| | Before | After |
|---|---|---|
| Files of the area | 361 | about 355 (about 37 rows out, 14 in, 15 new ports and implementations) |
| Lines of the area | 19,880 | about 19,400 |
| Exposed files | 75 | 27 |
| **Exposed lines** | **1,696** | **about 360** |
| References against the order with a Notifications end | 85 (66 out, 19 in from Core) | 37 out (wave 4), 2 in (left to the Core design) |

Notifications exposes 8.5% of its code today. After this design it exposes about 1.9%, under the 2.75% the
whole app needs for 4.5. Of the about 1,337 lines that stop being exposed, about 1,245 leave exposure
altogether and about 91 stay exposed in another area because the class now counts to the area whose work it
does; the two Core-owned ports add about 23 new lines to Core (section 4, so the owners of those designs can
count them).

Three facts shape the design:

1. **Most exposure is not a contract problem.** 900 of the 1,696 lines are classes that do another area's work
   (chat messages, webhooks, mail-app links, the activity filter, scheduled-report bags), wiring that sits in
   the wrong registrar (six listeners named by the Processes listener registrar), or a schema check Core
   constructs itself. Those go by rules 3 and 5 of `BRIEF.md` and by the positioned declaration checks of #10685.
2. **The real contracts are small.** What outside callers need from Notifications is ten capabilities. Nine
   become ports in `lib/Kernel/Contracts/Notifications/` (114 lines measured on drafts), the tenth is the
   plug-in port fleet apps already implement.
3. **The floor is the messaging value objects.** The flow messaging code in Processes builds 13 Notifications
   value objects (170 lines) to send mail, in-app notices, push and Talk messages. They stay exposed in this
   design; a later step that moves message composition behind one `deliver()` port is described under
   "Findings", not planned here.

## 1. The exposed files today

Every Notifications file another area references, grouped by what the reference is for. "Outside callers" are
the referencing files, per area.

### Wiring (321 lines)

| File | Lines | Outside callers |
|---|---|---|
| `lib/Notification/NotifierRegistrar.php` | 10 | Platform: `AppInfo/Application` |
| `lib/Notifications/NotificationsRegistrar.php` | 10 | Platform: `AppInfo/Application` |
| `lib/Notifications/Listener/Registrar/NotificationsListenerRegistrar.php` | 9 | Processes: `Listener/ListenerRegistrar` |
| `lib/Notifications/Listener/ActivityEventListener.php` | 46 | Processes: `Listener/ListenerRegistrar` |
| `lib/Notifications/Listener/GraphQLSubscriptionListener.php` | 31 | Processes: `Listener/ListenerRegistrar` |
| `lib/Notifications/Listener/NotificationDedupeAnnotationSyncListener.php` | 61 | Processes: `Listener/ListenerRegistrar` |
| `lib/Notifications/Listener/NotificationDedupePruneListener.php` | 31 | Processes: `Listener/ListenerRegistrar` |
| `lib/Notifications/Listener/SystemEntityNotificationListener.php` | 106 | Processes: `Listener/ListenerRegistrar` |
| `lib/Listener/NotificationsAnnotationInstallListener.php` | 17 | Processes: `Listener/ListenerRegistrar` |

### Message delivery for flows, view alerts and referenced addresses (236 lines)

All in `lib/Notifications/Service/Notification/` except `lib/Push/PushChannels.php`.

| File | Lines | Outside callers |
|---|---|---|
| `EmailSenderInterface` | 9 | Processes: `FlowMessagingService`, `FlowMessagingChannels`, `ViewAlertCrossedListener`; Search: `ViewAlertMail`; Configuration: `AddressDeliveryServices` |
| `NcNotificationSenderInterface` | 7 | Processes: `FlowMessagingChannels` |
| `TalkSenderInterface` | 7 | Processes: `FlowMessagingChannels`, `FlowMessagingService` |
| `NotificationChannelPolicyInterface` | 7 | Processes: `FlowMessagingChannels`, `FlowMessagingService` |
| `NotificationTemplatingInterface` | 6 | Processes: `FlowMessagePreview`, `FlowMessagingPlatform` |
| `NotificationPreferenceServiceInterface` | 6 | Processes: `FlowMessagingRecipients` |
| `NotificationRecipientResolverInterface` | 10 | Processes: `FlowMessagingRecipients`, `FlowRecipientAddresses`; Search: `ViewAlertAudience` |
| `RateLimiterInterface` | 6 | Processes: `FlowMessagingRecipients` |
| `OptOutAuthorityInterface` | 11 | Processes: `FlowMessagingService`, `FlowMessagingOverrides`, `Nodes/SendEmailNode`; Configuration: `AddressDeliveryServices`, `ReferencedAddressNotifier` |
| `InAppNotice`, `NoticeContent`, `NoticeRule`, `PushRideAlong`, `TalkMessage`, `TalkSendException`, `PlaceholderValues`, `PreferenceResolution`, `PreferenceTopic` | 13+12+12+13+10+6+11+12+19 | Processes: `FlowMessagingService` (`PlaceholderValues` also `FlowMessagePreview`) |
| `MailAddressee`, `MailContent`, `OptOutQuestion` | 10+11+18 | Processes: `FlowMessagingService`; Configuration: `ReferencedAddressNotifier` |
| `RecipientSource` | 13 | Processes: `ViewAlertCrossedListener`, `FlowRecipientAddresses` |
| `lib/Push/PushChannels.php` | 7 | Platform: `Listener/NotifyPushListener` |

### Scheduled messages (376 lines)

| File | Lines | Outside callers |
|---|---|---|
| `Service/Notification/ScheduledMessageScheduler.php` | 111 | Integrations: `Controller/MessageDispatchController`, `Controller/ScheduledMessagesController` |
| `Service/Notification/ScheduledMessageRefusal.php` | 9 | Integrations: `Controller/ScheduledMessagesController` |
| `Service/Notification/ScheduledMessageStore.php` | 150 | Configuration: `Service/ContractBindings` |
| `Service/Notification/ScheduledMessageStoreInterface.php` | 11 | Configuration: `Service/ContractBindings` |
| `Service/Notification/ScheduledMessageSweep.php` | 56 | Processes: `BackgroundJob/ScheduledMessageSweepJob` |
| `Service/Notification/ClaimAttempt.php` | 11 | Processes: `BackgroundJob/ScheduledMessageSweepJob` |
| `Controller/Support/ReadableObject.php` | 28 | Integrations: `Controller/MessageDispatchController`, `Controller/ScheduledMessagesController` |

### Scheduled reports (252 lines)

| File | Lines | Outside callers |
|---|---|---|
| `lib/Notifications/Db/ScheduledReport.php` | 118 | Configuration: `Service/ScheduledReportService`, `Service/ScheduledReportRun` |
| `lib/Notifications/Db/ScheduledReportMapperInterface.php` | 12 | Configuration: `Service/ScheduledReportService` |
| `lib/Service/ScheduledReportDelivery.php`, `ScheduledReportExportQuery.php`, `ScheduledReportExporters.php`, `ScheduledReportNotice.php`, `ScheduledReportStores.php` | 14+15+15+10+18 | Configuration: `Service/ScheduledReportService` (their only user) |
| `lib/Service/AdminRecipientExpander.php` | 50 | Configuration: `Service/ScheduledReportService` |

### Schema notification declarations (217 lines)

| File | Lines | Outside callers |
|---|---|---|
| `Service/Notification/NotificationContentValidator.php` | 193 | Core: `Db/SchemaMapper/NotificationAnnotationCheck` (for the `BAD_HREF` constant) |
| `Service/Notification/NotificationAnnotationValidators.php` | 8 | Core: `Db/SchemaMapper/NotificationAnnotationCheck` |
| `Service/Notification/NotificationDeclarationException.php` | 16 | Core: `Db/SchemaMapper/NotificationAnnotationCheck`, `Controller/SchemasController` |

### Chat, webhooks, mail links, activity, configuration notices (153 lines)

| File | Lines | Outside callers |
|---|---|---|
| `lib/Notifications/Db/Message.php` | 47 | Platform: `Db/FeedbackMapper`; Integrations: `Mcp/Service/Chat/ConversationManagementHandler`, `MessageHistoryHandler` |
| `lib/Notifications/Db/MessageMapperInterface.php` | 16 | Platform: `ChatController`, `ConversationController`; Core: `Controller/Support/ChatMappers`; Integrations: `Mcp/Service/ChatService`, `Chat/ChatServiceStores`, `Chat/ConversationStores`, `Chat/ConversationManagementHandler`, `Chat/MessageHistoryHandler` |
| `lib/Db/FeedbackMapperInterface.php` | 12 | Platform: `ChatController`, `ConversationController`, `Db/FeedbackMapper`, `Db/PlatformMapperBindings`; Core: `Controller/Support/ChatMappers` |
| `Service/Webhook/CloudEventFormatterInterface.php`, `CloudEventAttributes.php`, `WebhookInterceptionCacheInterface.php` | 8+11+8 | Integrations: `Service/WebhookService`, `WebhookDispatchServices`, `Db/WebhookMapper` |
| `Service/EmailServiceInterface.php` | 13 | Integrations: `Integration/Providers/EmailProvider`, `Integration/BuiltinIntegrationServices`; Core: `Controller/Support/RelationsRecords`, `Service/ObjectRelationCleanupLinks` |
| `lib/Service/ActivityFilterServiceInterface.php`, `lib/Service/ActivityFilter.php` | 9+21 | Integrations: `Controller/ActivityLinksController`, `Service/ActivityFilterService` (its implementation); Core: `Controller/Support/ActivityLinksControllerServices`; Configuration: `Service/ContractBindings` |
| `lib/Service/NotificationServiceInterface.php` | 8 | Configuration: `Service/NotificationService` (its implementation), `ConfigurationController`, `Support/ConfigurationDomain`, `BackgroundJob/ConfigurationCheckJob`, `Service/ContractBindings`; Compliance: `BackgroundJob/Support/ConfigurationCheckServices` |

### Following objects, timeline, notices, rules, statistics (141 lines)

| File | Lines | Outside callers |
|---|---|---|
| `lib/Notifications/Db/Watcher.php` | 36 | Processes: `Interaction/WatcherService`, `WatcherServiceInterface`, `FavouriteService`, `FavouriteServiceInterface`, `Controller/ObjectFavouriteController`; Configuration: `ExampleSet/ExampleSetWatchers`; Core: `UserMigration/PersonalStateMigrator`, `PersonalStateReader` |
| `lib/Notifications/Db/WatcherMapperInterface.php` | 16 | Processes: `Interaction/WatcherService`, `WatcherStore`; Configuration: `ExampleSet/ExampleSetWatchers`; Core: `Db/MagicMapper/MagicSearchHandler` (the `TABLE` constant), `UserMigration/PersonalStateMigrator`, `PersonalStateReader` |
| `Service/TimelineVisibilityServiceInterface.php` | 13 | Integrations: `ActivityLinksController`, `ObjectIntegrationsController`, `IntegrationControllersWiring`, `ActivityFilterService`, `Providers/ActivityProvider`; Files: `FilesRegistrar`, `NoteService`, `NoteReplies`; Core: `Support/ActivityLinksControllerServices`, `Support/ObjectIntegrationsAccess`, `Sharing/AccessLinkActs` |
| `Service/Timeline/PublicTimelineInterface.php` | 7 | Integrations: `CaseTokenService`; Core: `Sharing/AccessLinkReaderFilters` |
| `Service/Notification/NotificationClearingServiceInterface.php` | 8 | Core: `Support/ObjectReadStateControllerServices`; Processes: `Listener/ReadStatePruneListener` |
| `Service/Notification/NotificationRuleDispatcherInterface.php` | 9 | Processes: `BackgroundJob/AnnotationNotificationDispatchJob`, `Listener/AnnotationNotificationListener`; Search: `Aggregation/ThresholdEvaluationService` |
| `Service/Notification/TaskTransitionNotifierInterface.php`, `TransitionNotice.php`, `TaskNotificationRulesInterface.php` | 7+10+8 | Processes: `Listener/TaskNotificationListener`, `Task/WriteBack/WriteBackRefusalNotice` |
| `Service/ExampleSet/ExampleSetTimelineRowsInterface.php` | 7 | Configuration: `ExampleSet/ExampleSetHistoryRowKinds` |
| `Db/NotificationHistoryMapperInterface.php`, `Db/QueuedNotificationMapperInterface.php` | 7+6 | Processes: `Service/OperationsConsoleNotifications` (a bag whose only user is Configuration's `OperationsConsoleService`) |
| `Service/Notification/RecipientResolverInterface.php` | 7 | Compliance: `Gdpr/PrivacyOfficerRecipientResolver` (implements it) |

Totals: 321 + 236 + 376 + 252 + 217 + 153 + 141 = 1,696 lines in 75 files.

## 2. What outside callers actually need

Grouped by capability; the methods and constants actually called, not the class surface.

| # | Capability | Called today | Callers |
|---|---|---|---|
| C1 | Deliver a message on a channel | `EmailSender::send`, `::sendMailTo`, `::OUTCOME_DISPATCHED`; `NcNotificationSender::notify`, `::enqueuePush`; `TalkSender::postAsUser`, `::OUTCOME_KILL_SWITCH`; `NotificationChannelPolicy::isChannelEnabled`, `::APP_ID`; `NotificationTemplating::render`; `PushChannels::OR_OBJECT`, `::OR_COLLECTION` | Processes flow messaging, Search and Processes view alerts, Platform push listener, Configuration referenced-address mail |
| C2 | Decide who receives it | `NotificationRecipientResolver::resolve`, `::resolveFromSource`, `::userExists`, `::groupExists`, `::extractUidsFromRelation`; `NotificationPreferenceService::resolve`; `RateLimiter::tryConsume` | Processes flow messaging, Search view alerts |
| C3 | Respect a mail opt-out | `OptOutAuthority::askQuestion`, `::decisionFor`, `::withLink`, `::CATEGORIES`, `::DEFAULT_CATEGORY`, `::CODE_AUTHORITY_UNAVAILABLE` | Processes flow messaging and `SendEmailNode`, Configuration referenced-address mail |
| C4 | Fire notification rules | `NotificationRuleDispatcher::dispatch`, `::dispatchWithSchema`; `TaskTransitionNotifier::notifyTransition`; `TaskNotificationRules::ACTION_WRITE_BACK_REFUSED` | Processes annotation and task listeners, write-back refusal; Search threshold evaluation |
| C5 | Timeline visibility and the public timeline | `TimelineVisibility::normalise`, `::mayManage`, `::effectiveFilter`, `::filterRows`, `::isTimelineIntegration`, `::INTERNAL`, `::PUBLIC_ENTRY`; `PublicTimeline::forObject` | Integrations activity and integration controllers, Files notes, Integrations case tokens, Core access links |
| C6 | Follow an object | `WatcherMapper::TABLE` (Core search lens); `Watcher` rows read and restored (user migration, example sets: `subscribe`, `findOne`, `unsubscribe`); `WatcherService::followAssigned`, `::cleanupForObject` (Processes listeners); `WatcherService::callerUid`, `::isWatchedByCaller`, `::notifyForCaller`, `::maySeeWatchers`, `::watcherCount` (Core `RenderObject`) | Core, Processes, Configuration |
| C7 | Clear notices when work is opened | `NotificationClearing::clearForObject`, `::clearForSubResource`, `::archiveOrphaned` | Core read-state controller, Processes prune listener |
| C8 | Store scheduled reports | `ScheduledReportMapper::find`, `::findByOwner`, `::findAll`, `::insert`, `::update`, `::delete`, and the entity's setters | Configuration `ScheduledReportService` |
| C9 | Schedule a message for later | `ScheduledMessageScheduler::schedule` | Integrations `MessageDispatchController` |
| C10 | Notification statistics for the operations console | `NotificationHistoryMapper::countByStatus`, `count(QueuedNotificationMapper::findAll())` | Configuration operations console |
| C11 | Plug in a recipient resolver | `RecipientResolverInterface::resolve` implemented outside | Compliance, and fleet apps humaniq, integriq, planninq, shillinq |
| C12 | Seed timeline entries from an example set | `TimelineEntryMapper::insert`, `::findByUuid`, `::delete`, `::findForObject`, `TimelineKindService::validateFields` | Configuration example-set engine (today inverted: Notifications extends Configuration's row type) |

The rest of what is exposed today is not a capability other areas should need from Notifications: wiring,
classes that do another area's work, and the schema check Core runs on Notifications' behalf.

## 3. The proposed contract set

### 3.1 Ports in `lib/Kernel/Contracts/Notifications/`

Each port is one interface, signatures on one line, importing only what it uses. Line counts are measured on
drafts with the scorer's line counter. An existing interface whose methods a port takes over extends the port
(its own declaration of those methods goes), so internal and fleet callers of the old name keep working.

| Port | Methods and constants | Implemented by | Lines |
|---|---|---|---|
| `MessageChannelsInterface` (C1) | `APP_ID`, `OUTCOME_DISPATCHED`, `OUTCOME_KILL_SWITCH`, `PUSH_OBJECT`, `PUSH_COLLECTION`; `isChannelEnabled(string $channel): bool`; `send(string $uid, string $subject, string $body): string`; `sendMailTo(MailAddressee $addressee, MailContent $content): string`; `notify(InAppNotice $notice): string`; `enqueuePush(PushRideAlong $push): void`; `postAsUser(TalkMessage $talkMessage, string $actorUid): string`; `render(string $template, PlaceholderValues $values): string` | new `Notifications/Service/Notification/MessageChannels` delegating to `EmailSender`, `NcNotificationSender`, `TalkSender`, `NotificationChannelPolicy`, `NotificationTemplating`; `PushChannels` constants alias the port's | 23 |
| `MessageAudienceInterface` (C2) | `resolve(array $recipientsSpec, RecipientSource $source): array`; `resolveFromSource(array $recipientsSpec, RecipientSource $source): array`; `userExists(string $uid): bool`; `groupExists(string $gid): bool`; `extractUidsFromRelation(mixed $value): array`; `preferencesFor(PreferenceResolution $resolution): array`; `tryConsume(string $ruleId, string $recipient, ?array $perRuleOverride = null): bool` | new `MessageAudience` delegating to `NotificationRecipientResolver`, `NotificationPreferenceService`, `RateLimiter` | 14 |
| `MailOptOutInterface` (C3) | `CATEGORIES`, `CODE_AUTHORITY_UNAVAILABLE`, `DEFAULT_CATEGORY`; `askQuestion(OptOutQuestion $question): array`; `decisionFor(array $decisions, string $address): array`; `withLink(string $body, ?array $unsubscribe): string` | `OptOutAuthority` (`OptOutAuthorityInterface` extends the port) | 12 |
| `NotificationRulesInterface` (C4) | `ACTION_WRITE_BACK_REFUSED`; `dispatch(ObjectEntity $object, string $trigger, array $context = []): void`; `dispatchWithSchema(ObjectEntity $object, string $trigger, array $context, Schema $schema): void`; `notifyTransition(TaskInterface $task, TransitionNotice $notice): void` | new `NotificationRules` delegating to `AnnotationNotificationDispatcher` and `TaskTransitionNotifier` | 13 |
| `ObjectTimelineInterface` (C5) | `INTERNAL`, `PUBLIC_ENTRY`; `normalise(?string $value): string`; `mayManage(?ObjectEntity $object): bool`; `effectiveFilter(?ObjectEntity $object, ?string $requested): ?string`; `filterRows(array $rows, ?string $filter): array`; `isTimelineIntegration(string $integrationId): bool`; `publicEntries(ObjectEntity $object, int $limit = 50): array` | new `ObjectTimeline` delegating to `TimelineVisibilityService` and `PublicTimeline` | 14 |
| `ObjectFollowsInterface` (C6) | `followAssignee(AddressedObject $target, string $userId): void`; `cleanupForObject(string $objectUuid): int`; `followsOf(string $userId): array`; `restoreFollow(string $userId, array $follow): void`; `forgetFollow(string $userId, string $objectUuid): bool` | `WatcherService` (after its row moves, see 3.3) | 11 |
| `ScheduledReportStoreInterface` (C8) | `find(int $id): ScheduledReportInterface`; `findByOwner(string $owner): array`; `findAll(): array`; `create(array $fields, string $owner): ScheduledReportInterface`; `change(int $id, array $fields): ScheduledReportInterface`; `delete(int $id): void` | new `Notifications/Db/ScheduledReportStore` over `ScheduledReportMapper`; the field-to-setter mapping of `ScheduledReportService::create()` and `applyFields()` moves into it, validation stays in the service | 12 |
| `ScheduledMessagesInterface` (C9) | `schedule(array $message, ?ObjectEntity $object, DateTimeImmutable $now): array` | `ScheduledMessageScheduler` | 8 |
| `NotificationStatisticsInterface` (C10) | `countByStatus(?DateTime $since = null): array`; `queuedCount(): int` | new `NotificationStatistics` over the two mappers | 8 |
| `TimelineSeedInterface` (C12) | `seed(array $data, ObjectEntity $object): string`; `isSeeded(string $uuid): bool`; `unseed(string $uuid): bool` | new `TimelineSeeds` holding the writes `ExampleSetTimelineEntries` does today | 9 (estimate) |

`RecipientResolverInterface` (C11) stays where it is, unchanged: it is a plug-in point four fleet apps
implement (section 6). 7 lines.

C7 gets no Notifications port: its callers outside Processes are in Core, so it is a Core-owned port (3.2).

### 3.2 Ports Notifications implements for Core (in `lib/Kernel/Contracts/Core/`)

Core ranks below Notifications, so Core may not name a Notifications port. Where Core needs Notifications'
work, Core owns the port and Notifications implements and binds it in `NotificationsWiring`, as with the
presence and action-rights ports (#10675, #10688). These lines count to Core.

| Core port | Methods | Implemented by | Replaces | Lines |
|---|---|---|---|---|
| `ObjectNoticesInterface` | `clearForObject(string $objectUuid, ?string $userId = null): int`; `clearForSubResource(string $objectUuid, string $subjectId): int`; `archiveOrphaned(string $objectUuid): int` | `NotificationClearingService` (`NotificationClearingServiceInterface` extends it) | Core read-state controller and Processes prune listener on `NotificationClearingServiceInterface` | about 9 |
| `ObjectFollowStateInterface` | `markFollowing(ObjectEntity $entity): void`; `narrowToFollowed(IQueryBuilder $qb, string $userId): void` | `WatcherService` (`markFollowing` holds today's `RenderObject::setWatcherMarkers()` body, `narrowToFollowed` the subquery of `MagicSearchHandler::applyPersonalLensFilter()`) | `RenderObject` on `WatcherService`, `MagicSearchHandler` on `WatcherMapperInterface::TABLE` | about 9 |
| `SchemaDeclarationCheckInterface` (exists) | `position()`, `check()` | `NotificationAnnotationCheck`, contributed through `SchemaDeclarationChecksEvent` in its current slot | `SchemaMapper::checkNotifications()` constructing it | 0 new |
| `SchemaDeclarationRefusalInterface` (marker) | none | every `*DeclarationException` in `SchemasController::DECLARATION_REFUSALS` | the class list in `SchemasController` | about 5; one decision for all areas, belongs to the Core design |

### 3.3 Classes that count to another area (rule 3) and wiring (rule 5)

None of these files moves; their csv row follows the work they do. Each is justified by its callers and
dependencies, not by the reference it hides.

| Files | Today | Counts to | Why it is that area's work |
|---|---|---|---|
| `Notifications/Db/Message`, `MessageMapper`, `MessageMapperInterface` | Notifications | Integrations | Chat messages of MCP conversations: they reference `Mcp/Db/Conversation`, and no Notifications class uses them |
| `Db/FeedbackMapperInterface`; `Controller/Support/ChatMappers`, `ChatControllerServices`, `ConversationControllerServices` | Notifications; Core | Platform | `FeedbackMapper` and every user are Platform chat controllers; the bags' only users are `ChatController` and `ConversationController` (the decided rule for `Controller/Support` bags) |
| `Notifications/Service/Webhook/*` (5 files) | Notifications | Integrations | CloudEvent formatting and the webhook interception cache; their only users are the Integrations webhook services |
| `Notifications/Controller/EmailsController`, its four `Emails*` bags, `Service/EmailService`, `EmailServiceInterface`, `EmailLinkRequest`, `EmailLinkedSchemas`, `EmailMailAppReader`, `ReplyThreadService`, `Notification/ReplyThreadResolver`; `Controller/Support/RelationsRecords`, `Service/ObjectRelationCleanupLinks` | Notifications; Core | Integrations | Linking Mail-app messages to objects: `EmailLink` and `EmailLinkService` are Integrations, `EmailProvider` is its integration; the two Core bags' only users are Integrations classes |
| `Service/ActivityFilter`, `ActivityFilterServiceInterface`; `Controller/Support/ActivityLinksControllerServices`, `ObjectIntegrationsAccess` | Notifications; Core | Integrations | `ActivityFilterService` (Integrations) implements it and is its only user besides Integrations controllers; the interface names Integrations' `ActivityEntriesOptions` |
| `Service/NotificationServiceInterface`; `BackgroundJob/Support/ConfigurationCheckServices` | Notifications; Compliance | Configuration | Configuration-update notices: implemented by Configuration's `NotificationService`, names the `Configuration` entity; the bag's only user is `ConfigurationCheckJob` |
| `Service/ScheduledReportDelivery`, `ExportQuery`, `Exporters`, `Notice`, `Stores`, `OwnerServices`, `AdminRecipientExpander` | Notifications | Configuration | Constructor bags and the recipient expander of `ScheduledReportService`, their only user; `ScheduledReportExporters` names Configuration's export services |
| `Service/OperationsConsoleNotifications` | Processes | Configuration | A bag whose only user is `OperationsConsoleService` |
| `Notifications/Service/ExampleSet/ExampleSetTimelineEntries`, `ExampleSetTimelineRowsInterface` | Notifications | Configuration | Example-set row kinds; they extend Configuration's `ExampleSetEngineRows`, like `ExampleSetWatchers` already in Configuration. They then write through `TimelineSeedInterface` |
| `Listener/NotificationsAnnotationInstallListener` | Notifications | Configuration | It only calls Configuration's `NotificationsAnnotationInstallerInterface`. Registered by Configuration's listener registrar, in its slot |
| `Service/UserMigration/PersonalStateMigrator`, `PersonalStateReader` | Core | Platform | Nextcloud user migration of personal state (follows and views); the only user is `AppInfo/Application`. They then read and restore follows through `ObjectFollowsInterface` |
| `Service/Interaction/WatcherService`, `WatcherServiceInterface`, `WatcherStore`, `WatcherCaller`, `FavouriteService`, `FavouriteServiceInterface`, `Controller/ObjectFavouriteController`, `Controller/Support/ObjectFavouriteControllerServices` | Processes | Notifications | Following an object to be notified: the `Watcher` entity and mapper, `ObjectWatchersController`, and every reader of watcher uids (`NotificationRecipientResolver`, `MentionPrincipals`) are Notifications; a star is a quiet follow on the same rows (`FavouriteService` is deprecated in favour of `WatcherService`) |
| `Controller/ScheduledMessagesController`, `BackgroundJob/ScheduledMessageSweepJob` | Integrations; Processes | Notifications | They use only Notifications classes (scheduler, refusal, sweep, claim) |
| `BackgroundJob/AnnotationNotificationDispatchJob` | Processes | Notifications | Deferred dispatch of notification rules; uses only Core and `NotificationRuleDispatcherInterface`. Its handle is registered by Notifications (the job-handles decision) |
| `Service/Interaction/AddressedObject` | Processes | Core | A value naming the object a user acts on; its lowest user is Core's read-state controller, and Processes and Notifications use it too |
| `Notifications/Controller/Support/ReadableObject` | Notifications | Core | Resolves an object the caller may read, or answers 404, through Core's object operations; used by Notifications and Integrations controllers |
| `Db/SchemaMapper/NotificationAnnotationCheck` | Core | Notifications | It validates the `x-openregister-notifications` annotation with Notifications' validators; contributed through `SchemaDeclarationChecksEvent` |

Wiring:

- `Listener/ListenerRegistrar` (Processes) stops naming the five Notifications listeners. Each block it registers
  today becomes a public static method of `NotificationsListenerRegistrar` (content notices, dedupe, GraphQL
  subscriptions, activity), called by `ListenerRegistrar` at exactly the position the block has today, so
  `listener-order.json` and `ListenerRegistrationOrderGoldenTest` do not change.
- `Service/ContractBindings` (Configuration) stops binding Notifications' `ScheduledMessageStoreInterface` and,
  after the rows of 3.3, `WatcherServiceInterface` and `FavouriteServiceInterface`; `NotificationsWiring` binds
  them. `ActivityFilterServiceInterface` moves to Integrations' wiring.
- `NotifierRegistrar::register()` is called from `NotificationsRegistrar::register()` instead of from
  `Application`, keeping the order of the two notifier registrations.

### 3.4 Entities that cross the boundary

| Entity | Crosses to | Decision |
|---|---|---|
| `ScheduledReport` | Configuration (`ScheduledReportService`, `ScheduledReportRun`) | Behind `ScheduledReportStoreInterface`, typed as the existing Kernel `ScheduledReportInterface`; `ScheduledReportRun` holds that interface |
| `Watcher` | Core, Processes, Configuration | Stays inside: Core uses `ObjectFollowStateInterface`, user migration and example sets use `followsOf()` / `restoreFollow()` / `forgetFollow()` with portable arrays (today's `PersonalStateMigrator::portableWatch()` shape) |
| `Message` | Integrations, Platform | Not a Notifications entity (3.3); the Integrations design decides whether Platform reaches it through the Kernel `ConversationMapperInterface` / `ChatServiceInterface` |
| `Task` (Processes) named by `TaskTransitionNotifierInterface` | Notifications to Processes, against the order | `notifyTransition()` takes the Kernel `TaskInterface` (rule 4); a widening, `TaskTransitionNotifier` keeps accepting a `Task` |

## 4. The budget

| Capability / group | Before | After | How |
|---|---|---|---|
| Wiring | 321 | about 48 | `NotificationsRegistrar` (11) and `NotificationsListenerRegistrar` with positioned slots (about 37); listeners internal; install listener to Configuration |
| C1-C3 Message delivery | 236 | 219 | 3 ports (49) + 13 value objects that the ports' signatures carry (170) |
| C4 Rules and task notices | 34 | 23 | `NotificationRulesInterface` (13) + `TransitionNotice` (10) |
| C5 Timeline | 20 | 14 | `ObjectTimelineInterface` |
| C6 Follows | 52 | 11 | `ObjectFollowsInterface`; Core side in a Core port |
| C7 Clearing | 8 | 0 | Core port |
| C8 Scheduled reports | 252 | 12 | `ScheduledReportStoreInterface`; bags count to Configuration |
| C9 Scheduled messages | 376 | 8 | `ScheduledMessagesInterface`; controller and sweep job count to Notifications, store bound by Notifications, `ReadableObject` counts to Core |
| C10 Statistics | 13 | 8 | `NotificationStatisticsInterface` |
| C11 Plug-in resolver | 7 | 7 | unchanged |
| C12 Example-set timeline | 7 | 9 | `TimelineSeedInterface` |
| Schema declarations | 217 | 0 | the check is contributed; refusal by Core marker |
| Chat, webhooks, mail links, activity, configuration notices | 153 | 0 | rule 3 |
| **Total** | **1,696** | **about 359** | |

Exposure that lands elsewhere, for the owners of those designs:

| Area | Lines | What |
|---|---|---|
| Integrations | about 63 | `Message` (47) and `MessageMapperInterface` (16), still named by Platform's chat controllers and `FeedbackMapper` |
| Core | about 63 | `ReadableObject` (28), `AddressedObject` (12, exposed in Processes today), `ObjectNoticesInterface` and `ObjectFollowStateInterface` (about 18), the refusal marker (about 5) |
| Kernel | 0 | no new Kernel file |

Nothing in this budget fits only by breaking a rule. Two places deserve a reviewer's look:

- **The 3.3 rows that move a Core class up** (`ChatMappers`, `RelationsRecords`, `ObjectRelationCleanupLinks`,
  `ActivityLinksControllerServices`, `ObjectIntegrationsAccess`, `ConfigurationCheckServices`,
  `PersonalStateMigrator`) follow the decided rule for `Controller/Support` bags and its plain reading for
  constructor bags and a registration-only class: every one has only callers in the area it moves to.
- **`ReadableObject` to Core.** It is generic "load an object the caller may read" code. If the Core design
  prefers not to take it, the fallback is `ScheduledMessagesInterface` gaining
  `readable(array $path): ObjectEntity|JSONResponse` (+1 line) and the class staying in Notifications
  (28 lines exposed).

### Optional: Kernel contracts that Notifications owns

Four groups in `lib/Kernel/Contracts/` are Notifications ports in all but location: Notifications defines the
need and the higher area implements it, or Notifications implements and a higher area calls.

| Files | Lines | Implemented by | Called by |
|---|---|---|---|
| `ScheduledReportsInterface`, `ScheduledReportInterface`, `ScheduledReportUpdate`, `ReportCaller` | 48 | Configuration `ScheduledReportService`; Notifications `ScheduledReport` | Notifications controller and jobs |
| `NotificationTemplatesInterface`, `TemplateRendering` | 23 | Configuration `NotificationTemplateRegistry` | Notifications `Notifier`, templates controller |
| `NotificationPreferencesInterface`, `PreferenceAddress`, `PreferenceWrite` | 35 | Notifications `NotificationPreferenceService` | Notifications controllers; Platform `MigrateNotificationSubscriptionsToUserConfig` |

Moving them to `lib/Kernel/Contracts/Notifications/` lowers Kernel by 106 lines (Kernel is at 10,282 against
the about 9,700 the 4.5 target leaves it) and raises Notifications' exposure by the same 106, to about 465
(2.4%). It renames internal interfaces; none is used by a fleet app (section 6). It is listed as the last
build package so the coordinator can take it or leave it on the Kernel budget alone.

## 5. The build packages

Dependency order. File lists are disjoint between packages; each PR stays at or under about 15 files. Every
PR adds or retargets unit tests for each class it changes, keeps `ListenerRegistrationOrderGoldenTest` and
`listener-order.json` unchanged, and must show no new entry in `modularity_check.py --check` and
`deptrac.sh areas --check`. "csv" means `scripts/component-map.csv` plus the regenerated
the generated components file and the deptrac areas file; packages that touch it are stacked, never in
parallel.

### N1: rows only (2 PRs, csv only)

No PHP changes. Each PR must keep `--check` free of new references against the order, which is why the rows
are grouped as they are (a bag moves in the same PR as the interface it names).

- **N1a, rows out of Notifications and Core bags with them:** the chat group, webhooks, the mail-link group
  with `RelationsRecords` and `ObjectRelationCleanupLinks`, the activity filter with its two Core bags,
  `NotificationServiceInterface` with `ConfigurationCheckServices`, the scheduled-report bags and
  `AdminRecipientExpander`, `OperationsConsoleNotifications`, `AccessLinkReaderFilters` (Files; its only user is
  `AccessLinkReader`), `PersonalStateMigrator` and `PersonalStateReader` (Platform). Files: csv.
- **N1b, rows into Notifications and Core:** the follow group from Processes, `AddressedObject` and
  `ReadableObject` to Core, `ScheduledMessagesController`, `ScheduledMessageSweepJob`,
  `AnnotationNotificationDispatchJob`. Files: csv. Removes the Notifications-to-Processes references through
  `WatcherServiceInterface` and `AddressedObject`; `RenderObject -> WatcherService` and
  `JobHandles -> AnnotationNotificationDispatchJob` stay as the same pair until N4 and the job-handles change.

Removes from Notifications' exposure: about 380 lines. References against the order removed: about 30.

### N2: wiring (1 PR, after N1)

`lib/Listener/ListenerRegistrar.php`, `lib/Notifications/Listener/Registrar/NotificationsListenerRegistrar.php`,
`lib/Notifications/Listener/Registrar/NotificationsListenerWiring.php` (only if the slots need it), the
Configuration listener registrar (`lib/Listener/Registrar/*Configuration*`, new slot for
`NotificationsAnnotationInstallListener`, with its csv row), `lib/Service/ContractBindings.php`,
`lib/Notifications/NotificationsWiring.php`, `lib/Service/Integration/IntegrationWiring.php`,
`lib/AppInfo/Application.php`, `lib/Notifications/NotificationsRegistrar.php`, csv, the binding tests in
`tests/Unit/Architecture` that list these bindings, and the registrar tests. Removes about 435 lines (the
listeners, `NotifierRegistrar`, the install listener, and `ScheduledMessageStore` with its interface).

### N3: the Notifications ports (2 PRs, stacked, after N2)

- **N3a, messaging:** new `lib/Kernel/Contracts/Notifications/MessageChannelsInterface.php`,
  `MessageAudienceInterface.php`, `MailOptOutInterface.php`; new `MessageChannels`, `MessageAudience`;
  `EmailSenderInterface`, `NcNotificationSenderInterface`, `TalkSenderInterface`,
  `NotificationChannelPolicyInterface`, `NotificationTemplatingInterface`, `OptOutAuthorityInterface`,
  `lib/Push/PushChannels.php` (constants alias the port); `NotificationsWiring`; tests. 13 files plus tests.
- **N3b, the rest:** new `NotificationRulesInterface`, `ObjectTimelineInterface`, `ObjectFollowsInterface`,
  `ScheduledReportStoreInterface`, `ScheduledMessagesInterface`, `NotificationStatisticsInterface`,
  `TimelineSeedInterface`; new `NotificationRules`, `ObjectTimeline`, `ScheduledReportStore`,
  `NotificationStatistics`, `TimelineSeeds`; the Core-owned `lib/Kernel/Contracts/Core/ObjectNoticesInterface.php`
  and `ObjectFollowStateInterface.php`; `WatcherService` (implements the follow port and the follow-state
  port), `NotificationClearingServiceInterface` (extends the notices port); `NotificationsWiring` binds all of
  them; csv for the new implementation files. About 15 files plus tests; split ports / implementations if the
  tests push it over.

N3 adds about 115 exposed-to-be lines but exposes nothing yet: no outside caller types against the ports until
N4-N7. `modularity_check.py` reports no new exposed file in N3.

### N4: follows and notices (1 PR, after N3)

Callers only (the ports and their bindings came in N3b):
`lib/Service/Object/RenderObject.php`, `lib/Db/MagicMapper/MagicSearchHandler.php`,
`lib/Service/UserMigration/PersonalStateMigrator.php`, `PersonalStateReader.php`,
`lib/Service/ExampleSet/ExampleSetWatchers.php`, `lib/Listener/Support/AssigneeFollowServices.php`,
`lib/Listener/AssigneeFollowListener.php`, `lib/Listener/WatcherPruneListener.php`,
`lib/Controller/Support/ObjectReadStateControllerServices.php`, `lib/Listener/ReadStatePruneListener.php`.
Removes `Watcher` and `WatcherMapperInterface` from exposure and every Core-to-Notifications reference about
follows and notices. A golden test pins `RenderObject`'s watcher markers and the personal-lens query before
the move.

### N5: scheduled reports, scheduled messages, statistics (1 PR, after N3)

`lib/Service/ScheduledReportService.php`, `lib/Service/ScheduledReportRun.php`,
`lib/Service/ScheduledReportStores.php`, `lib/Controller/MessageDispatchController.php`,
`lib/Service/OperationsConsoleNotifications.php`, `lib/Service/OperationsConsoleService.php`,
`lib/Notifications/Service/ExampleSet/ExampleSetTimelineEntries.php` (writes through `TimelineSeedInterface`,
then with `ExampleSetTimelineRowsInterface` its csv row to Configuration), csv, tests. A golden
test pins `ScheduledReportService::create()` and `applyUpdate()` output before the setters move into
`ScheduledReportStore`. Removes `ScheduledReport`, `ScheduledReportMapperInterface`,
`ScheduledMessageScheduler`, `ScheduledMessageRefusal`, the two statistics mappers from exposure.

### N6: message delivery callers (2 PRs, after N3a)

- **N6a, Processes flows:** `lib/Service/Flow/FlowMessagingService.php`, `FlowMessagingChannels.php`,
  `FlowMessagingRecipients.php`, `FlowMessagingPlatform.php`, `FlowMessagePreview.php`,
  `FlowMessagingOverrides.php`, `FlowRecipientAddresses.php`, `Nodes/SendEmailNode.php`, tests.
- **N6b, the others:** `lib/Listener/ViewAlertMail.php`, `ViewAlertAudience.php`,
  `ViewAlertCrossedListener.php`, `lib/Service/Notification/AddressDeliveryServices.php`,
  `ReferencedAddressNotifier.php`, `lib/Listener/NotifyPushListener.php`, tests.

Removes the nine messaging interfaces and `PushChannels` from exposure (about 66 lines); the value objects
stay.

### N7: rules, timeline, schema check (2 PRs, after N3b)

- **N7a, rules and timeline:** `lib/Listener/AnnotationNotificationListener.php`,
  `lib/Search/Service/Aggregation/ThresholdEvaluationService.php`, `lib/Listener/TaskNotificationListener.php`,
  `lib/Service/Task/WriteBack/WriteBackRefusalNotice.php`, `TaskTransitionNotifier.php` and its interface
  (widened to `TaskInterface`), `lib/Controller/ActivityLinksController.php`,
  `ObjectIntegrationsController.php`, `IntegrationControllersWiring.php`, `lib/Service/ActivityFilterService.php`,
  `Integration/Providers/ActivityProvider.php`, `lib/Files/Service/NoteReplies.php`, `NoteService.php`,
  `lib/Files/FilesRegistrar.php`, `lib/Service/CaseTokenService.php`, `lib/Service/Sharing/AccessLinkReaderFilters.php`.
  15 files; split rules / timeline if tests push it over.
- **N7b, the schema check:** `lib/Db/SchemaMapper.php`, `lib/Db/SchemaMapper/NotificationAnnotationCheck.php`
  (implements `SchemaDeclarationCheckInterface`, csv row to Notifications), the Notifications listener that
  contributes it to `SchemaDeclarationChecksEvent` in the slot `SchemaDeclarationOrder` gives it today,
  `lib/Controller/SchemasController.php` with the refusal marker (or left to the Core design, see Findings),
  csv, the `SchemaMapper` tests that build `ProcessDeclarationChecksDispatcher`. The import mode that only warns
  (`warnUnlinkable`) has to reach the contributed check; today it is a `SchemaMapper` field.

### N8 (optional): Kernel contracts to `lib/Kernel/Contracts/Notifications/` (1 PR, last)

The nine files of the optional table in section 4 and their 20 users. Rename only, no behaviour.

## 6. Fleet impact

`fleet_checkout.sh` on the development branches of the 19 fleet apps (19 Nov 2026), then
`fleet_surface.py`. Fleet references to Notifications classes, and to the classes whose rows this
design changes:

| Class | Apps | Effect of this design |
|---|---|---|
| `Notifications\Service\Notification\RecipientResolverInterface` | humaniq, integriq, planninq, shillinq | unchanged; **candidate for promotion to `lib/Contract`** (a plug-in point four apps implement) |
| `Notifications\Service\Notification\EmailSenderInterface`, `MailAddressee`, `MailContent` | filinq | unchanged names; the interface extends `MessageChannelsInterface`. **Candidate for promotion:** the mail part of `MessageChannelsInterface` |
| `Notifications\Service\Notification\NotificationPreferenceService` (concrete) | dossiq, planninq | unchanged; they should use `NotificationPreferencesInterface`, **candidate for promotion** with N8 |
| `Notifications\Service\Notification\UnsubscribeHeaders` | dossiq, pipelinq | unchanged |
| `Notifications\Service\Party\PartyNotificationService`, `PartyMessage` | filinq | unchanged; candidate for a published party-messaging contract, out of scope here |
| `Notifications\Service\Timeline\TimelineWriteService`, `TimelineEntryService`, `TimelineKindService`, `TextBlockService` | dossiq | unchanged; `TimelineSeedInterface` is internal, a published timeline-write contract is a **promotion candidate** |
| `Service\EmailLinkService`, `Service\EmailMessageRef` | dossiq, pipelinq | Integrations already; the mail-link rows in N1a join them there, no rename |
| `WatcherService`, `WatcherServiceInterface`, `ScheduledReportService`, `ScheduledMessagesController`, `Message`, `MessageMapper`, webhooks, `ActivityFilter*`, `NotificationServiceInterface` | none | free to re-row |

Nothing other apps use is renamed or removed. The promotions are named, not done (ADR-119 Decision 14).

## Findings

- **The messaging floor.** 170 of the remaining lines are value objects `FlowMessagingService` (Processes,
  366 lines) constructs to drive Notifications' channels one by one. A later step could give Notifications one
  `deliver()` port taking a single message value and move the composition (opt-out question, preference
  lookup, push ride-along, Talk fallback) behind it, bringing messaging from 219 to about 30 exposed lines.
  It means splitting `FlowMessagingService` with behaviour pinned first; not planned in this wave.
- **Against the order, outward.** 66 references run from Notifications to higher areas today. N1 and N4 remove
  about 30. The 37 that remain are wave 4 work, in four themes: task notifications reading Processes'
  `Task` and inbox criteria (`TaskScheduledNotificationJob`, `TaskNotificationCandidates`,
  `TaskObjectAdapter(s)`, `TaskTransitionNotifier`); the annotation dispatcher writing Processes automation
  records and Configuration's referenced-address notifier; party notifications using Processes' party service
  and Integrations' contact links; scheduled messages sending through Integrations' `MessageDispatcherInterface`
  and audit through Compliance's `ObjectAuditEvent`. Each needs a Notifications-owned port the higher area
  implements, or an event; they are not exposure of Notifications and are left to the wave 4 packages.
- **Against the order, inward.** Of the 19 Core-to-Notifications references, 17 are addressed here: 12 by rows
  (the bags and the user-migration pair, N1a), 2 by the Core ports (N4), 3 by the schema check (N7b); the
  other two are `AccessLinkActs`' use of
  `TimelineVisibilityServiceInterface::PUBLIC_ENTRY` (1) plus `SchemasController`'s refusal list (1), which
  belong to the Core design: `AccessLinkActs` already posts through Files' notes, so the visibility value
  travels with whatever port Core gets for notes, and the refusal marker is one decision for every area's
  declaration exceptions.
- **`MagicSearchHandler`** is Core's hot query path. `narrowToFollowed()` must be resolved once per handler,
  not per query; the golden test in N4 pins the SQL.
- **Listener slots, not a list.** Moving the Notifications listeners into one `register()` call would reorder
  listeners of the same event. The positioned slot methods keep the order; the cost is that
  `ListenerRegistrar` still names `NotificationsListenerRegistrar` (about 37 exposed lines).
