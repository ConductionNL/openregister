# Fleet breakage inventory against OpenRegister main

- OpenRegister main scanned: `cef85ccad5bfd56ee2c7b6855e8a245d69a19f88`
- Report generated: 2026-11-10 05:17 UTC
- Re-run: `python3 -I scan.py --or <openregister clone> --apps-root <dir holding the app clones> [app ...]`, then `python3 -I scan.py --report` (about 3 minutes, read-only on every app clone).
- Each app was scanned at its CURRENT local checkout (no branch switch); branch and sha are in the table. Machine-readable lists: `<app>.json` next to this file.

## How to read this

- **lib** findings break the running app. **tests** findings break or falsify the app's test suite. A tests finding marked *stub* hits a class the app also declares as a test stub, so the suite currently passes against the stub while the real OR class has moved on.
- **Silent** means no error surfaces: a `method_exists` guard that is now always false, a container lookup inside a `try/catch` that now always fails, a listener that never fires, positional arguments PHP silently drops, or a class that fails to load only when first autoloaded.
- Call shapes are checked where the receiver type is known (typed property, constructor injection, typed param, `::class`/string container lookup, `new`, mocks, one hop of return-type propagation). Everything else is in the *unresolved* count, not checked.
- Out of scope of the scanner: argument TYPE changes when the app passes a variable (only literal-vs-class mismatches are flagged), return-shape changes (an array key that moved), and calls through untyped receivers.

## Summary

| app | local dir | branch @ sha | OR refs | calls checked | lib breaks (silent) | test breaks (stub) | unresolved | implements/extends OR | stub classes drifted | JS routes / no route | lib dynamic sites |
|---|---|---|---|---|---|---|---|---|---|---|---|
| opencatalogi | opencatalogi | HEAD @ 7a191dbb7 | 884 | 509 | 0 (0) | 1 (1) | 58 | 3 | 8/33 | 41 / 0 | 46 |
| integriq | openconnector | development @ cd844692c | 1596 | 2910 | 0 (0) | 16 (15) | 155 | 55 | 24/74 | 67 / 0 | 46 |
| filinq | docudesk | development @ ab6c1ebb4 | 537 | 438 | 1 (1) | 111 (6) | 17 | 25 | 50/72 | 16 / 0 | 133 |
| thematiq | nldesign | development @ 74990116c | 15 | 4 | 0 (0) | 0 (0) | 0 | 1 | 0/0 | 0 / 0 | 0 |
| stackiq | softwarecatalog | development @ 9ad6a74fd | 430 | 303 | 1 (0) | 4 (0) | 3 | 5 | 7/23 | 40 / 0 | 59 |
| launchpad | launchpad | development @ 3e7bf07b3 | 21 | 3 | 0 (0) | 2 (0) | 0 | 2 | 3/7 | 2 / 0 | 4 |
| larpinq | larpingapp | HEAD @ 410f14c08 | 173 | 102 | 0 (0) | 2 (0) | 8 | 9 | 0/8 | 10 / 0 | 31 |
| dossiq | procest | HEAD @ 00b787be1 | 1286 | 1489 | 8 (3) | 21 (20) | 91 | 21 | 10/91 | 123 / 1 | 362 |
| pipelinq | pipelinq | development @ e4d420d42 | 1056 | 947 | 3 (0) | 30 (27) | 27 | 35 | 7/51 | 76 / 0 | 169 |
| shillinq | shillinq | development @ f1e580b05 | 918 | 1128 | 9 (8) | 18 (18) | 22 | 41 | 11/26 | 336 / 0 | 191 |
| learniq | scholiq | development @ e9dd0d0fb | 1591 | 1563 | 0 (0) | 9 (2) | 1 | 76 | 5/44 | 134 / 1 | 130 |
| portaliq | portaliq | development @ 795da17ba | 245 | 197 | 6 (0) | 1 (0) | 16 | 1 | 1/6 | 16 / 0 | 52 |
| decidiq | decidesk | HEAD @ 8375adfca | 892 | 888 | 5 (2) | 6 (4) | 68 | 5 | 8/35 | 21 / 0 | 130 |
| buildiq | openbuild | development @ ffb6d2e35 | 827 | 702 | 7 (3) | 9 (8) | 4 | 12 | 24/66 | 87 / 0 | 51 |
| keepiq | doriath | development @ bc7867fcf | 13 | 0 | 0 (0) | 0 (0) | 0 | 1 | 1/2 | 0 / 0 | 2 |
| hermiq | hermiq | development @ 9ef2b578b | 1200 | 3162 | 0 (0) | 2 (1) | 7 | 14 | 18/57 | 14 / 0 | 19 |
| humaniq | hrmq | development @ 00d1b6e14 | 619 | 686 | 1 (1) | 4 (3) | 11 | 28 | 7/27 | 9 / 0 | 43 |
| versioniq | app-versions | fix/spec-tags-on-version-compare @ 64693db57 | 0 | 0 | 0 (0) | 0 (0) | 0 | 0 | 0/0 | 0 / 0 | 0 |
| planninq | planix | development @ 19c35bed8 | 316 | 597 | 0 (0) | 8 (0) | 19 | 12 | 15/33 | 44 / 0 | 37 |

## Already-known items (reported by hand before this scan)

| app | known item | found by this scan |
|---|---|---|
| portaliq | FormSubmitService::findDraft(destination:,subject:,owner:,match:) vs findDraft(array,FormSubmitter,array) | yes: `lib/Service/Intake/DestinationDraft.php:137` |
| buildiq | VersionPromotionService getLockInfo missing | yes: `lib/Service/VersionPromotionService.php:546` |
| decidiq | AgendaService updateMeetingEvent missing | yes: `lib/Service/AgendaService.php:180` |
| decidiq | MailVoteReplyProcessor createNotification missing | yes: `lib/Service/MailVoteReplyProcessor.php:465` |
| filinq | EmlBackend anonymizeEmlStructured missing | yes: `lib/Service/Conversion/EmlBackend.php:139` |
| integriq | EndpointService find(extend:) vs $_extend | NOT FOUND by the scan (untyped receiver or not on this checkout): see the app section |
| integriq | EndpointService findAllPaginated missing | NOT FOUND by the scan (untyped receiver or not on this checkout): see the app section |
| pipelinq | SlaPolicyController find(uuid:) vs $id | yes: `lib/Controller/SlaPolicyController.php:154` |
| pipelinq | PosTransaction* SchemaMapper::find 5 args | yes: `lib/Lifecycle/PosTransactionConfirmGuard.php:189` |
| shillinq | AuditTrailService missing (compliance audit silently not recorded) | yes: `lib/Controller/ComplianceExportController.php:234` |
| shillinq | InitializeSettings ScheduledWorkflowMapper removed | yes: `lib/Repair/InitializeSettings.php:807` |
| shillinq | WidgetAuthService updateObject named args | yes: `lib/Service/WidgetAuthService.php:595` |
| stackiq | SettingsService OrganisationMapper::findAll 9 args | yes: `lib/Service/SettingsService.php:6481` |

## Fleet-wide lib breaks, silent first

| # | app | file:line | category | silent | what the app does |
|---|---|---|---|---|---|
| 1 | decidiq | `lib/Service/AgendaService.php:180` | dynamic-method-gone | SILENT | method_exists($this->calendarEventService, 'updateMeetingEvent') on OCA\OpenRegister\Service\CalendarEventService |
| 2 | filinq | `lib/Service/Conversion/EmlBackend.php:139` | dynamic-method-gone | SILENT | method_exists($fileService, 'anonymizeEmlStructured') on OCA\OpenRegister\Service\FileService |
| 3 | buildiq | `lib/Service/VersionPromotionService.php:546` | dynamic-method-gone | SILENT | method_exists($this->objectService, 'getLockInfo') on OCA\OpenRegister\Contract\ObjectServiceInterface |
| 4 | shillinq | `lib/Controller/ComplianceExportController.php:235` | dynamic-name-nowhere | SILENT | method_exists($auditService, 'recordEvent' (receiver $auditService) |
| 5 | buildiq | `lib/Service/ApplicationInsightsService.php:1008` | dynamic-name-nowhere | SILENT | method_exists($this->auditTrailMapper, 'countByRegisterAndWindow' (receiver $this->auditTrailMapper) |
| 6 | shillinq | `lib/Service/ComplianceExportService.php:325` | dynamic-name-nowhere | SILENT | method_exists($auditService, 'findInRange' (receiver $auditService) |
| 7 | decidiq | `lib/Service/DecisionIntegrationService.php:548` | dynamic-name-nowhere | SILENT | method_exists($registry, 'isRegisteredConsumer' (receiver $registry) |
| 8 | shillinq | `lib/Controller/ComplianceExportController.php:234` | string-lookup-missing | SILENT | container-get('OCA\OpenRegister\Service\AuditTrailService') |
| 9 | shillinq | `lib/Repair/InitializeSettings.php:807` | string-lookup-missing | SILENT | container-get('OCA\OpenRegister\Db\ScheduledWorkflowMapper') |
| 10 | shillinq | `lib/Repair/InitializeSettings.php:869` | string-lookup-missing | SILENT | container-get('OCA\OpenRegister\Db\ScheduledWorkflowMapper') |
| 11 | shillinq | `lib/Repair/InitializeSettings.php:936` | string-lookup-missing | SILENT | container-get('OCA\OpenRegister\Db\ScheduledWorkflowMapper') |
| 12 | shillinq | `lib/Repair/InitializeSettings.php:999` | string-lookup-missing | SILENT | container-get('OCA\OpenRegister\Db\ScheduledWorkflowMapper') |
| 13 | buildiq | `lib/Resources/template/tests/unit/AppInfo/OpenRegisterAutoloaderTest.php:78` | string-lookup-missing | SILENT | class_exists('OCA\OpenRegister\Fake\Probe') |
| 14 | shillinq | `lib/Service/ComplianceExportService.php:315` | string-lookup-missing | SILENT | container-get('OCA\OpenRegister\Service\AuditTrailService') |
| 15 | dossiq | `lib/Service/Settings/OpenRegisterBridge.php:216` | string-lookup-missing | SILENT | container-get('OCA\OpenRegister\Service\ApprovalService') |
| 16 | humaniq | `lib/Service/WorkingCalendarReader.php:77` | string-lookup-missing | SILENT | string('OCA\OpenRegister\Service\Calendar\WorkingCalendarService') |
| 17 | dossiq | `lib/Service/DecisionDocument/DecisionDocumentValidator.php:113` | call-shape | SILENT | $evaluator->holds($rule['when'], $document, $library) |
| 18 | dossiq | `lib/Woo/WooDeliveredSetWriter.php:316` | call-shape | SILENT | $handler->freeze($setId, 'Woo-levering, publicatie ' . $publicationId, self::FREEZE_STATE, $register, $schema) |
| 19 | pipelinq | `lib/Controller/SlaPolicyController.php:154` | call-shape | loud | $objectService->find(register: $register, schema: $schema, uuid: $id,) |
| 20 | pipelinq | `lib/Lifecycle/PosTransactionConfirmGuard.php:189` | call-shape | loud | $schemaMapper->find($slug, [], null, false, false) |
| 21 | dossiq | `lib/Service/Access/CaseRecordGrantsBackfill.php:249` | call-shape | loud | $rights->giveInEveryOrganisation(right: self::GRANT_MANAGE, group: BackgroundServiceAccount::GROUP, register: $this->settingsService->getCon |
| 22 | dossiq | `lib/Service/Access/CaseTeamAccessProjector.php:383` | call-shape | loud | $baselines->record(subject: $subject, definition: $definition, app: $baseline['app'], appVersion: $baseline['appVersion'],) |
| 23 | dossiq | `lib/Service/FormsIntakeService.php:282` | call-shape | loud | $submitter->submit(destination: $destination, mapping: $mapping, payload: $answers, scope: $submission['formHash']) |
| 24 | portaliq | `lib/Service/Intake/DestinationDraft.php:98` | call-shape | loud | $service->saveDraft(destination: $destination, mapping: null, payload: $payload, subject: null, draftId: self::orNull(value: $draftId), scop |
| 25 | portaliq | `lib/Service/Intake/DestinationDraft.php:137` | call-shape | loud | $service->findDraft(destination: $destination, subject: null, owner: $owner, match: $match) |
| 26 | portaliq | `lib/Service/Intake/DestinationDraft.php:171` | call-shape | loud | $service->submit(destination: $destination, mapping: null, payload: $payload, subject: null, idempotencyKey: self::orNull(value: $idempotenc |
| 27 | portaliq | `lib/Service/Intake/DestinationDraft.php:210` | call-shape | loud | $discard->discard(destination: $destination, draftId: $draftId, owner: $owner) |
| 28 | portaliq | `lib/Service/Intake/DestinationSubmit.php:104` | call-shape | loud | $service->submit(destination: $destination, mapping: $mapping, payload: $payload, subject: null, idempotencyKey: $key, scope: $scope) |
| 29 | pipelinq | `lib/Service/PosTransactionService.php:1482` | call-shape | loud | $schemaMapper->find($slug, [], null, false, false) |
| 30 | decidiq | `lib/Service/ProcessTemplateService.php:326` | call-shape | loud | $validator->validate(states: array_values(array_filter((array)($stateMachine['states'] ?? []), 'is_array')), initial: $initialState, transit |
| 31 | stackiq | `lib/Service/SettingsService.php:6481` | call-shape | loud | $organisationMapper->findAll(null, null, [], [], [], [], null, false, false) |
| 32 | dossiq | `lib/Service/Task/EngineTaskGateway.php:776` | call-shape | loud | $completion->complete($id, $outcome, null, null, $data, $actor) |
| 33 | dossiq | `lib/Service/Task/OnboardingSteps.php:226` | call-shape | loud | $completion->complete($taskId, 'done', null, null, [], $actor) |
| 34 | shillinq | `lib/Service/WidgetAuthService.php:595` | call-shape | loud | $objectService->updateObject(id: $id, object: $data, register: $registerSlug, schema: $schema,) |
| 35 | buildiq | `lib/Listener/FormDestinationDependentsListener.php:116` | constructor-shape | loud | new FormDependent(id: (string)($form['id'] ?? ''), app: 'buildiq', title: (string)($form['name'] ?? ''), author: $this->authorOf(form: $form |
| 36 | portaliq | `lib/Listener/FormDestinationDependentsListener.php:133` | constructor-shape | loud | new FormDependent(id: $bindingId, app: Application::APP_ID, title: $this->titleOf(binding: $binding, render: $render), author: $this->author |
| 37 | decidiq | `lib/Service/AgendaService.php:181` | method-missing | loud | $this->calendarEventService->updateMeetingEvent() on OCA\OpenRegister\Service\CalendarEventService |
| 38 | decidiq | `lib/Service/MailVoteReplyProcessor.php:465` | method-missing | loud | $notificationService->createNotification() on OCA\OpenRegister\Service\NotificationService |
| 39 | buildiq | `lib/Service/VersionPromotionService.php:599` | method-missing | loud | $this->objectService->getLockInfo() on OCA\OpenRegister\Contract\ObjectServiceInterface |
| 40 | buildiq | `lib/Service/ApplicationVersionService.php:840` | entity-accessor-missing | loud | $register->getMetadata() on OCA\OpenRegister\Db\Register |
| 41 | buildiq | `lib/Service/ApplicationVersionService.php:849` | entity-accessor-missing | loud | $register->setMetadata() on OCA\OpenRegister\Db\Register |

## opencatalogi

Local dir `opencatalogi`, branch `HEAD`, sha `7a191dbb7a3b3c705ae48b746f993408b155f8dd`.

### Runtime breaks (lib): 0

### Test-suite breaks (tests): 1

| file:line | category | stub | app calls | OR main now | fix |
|---|---|---|---|---|---|
| `tests/Stubs/OpenRegister/Service/ObjectService.php:297` | constructor-shape | stub | new \OCA\OpenRegister\Service\Object\PermissionHandler() | OCA\OpenRegister\Service\Object\PermissionHandler::__construct(private readonly PermissionCallerServices $caller, private readonly PermissionDataServices $data, private readonly PermissionRuleServices | MISSING-REQUIRED-ARG: `$caller` (ArgumentCountError); MISSING-REQUIRED-ARG: `$data` (ArgumentCountError); MISSING-REQUIRED-ARG: `$rules` (ArgumentCountError); MISSING-REQUIRED-ARG: `$grants` (Argument |

### Test stubs of OR classes that drifted from OR main: 8 of 33

- `tests/Stubs/OpenRegister/Db/FileMapper.php:21` `OCA\OpenRegister\Db\FileMapper` (alias): getFiles() vs OR main getFiles($node, $ids) [lib/Files/Db/FileMapper.php:335]
- `tests/Stubs/OpenRegister/Db/SchemaMapper.php:22` `OCA\OpenRegister\Db\SchemaMapper` (exists): findAll() vs OR main findAll($limit, $offset, $filters, $searchConditions, $searchParams, $_extend, $_rbac, $_multitenancy) [lib/Db/SchemaMapper.php:967]
- `tests/Stubs/OpenRegister/Service/Dmn/DecisionTableEvaluator.php:41` `OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator` (exists): ruleMatches($rule, $declaredInputs, $coercedInputs, $ruleIndex) vs OR main ruleMatches($cells, $inputs) [lib/Service/Dmn/DecisionTableEvaluator.php:222]; assertAllOutputsAgree($matchedRules, $declaredOutputs, $matchedIds) vs OR main assertAllOutputsAgree($match) [lib/Service/Dmn/DecisionTableEvaluator.php:258]; collectOutputs($matchedRules, $declaredOutputs) vs OR main collectOutputs($match) [lib/Service/Dmn/DecisionTableEvaluator.php:282]; applyHitPolicy($hitPolicy, $matchedRules, $declaredOutputs) vs OR main applyHitPolicy($hitPolicy, $match) [lib/Service/Dmn/DecisionTableEvaluator.php:360]
- `tests/Stubs/OpenRegister/Service/Dmn/UnaryTestEvaluator.php:75` `OCA\OpenRegister\Service\Dmn\UnaryTestEvaluator` (exists): matchesRange($match, $value, $type) vs OR main matchesRange($match, $subject) [lib/Service/Dmn/UnaryTestEvaluator.php:335]; matchesComparison($operator, $remainder, $value, $type) vs OR main matchesComparison($trimmed, $subject) [lib/Service/Dmn/UnaryTestEvaluator.php:359]; equals(): not on OR main
- `tests/Stubs/OpenRegister/Service/FileService.php:21` `OCA\OpenRegister\Service\FileService` (exists): getFilesForEntity($entity) vs OR main getFilesForEntity($entity, $sharedFilesOnly) [lib/Service/FileService.php:695]
- `tests/Stubs/OpenRegister/Service/Object/PermissionHandler.php:27` `OCA\OpenRegister\Service\Object\PermissionHandler` (exists): hasPermission($schema, $action, $userId, $objectOwner, $_rbac, $object) vs OR main hasPermission($schema, $action, $userId, $objectOwner, $_rbac, $object, $register) [lib/Service/Object/PermissionHandler.php:437]
- `tests/Stubs/OpenRegister/Service/ObjectService.php:26` `OCA\OpenRegister\Service\ObjectService` (exists): find($id, $_extend, $files, $register, $schema, $_rbac, $_multitenancy) vs OR main find($id, $_extend, $files, $register, $schema, $_rbac, $_multitenancy, $_render, $_audit) [lib/Service/ObjectService.php:1264]; deleteObject($uuid, $register, $schema, $_rbac, $_multitenancy, $_retentionSweep) vs OR main deleteObject($uuid, $register, $schema, $_rbac, $_multitenancy, $_retentionSweep, $currentUser, $permanent) [lib/Service/ObjectService.php:3827]; searchObjects($query, $_rbac, $_multitenancy, $ids, $uses, $views) vs OR main searchObjects($query, $_rbac, $_multitenancy, $ids, $uses, $views, $_viewScopeRequired) [lib/Service/ObjectService.php:4771]
- `tests/Stubs/OpenRegister/Service/RegisterResolverService.php:21` `OCA\OpenRegister\Service\RegisterResolverService` (exists): resolveRegisterId($appId, $key) vs OR main resolveRegisterId($appId, $configKey, $default, $organisationUuid) [lib/Service/RegisterResolverService.php:129]; resolveSchemaId($appId, $key) vs OR main resolveSchemaId($appId, $configKey, $default, $organisationUuid) [lib/Service/RegisterResolverService.php:189]

### Implements / extends OR classes: 3

- `lib/Mcp/OpenCatalogiToolProvider.php:64` OpenCatalogiToolProvider implements `OCA\OpenRegister\Mcp\IMcpToolProvider` (exists)
- `lib/Observability/OpenCatalogiMetricsProvider.php:66` OpenCatalogiMetricsProvider implements `OCA\OpenRegister\AppHost\IMetricsProvider` (exists)
- `lib/Tool/CMSTool.php:65` CMSTool implements `OCA\OpenRegister\Tool\ToolInterface` (alias)

### OR event listeners: 14 registrations

- `OCA\OpenRegister\Event\ObjectCreatedEvent` (exists, OR main constructed): 3x, e.g. `lib/Listener/ListenerRegistrar.php:57`
- `OCA\OpenRegister\Event\ObjectCreatingEvent` (exists, OR main constructed): 1x, e.g. `lib/Listener/ListenerRegistrar.php:109`
- `OCA\OpenRegister\Event\ObjectDeletedEvent` (exists, OR main constructed): 1x, e.g. `lib/Listener/ListenerRegistrar.php:100`
- `OCA\OpenRegister\Event\ObjectUpdatedEvent` (exists, OR main constructed): 5x, e.g. `lib/Listener/ListenerRegistrar.php:61`
- `OCA\OpenRegister\Event\ObjectUpdatingEvent` (exists, OR main constructed): 1x, e.g. `lib/Listener/ListenerRegistrar.php:110`
- `OCA\OpenRegister\Event\RegisterCreatedEvent` (exists, OR main constructed): 1x, e.g. `lib/Listener/ListenerRegistrar.php:129`
- `OCA\OpenRegister\Event\RegisterUpdatedEvent` (exists, OR main constructed): 1x, e.g. `lib/Listener/ListenerRegistrar.php:130`
- `OCA\OpenRegister\Event\ToolRegistrationEvent` (exists, OR main constructed): 1x, e.g. `lib/Listener/ListenerRegistrar.php:133`

<details><summary>Dynamic call sites in lib/ (method_exists, is_callable, $x->$m(), call_user_func): 46. Always suspect: a renamed OR method makes these silently skip.</summary>

- `lib/Controller/InspectionRecords.php:159` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Controller/PublicationsController.php:741` `if (is_array($item) === false && method_exists(object_or_class: $item, method: 'jsonSerialize') === true) {`
- `lib/Controller/SitemapDirectives.php:125` `if (method_exists($catalog, 'getObject') === true) {`
- `lib/Mcp/OpenCatalogiToolProvider.php:580` `if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {`
- `lib/Mcp/OpenCatalogiToolProvider.php:587` `if (is_object($item) === true && method_exists($item, 'getObject') === true) {`
- `lib/Observability/OpenCatalogiMetricsProvider.php:143` `if (method_exists($object, 'jsonSerialize') === true) {`
- `lib/Portal/ServiceStatusIndex.php:238` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/AttachmentMigrationService.php:307` `if (is_object($row) === true && method_exists($row, 'getUuid') === true) {`
- `lib/Service/CatalogAccessService.php:378` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/DcatService.php:931` `if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {`
- `lib/Service/FlattensObjectRows.php:43` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/OoapiService.php:509` `if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {`
- `lib/Service/Portal/PortalObjectStore.php:285` `if (method_exists($service, 'runAsAnonymous') === true) {`
- `lib/Service/Portal/PortalObjectStore.php:289` `if (method_exists($service, 'runAsAnonymous') === false) {`
- `lib/Service/Portal/PortalObjectStore.php:331` `if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Service/Publication/ObligationReadService.php:296` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/PublicationQueryService.php:1071` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/PublicationQueryService.php:2180` `if (method_exists($objectService, 'runAsAnonymous') === true) {`
- `lib/Service/ReadsObjectProperties.php:113` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/RegisterSchemaLinkService.php:236` `&& is_callable([$schema, 'getId']) === true`
- `lib/Service/RegisterSchemaLinkService.php:236` `&& is_callable([$schema, 'getId']) === true`
- `lib/Service/RegisterSchemaLinkService.php:237` `&& is_callable([$schema, 'getSlug']) === true;`
- `lib/Service/RegisterSchemaLinkService.php:237` `&& is_callable([$schema, 'getSlug']) === true;`
- `lib/Service/RegisterSchemaLinkService.php:317` `&& is_callable([$register, 'getSlug']) === true`
- `lib/Service/RegisterSchemaLinkService.php:317` `&& is_callable([$register, 'getSlug']) === true`
- `lib/Service/RetentionService.php:394` `if (method_exists($error, 'getErrorCode') === true && $error->getErrorCode() === 'no_rule_matched') {`
- `lib/Service/SchemaOrgService.php:147` `if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {`
- `lib/Service/ServiceCatalogueService.php:366` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/SettingsService.php:772` `if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Service/SettingsService.php:1653` `if (method_exists($entity, 'jsonSerialize') === false) {`
- `lib/Service/SettingsService.php:1950` `if (is_object($catalog) === true && method_exists($catalog, 'jsonSerialize') === true) {`
- `lib/Service/SitemapService.php:434` `if (method_exists($catalog, 'getObject') === true) {`
- `lib/Service/UsageCounterService.php:718` `if (method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Woo/BatchPublicationWriter.php:401` `if (is_object($entity) === true && method_exists($entity, 'getUuid') === true) {`
- `lib/Service/Woo/BatchPublicationWriter.php:405` `if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Service/Woo/DocumentRedactor.php:373` `if (is_object($batch) === true && method_exists($batch, 'jsonSerialize') === true) {`
- `lib/Service/Woo/StatutoryTerm.php:482` `if (method_exists($timer, 'getExtensionCount') === true) {`
- `lib/Service/Woo/StatutoryTerm.php:487` `if (method_exists($timer, 'getExtensionMax') === true) {`
- `lib/Service/Woo/StatutoryTerm.php:492` `if (method_exists($timer, 'getUuid') === true) {`
- `lib/Service/Woo/WooCategoryRegistry.php:431` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/WooRegistrationService.php:254` `if (is_object($found) === true && method_exists($found, 'jsonSerialize') === true) {`
- `lib/Service/WooService.php:449` `if (method_exists($link, 'jsonSerialize') === true) {`
- `lib/Service/WooService.php:899` `if ($mapper === null \|\| method_exists($mapper, 'findNewestForAnchor') === false) {`
- `lib/Service/WooService.php:911` `if (is_object($sequence) === true && method_exists($sequence, 'getStatus') === true) {`
- `lib/Tool/CMSTool.php:973` `if (method_exists($this, $camelCaseMethod) === false) {`
- `lib/Tool/CMSTool.php:977` `$result = $this->$camelCaseMethod(...$this->callArguments(method: new ReflectionMethod($this, $camelCaseMethod), arguments: $arguments));`

</details>

Unresolved call sites (receiver type unknown, magic, or spread args): 58, listed in `opencatalogi.json`.

## integriq

Local dir `openconnector`, branch `development`, sha `cd844692cca2453c365bb25ed6bd558aa62022a7`.

### Runtime breaks (lib): 0

### Test-suite breaks (tests): 16

| file:line | category | stub | app calls | OR main now | fix |
|---|---|---|---|---|---|
| `tests/Helpers/MigrationSimulatingObjectService.php:112` | contract-signature | stub | MigrationSimulatingObjectService::find($id, ?string $register = null, ?string $schema = null, bool $_rbac = true, bool $_multitenancy = true, bool $_render = tr | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::find(int \| string $id, ?array $_extend=[], bool $files=false, Register \| string \| int \| null $register=null, Schema \| string \| int \| null $ | align the signature with OR main (fatal at class load): takes 8 params, OR declares 9; param 2 `$register` typed `null\|string`, OR has `array\|null`; param 3 `$schema` typed `null\|string`, OR has `bool |
| `tests/Helpers/MigrationSimulatingObjectService.php:174` | contract-signature | stub | MigrationSimulatingObjectService::saveObject($object, ?string $register = null, ?string $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multite | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::saveObject(array \| ObjectEntity $object, ?array $extend=[], Register \| string \| int \| null $register=null, Schema \| string \| int \| null $sc | align the signature with OR main (fatal at class load): takes 8 params, OR declares 15; param 2 `$register` typed `null\|string`, OR has `array\|null`; param 3 `$schema` typed `null\|string`, OR has `OCA |
| `tests/Helpers/NestedWriteOnlyRenderBoundaryObjectService.php:93` | contract-signature | stub | NestedWriteOnlyRenderBoundaryObjectService::find($id, ?string $register = null, ?string $schema = null, bool $_rbac = true, bool $_multitenancy = true, bool $_r | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::find(int \| string $id, ?array $_extend=[], bool $files=false, Register \| string \| int \| null $register=null, Schema \| string \| int \| null $ | align the signature with OR main (fatal at class load): takes 8 params, OR declares 9; param 2 `$register` typed `null\|string`, OR has `array\|null`; param 3 `$schema` typed `null\|string`, OR has `bool |
| `tests/Helpers/RenderBoundarySimulatingObjectService.php:113` | contract-signature | stub | RenderBoundarySimulatingObjectService::find($id, ?string $register = null, ?string $schema = null, bool $_rbac = true, bool $_multitenancy = true, bool $_render | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::find(int \| string $id, ?array $_extend=[], bool $files=false, Register \| string \| int \| null $register=null, Schema \| string \| int \| null $ | align the signature with OR main (fatal at class load): takes 8 params, OR declares 9; param 2 `$register` typed `null\|string`, OR has `array\|null`; param 3 `$schema` typed `null\|string`, OR has `bool |
| `tests/Unit/Mcp/IntegriqAgentToolsTest.php:75` | contract-signature | stub | InMemoryObjects::find($id, ?string $register=null, ?string $schema=null, bool $_rbac=true, bool $_multitenancy=true, bool $_render=true, bool $_audit=true, ?arr | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::find(int \| string $id, ?array $_extend=[], bool $files=false, Register \| string \| int \| null $register=null, Schema \| string \| int \| null $ | align the signature with OR main (fatal at class load): takes 8 params, OR declares 9; param 2 `$register` typed `null\|string`, OR has `array\|null`; param 3 `$schema` typed `null\|string`, OR has `bool |
| `tests/Unit/Mcp/IntegriqAgentToolsTest.php:101` | contract-signature | stub | InMemoryObjects::saveObject($object, ?string $register=null, ?string $schema=null, ?string $uuid=null, bool $_rbac=true, bool $_multitenancy=true, bool $silent= | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::saveObject(array \| ObjectEntity $object, ?array $extend=[], Register \| string \| int \| null $register=null, Schema \| string \| int \| null $sc | align the signature with OR main (fatal at class load): takes 8 params, OR declares 15; param 2 `$register` typed `null\|string`, OR has `array\|null`; param 3 `$schema` typed `null\|string`, OR has `OCA |
| `tests/Unit/Service/PaymentIntentServiceLockdownTest.php:108` | contract-signature | stub | LockdownSimulatingObjectService::saveObject($object, ?string $register = null, ?string $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multiten | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::saveObject(array \| ObjectEntity $object, ?array $extend=[], Register \| string \| int \| null $register=null, Schema \| string \| int \| null $sc | align the signature with OR main (fatal at class load): takes 8 params, OR declares 15; param 2 `$register` typed `null\|string`, OR has `array\|null`; param 3 `$schema` typed `null\|string`, OR has `OCA |
| `tests/Helpers/SchemaMapperAdapterDouble.php:43` | override-signature | stub | SchemaMapperAdapterDouble::updateFromArray(int\|string $id, array $object, bool $updateVersion, bool $patch=false): mixed | OCA\OpenRegister\Service\ObjectServiceMapperAdapter::updateFromArray(int\|string $id, array $object, bool $validate = true, bool $patch = false): OCA\OpenRegister\Db\ObjectEntity (lib/Service/ObjectSer | align the signature with OR main (fatal at class load): requires 3 params, OR requires 2 |
| `tests/Helpers/OpenRegisterCredentials.php:64` | constructor-shape | stub | new AuthorizationService(userManager: $userManager, userSession: $userSession, consumerMapper: $consumerMapper, cacheFactory: $cacheFactory, groupManager: $grou | OCA\OpenRegister\Service\AuthorizationService::__construct(UserDirectory $directory, private readonly ConsumerMapperInterface $consumerMapper, private readonly ?\OCA\OpenRegister\Service\Rbac\TokenGra | UNKNOWN-NAMED-ARG: `userManager:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `userSession:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `groupManager:` (Error: Unknown named paramet |
| `tests/Unit/Controller/CatalogControllerTest.php:90` | constructor-shape | stub | new \OCA\OpenRegister\Service\Integration\IntegrationRegistry() | OCA\OpenRegister\Service\Integration\IntegrationRegistry::__construct(private LoggerInterface $logger) (lib/Service/Integration/IntegrationRegistry.php:106) | MISSING-REQUIRED-ARG: `$logger` (ArgumentCountError) |
| `tests/Unit/Flow/FlowNodeListenerTest.php:184` | constructor-shape | stub | new FlowNodeRegistry() | OCA\OpenRegister\Service\Flow\FlowNodeRegistry::__construct(private readonly IEventDispatcher $dispatcher, private readonly LoggerInterface $logger, private readonly ?IURLGenerator $urls = null, priva | MISSING-REQUIRED-ARG: `$dispatcher` (ArgumentCountError); MISSING-REQUIRED-ARG: `$logger` (ArgumentCountError) |
| `tests/Unit/Flow/FlowNodeListenerTest.php:216` | constructor-shape | stub | new FlowNodeRegistry() | OCA\OpenRegister\Service\Flow\FlowNodeRegistry::__construct(private readonly IEventDispatcher $dispatcher, private readonly LoggerInterface $logger, private readonly ?IURLGenerator $urls = null, priva | MISSING-REQUIRED-ARG: `$dispatcher` (ArgumentCountError); MISSING-REQUIRED-ARG: `$logger` (ArgumentCountError) |
| `tests/Unit/Service/CatalogRegistryServiceTest.php:60` | constructor-shape | stub | new IntegrationRegistry() | OCA\OpenRegister\Service\Integration\IntegrationRegistry::__construct(private LoggerInterface $logger) (lib/Service/Integration/IntegrationRegistry.php:106) | MISSING-REQUIRED-ARG: `$logger` (ArgumentCountError) |
| `tests/Unit/Service/CatalogRegistryStatusVocabularyTest.php:51` | constructor-shape | stub | new IntegrationRegistry() | OCA\OpenRegister\Service\Integration\IntegrationRegistry::__construct(private LoggerInterface $logger) (lib/Service/Integration/IntegrationRegistry.php:106) | MISSING-REQUIRED-ARG: `$logger` (ArgumentCountError) |
| `tests/Unit/Settings/CaseSystemSeedTest.php:40` | constructor-shape | stub | new IntegrationRegistry() | OCA\OpenRegister\Service\Integration\IntegrationRegistry::__construct(private LoggerInterface $logger) (lib/Service/Integration/IntegrationRegistry.php:106) | MISSING-REQUIRED-ARG: `$logger` (ArgumentCountError) |
| `tests/stubs/OCA/OpenRegister/Service/AuthorizationService.php:185` | constructor-shape |  | new TokenIdentity(mechanism: $mechanism, reference: $reference, name: $consumer->name, ownerUid: $consumer->userId, ownerName: null, consumerUuid: $consumer->uu | OCA\OpenRegister\Service\Audit\TokenIdentity::__construct(private readonly string $mechanism, ?NamedIdentifier $token = null, ?NamedIdentifier $owner = null, ?NamedIdentifier $consumer = null) (lib/Se | UNKNOWN-NAMED-ARG: `reference:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `name:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `ownerUid:` (Error: Unknown named parameter); UNKNOWN- |

### Test stubs of OR classes that drifted from OR main: 24 of 74

- `tests/Unit/Service/Consumer/Fixtures/old-openregister-probe.php:24` `OCA\OpenRegister\Service\AuthorizationService` (exists): authorizeJwt($authorization) vs OR main authorizeJwt($authorization, $consumers) [lib/Service/AuthorizationService.php:292]; authorizeApiKey($header, $keys) vs OR main authorizeApiKey($header, $keys, $consumers) [lib/Service/AuthorizationService.php:571]
- `tests/stubs/OCA/OpenRegister/AppHost/Controller/GenericHealthController.php:31` `OCA\OpenRegister\AppHost\Controller\GenericHealthController` (exists): __construct($appName, $request) vs OR main __construct($appName, $request, $manifestLoader, $executor) [lib/AppHost/Controller/GenericHealthController.php:61]
- `tests/stubs/OCA/OpenRegister/Db/RegisterMapper.php:18` `OCA\OpenRegister\Db\RegisterMapper` (exists): findAll($limit, $offset, $filters) vs OR main findAll($limit, $offset, $filters, $searchConditions, $searchParams, $_rbac, $_multitenancy) [lib/Db/RegisterMapper.php:562]
- `tests/stubs/OCA/OpenRegister/Db/SchemaMapper.php:18` `OCA\OpenRegister\Db\SchemaMapper` (exists): findAll($limit, $offset, $filters) vs OR main findAll($limit, $offset, $filters, $searchConditions, $searchParams, $_extend, $_rbac, $_multitenancy) [lib/Db/SchemaMapper.php:967]; findBySlug($slug) vs OR main findBySlug($slug, $limit, $offset, $_rbac, $_multitenancy) [lib/Db/SchemaMapper.php:893]
- `tests/stubs/OCA/OpenRegister/Dto/DeletionAnalysis.php:33` `OCA\OpenRegister\Dto\DeletionAnalysis` (exists): __construct($deletable, $cascadeTargets, $nullifyTargets, $defaultTargets, $blockers, $chainPaths) vs OR main __construct($deletable, $effects, $blockers, $chainPaths) [lib/Dto/DeletionAnalysis.php:64]
- `tests/stubs/OCA/OpenRegister/Exception/ReferentialIntegrityException.php:37` `OCA\OpenRegister\Exception\ReferentialIntegrityException` (exists): __construct($analysis, $code, $previous) vs OR main __construct($analysis) [lib/Exception/ReferentialIntegrityException.php:48]
- `tests/stubs/OCA/OpenRegister/Exception/ValidationException.php:32` `OCA\OpenRegister\Exception\ValidationException` (exists): __construct($message, $code, $previous) vs OR main __construct($message, $code, $previous, $errors) [lib/Exception/ValidationException.php:64]
- `tests/stubs/OCA/OpenRegister/Mcp/Attribute/McpTool.php:74` `OCA\OpenRegister\Mcp\Attribute\McpTool` (exists): __construct($name, $description, $readOnlyHint, $destructiveHint, $idempotentHint, $scope, $subject, $action) vs OR main __construct($name, $description, $readOnlyHint, $destructiveHint, $idempotentHint, $scope, $subject, $action, $reach, $annotations) [lib/Mcp/Attribute/McpTool.php:130]
- `tests/stubs/OCA/OpenRegister/Mcp/AttributeToolScanner.php:63` `OCA\OpenRegister\Mcp\AttributeToolScanner` (exists): scanClasses($appId, $classNames, $logger) vs OR main scanClasses($app, $classNames) [lib/Mcp/AttributeToolScanner.php:90]; scanClass($appId, $className, $logger) vs OR main scanClass($subject) [lib/Mcp/AttributeToolScanner.php:115]; buildDescriptor($appId, $className, $method, $attribute) vs OR main buildDescriptor($run, $attribute) [lib/Mcp/AttributeToolScanner.php:271]; taxonomyOf(): not on OR main; hintValue(): not on OR main; hasUnknownScope(): not on OR main ...
- `tests/stubs/OCA/OpenRegister/Notifications/Service/Notification/ForcedChannelPolicy.php:53` `OCA\OpenRegister\Notifications\Service\Notification\ForcedChannelPolicy` (exists): decide($resolved, $declaration, $audience) vs OR main decide($preference, $audience) [lib/Notifications/Service/Notification/ForcedChannelPolicy.php:116]
- `tests/stubs/OCA/OpenRegister/Notifications/Service/Notification/NotificationAnnotationValidator.php:48` `OCA\OpenRegister\Notifications\Service\Notification\NotificationAnnotationValidator` (exists): validateAppEvent(): not on OR main; validateActions(): not on OR main; validateTaskVerbTarget(): not on OR main; validateMessage(): not on OR main; validateTransports(): not on OR main
- `tests/stubs/OCA/OpenRegister/Notifications/Service/Notification/ScheduledFilterEvaluator.php:66` `OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterEvaluator` (exists): nodeMatches($node, $objectData, $now) vs OR main nodeMatches($node, $target) [lib/Notifications/Service/Notification/ScheduledFilterEvaluator.php:175]; leafMatches($node, $objectData, $now) vs OR main leafMatches($clause) [lib/Notifications/Service/Notification/ScheduledFilterEvaluator.php:236]; durationMatches($operator, $operand, $actual, $field, $now) vs OR main durationMatches($clause) [lib/Notifications/Service/Notification/ScheduledFilterEvaluator.php:346]; resolveInstant(): not on OR main; parseDuration(): not on OR main; parseDate(): not on OR main
- `tests/stubs/OCA/OpenRegister/Notifications/Service/Notification/ScheduledFilterParser.php:51` `OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterParser` (exists): parseFieldEntry($field, $spec, $ruleKey, $path) vs OR main parseFieldEntry($spec, $position) [lib/Notifications/Service/Notification/ScheduledFilterParser.php:123]; parseOperatorObject($field, $spec, $ruleKey, $path) vs OR main parseOperatorObject($spec, $position) [lib/Notifications/Service/Notification/ScheduledFilterParser.php:162]; validateOperand($operator, $operand, $ruleKey, $path, $operandKey) vs OR main validateOperand($spec, $position) [lib/Notifications/Service/Notification/ScheduledFilterParser.php:293]; validateMembershipOperand($operator, $operand, $ruleKey, $path) vs OR main validateMembershipOperand($spec, $position) [lib/Notifications/Service/Notification/ScheduledFilterParser.php:322]; validateDurationOperand($operator, $operand, $ruleKey, $path) vs OR main validateDurationOperand($spec, $position) [lib/Notifications/Service/Notification/ScheduledFilterParser.php:356]; validateInstantOperand($operator, $operand, $ruleKey, $path) vs OR main validateInstantOperand($spec, $position) [lib/Notifications/Service/Notification/ScheduledFilterParser.php:391] ...
- `tests/stubs/OCA/OpenRegister/Service/Consumer/JwtValidator.php:38` `OCA\OpenRegister\Service\Consumer\JwtValidator` (exists): verifySignature($token, $algorithm, $key) vs OR main verifySignature($token, $key) [lib/Service/Consumer/JwtValidator.php:253]; refuseReplay($payload, $expiresAt, $now) vs OR main refuseReplay($payload, $lifetime) [lib/Service/Consumer/JwtValidator.php:351]
- `tests/stubs/OCA/OpenRegister/Service/Consumer/RsaJwsVerifier.php:46` `OCA\OpenRegister\Service\Consumer\RsaJwsVerifier` (exists): verify($token, $algorithm, $key) vs OR main verify($token, $key) [lib/Service/Consumer/RsaJwsVerifier.php:58]
- `tests/stubs/OCA/OpenRegister/Service/Credential/CredentialBrokerService.php:36` `OCA\OpenRegister\Service\Credential\CredentialBrokerService` (exists): mint($name, $provider, $owner, $allowedApps, $secret, $scope, $organisation) vs OR main mint($name, $provider, $owner, $allowedApps, $secret, $scope, $organisation, $metadata) [lib/Service/Credential/CredentialBrokerService.php:459]
- `tests/stubs/OCA/OpenRegister/Service/Flow/FlowItems.php:44` `OCA\OpenRegister\Service\Flow\FlowItems` (exists): pairedIndex(): not on OR main
- `tests/stubs/OCA/OpenRegister/Service/Flow/FlowNodeRegistry.php:23` `OCA\OpenRegister\Service\Flow\FlowNodeRegistry` (exists): all() vs OR main all($scope) [lib/Service/Flow/FlowNodeRegistry.php:184]
- `tests/stubs/OCA/OpenRegister/Service/Flow/FlowResumeState.php:53` `OCA\OpenRegister\Service\Flow\FlowResumeState` (exists): storableWhen($suspended) vs OR main storableWhen($live) [lib/Service/Flow/FlowResumeState.php:239]
- `tests/stubs/OCA/OpenRegister/Service/Mcp/McpAnnotationValidator.php:42` `OCA\OpenRegister\Service\Mcp\McpAnnotationValidator` (alias): validateEnabled(): not on OR main; validateTools(): not on OR main; validateVerbConfig(): not on OR main; validateFilters(): not on OR main
- `tests/stubs/OCA/OpenRegister/Service/ObjectService.php:29` `OCA\OpenRegister\Service\ObjectService` (exists): find($id, $register, $schema, $_rbac, $_multitenancy, $_render, $_audit, $_extend) vs OR main find($id, $_extend, $files, $register, $schema, $_rbac, $_multitenancy, $_render, $_audit) [lib/Service/ObjectService.php:1264]; saveObject($object, $register, $schema, $uuid, $_rbac, $_multitenancy, $silent, $_validation) vs OR main saveObject($object, $extend, $register, $schema, $uuid, $_rbac, $_multitenancy, $silent, $_validation, $uploadedFiles, $currentUser, $failIfExists, $_unowned, $_dedupOverride, $_owner) [lib/Service/ObjectService.php:2112]; deleteObject($uuid, $register, $schema, $_rbac, $_multitenancy, $_retentionSweep, $currentUser, $permanent) vs OR main deleteObject($uuid, $register, $schema, $_rbac, $_multitenancy, $_retentionSweep, $currentUser, $permanent) [lib/Service/ObjectService.php:3827]; lockObject($identifier, $process, $duration) vs OR main lockObject($identifier, $process, $duration, $advisory, $runUuid, $nodeId) [lib/Service/ObjectService.php:5736]; unlockObject($identifier) vs OR main unlockObject($identifier, $advisory, $runUuid) [lib/Service/ObjectService.php:5793]
- `tests/stubs/OCA/OpenRegister/Service/OrganisationService.php:18` `OCA\OpenRegister\Service\OrganisationService` (exists): getOrganisation(): not on OR main; setOrganisation(): not on OR main
- `tests/stubs/OCA/OpenRegister/Service/Task/TaskService.php:36` `OCA\OpenRegister\Service\Task\TaskService` (exists): import($data, $actor) vs OR main import($data, $actor, $mayReadBlocker, $createdAt) [lib/Service/Task/TaskService.php:282]
- `tests/stubs/openregister-service-stubs.php:28` `OCA\OpenRegister\Service\ConfigurationService` (exists): importFromApp($appId, $data, $version) vs OR main importFromApp($appId, $data, $version, $force) [lib/Service/ConfigurationService.php:507]

### Implements / extends OR classes: 55

- `lib/BackgroundJob/DeferredViewCascadeJob.php:53` DeferredViewCascadeJob extends `OCA\OpenRegister\BackgroundJob\ActorForwardedJob` (exists)
- `lib/Flow/ApplyMappingNode.php:74` ApplyMappingNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Flow/ApplyMappingNode.php:74` ApplyMappingNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys` (exists)
- `lib/Flow/ApplyMappingNode.php:74` ApplyMappingNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm` (exists)
- `lib/Flow/ApprovalRequestNode.php:87` ApprovalRequestNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Flow/ApprovalRequestNode.php:87` ApprovalRequestNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys` (exists)
- `lib/Flow/ApprovalRequestNode.php:87` ApprovalRequestNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm` (exists)
- `lib/Flow/ContractCommitNode.php:96` ContractCommitNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Flow/ContractCommitNode.php:96` ContractCommitNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys` (exists)
- `lib/Flow/ContractCommitNode.php:96` ContractCommitNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm` (exists)
- `lib/Flow/ContractCommitNode.php:96` ContractCommitNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeLogActions` (exists)
- `lib/Flow/ContractMatchNode.php:73` ContractMatchNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Flow/ContractMatchNode.php:73` ContractMatchNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys` (exists)
- `lib/Flow/ContractMatchNode.php:73` ContractMatchNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm` (exists)
- `lib/Flow/ContractSweepNode.php:76` ContractSweepNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Flow/ContractSweepNode.php:76` ContractSweepNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys` (exists)
- `lib/Flow/ContractSweepNode.php:76` ContractSweepNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm` (exists)
- `lib/Flow/ContractSweepNode.php:76` ContractSweepNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeLogActions` (exists)
- `lib/Flow/EventEmitNode.php:67` EventEmitNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Flow/EventEmitNode.php:67` EventEmitNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys` (exists)
- `lib/Flow/EventEmitNode.php:67` EventEmitNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm` (exists)
- `lib/Flow/FetchFileNode.php:89` FetchFileNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Flow/FetchFileNode.php:89` FetchFileNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys` (exists)
- `lib/Flow/FetchFileNode.php:89` FetchFileNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm` (exists)
- `lib/Flow/FetchFileNode.php:89` FetchFileNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeLogActions` (exists)
- `lib/Flow/SourceCallNode.php:86` SourceCallNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Flow/SourceCallNode.php:86` SourceCallNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys` (exists)
- `lib/Flow/SourceCallNode.php:86` SourceCallNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm` (exists)
- `lib/Flow/SourceCallNode.php:86` SourceCallNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeLogActions` (exists)
- `lib/Flow/SourcePaginateNode.php:110` SourcePaginateNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Flow/SourcePaginateNode.php:110` SourcePaginateNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys` (exists)
- `lib/Flow/SourcePaginateNode.php:110` SourcePaginateNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm` (exists)
- `lib/Flow/SourcePaginateNode.php:110` SourcePaginateNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeLogActions` (exists)
- `lib/Flow/SynchronizationRunNode.php:92` SynchronizationRunNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Flow/SynchronizationRunNode.php:92` SynchronizationRunNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys` (exists)
- `lib/Flow/SynchronizationRunNode.php:92` SynchronizationRunNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm` (exists)
- `lib/Flow/SynchronizationRunNode.php:92` SynchronizationRunNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeLogActions` (exists)
- `lib/Mcp/IntegriqScannableServices.php:33` IntegriqScannableServices implements `OCA\OpenRegister\Mcp\IMcpScannableServices` (exists)
- `lib/Notification/ConnectionAlertRecipientResolver.php:48` ConnectionAlertRecipientResolver implements `OCA\OpenRegister\Notifications\Service\Notification\RecipientResolverInterface` (exists)
- `lib/Observability/IntegriqMetricsProvider.php:64` IntegriqMetricsProvider implements `OCA\OpenRegister\AppHost\IMetricsProvider` (exists)
- `lib/Repair/InitializeActions.php:48` InitializeActions extends `OCA\OpenRegister\AppHost\Repair\GenericInitializeActions` (exists)
- `lib/Sections/IntegriqAdmin.php:45` IntegriqAdmin extends `OCA\OpenRegister\AppHost\Settings\GenericSettingsSection` (exists)
- `lib/Service/Adapter/AbstractCategoryAdapterProvider.php:56` AbstractCategoryAdapterProvider extends `OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider` (exists)
- `lib/Service/Consumer/IntegriqConsumerSource.php:44` IntegriqConsumerSource implements `OCA\OpenRegister\Service\Consumer\ConsumerSource` (exists)
- `lib/Service/Integration/SynchronizationContractProvider.php:51` SynchronizationContractProvider extends `OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider` (exists)
- `lib/Settings/IntegriqAdmin.php:52` IntegriqAdmin extends `OCA\OpenRegister\AppHost\Settings\GenericAdminSettings` (exists)
- `tests/Helpers/MigrationSimulatingObjectService.php:45` MigrationSimulatingObjectService extends `OCA\OpenRegister\Service\ObjectService` (exists)
- `tests/Helpers/NestedWriteOnlyRenderBoundaryObjectService.php:48` NestedWriteOnlyRenderBoundaryObjectService extends `OCA\OpenRegister\Service\ObjectService` (exists)
- `tests/Helpers/RenderBoundarySimulatingObjectService.php:45` RenderBoundarySimulatingObjectService extends `OCA\OpenRegister\Service\ObjectService` (exists)
- `tests/Helpers/SchemaMapperAdapterDouble.php:24` SchemaMapperAdapterDouble extends `OCA\OpenRegister\Service\ObjectServiceMapperAdapter` (exists)
- `tests/Helpers/UriObjectEntity.php:24` UriObjectEntity extends `OCA\OpenRegister\Db\ObjectEntity` (exists)
- `tests/Unit/Mcp/IntegriqAgentToolsTest.php:61` InMemoryObjects extends `OCA\OpenRegister\Service\ObjectService` (exists)
- `tests/Unit/Service/PaymentIntentServiceLockdownTest.php:45` LockdownSimulatingObjectService extends `OCA\OpenRegister\Service\ObjectService` (exists)
- `tests/Unit/Support/FakeSlugResolver.php:42` FakeSlugResolver implements `OCA\OpenRegister\Contract\RegisterSlugResolverInterface` (exists)
- `tests/stubs/OCA/OpenRegister/Service/Integration/AbstractIntegrationProvider.php:59` AbstractIntegrationProvider implements `OCA\OpenRegister\Service\Integration\IntegrationProvider` (exists)

### OR event listeners: 1 registrations

- `OCA\OpenRegister\Event\TaskTerminalEvent` (exists, OR main constructed): 1x, e.g. `lib/EventListener/EventListenerBindings.php:205`

<details><summary>Dynamic call sites in lib/ (method_exists, is_callable, $x->$m(), call_user_func): 46. Always suspect: a renamed OR method makes these silently skip.</summary>

- `lib/Adapters/Digikoppeling/PkiOverheidCredentialResolver.php:107` `if (method_exists($broker, self::MATERIAL_METHOD) === false) {`
- `lib/Auth/Idp/IdpConsumerSecretResolver.php:90` `if ($broker === null \|\| method_exists($broker, 'resolveInjectable') === false) {`
- `lib/BackgroundJob/RISPollJob.php:147` `if (method_exists($entity, 'getObject') === true) {`
- `lib/Controller/ApprovalsController.php:103` `return new JSONResponse(['results' => array_map([$this, 'summarize'], $rows)]);`
- `lib/Controller/SynchronizationsController.php:224` `if (method_exists($e, 'getHeaders') === true) {`
- `lib/Controller/SynchronizationsController.php:333` `if (method_exists($e, 'getHeaders') === true) {`
- `lib/EventListener/ObjectCreatedEventListener.php:57` `if (method_exists($event, 'getObject') === false) {`
- `lib/EventListener/ObjectDeletedEventListener.php:57` `if (method_exists($event, 'getObject') === false) {`
- `lib/EventListener/ObjectUpdatedEventListener.php:57` `if (method_exists($event, 'getNewObject') === false) {`
- `lib/EventListener/ViewUpdatedOrCreatedEventListener.php:80` `if (method_exists($event, 'getNewObject') === false) {`
- `lib/Intake/IntakeRoutingService.php:479` `if (method_exists($schema, 'getProperties') === false) {`
- `lib/Observability/IntegriqMetricsProvider.php:141` `if (method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/BrokeredCallService.php:459` `if (method_exists($broker, 'resolveInjectable') === false) {`
- `lib/Service/Consumer/OpenRegisterEntryPoints.php:56` `$available = ($available === true && is_callable([$authorization, $method]) === true);`
- `lib/Service/Dso/DsoAccountRights.php:140` `if (is_object($handler) === false \|\| method_exists($handler, 'hasPermission') === false) {`
- `lib/Service/Dso/DsoAttachmentFetcher.php:132` `$todo = array_keys(array_filter($attachments, [$this, 'needsDownload']));`
- `lib/Service/EndpointService.php:2482` `if (method_exists($schema, 'getRequired') === true) {`
- `lib/Service/Helper/SyncRefResolver.php:153` `if (is_object($row) === true && method_exists($row, 'getUuid') === true) {`
- `lib/Service/Integration/SynchronizationContractProvider.php:252` `if (is_object($contract) === true && method_exists($contract, 'getObject') === true) {`
- `lib/Service/Integration/SynchronizationContractProvider.php:318` `if (is_object($sync) === true && method_exists($sync, 'getObject') === true) {`
- `lib/Service/Integration/WriteBackConflictReader.php:89` `if (is_object($object) === false \|\| method_exists($object, 'getObject') === false) {`
- `lib/Service/Integration/WriteBackConflictReader.php:123` `if (is_object($synchronization) === true && method_exists($synchronization, 'getObject') === true) {`
- `lib/Service/Kiss/CallEventLog.php:171` `if (is_object($result) === true && method_exists($result, 'getObject') === true) {`
- `lib/Service/ListenerSchemaResolver.php:193` `$value = $entity->{$getter}();`
- `lib/Service/Objecten/ObjectenOpenRegisterAccess.php:168` `if (is_object($stored) === false \|\| method_exists($stored, 'getProperties') === false) {`
- `lib/Service/Objecten/ObjectenOpenRegisterAccess.php:178` `if (method_exists($stored, 'getTitle') === true) {`
- `lib/Service/Objecten/ObjectenOpenRegisterAccess.php:182` `if (method_exists($stored, 'getRequired') === true && (array)$stored->getRequired() !== []) {`
- `lib/Service/Objecten/ObjectenOpenRegisterAccess.php:329` `if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Service/Security/InlineSecretMigrationExecutor.php:733` `if (method_exists($broker, 'mint') === false) {`
- `lib/Service/Security/InlineSecretMigrationExecutor.php:765` `if (method_exists($broker, 'resolveInjectable') === false) {`
- `lib/Service/Security/SensitiveFieldRegistry.php:352` `$data->{$key} = self::PLACEHOLDER;`
- `lib/Service/Security/SensitiveFieldRegistry.php:358` `$data->{$key} = $this->maskIssuedTokens(data: $value, changed: $changed);`
- `lib/Service/SoftwareCatalogueService.php:235` `$promisesNodes = array_map([$this, 'extendNode'], $nodes);`
- `lib/Service/SoftwareCatalogueService.php:236` `$promisesConnections = array_map([$this, 'extendConnection'], $connections);`
- `lib/Service/SoftwareCatalogueService.php:328` `$nestedPromises = array_map([$this, 'extendNode'], $node['nodes']);`
- `lib/Service/SynchronizationLogService.php:217` `if (method_exists($contract, 'getUuid') === false) {`
- `lib/Service/SynchronizationService.php:14100` `if (is_object($response) === true && method_exists($response, 'getHeaderLine') === true) {`
- `lib/Service/UserService.php:121` `if (method_exists($user, 'getEmailVerified') === true) {`
- `lib/Service/UserService.php:127` `if (method_exists($user, 'getAvatarScope') === true) {`
- `lib/Service/UserService.php:144` `if (method_exists($user, 'canChangeMailAddress') === true) {`
- `lib/Service/UserService.php:355` `if (method_exists($user, 'getUsedSpace') === true) {`
- `lib/Service/UserService.php:497` `if (method_exists($user, 'getLanguage') === true) {`
- `lib/Service/UserService.php:505` `if (method_exists($user, 'getLocale') === true) {`
- `lib/Service/UserService.php:670` `&& method_exists($user, 'canChangeMailAddress') === true`
- `lib/Service/UserService.php:684` `if (isset($data['language']) === true && method_exists($user, 'setLanguage') === true) {`
- `lib/Service/UserService.php:689` `if (isset($data['locale']) === true && method_exists($user, 'setLocale') === true) {`

</details>

Unresolved call sites (receiver type unknown, magic, or spread args): 155, listed in `integriq.json`.

## filinq

Local dir `docudesk`, branch `development`, sha `ab6c1ebb435627aedeb92face7148663e6040cce`.

### Runtime breaks (lib): 1

| file:line | category | app calls | OR main now | fix |
|---|---|---|---|---|
| `lib/Service/Conversion/EmlBackend.php:139` | dynamic-method-gone (SILENT) | method_exists($fileService, 'anonymizeEmlStructured') on OCA\OpenRegister\Service\FileService | OCA\OpenRegister\Service\FileService (lib/Service/FileService.php) has no anonymizeEmlStructured: branch silently skipped | call the replacement method directly |

### Test-suite breaks (tests): 111

| file:line | category | stub | app calls | OR main now | fix |
|---|---|---|---|---|---|
| `tests/unit/Service/BatchStateTestDoubles.php:302` | contract-signature |  | InMemoryObjectServiceFake::find(string $id = '', string $register = '', string $schema = '', bool $_rbac = true, bool $_multitenancy = true, bool $_render = tru | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::find(int \| string $id, ?array $_extend=[], bool $files=false, Register \| string \| int \| null $register=null, Schema \| string \| int \| null $ | align the signature with OR main (fatal at class load): takes 7 params, OR declares 9; param 1 `$id` typed `string`, OR has `int\|string`; param 2 `$register` typed `string`, OR has `array\|null`; param |
| `tests/unit/Service/BatchStateTestDoubles.php:331` | contract-signature |  | InMemoryObjectServiceFake::saveObject(array $object = [], string $register = '', string $schema = '', ?string $uuid = null, bool $_rbac = true, bool $_multitena | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::saveObject(array \| ObjectEntity $object, ?array $extend=[], Register \| string \| int \| null $register=null, Schema \| string \| int \| null $sc | align the signature with OR main (fatal at class load): takes 8 params, OR declares 15; param 1 `$object` typed `array`, OR has `OCA\OpenRegister\Db\ObjectEntity\|array`; param 2 `$register` typed `str |
| `tests/unit/Service/BatchStateTestDoubles.php:367` | contract-signature |  | InMemoryObjectServiceFake::deleteObject(string $uuid = '', string $register = '', string $schema = '', bool $_rbac = true, bool $_multitenancy = true) | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::deleteObject(string $uuid, Register \| string \| int \| null $register=null, Schema \| string \| int \| null $schema=null, bool $_rbac=true, bool | align the signature with OR main (fatal at class load): takes 5 params, OR declares 8; param 2 `$register` typed `string`, OR has `OCA\OpenRegister\Db\Register\|int\|null\|string`; param 3 `$schema` type |
| `tests/unit/Service/BatchStateTestDoubles.php:447` | contract-signature |  | FixedResultObjectServiceFake::find(string $id = '', string $register = '', string $schema = '', bool $_rbac = true, bool $_multitenancy = true, bool $_render =  | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::find(int \| string $id, ?array $_extend=[], bool $files=false, Register \| string \| int \| null $register=null, Schema \| string \| int \| null $ | align the signature with OR main (fatal at class load): takes 7 params, OR declares 9; param 1 `$id` typed `string`, OR has `int\|string`; param 2 `$register` typed `string`, OR has `array\|null`; param |
| `tests/unit/Service/BatchStateTestDoubles.php:500` | contract-signature |  | RefusingObjectServiceFake::saveObject(array $object = [], string $register = '', string $schema = '', ?string $uuid = null, bool $_rbac = true, bool $_multitena | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::saveObject(array \| ObjectEntity $object, ?array $extend=[], Register \| string \| int \| null $register=null, Schema \| string \| int \| null $sc | align the signature with OR main (fatal at class load): takes 8 params, OR declares 15; param 1 `$object` typed `array`, OR has `OCA\OpenRegister\Db\ObjectEntity\|array`; param 2 `$register` typed `str |
| `tests/unit/Service/BatchStateTestDoubles.php:526` | contract-signature |  | RefusingObjectServiceFake::deleteObject(string $uuid = '', string $register = '', string $schema = '', bool $_rbac = true, bool $_multitenancy = true) | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::deleteObject(string $uuid, Register \| string \| int \| null $register=null, Schema \| string \| int \| null $schema=null, bool $_rbac=true, bool | align the signature with OR main (fatal at class load): takes 5 params, OR declares 8; param 2 `$register` typed `string`, OR has `OCA\OpenRegister\Db\Register\|int\|null\|string`; param 3 `$schema` type |
| `tests/unit/Service/Classification/ClassificationDoubles.php:64` | contract-signature |  | ClassificationObjectStore::saveObject(array $object = [], string $register = '', string $schema = '', ?string $uuid = null, bool $_rbac = true, bool $_multitena | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::saveObject(array \| ObjectEntity $object, ?array $extend=[], Register \| string \| int \| null $register=null, Schema \| string \| int \| null $sc | align the signature with OR main (fatal at class load): takes 8 params, OR declares 15; param 1 `$object` typed `array`, OR has `OCA\OpenRegister\Db\ObjectEntity\|array`; param 2 `$register` typed `str |
| `tests/unit/Service/Classification/ClassificationDoubles.php:98` | contract-signature |  | ClassificationObjectStore::searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = [], bool $_rbac = true, bool $_multitenancy = true) | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters=[], bool $_rbac=true, bool $_multitenancy=true): array\|int (li | align the signature with OR main (fatal at class load): return `none`, OR has `array\|int` |
| `tests/unit/Service/EmailIngestion/EmailIngestionDoubles.php:90` | contract-signature |  | EmailObjectStore::find(string $id = '', string $register = '', string $schema = '', bool $_rbac = true, bool $_multitenancy = true, bool $_render = true, bool $ | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::find(int \| string $id, ?array $_extend=[], bool $files=false, Register \| string \| int \| null $register=null, Schema \| string \| int \| null $ | align the signature with OR main (fatal at class load): takes 7 params, OR declares 9; param 1 `$id` typed `string`, OR has `int\|string`; param 2 `$register` typed `string`, OR has `array\|null`; param |
| `tests/unit/Service/EmailIngestion/EmailIngestionDoubles.php:119` | contract-signature |  | EmailObjectStore::saveObject(array $object = [], string $register = '', string $schema = '', ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = tru | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::saveObject(array \| ObjectEntity $object, ?array $extend=[], Register \| string \| int \| null $register=null, Schema \| string \| int \| null $sc | align the signature with OR main (fatal at class load): takes 8 params, OR declares 15; param 1 `$object` typed `array`, OR has `OCA\OpenRegister\Db\ObjectEntity\|array`; param 2 `$register` typed `str |
| `tests/unit/Service/EmailIngestion/EmailIngestionDoubles.php:156` | contract-signature |  | EmailObjectStore::searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = [], bool $_rbac = true, bool $_multitenancy = true) | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters=[], bool $_rbac=true, bool $_multitenancy=true): array\|int (li | align the signature with OR main (fatal at class load): return `none`, OR has `array\|int` |
| `tests/unit/Service/SigningEnvelope/SigningEnvelopeServiceTest.php:91` | contract-signature |  | EnvelopeObjectStore::find(string $id = '', string $register = '', string $schema = '', bool $_rbac = true, bool $_multitenancy = true, bool $_render = true, boo | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::find(int \| string $id, ?array $_extend=[], bool $files=false, Register \| string \| int \| null $register=null, Schema \| string \| int \| null $ | align the signature with OR main (fatal at class load): takes 7 params, OR declares 9; param 1 `$id` typed `string`, OR has `int\|string`; param 2 `$register` typed `string`, OR has `array\|null`; param |
| `tests/unit/Service/SigningEnvelope/SigningEnvelopeServiceTest.php:116` | contract-signature |  | EnvelopeObjectStore::saveObject(array $object = [], string $register = '', string $schema = '', ?string $uuid = null, bool $_rbac = true, bool $_multitenancy =  | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::saveObject(array \| ObjectEntity $object, ?array $extend=[], Register \| string \| int \| null $register=null, Schema \| string \| int \| null $sc | align the signature with OR main (fatal at class load): takes 8 params, OR declares 15; param 1 `$object` typed `array`, OR has `OCA\OpenRegister\Db\ObjectEntity\|array`; param 2 `$register` typed `str |
| `tests/unit/Service/SigningEnvelope/SigningEnvelopeServiceTest.php:150` | contract-signature |  | EnvelopeObjectStore::searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = [], bool $_rbac = true, bool $_multitenancy = true) | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters=[], bool $_rbac=true, bool $_multitenancy=true): array\|int (li | align the signature with OR main (fatal at class load): return `none`, OR has `array\|int` |
| `tests/unit/Service/Wizard/WizardDoubles.php:78` | contract-signature |  | WizardObjectStore::find(string $id = '', string $register = '', string $schema = '', bool $_rbac = true, bool $_multitenancy = true, bool $_render = true, bool  | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::find(int \| string $id, ?array $_extend=[], bool $files=false, Register \| string \| int \| null $register=null, Schema \| string \| int \| null $ | align the signature with OR main (fatal at class load): takes 7 params, OR declares 9; param 1 `$id` typed `string`, OR has `int\|string`; param 2 `$register` typed `string`, OR has `array\|null`; param |
| `tests/unit/Service/Wizard/WizardDoubles.php:107` | contract-signature |  | WizardObjectStore::saveObject(array $object = [], string $register = '', string $schema = '', ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = tr | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::saveObject(array \| ObjectEntity $object, ?array $extend=[], Register \| string \| int \| null $register=null, Schema \| string \| int \| null $sc | align the signature with OR main (fatal at class load): takes 8 params, OR declares 15; param 1 `$object` typed `array`, OR has `OCA\OpenRegister\Db\ObjectEntity\|array`; param 2 `$register` typed `str |
| `tests/unit/Service/Wizard/WizardDoubles.php:142` | contract-signature |  | WizardObjectStore::searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = [], bool $_rbac = true, bool $_multitenancy = true) | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters=[], bool $_rbac=true, bool $_multitenancy=true): array\|int (li | align the signature with OR main (fatal at class load): return `none`, OR has `array\|int` |
| `tests/unit/Service/Wizard/WizardDoubles.php:175` | contract-signature |  | WizardObjectStore::deleteObject(string $uuid = '', string $register = '', string $schema = '', bool $_rbac = true, bool $_multitenancy = true) | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::deleteObject(string $uuid, Register \| string \| int \| null $register=null, Schema \| string \| int \| null $schema=null, bool $_rbac=true, bool | align the signature with OR main (fatal at class load): takes 5 params, OR declares 8; param 2 `$register` typed `string`, OR has `OCA\OpenRegister\Db\Register\|int\|null\|string`; param 3 `$schema` type |
| `tests/scripts/boot-without-openregister.php:69` | string-lookup-missing |  | string('OCA\OpenRegister\Db\ApprovalChain') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/scripts/boot-without-openregister.php:70` | string-lookup-missing |  | string('OCA\OpenRegister\Db\ApprovalChainMapper') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/scripts/boot-without-openregister.php:71` | string-lookup-missing |  | string('OCA\OpenRegister\Db\ApprovalStep') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/scripts/boot-without-openregister.php:72` | string-lookup-missing |  | string('OCA\OpenRegister\Db\ApprovalStepMapper') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/scripts/boot-without-openregister.php:73` | string-lookup-missing |  | string('OCA\OpenRegister\Service\ApprovalService') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/scripts/boot-without-openregister.php:74` | string-lookup-missing |  | string('OCA\OpenRegister\Controller\ApprovalController') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/scripts/boot-without-openregister.php:75` | string-lookup-missing |  | string('OCA\OpenRegister\Event\ApprovalStepInitiatedEvent') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/scripts/boot-without-openregister.php:76` | string-lookup-missing |  | string('OCA\OpenRegister\Event\ApprovalStepApprovedEvent') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/scripts/boot-without-openregister.php:77` | string-lookup-missing |  | string('OCA\OpenRegister\Event\ApprovalStepRejectedEvent') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/scripts/boot-without-openregister.php:78` | string-lookup-missing |  | string('OCA\OpenRegister\Event\ApprovalStepCompletedEvent') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/unit/AppInfo/RetiredApprovalSurfaceTest.php:61` | string-lookup-missing |  | string('OCA\OpenRegister\Db\ApprovalChain') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/unit/AppInfo/RetiredApprovalSurfaceTest.php:62` | string-lookup-missing |  | string('OCA\OpenRegister\Db\ApprovalStep') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/unit/AppInfo/RetiredApprovalSurfaceTest.php:63` | string-lookup-missing |  | string('OCA\OpenRegister\Service\ApprovalService') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/unit/AppInfo/RetiredApprovalSurfaceTest.php:64` | string-lookup-missing |  | string('OCA\OpenRegister\Controller\ApprovalController') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/unit/AppInfo/RetiredApprovalSurfaceTest.php:65` | string-lookup-missing |  | string('OCA\OpenRegister\Event\ApprovalStep') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/stubs/OpenRegisterStubs.php:1930` | parent-missing | stub | Folder implements OCA\OpenRegister\Service\Node | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |
| `tests/stubs/OpenRegisterStubs.php:2113` | parent-missing | stub | File implements OCA\OpenRegister\Service\Node | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |
| `tests/stubs/OpenRegisterStubs.php:2385` | parent-missing |  | TaskTransitionedEvent extends OCA\OpenRegister\Service\Event | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |
| `tests/stubs/OpenRegisterStubs.php:2425` | parent-missing |  | TaskTerminalEvent extends OCA\OpenRegister\Service\Event | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |
| `tests/stubs/OpenRegisterStubs.php:2468` | parent-missing |  | TaskSequenceCompletedEvent extends OCA\OpenRegister\Service\Event | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |
| `tests/stubs/OpenRegisterStubs.php:2508` | parent-missing |  | ObjectCreatedEvent extends OCA\OpenRegister\Service\Event | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |
| `tests/stubs/OpenRegisterStubs.php:2546` | parent-missing |  | ObjectUpdatingEvent extends OCA\OpenRegister\Service\Event | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |
| `tests/stubs/OpenRegisterStubs.php:2599` | parent-missing |  | ObjectCreatingEvent extends OCA\OpenRegister\Service\Event | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |
| `tests/stubs/OpenRegisterStubs.php:2621` | parent-missing |  | ObjectUpdatedEvent extends OCA\OpenRegister\Service\Event | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |
| `tests/stubs/OpenRegisterStubs.php:2647` | parent-missing |  | ObjectDeletedEvent extends OCA\OpenRegister\Service\Event | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |
| `tests/stubs/OpenRegisterStubs.php:3093` | parent-missing |  | RegisterLeafProvidersEvent extends OCA\OpenRegister\Service\Event | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |
| `tests/unit/EventListener/FilinqEventHandlerTest.php:239` | constructor-shape |  | new ObjectCreatedEvent(null) | OCA\OpenRegister\Event\AbstractObjectEntityEvent::__construct(private readonly ObjectEntity $object) (lib/Event/AbstractObjectEntityEvent.php:39) | TYPE: passes null literal `null` to `$object` typed `OCA\OpenRegister\Db\ObjectEntity` (TypeError) |
| `tests/unit/EventListener/FilinqEventHandlerTest.php:491` | constructor-shape |  | new ObjectUpdatedEvent(null, $this->makeObject(['title' => 'old'])) | OCA\OpenRegister\Event\ObjectUpdatedEvent::__construct(ObjectEntity $newObject, ?ObjectEntity $oldObject = null) (lib/Event/ObjectUpdatedEvent.php:56) | TYPE: passes null literal `null` to `$newObject` typed `OCA\OpenRegister\Db\ObjectEntity` (TypeError) |
| `tests/unit/EventListener/FilinqEventHandlerTest.php:911` | constructor-shape |  | new ObjectDeletedEvent(null) | OCA\OpenRegister\Event\AbstractObjectEntityEvent::__construct(private readonly ObjectEntity $object) (lib/Event/AbstractObjectEntityEvent.php:39) | TYPE: passes null literal `null` to `$object` typed `OCA\OpenRegister\Db\ObjectEntity` (TypeError) |
| `tests/unit/EventListener/FilinqEventListenerTest.php:321` | constructor-shape |  | new ObjectCreatedEvent(null) | OCA\OpenRegister\Event\AbstractObjectEntityEvent::__construct(private readonly ObjectEntity $object) (lib/Event/AbstractObjectEntityEvent.php:39) | TYPE: passes null literal `null` to `$object` typed `OCA\OpenRegister\Db\ObjectEntity` (TypeError) |
| `tests/unit/EventListener/SignerApprovalsTest.php:53` | constructor-shape |  | new Task(state: 'completed', outcome: 'approved', completedBy: 'alice') | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `outcome:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `completedBy:` (Error: Unknown named parameter) |
| `tests/unit/EventListener/SignerApprovalsTest.php:57` | constructor-shape |  | new Task(state: 'completed', outcome: 'done', completedBy: 'bob') | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `outcome:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `completedBy:` (Error: Unknown named parameter) |
| `tests/unit/EventListener/SignerApprovalsTest.php:58` | constructor-shape |  | new Task(state: 'completed', outcome: 'approved', completedBy: ' ') | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `outcome:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `completedBy:` (Error: Unknown named parameter) |
| `tests/unit/EventListener/SignerApprovalsTest.php:60` | constructor-shape |  | new Task(state: 'enabled', outcome: 'approved', completedBy: 'alice') | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `outcome:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `completedBy:` (Error: Unknown named parameter) |
| `tests/unit/EventListener/SignerSequenceTasksTest.php:164` | constructor-shape |  | new Task(uuid: 'task-2', objectUuid: 'doc-1', sequencePosition: 2) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `objectUuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `sequencePosition:` (Error: Unknown named parameter) |
| `tests/unit/EventListener/SignerSequenceTasksTest.php:165` | constructor-shape |  | new Task(uuid: 'task-1', objectUuid: 'doc-1', sequencePosition: 1) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `objectUuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `sequencePosition:` (Error: Unknown named parameter) |
| `tests/unit/EventListener/SignerSequenceTasksTest.php:166` | constructor-shape |  | new Task(uuid: 'task-3', objectUuid: 'doc-1', sequencePosition: 3) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `objectUuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `sequencePosition:` (Error: Unknown named parameter) |
| `tests/unit/EventListener/SignerSequenceTasksTest.php:167` | constructor-shape |  | new TaskSequence(uuid: 'seq-1', anchorObjectUuid: 'doc-1', templateSnapshot: ['positions' => [['order' => 1], ['role' => 'b'], ['order' => 3]]]) | OCA\OpenRegister\Db\TaskSequence::__construct() (lib/Db/TaskSequence.php:271) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `anchorObjectUuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `templateSnapshot:` (Error: Unknown named param |
| `tests/unit/EventListener/SignerSequenceTasksTest.php:182` | constructor-shape |  | new TaskSequence(uuid: 'seq-1', anchorObjectUuid: 'doc-1', templateSnapshot: ['name' => 'sign']) | OCA\OpenRegister\Db\TaskSequence::__construct() (lib/Db/TaskSequence.php:271) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `anchorObjectUuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `templateSnapshot:` (Error: Unknown named param |
| `tests/unit/EventListener/SignerSequenceTasksTest.php:183` | constructor-shape |  | new TaskSequence(uuid: 'seq-1', anchorObjectUuid: 'doc-1', templateSnapshot: ['positions' => []]) | OCA\OpenRegister\Db\TaskSequence::__construct() (lib/Db/TaskSequence.php:271) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `anchorObjectUuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `templateSnapshot:` (Error: Unknown named param |
| `tests/unit/EventListener/SignerSequenceTasksTest.php:184` | constructor-shape |  | new TaskSequence(uuid: 'seq-1', anchorObjectUuid: 'doc-1', templateSnapshot: ['positions' => [['order' => 1], 'two']]) | OCA\OpenRegister\Db\TaskSequence::__construct() (lib/Db/TaskSequence.php:271) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `anchorObjectUuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `templateSnapshot:` (Error: Unknown named param |
| `tests/unit/EventListener/SignerSequenceTasksTest.php:185` | constructor-shape |  | new TaskSequence(uuid: 'seq-1', anchorObjectUuid: 'doc-2', templateSnapshot: ['positions' => [['order' => 1], ['order' => 2]]]) | OCA\OpenRegister\Db\TaskSequence::__construct() (lib/Db/TaskSequence.php:271) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `anchorObjectUuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `templateSnapshot:` (Error: Unknown named param |
| `tests/unit/EventListener/SignerSequenceTasksTest.php:198` | constructor-shape |  | new Task(uuid: 'task-1', objectUuid: 'doc-1', sequencePosition: 1) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `objectUuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `sequencePosition:` (Error: Unknown named parameter) |
| `tests/unit/EventListener/SignerSequenceTasksTest.php:209` | constructor-shape |  | new Task(uuid: 'task-1', objectUuid: 'doc-1', sequencePosition: 1) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `objectUuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `sequencePosition:` (Error: Unknown named parameter) |
| `tests/unit/EventListener/SignerSequenceTasksTest.php:210` | constructor-shape |  | new TaskSequence(uuid: 'seq-1', anchorObjectUuid: 'doc-1', templateSnapshot: ['positions' => [['order' => 1], ['order' => 3]]], resolvedTier: ['positions' => [[ | OCA\OpenRegister\Db\TaskSequence::__construct() (lib/Db/TaskSequence.php:271) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `anchorObjectUuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `templateSnapshot:` (Error: Unknown named param |
| `tests/unit/EventListener/SignerSequenceTasksTest.php:226` | constructor-shape |  | new Task(uuid: 'task-1', objectUuid: 'doc-1', sequencePosition: 1) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `objectUuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `sequencePosition:` (Error: Unknown named parameter) |
| `tests/unit/EventListener/SignerSequenceTasksTest.php:227` | constructor-shape |  | new TaskSequence(uuid: 'seq-1', anchorObjectUuid: 'doc-1', templateSnapshot: ['positions' => [['order' => 1], 'two', ['order' => 3]]]) | OCA\OpenRegister\Db\TaskSequence::__construct() (lib/Db/TaskSequence.php:271) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `anchorObjectUuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `templateSnapshot:` (Error: Unknown named param |
| `tests/unit/EventListener/SignerSequenceTasksTest.php:238` | constructor-shape |  | new TaskSequence(uuid: 'seq-1', anchorObjectUuid: 'doc-1', templateSnapshot: ['positions' => [['order' => 1], ['order' => 2]]]) | OCA\OpenRegister\Db\TaskSequence::__construct() (lib/Db/TaskSequence.php:271) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `anchorObjectUuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `templateSnapshot:` (Error: Unknown named param |
| `tests/unit/EventListener/SignerSequenceTasksTest.php:239` | constructor-shape |  | new Task(uuid: 'task-1', objectUuid: 'doc-1', sequencePosition: 1) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `objectUuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `sequencePosition:` (Error: Unknown named parameter) |
| `tests/unit/EventListener/SignerSequenceTasksTest.php:240` | constructor-shape |  | new Task(uuid: 'task-x', objectUuid: 'doc-1', sequencePosition: null) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `objectUuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `sequencePosition:` (Error: Unknown named parameter) |
| `tests/unit/EventListener/SignerSequenceTasksTest.php:253` | constructor-shape |  | new TaskSequence(uuid: 'seq-1', anchorObjectUuid: 'doc-1', templateSnapshot: ['positions' => [['order' => 1], ['order' => 2]]]) | OCA\OpenRegister\Db\TaskSequence::__construct() (lib/Db/TaskSequence.php:271) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `anchorObjectUuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `templateSnapshot:` (Error: Unknown named param |
| `tests/unit/EventListener/SignerSequenceTasksTest.php:254` | constructor-shape |  | new Task(uuid: 'task-1', objectUuid: 'doc-1', sequencePosition: 1) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `objectUuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `sequencePosition:` (Error: Unknown named parameter) |
| `tests/unit/EventListener/SignerSequenceTasksTest.php:255` | constructor-shape |  | new Task(uuid: 'task-x', objectUuid: 'doc-1', sequencePosition: 1) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `objectUuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `sequencePosition:` (Error: Unknown named parameter) |
| `tests/unit/EventListener/SignerSequenceTasksTest.php:256` | constructor-shape |  | new Task(uuid: 'task-y', objectUuid: 'doc-2', sequencePosition: 1) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `objectUuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `sequencePosition:` (Error: Unknown named parameter) |
| `tests/unit/EventListener/SignerSequenceTasksTest.php:268` | constructor-shape |  | new Task(uuid: 'task-1', objectUuid: 'doc-1', sequencePosition: 1) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `objectUuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `sequencePosition:` (Error: Unknown named parameter) |
| `tests/unit/EventListener/SignerSequenceTasksTest.php:279` | constructor-shape |  | new Task(uuid: 'task-1', objectUuid: 'doc-1', sequencePosition: 1) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `objectUuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `sequencePosition:` (Error: Unknown named parameter) |
| `tests/unit/EventListener/SigningTaskDecisionGoldenTest.php:87` | constructor-shape |  | new Task(uuid: 'task-1', state: 'completed', outcome: $outcome, objectUuid: self::OWNED, sequenceUuid: 'seq-1', sequencePosition: 2, completedBy: $signer, comme | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `outcome:` (Error: Unknown named parameter); UNKNOWN-NAMED |
| `tests/unit/EventListener/SigningTaskDecisionGoldenTest.php:103` | constructor-shape |  | new Task(state: 'completed', outcome: 'approved', objectUuid: self::OWNED, sequenceUuid: 'seq-1', completedBy: 'bob') | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `outcome:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `objectUuid:` (Error: Unknown named parameter); UNKNOWN |
| `tests/unit/EventListener/SigningTaskDecisionGoldenTest.php:104` | constructor-shape |  | new Task(uuid: 't', state: 'completed', outcome: 'rejected', objectUuid: self::OWNED, sequenceUuid: 's', sequencePosition: 0) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `outcome:` (Error: Unknown named parameter); UNKNOWN-NAMED |
| `tests/unit/EventListener/SigningTaskDecisionGoldenTest.php:105` | constructor-shape |  | new Task(uuid: 't', state: 'completed', outcome: 'approved', objectUuid: self::OWNED, sequenceUuid: 's', sequencePosition: 9) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `outcome:` (Error: Unknown named parameter); UNKNOWN-NAMED |
| `tests/unit/EventListener/SigningTaskDecisionGoldenTest.php:106` | constructor-shape |  | new Task(uuid: 't', state: 'completed', outcome: 'approved', objectUuid: 'other', sequenceUuid: 's', sequencePosition: 1) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `outcome:` (Error: Unknown named parameter); UNKNOWN-NAMED |
| `tests/unit/EventListener/SigningTaskDecisionGoldenTest.php:107` | constructor-shape |  | new Task(uuid: 't', state: 'completed', outcome: 'approved', objectUuid: self::OWNED, sequencePosition: 1) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `outcome:` (Error: Unknown named parameter); UNKNOWN-NAMED |
| `tests/unit/EventListener/SigningTaskDecisionGoldenTest.php:108` | constructor-shape |  | new Task(uuid: 't', state: 'completed', outcome: 'approved', sequenceUuid: 's', sequencePosition: 1) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `outcome:` (Error: Unknown named parameter); UNKNOWN-NAMED |
| `tests/unit/EventListener/SigningTaskDecisionGoldenTest.php:109` | constructor-shape |  | new Task(uuid: 't', state: 'terminated', outcome: 'rejected', objectUuid: self::OWNED, sequenceUuid: 's') | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `outcome:` (Error: Unknown named parameter); UNKNOWN-NAMED |
| `tests/unit/EventListener/SigningTaskListenerTerminalTraceTest.php:134` | constructor-shape |  | new Task(state: 'completed', outcome: ' Rejected ', objectUuid: self::OWNED, sequenceUuid: 'seq-1', completedBy: 'carol') | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `outcome:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `objectUuid:` (Error: Unknown named parameter); UNKNOWN |
| `tests/unit/EventListener/SigningTaskListenerTerminalTraceTest.php:210` | constructor-shape |  | new Task(uuid: 'task-1', state: $state, outcome: $outcome, objectUuid: $objectUuid, sequenceUuid: $sequenceUuid, sequencePosition: 2, completedBy: $completedBy, | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `outcome:` (Error: Unknown named parameter); UNKNOWN-NAMED |
| `tests/unit/EventListener/SigningTaskListenerTest.php:269` | constructor-shape |  | new TaskSequence(uuid: 'seq-1', anchorObjectUuid: $anchor, templateSnapshot: ['name' => 'sign', 'positions' => $positions]) | OCA\OpenRegister\Db\TaskSequence::__construct() (lib/Db/TaskSequence.php:271) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `anchorObjectUuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `templateSnapshot:` (Error: Unknown named param |
| `tests/unit/EventListener/SigningTaskListenerTest.php:308` | constructor-shape |  | new Task(uuid: 'task-1', state: $state, outcome: $outcome, candidateGroups: ['filinq-signers'], objectUuid: $objectUuid, sequenceUuid: $sequenceUuid, sequencePo | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `outcome:` (Error: Unknown named parameter); UNKNOWN-NAMED |
| `tests/unit/EventListener/SigningTaskListenerTest.php:355` | constructor-shape |  | new Task(uuid: 'task-3', state: 'available', sequenceUuid: 'seq-1', sequencePosition: 3) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `sequenceUuid:` (Error: Unknown named parameter); UNKNOWN- |
| `tests/unit/EventListener/SigningTaskListenerTest.php:418` | constructor-shape |  | new Task(uuid: 'task-1', state: 'enabled', sequenceUuid: 'seq-1', sequencePosition: 2) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `sequenceUuid:` (Error: Unknown named parameter); UNKNOWN- |
| `tests/unit/EventListener/SigningTaskListenerTest.php:451` | constructor-shape |  | new Task(uuid: 'task-1', state: 'enabled', candidateGroups: ['filinq-signers'], objectUuid: self::OWNED_OBJECT, sequenceUuid: 'seq-1', sequencePosition: 1) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `candidateGroups:` (Error: Unknown named parameter); UNKNO |
| `tests/unit/EventListener/SigningTaskListenerTest.php:474` | constructor-shape |  | new Task(uuid: 'task-1', state: 'enabled', objectUuid: self::OWNED_OBJECT, sequenceUuid: 'seq-1', sequencePosition: null) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `objectUuid:` (Error: Unknown named parameter); UNKNOWN-NA |
| `tests/unit/EventListener/SigningTaskListenerTest.php:485` | constructor-shape |  | new Task(uuid: 'task-1', state: 'enabled', objectUuid: self::OWNED_OBJECT, sequenceUuid: 'seq-1', sequencePosition: null) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `objectUuid:` (Error: Unknown named parameter); UNKNOWN-NA |
| `tests/unit/EventListener/SigningTaskListenerTest.php:498` | constructor-shape |  | new Task(uuid: 'task-1', state: 'enabled', objectUuid: self::OWNED_OBJECT, sequenceUuid: 'seq-1', sequencePosition: 1) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `objectUuid:` (Error: Unknown named parameter); UNKNOWN-NA |
| `tests/unit/EventListener/SigningTaskListenerTest.php:515` | constructor-shape |  | new Task(uuid: 'task-x', state: 'completed', outcome: 'approved', objectUuid: 'other-doc', sequenceUuid: 'seq-1', sequencePosition: 1, completedBy: 'alice') | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `outcome:` (Error: Unknown named parameter); UNKNOWN-NAMED |
| `tests/unit/EventListener/SigningTaskListenerTest.php:544` | constructor-shape |  | new Task(uuid: 'task-3', state: 'enabled', objectUuid: self::OWNED_OBJECT, sequenceUuid: 'seq-1', sequencePosition: 3) | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `objectUuid:` (Error: Unknown named parameter); UNKNOWN-NA |
| `tests/unit/EventListener/SigningTaskListenerTest.php:561` | constructor-shape |  | new Task(uuid: 'task-r', state: 'enabled', outcome: 'approved', objectUuid: self::OWNED_OBJECT, sequenceUuid: 'seq-1', sequencePosition: 1, completedBy: 'alice' | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `outcome:` (Error: Unknown named parameter); UNKNOWN-NAMED |
| `tests/unit/EventListener/SigningTaskListenerTest.php:620` | constructor-shape |  | new TaskSequence(uuid: 'seq-1', anchorObjectUuid: self::OWNED_OBJECT, templateSnapshot: ['positions' => [['order' => 1], ['order' => 2], ['order' => 3]]], resol | OCA\OpenRegister\Db\TaskSequence::__construct() (lib/Db/TaskSequence.php:271) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `anchorObjectUuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `templateSnapshot:` (Error: Unknown named param |
| `tests/unit/EventListener/SigningTaskListenerTest.php:939` | constructor-shape |  | new TaskSequence(uuid: 'seq-1', anchorObjectUuid: self::OWNED_OBJECT) | OCA\OpenRegister\Db\TaskSequence::__construct() (lib/Db/TaskSequence.php:271) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `anchorObjectUuid:` (Error: Unknown named parameter) |
| `tests/unit/EventListener/SigningTaskListenerTest.php:957` | constructor-shape |  | new TaskSequence(uuid: 'seq-9', anchorObjectUuid: 'foreign-object') | OCA\OpenRegister\Db\TaskSequence::__construct() (lib/Db/TaskSequence.php:271) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `anchorObjectUuid:` (Error: Unknown named parameter) |
| `tests/unit/EventListener/SigningTaskListenerTest.php:1137` | constructor-shape |  | new Task(uuid: 'task-'.$position, state: 'completed', outcome: $outcome, objectUuid: self::OWNED_OBJECT, sequenceUuid: 'seq-1', sequencePosition: $position, com | OCA\OpenRegister\Db\Task::__construct() (lib/Db/Task.php:743) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `state:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `outcome:` (Error: Unknown named parameter); UNKNOWN-NAMED |
| `tests/unit/EventListener/SigningTaskListenerTest.php:1158` | constructor-shape |  | new TaskSequence(uuid: 'seq-1', anchorObjectUuid: self::OWNED_OBJECT) | OCA\OpenRegister\Db\TaskSequence::__construct() (lib/Db/TaskSequence.php:271) | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `anchorObjectUuid:` (Error: Unknown named parameter) |
| `tests/unit/EventListener/ValidationRunnerTest.php:233` | constructor-shape |  | new ObjectCreatedEvent(null) | OCA\OpenRegister\Event\AbstractObjectEntityEvent::__construct(private readonly ObjectEntity $object) (lib/Event/AbstractObjectEntityEvent.php:39) | TYPE: passes null literal `null` to `$object` typed `OCA\OpenRegister\Db\ObjectEntity` (TypeError) |
| `tests/unit/Flow/FilinqFlowNodeListenerTest.php:72` | constructor-shape | stub | new FlowNodeRegistry() | OCA\OpenRegister\Service\Flow\FlowNodeRegistry::__construct(private readonly IEventDispatcher $dispatcher, private readonly LoggerInterface $logger, private readonly ?IURLGenerator $urls = null, priva | MISSING-REQUIRED-ARG: `$dispatcher` (ArgumentCountError); MISSING-REQUIRED-ARG: `$logger` (ArgumentCountError) |
| `tests/unit/Flow/FilinqFlowNodeListenerTest.php:100` | constructor-shape | stub | new FlowNodeRegistry() | OCA\OpenRegister\Service\Flow\FlowNodeRegistry::__construct(private readonly IEventDispatcher $dispatcher, private readonly LoggerInterface $logger, private readonly ?IURLGenerator $urls = null, priva | MISSING-REQUIRED-ARG: `$dispatcher` (ArgumentCountError); MISSING-REQUIRED-ARG: `$logger` (ArgumentCountError) |
| `tests/unit/Flow/FilinqFlowNodeListenerTest.php:131` | constructor-shape | stub | new FlowNodeRegistry() | OCA\OpenRegister\Service\Flow\FlowNodeRegistry::__construct(private readonly IEventDispatcher $dispatcher, private readonly LoggerInterface $logger, private readonly ?IURLGenerator $urls = null, priva | MISSING-REQUIRED-ARG: `$dispatcher` (ArgumentCountError); MISSING-REQUIRED-ARG: `$logger` (ArgumentCountError) |
| `tests/unit/Service/DetectionStates.php:89` | constructor-shape |  | new BackendInfo(name: $method, available: $isUp, configured: $isUp, lastProbedAt: '2026-09-29T08:00:00+00:00', latencyMs: 0) | OCA\OpenRegister\Service\Anonymisation\BackendInfo::__construct(public readonly string $name, public readonly bool $configured, ProbeResult $probe) (lib/Service/Anonymisation/BackendInfo.php:63) | UNKNOWN-NAMED-ARG: `available:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `lastProbedAt:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `latencyMs:` (Error: Unknown named parameter); |
| `tests/unit/Service/EmailIngestion/EmailIngestionDoubles.php:563` | constructor-shape |  | new EmlAttachment(filename: 'machtiging.pdf', mimeType: 'application/pdf', content: '%PDF', isInline: false, contentId: null, nestedEml: null) | OCA\OpenRegister\Files\Service\TextExtraction\EmlAttachment::__construct(EmlAttachmentFile $file, public readonly bool $isInline, public readonly ?string $contentId, public readonly ?EmlStructure $nes | UNKNOWN-NAMED-ARG: `filename:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `mimeType:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `content:` (Error: Unknown named parameter); MISSIN |
| `tests/unit/Service/EmailIngestion/EmailIngestionDoubles.php:564` | constructor-shape |  | new EmlAttachment(filename: 'logo.png', mimeType: 'image/png', content: 'png', isInline: true, contentId: 'logo', nestedEml: null) | OCA\OpenRegister\Files\Service\TextExtraction\EmlAttachment::__construct(EmlAttachmentFile $file, public readonly bool $isInline, public readonly ?string $contentId, public readonly ?EmlStructure $nes | UNKNOWN-NAMED-ARG: `filename:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `mimeType:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `content:` (Error: Unknown named parameter); MISSIN |
| `tests/unit/Service/Pseudonymisation/DocumentAnonymizeRunnerSanitizationTest.php:98` | constructor-shape | stub | new \OCA\OpenRegister\Service\File\SanitizationReport(commentsRemoved: 3, metadataFieldsScrubbed: 5) | OCA\OpenRegister\Files\Service\File\SanitizationReport::__construct(SanitizationRevisionCounts $revisions = new SanitizationRevisionCounts(), SanitizationStructureCounts $structure = new SanitizationS | UNKNOWN-NAMED-ARG: `commentsRemoved:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `metadataFieldsScrubbed:` (Error: Unknown named parameter) |
| `tests/unit/Service/Sanitization/AnonymisationSanitizationRecorderTest.php:66` | constructor-shape |  | new SanitizationReport(commentsRemoved: 2, trackedChangesDropped: 1) | OCA\OpenRegister\Files\Service\File\SanitizationReport::__construct(SanitizationRevisionCounts $revisions = new SanitizationRevisionCounts(), SanitizationStructureCounts $structure = new SanitizationS | UNKNOWN-NAMED-ARG: `commentsRemoved:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `trackedChangesDropped:` (Error: Unknown named parameter) |
| `tests/unit/Service/Sanitization/AnonymisationSanitizationRecorderTest.php:105` | constructor-shape |  | new SanitizationReport(commentsRemoved: 2) | OCA\OpenRegister\Files\Service\File\SanitizationReport::__construct(SanitizationRevisionCounts $revisions = new SanitizationRevisionCounts(), SanitizationStructureCounts $structure = new SanitizationS | UNKNOWN-NAMED-ARG: `commentsRemoved:` (Error: Unknown named parameter) |
| `tests/unit/Service/Sanitization/DocumentSanitizationServiceTest.php:165` | constructor-shape |  | new SanitizationReport(commentsRemoved: 4, metadataFieldsScrubbed: 6) | OCA\OpenRegister\Files\Service\File\SanitizationReport::__construct(SanitizationRevisionCounts $revisions = new SanitizationRevisionCounts(), SanitizationStructureCounts $structure = new SanitizationS | UNKNOWN-NAMED-ARG: `commentsRemoved:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `metadataFieldsScrubbed:` (Error: Unknown named parameter) |

### Test stubs of OR classes that drifted from OR main: 50 of 72

- `tests/stubs/OpenRegisterEmlStubs.php:120` `OCA\OpenRegister\Service\TextExtraction\EmlAttachment` (alias): __construct($filename, $mimeType, $content, $isInline, $contentId, $nestedEml) vs OR main __construct($file, $isInline, $contentId, $nestedEml) [lib/Files/Service/TextExtraction/EmlAttachment.php:75]
- `tests/stubs/OpenRegisterSanitizerStubs.php:137` `OCA\OpenRegister\Service\File\SanitizationReport` (alias): __construct($commentsRemoved, $trackedChangesAccepted, $trackedChangesDropped, $revisionAttributesStripped, $hyperlinksFlattened, $metadataFieldsScrubbed, $customXmlPartsDropped, $fieldCodesStripped, $sentinelApplied) vs OR main __construct($revisions, $structure, $sentinelApplied) [lib/Files/Service/File/SanitizationReport.php:124]
- `tests/stubs/OpenRegisterStubs.php:32` `OCA\OpenRegister\Service\ObjectService` (exists): find($id, $register, $schema, $_rbac, $_multitenancy, $_render, $_audit) vs OR main find($id, $_extend, $files, $register, $schema, $_rbac, $_multitenancy, $_render, $_audit) [lib/Service/ObjectService.php:1264]; saveObject($object, $register, $schema, $uuid, $_rbac, $_multitenancy, $_unowned, $_owner) vs OR main saveObject($object, $extend, $register, $schema, $uuid, $_rbac, $_multitenancy, $silent, $_validation, $uploadedFiles, $currentUser, $failIfExists, $_unowned, $_dedupOverride, $_owner) [lib/Service/ObjectService.php:2112]; deleteObject($uuid, $register, $schema, $_rbac, $_multitenancy) vs OR main deleteObject($uuid, $register, $schema, $_rbac, $_multitenancy, $_retentionSweep, $currentUser, $permanent) [lib/Service/ObjectService.php:3827]; buildSearchQuery($requestParams, $register, $schema) vs OR main buildSearchQuery($requestParams, $register, $schema, $ids) [lib/Service/ObjectService.php:4686]; searchObjectsPaginated($query, $_rbac, $_multitenancy) vs OR main searchObjectsPaginated($query, $_rbac, $_multitenancy, $deleted, $ids, $uses, $views) [lib/Service/ObjectService.php:5154]; searchObjects($query) vs OR main searchObjects($query, $_rbac, $_multitenancy, $ids, $uses, $views, $_viewScopeRequired) [lib/Service/ObjectService.php:4771]
- `tests/stubs/OpenRegisterStubs.php:310` `OCA\OpenRegister\Service\RegisterService` (exists): findAll($limit, $offset, $filters, $searchConditions, $searchParams, $_extend) vs OR main findAll($limit, $offset, $filters, $searchConditions, $searchParams, $_extend, $_multitenancy) [lib/Service/RegisterService.php:215]
- `tests/stubs/OpenRegisterStubs.php:612` `OCA\OpenRegister\Service\Register` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:659` `OCA\OpenRegister\Service\Schema` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:697` `OCA\OpenRegister\Service\View` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:735` `OCA\OpenRegister\Service\ObjectEntity` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:939` `OCA\OpenRegister\Service\AuditTrail` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:1062` `OCA\OpenRegister\Service\AuditTrailMapper` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:1123` `OCA\OpenRegister\Service\SchemaMapper` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:1176` `OCA\OpenRegister\Service\EntityRelationMapper` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:1268` `OCA\OpenRegister\Service\GdprEntity` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:1429` `OCA\OpenRegister\Service\GdprEntityMapper` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:1463` `OCA\OpenRegister\Service\Chunk` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:1570` `OCA\OpenRegister\Service\ChunkMapper` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:1593` `OCA\OpenRegister\Service\EntityRelation` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:1725` `OCA\OpenRegister\Service\Emitter` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:1739` `OCA\OpenRegister\Service\IUserSession` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:1771` `OCA\OpenRegister\Service\LoggerInterface` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:1802` `OCA\OpenRegister\Service\IUser` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:1821` `OCA\OpenRegister\Service\IGroupManager` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:1865` `OCA\OpenRegister\Service\IGroup` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:1930` `OCA\OpenRegister\Service\Folder` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:1994` `OCA\OpenRegister\Service\FileInfo` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:2009` `OCA\OpenRegister\Service\Node` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:2113` `OCA\OpenRegister\Service\File` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:2164` `OCA\OpenRegister\Service\IRootFolder` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:2201` `OCA\OpenRegister\Service\Task` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:2267` `OCA\OpenRegister\Service\TaskSequence` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:2320` `OCA\OpenRegister\Service\ArchivalImmutableException` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:2385` `OCA\OpenRegister\Service\TaskTransitionedEvent` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:2425` `OCA\OpenRegister\Service\TaskTerminalEvent` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:2468` `OCA\OpenRegister\Service\TaskSequenceCompletedEvent` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:2508` `OCA\OpenRegister\Service\ObjectCreatedEvent` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:2546` `OCA\OpenRegister\Service\ObjectUpdatingEvent` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:2599` `OCA\OpenRegister\Service\ObjectCreatingEvent` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:2621` `OCA\OpenRegister\Service\ObjectUpdatedEvent` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:2647` `OCA\OpenRegister\Service\ObjectDeletedEvent` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:2670` `OCA\OpenRegister\Service\Routes` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:2736` `OCA\OpenRegister\Service\IMcpScannableServices` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:2764` `OCA\OpenRegister\Service\McpTool` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:2808` `OCA\OpenRegister\Service\ObjectEntityInterface` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:2877` `OCA\OpenRegister\Service\ObjectServiceInterface` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:2944` `OCA\OpenRegister\Service\TaskState` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:2972` `OCA\OpenRegister\Service\LeafDescriptor` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:3093` `OCA\OpenRegister\Service\RegisterLeafProvidersEvent` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:3120` `OCA\OpenRegister\Service\BackendInfo` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterStubs.php:3153` `OCA\OpenRegister\Service\BackendState` (missing): class no longer exists on OR main
- `tests/stubs/openregister-mcp.stub.php:75` `OCA\OpenRegister\Mcp\McpTool` (missing): class no longer exists on OR main

### Implements / extends OR classes: 25

- `lib/Flow/DirectGenerateDocumentNode.php:52` DirectGenerateDocumentNode implements `OCA\OpenRegister\Service\Flow\IFlowDirectlyInvokable` (exists)
- `lib/Flow/GenerateDocumentNode.php:58` GenerateDocumentNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Flow/GenerateDocumentNode.php:58` GenerateDocumentNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys` (exists)
- `lib/Flow/GenerateDocumentNode.php:58` GenerateDocumentNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm` (exists)
- `lib/Flow/GenerateDocumentNode.php:58` GenerateDocumentNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy` (exists)
- `lib/Mcp/FilinqScannableServices.php:58` FilinqScannableServices implements `OCA\OpenRegister\Mcp\IMcpScannableServices` (exists)
- `tests/stubs/OpenRegisterStubs.php:1930` Folder implements `OCA\OpenRegister\Service\Node` (missing)
- `tests/stubs/OpenRegisterStubs.php:2113` File implements `OCA\OpenRegister\Service\Node` (missing)
- `tests/stubs/OpenRegisterStubs.php:2385` TaskTransitionedEvent extends `OCA\OpenRegister\Service\Event` (missing)
- `tests/stubs/OpenRegisterStubs.php:2425` TaskTerminalEvent extends `OCA\OpenRegister\Service\Event` (missing)
- `tests/stubs/OpenRegisterStubs.php:2468` TaskSequenceCompletedEvent extends `OCA\OpenRegister\Service\Event` (missing)
- `tests/stubs/OpenRegisterStubs.php:2508` ObjectCreatedEvent extends `OCA\OpenRegister\Service\Event` (missing)
- `tests/stubs/OpenRegisterStubs.php:2546` ObjectUpdatingEvent extends `OCA\OpenRegister\Service\Event` (missing)
- `tests/stubs/OpenRegisterStubs.php:2599` ObjectCreatingEvent extends `OCA\OpenRegister\Service\Event` (missing)
- `tests/stubs/OpenRegisterStubs.php:2621` ObjectUpdatedEvent extends `OCA\OpenRegister\Service\Event` (missing)
- `tests/stubs/OpenRegisterStubs.php:2647` ObjectDeletedEvent extends `OCA\OpenRegister\Service\Event` (missing)
- `tests/stubs/OpenRegisterStubs.php:3093` RegisterLeafProvidersEvent extends `OCA\OpenRegister\Service\Event` (missing)
- `tests/unit/Service/BatchStateTestDoubles.php:224` StoredObjectFake extends `OCA\OpenRegister\Db\ObjectEntity` (exists)
- `tests/unit/Service/BatchStateTestDoubles.php:272` InMemoryObjectServiceFake extends `OCA\OpenRegister\Service\ObjectService` (exists)
- `tests/unit/Service/BatchStateTestDoubles.php:415` FixedResultObjectServiceFake extends `OCA\OpenRegister\Service\ObjectService` (exists)
- `tests/unit/Service/BatchStateTestDoubles.php:477` RefusingObjectServiceFake extends `OCA\OpenRegister\Service\ObjectService` (exists)
- `tests/unit/Service/Classification/ClassificationDoubles.php:36` ClassificationObjectStore extends `OCA\OpenRegister\Service\ObjectService` (exists)
- `tests/unit/Service/EmailIngestion/EmailIngestionDoubles.php:61` EmailObjectStore extends `OCA\OpenRegister\Service\ObjectService` (exists)
- `tests/unit/Service/SigningEnvelope/SigningEnvelopeServiceTest.php:62` EnvelopeObjectStore extends `OCA\OpenRegister\Service\ObjectService` (exists)
- `tests/unit/Service/Wizard/WizardDoubles.php:35` WizardObjectStore extends `OCA\OpenRegister\Service\ObjectService` (exists)

### OR event listeners: 5 registrations

- `OCA\OpenRegister\Event\ObjectCreatedEvent` (exists, OR main constructed): 1x, e.g. `lib/EventListener/ObjectEventRegistrar.php:78`
- `OCA\OpenRegister\Event\ObjectDeletedEvent` (exists, OR main constructed): 2x, e.g. `lib/EventListener/ObjectEventRegistrar.php:99`
- `OCA\OpenRegister\Event\ObjectUpdatedEvent` (exists, OR main constructed): 1x, e.g. `lib/EventListener/ObjectEventRegistrar.php:79`
- `OCA\OpenRegister\Event\ObjectUpdatingEvent` (exists, OR main constructed): 1x, e.g. `lib/EventListener/ObjectEventRegistrar.php:86`

<details><summary>Dynamic call sites in lib/ (method_exists, is_callable, $x->$m(), call_user_func): 133. Always suspect: a renamed OR method makes these silently skip.</summary>

- `lib/BackgroundJob/SigningExpirationJob.php:179` `if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/Controller/PortalSigningReceiverController.php:472` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/EventListener/ContractNoticeDeadlineListener.php:81` `if (method_exists($event, 'getNewObject') === true) {`
- `lib/EventListener/ContractNoticeDeadlineListener.php:83` `} else if (method_exists($event, 'getObject') === true) {`
- `lib/EventListener/ContractNoticeDeadlineListener.php:87` `if (is_object($object) === false \|\| method_exists($event, 'setModifiedData') === false \|\| method_exists($event, 'getModifiedData') === false) {`
- `lib/EventListener/ContractNoticeDeadlineListener.php:87` `if (is_object($object) === false \|\| method_exists($event, 'setModifiedData') === false \|\| method_exists($event, 'getModifiedData') === false) {`
- `lib/EventListener/DocumentRegistrationWriteGuard.php:202` `if (method_exists($object, 'getObject') === false) {`
- `lib/EventListener/DossierCheckedOnListener.php:220` `if (method_exists(object_or_class: $object, method: 'getRegister') === true) {`
- `lib/EventListener/DossierCheckedOnListener.php:224` `if (method_exists(object_or_class: $object, method: 'getSchema') === true) {`
- `lib/EventListener/DossierCheckedOnListener.php:253` `if (method_exists(object_or_class: $candidate, method: 'getSlug') === false) {`
- `lib/EventListener/SignerSequenceTasks.php:285` `if (is_callable([$subject, $getter]) === false) {`
- `lib/EventListener/SignerSequenceTasks.php:289` `return $subject->$getter();`
- `lib/EventListener/SigningTaskListener.php:462` `if (is_callable([$subject, $getter]) === false) {`
- `lib/EventListener/SigningTaskListener.php:466` `return $subject->{$getter}();`
- `lib/EventListener/SigningTaskListener.php:534` `if (class_exists('\\' . self::TASK_STATE_CLASS) === true && is_callable($classifier) === true) {`
- `lib/EventListener/SigningTaskListener.php:535` `return (bool) call_user_func($classifier, $outcome);`
- `lib/EventListener/ValidationRunner.php:200` `if (method_exists($object, 'getOwner') === true) {`
- `lib/Service/AnonymizationPersistenceService.php:439` `if (method_exists(object_or_class: $sourceNode, method: 'getName') === true) {`
- `lib/Service/AnonymizationPersistenceService.php:443` `if (method_exists(object_or_class: $sourceNode, method: 'getPath') === true) {`
- `lib/Service/AnonymizationPersistenceService.php:448` `if (method_exists(object_or_class: $sourceNode, method: 'getOwner') === true) {`
- `lib/Service/AnonymizationPersistenceService.php:452` `if ($owner !== null && method_exists(object_or_class: $owner, method: 'getUID') === true) {`
- `lib/Service/AnonymizationPersistenceService.php:469` `if (method_exists(object_or_class: $candidate, method: 'getObject') === true) {`
- `lib/Service/AnonymizationPersistenceService.php:473` `&& method_exists(object_or_class: $candidate, method: 'getUuid') === true`
- `lib/Service/AnonymizationPersistenceService.php:485` `if (method_exists(object_or_class: $candidate, method: 'jsonSerialize') === true) {`
- `lib/Service/AnonymizationPersistenceService.php:506` `if (method_exists(object_or_class: $saved, method: 'getUuid') === true) {`
- `lib/Service/AnonymizationPersistenceService.php:513` `if (method_exists(object_or_class: $saved, method: 'getId') === true) {`
- `lib/Service/AnonymizationPersistenceService.php:520` `if (method_exists(object_or_class: $saved, method: 'jsonSerialize') === true) {`
- `lib/Service/AnonymizationResultParser.php:48` `if (is_object($result) === true && method_exists($result, 'getId') === true) {`
- `lib/Service/AnonymizationResultParser.php:73` `if (method_exists($result, 'getName') === true) {`
- `lib/Service/AnonymizationResultParser.php:78` `if (method_exists($result, 'getPath') === true) {`
- `lib/Service/BasesResolverService.php:358` `if (method_exists($candidate, 'getObject') === true) {`
- `lib/Service/BasesResolverService.php:365` `if (method_exists($candidate, 'jsonSerialize') === true) {`
- `lib/Service/BatchStateRepository.php:295` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/Classification/ClassificationResultRepository.php:152` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Classification/ClassificationSources.php:114` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Comparison/RedactionAnnotator.php:337` `if (method_exists($relation, 'getSkipAnonymization') === true) {`
- `lib/Service/ConsentCrudService.php:122` `if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/Service/ConsentCrudService.php:174` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/ConsentUpdateHandler.php:445` `&& method_exists($result, 'getObject') === true`
- `lib/Service/Conversion/EmlBackend.php:139` `return method_exists($fileService, 'anonymizeEmlStructured');`
- `lib/Service/CorrespondenceService.php:608` `&& method_exists(object_or_class: $result, method: 'jsonSerialize') === true`
- `lib/Service/CorrespondenceService.php:820` `&& method_exists(object_or_class: $result, method: 'jsonSerialize') === true`
- `lib/Service/CustomDictionaryRepository.php:213` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/DataResolverService.php:289` `&& method_exists(object_or_class: $result, method: 'jsonSerialize') === true`
- `lib/Service/DocumentFinalityRuleService.php:346` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/DocumentRenderPipeline.php:121` `&& method_exists(object_or_class: $result, method: 'jsonSerialize') === true`
- `lib/Service/DomainDirectory.php:188` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/DossierFileService.php:213` `if ($ref === null && method_exists($object, 'getFolder') === true) {`
- `lib/Service/DossierObjectReader.php:75` `if (method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/DossierObjectReader.php:82` `if (method_exists($object, 'getObject') === true) {`
- `lib/Service/DossierObjectReader.php:105` `if ($uuid === '' && method_exists($object, 'getUuid') === true) {`
- `lib/Service/DossierObjectRepository.php:304` `if (is_object($object) === true && method_exists($object, 'getObject') === true) {`
- `lib/Service/DossierPlaceholderRanker.php:208` `&& method_exists($mapper, 'findEntityIdsByValueForFiles') === true`
- `lib/Service/DossierPlaceholderRanker.php:209` `&& method_exists($mapper, 'findEntityIdsByValueForFile') === true`
- `lib/Service/Editing/DocumentGuard.php:260` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/EmailIngestion/EmailDocumentRepository.php:181` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/EmailIngestion/EmailMessageReader.php:151` `if (method_exists($parser, 'parseEmlStructured') === false) {`
- `lib/Service/EmlAnonymizationService.php:94` `return method_exists($node, 'getMimeType') === true`
- `lib/Service/EmlAnonymizationService.php:106` `if (method_exists($node, 'getName') === false) {`
- `lib/Service/EmlAnonymizationService.php:142` `if (method_exists($node, 'getName') === true) {`
- `lib/Service/EmlAnonymizationService.php:173` `if (method_exists($fileService, 'getLastPlaceholderMap') === true) {`
- `lib/Service/EmlAnonymizationService.php:198` `if (method_exists($fileService, 'anonymizeEmlStructured') === false) {`
- `lib/Service/EmlPreviewService.php:99` `if (method_exists($fileService, 'anonymizeEmlStructured') === false) {`
- `lib/Service/EntityConsolidationService.php:175` `if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Service/EntityDetectionService.php:114` `if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Service/EntitySearch/EntityCatalogue.php:118` `if (is_object($organisation) === false \|\| method_exists($organisation, 'getUuid') === false) {`
- `lib/Service/EntitySearch/EntityOccurrences.php:242` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/FinalDocumentRepository.php:291` `if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/Service/FinalDocumentRepository.php:493` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/FinancialExtractionService.php:1137` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/GeneratedDocumentLogger.php:144` `&& method_exists(object_or_class: $result, method: 'jsonSerialize') === true`
- `lib/Service/GlAccountSuggestionService.php:602` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/IntakeDefaultRuleService.php:194` `if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/Service/IntakeFilePlacement.php:184` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/IntakeRepository.php:323` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/IntakeRoutingService.php:181` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/LegalHold/LegalHoldFanOut.php:164` `if (method_exists($entity, 'getOwner') === false) {`
- `lib/Service/LegalHold/LegalHoldFileFreeze.php:200` `if (method_exists($entity, 'getObject') === true) {`
- `lib/Service/LegalHold/LegalHoldFileFreeze.php:226` `if (method_exists($entity, 'getFolder') === true) {`
- `lib/Service/LegalHold/LegalHoldRecordFreeze.php:93` `if (is_object($entity) === false \|\| method_exists($entity, 'getRetention') === false) {`
- `lib/Service/LegalHold/LegalHoldRecordFreeze.php:120` `if (is_object($entity) === false \|\| method_exists($entity, 'getRetention') === false) {`
- `lib/Service/ListReferenceResolver.php:455` `&& method_exists(object_or_class: $item, method: 'jsonSerialize') === true`
- `lib/Service/ObjectResultExtractor.php:153` `if (method_exists(object_or_class: $candidate, method: 'getObject') === false) {`
- `lib/Service/ObjectResultExtractor.php:173` `if (method_exists(object_or_class: $candidate, method: 'jsonSerialize') === false) {`
- `lib/Service/ObjectResultExtractor.php:201` `if (method_exists(object_or_class: $candidate, method: 'getUuid') === false) {`
- `lib/Service/Ocr/OcrExtractionFallback.php:126` `if (method_exists($textExtractor, 'getExtractedText') === true) {`
- `lib/Service/Ocr/OcrExtractionFallback.php:177` `return method_exists($textExtractor, self::INGEST_METHOD) === true;`
- `lib/Service/OpenRegisterServiceLocator.php:96` `if (method_exists($fileService, 'getLastResidualEntities') === true) {`
- `lib/Service/OpenRegisterServiceLocator.php:120` `if (method_exists($fileService, 'getLastPlaceholderMap') === true) {`
- `lib/Service/OpenRegisterServiceLocator.php:142` `if (method_exists($fileService, 'getLastStructurePreservation') === false) {`
- `lib/Service/OpenRegisterServiceLocator.php:174` `if (method_exists($fileService, 'getLastSanitizationReport') === false) {`
- `lib/Service/OpenRegisterServiceLocator.php:182` `if (is_object($source) === false \|\| method_exists($source, 'getLastSanitizationReport') === false) {`
- `lib/Service/PageLayoutService.php:364` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/PartySuggestionService.php:187` `if (method_exists($relation, 'getType') === true) {`
- `lib/Service/PartySuggestionService.php:192` `if (method_exists($relation, 'getValue') === true) {`
- `lib/Service/PeriodicDocumentService.php:322` `if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/Service/PolicyCrudService.php:500` `if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/Service/PolicyCrudService.php:538` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/PostRegisterReader.php:159` `usort($open, [$this, 'oldestFirst']);`
- `lib/Service/PostRegisterReader.php:328` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/ProhibitionGateService.php:400` `if (is_object($raw) === true && method_exists($raw, 'jsonSerialize') === true) {`
- `lib/Service/Publication/PublicationStore.php:295` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/ReadsStoredObjectRows.php:98` `if (is_object($row) === true && method_exists($row, 'getObject') === true && method_exists($row, 'getUuid') === true) {`
- `lib/Service/ReadsStoredObjectRows.php:98` `if (is_object($row) === true && method_exists($row, 'getObject') === true && method_exists($row, 'getUuid') === true) {`
- `lib/Service/ReadsStoredObjectRows.php:114` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Redaction/AnonymizationLinkReader.php:247` `if (is_object($row) === true && method_exists($row, 'getUuid') === true) {`
- `lib/Service/Redaction/PublicationListComposer.php:172` `if (is_object($record) === true && method_exists($record, 'jsonSerialize') === true) {`
- `lib/Service/RedactionAccessibilityService.php:151` `&& method_exists($output, 'getName') === true`
- `lib/Service/RedactionAccessibilityService.php:153` `&& method_exists($output, 'getContent') === true`
- `lib/Service/RegisterDiscoveryService.php:108` `return array_map([$this, 'filterSchemas'], $rawRegisters);`
- `lib/Service/RegisterDiscoveryService.php:180` `[$this, 'filterSchemaProperties'],`
- `lib/Service/Sanitization/DocumentSanitizationService.php:178` `if (method_exists($e, 'getReason') === true) {`
- `lib/Service/Sanitization/SanitizationRecordRepository.php:126` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/SavedViewReader.php:166` `if (method_exists($found, 'getQuery') === false) {`
- `lib/Service/SignerAuth/BrokerCredentialResolver.php:131` `if (is_object($broker) === true && method_exists($broker, 'resolveInjectable') === true) {`
- `lib/Service/Signing/LibreSignCompletion.php:206` `if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/Service/Signing/NativeSigningProvider.php:592` `if (is_object($entry) === true && method_exists($entry, 'jsonSerialize') === true) {`
- `lib/Service/Signing/NativeSigningProvider.php:599` `if (is_object($entry) === true && method_exists($entry, 'getObject') === true) {`
- `lib/Service/SigningActorResolver.php:387` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/SigningFolderService.php:111` `usort($entries, [$this, 'compareEntries']);`
- `lib/Service/SigningService.php:946` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/TemplateService.php:184` `&& method_exists(object_or_class: $result, method: 'jsonSerialize') === true`
- `lib/Service/TemplateService.php:305` `&& method_exists(object_or_class: $result, method: 'jsonSerialize') === true`
- `lib/Service/TemplateService.php:373` `&& method_exists(object_or_class: $result, method: 'jsonSerialize') === true`
- `lib/Service/TemplateService.php:414` `&& method_exists(object_or_class: $result, method: 'jsonSerialize') === true`
- `lib/Service/TemplateService.php:512` `&& method_exists(object_or_class: $result, method: 'jsonSerialize') === true`
- `lib/Service/TemplateSlugResolver.php:225` `&& method_exists(object_or_class: $result, method: 'jsonSerialize') === true`
- `lib/Service/TemplateVersionService.php:125` `&& method_exists(object_or_class: $result, method: 'jsonSerialize') === true`
- `lib/Service/TemplateVersionService.php:192` `&& method_exists(object_or_class: $result, method: 'jsonSerialize') === true`
- `lib/Service/TemplateVersionService.php:345` `if (is_object($row) === true && method_exists(object_or_class: $row, method: 'jsonSerialize') === true) {`
- `lib/Service/UploadPolicyService.php:256` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Wizard/WizardRepository.php:213` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Wizard/WizardService.php:314` `if (is_object($schema) === false \|\| method_exists($schema, 'getProperties') === false) {`

</details>

Unresolved call sites (receiver type unknown, magic, or spread args): 17, listed in `filinq.json`.

## thematiq

Local dir `nldesign`, branch `development`, sha `74990116c1c98bbfc66eff82c7a3de6f28aef55e`.

### Runtime breaks (lib): 0

### Test-suite breaks (tests): 0

### Implements / extends OR classes: 1

- `lib/Service/Config/NlDesignThemeShareableConfigType.php:45` NlDesignThemeShareableConfigType implements `OCA\OpenRegister\Service\Config\IShareableConfigType` (exists)

### OR event listeners: 1 registrations

- `OCA\OpenRegister\Service\Config\RegisterShareableConfigTypesEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Application.php:183`

Unresolved call sites (receiver type unknown, magic, or spread args): 0, listed in `thematiq.json`.

## stackiq

Local dir `softwarecatalog`, branch `development`, sha `9ad6a74fd5b14e252c1ae07725e94f44b1e2be9a`.

### Runtime breaks (lib): 1

| file:line | category | app calls | OR main now | fix |
|---|---|---|---|---|
| `lib/Service/SettingsService.php:6481` | call-shape | $organisationMapper->findAll(null, null, [], [], [], [], null, false, false) | OCA\OpenRegister\Db\OrganisationMapper::findAll(int $limit = 50, int $offset = 0, ?array $filters = []): array (lib/Db/OrganisationMapper.php:565) | SILENT-EXTRA-ARGS: 9 positional args, method takes 3 (extra args are silently dropped); TYPE: passes null literal `null` to `$limit` typed `int` (TypeError); TYPE: passes null literal `null` to `$offset` typed `int` (TypeError) |

### Test-suite breaks (tests): 4

| file:line | category | stub | app calls | OR main now | fix |
|---|---|---|---|---|---|
| `tests/Unit/Controller/SettingsControllerCmdbImportMappingTest.php:320` | string-lookup-missing |  | string('OCA\OpenRegister\Service\MigrationPack\NoSuchValidator') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/Unit/Service/Cmdb/CmdbImportPackStoreTest.php:131` | string-lookup-missing |  | string('OCA\OpenRegister\Exception\NotInThisOpenRegister') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/Unit/Service/Cmdb/CmdbImportProfileTest.php:298` | string-lookup-missing |  | string('OCA\OpenRegister\Service\MigrationPack\NoSuchValidator') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/Unit/Service/CmdbExportImportServiceTest.php:4288` | string-lookup-missing |  | string('OCA\OpenRegister\Service\MigrationPack\NoSuchEngine') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |

### Test stubs of OR classes that drifted from OR main: 7 of 23

- `tests/Stubs/Db/OrganisationMapper.php:23` `OCA\OpenRegister\Db\OrganisationMapper` (exists): save($entity) vs OR main save($organisation) [lib/Db/OrganisationMapper.php:336]
- `tests/Stubs/Db/SchemaMapper.php:27` `OCA\OpenRegister\Db\SchemaMapper` (exists): findBySlug($slug, $limit, $offset) vs OR main findBySlug($slug, $limit, $offset, $_rbac, $_multitenancy) [lib/Db/SchemaMapper.php:893]
- `tests/Stubs/Service/ObjectService.php:25` `OCA\OpenRegister\Service\ObjectService` (exists): find($id, $_extend, $files, $register, $schema, $_rbac, $_multitenancy, $_render) vs OR main find($id, $_extend, $files, $register, $schema, $_rbac, $_multitenancy, $_render, $_audit) [lib/Service/ObjectService.php:1264]; searchObjectsPaginated($query, $_rbac, $_multitenancy, $deleted, $uses) vs OR main searchObjectsPaginated($query, $_rbac, $_multitenancy, $deleted, $ids, $uses, $views) [lib/Service/ObjectService.php:5154]; searchObjects($query, $_rbac, $_multitenancy, $ids, $uses, $views) vs OR main searchObjects($query, $_rbac, $_multitenancy, $ids, $uses, $views, $_viewScopeRequired) [lib/Service/ObjectService.php:4771]; saveObject($object, $register, $schema, $uuid, $_rbac, $_multitenancy, $extend, $silent, $uploadedFiles, $currentUser) vs OR main saveObject($object, $extend, $register, $schema, $uuid, $_rbac, $_multitenancy, $silent, $_validation, $uploadedFiles, $currentUser, $failIfExists, $_unowned, $_dedupOverride, $_owner) [lib/Service/ObjectService.php:2112]; buildSearchQuery($options) vs OR main buildSearchQuery($requestParams, $register, $schema, $ids) [lib/Service/ObjectService.php:4686]; deleteObject($uuid, $_rbac, $_multitenancy) vs OR main deleteObject($uuid, $register, $schema, $_rbac, $_multitenancy, $_retentionSweep, $currentUser, $permanent) [lib/Service/ObjectService.php:3827] ...
- `tests/Stubs/Service/OrganisationService.php:32` `OCA\OpenRegister\Service\OrganisationService` (exists): getActiveOrganisation() vs OR main getActiveOrganisation($preloadedOrgs) [lib/Service/OrganisationService.php:527]; getUserOrganisations() vs OR main getUserOrganisations($_useCache) [lib/Service/OrganisationService.php:488]
- `tests/Unit/Support/OpenRegister/MappingEngine.php:59` `OCA\OpenRegister\Service\MigrationPack\MappingEngine` (exists): applyIdStrategy($pack, $sourceRow, $data) vs OR main applyIdStrategy($run, $pack) [lib/Service/MigrationPack/MappingEngine.php:239]; resolveSource(): not on OR main; applyTransform(): not on OR main; applyDateTransform(): not on OR main; applyMapTransform(): not on OR main; applyConcatTransform(): not on OR main
- `tests/Unit/Support/OpenRegister/MigrationPacksController.php:30` `OCA\OpenRegister\Controller\MigrationPacksController` (exists): update() vs OR main update($id) [lib/Controller/MigrationPacksController.php:382]; destroy() vs OR main destroy($id) [lib/Controller/MigrationPacksController.php:427]
- `tests/Unit/Support/OpenRegister/PackDefinitionValidator.php:55` `OCA\OpenRegister\Service\MigrationPack\PackDefinitionValidator` (exists): validateTransform(): not on OR main

### Implements / extends OR classes: 5

- `tests/Stubs/Db/ObjectEntity.php:68` ObjectEntity implements `OCA\OpenRegister\Contract\ObjectEntityInterface` (exists)
- `tests/Unit/Support/OpenRegister/BuiltinPackSlugLockedException.php:31` BuiltinPackSlugLockedException extends `OCA\OpenRegister\Exception\InvalidPackDefinitionException` (exists)
- `tests/Unit/Support/OpenRegister/InvalidPackDefinitionException.php:26` InvalidPackDefinitionException extends `OCA\OpenRegister\Exception\MigrationPackRefusedException` (exists)
- `tests/Unit/Support/OpenRegister/PackChangedException.php:26` PackChangedException extends `OCA\OpenRegister\Exception\MigrationPackRefusedException` (exists)
- `tests/Unit/Support/OpenRegister/PackSlugExistsException.php:26` PackSlugExistsException extends `OCA\OpenRegister\Exception\InvalidPackDefinitionException` (exists)

### OR event listeners: 12 registrations

- `OCA\OpenRegister\Event\ObjectCreatedEvent` (exists, OR main constructed): 4x, e.g. `lib/AppInfo/Application.php:815`
- `OCA\OpenRegister\Event\ObjectCreatingEvent` (exists, OR main constructed): 2x, e.g. `lib/AppInfo/Application.php:822`
- `OCA\OpenRegister\Event\ObjectDeletedEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Application.php:835`
- `OCA\OpenRegister\Event\ObjectUpdatedEvent` (exists, OR main constructed): 3x, e.g. `lib/AppInfo/Application.php:816`
- `OCA\OpenRegister\Event\ObjectUpdatingEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Application.php:826`
- `OCA\OpenRegister\Event\UserProfileUpdatedEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Application.php:838`

<details><summary>Dynamic call sites in lib/ (method_exists, is_callable, $x->$m(), call_user_func): 59. Always suspect: a renamed OR method makes these silently skip.</summary>

- `lib/BackgroundJob/OrganizationContactSyncJob.php:239` `if (is_object($object) === true && method_exists($object, 'getObject') === true) {`
- `lib/BackgroundJob/OrganizationContactSyncJob.php:244` `if (is_object($object) === true && method_exists($object, 'getUuid') === true) {`
- `lib/Command/ObjectPages.php:121` `$out = $row->$method();`
- `lib/Controller/ContactNamesController.php:228` `if (is_object($entry) === true && method_exists($entry, 'jsonSerialize') === true) {`
- `lib/Controller/SettingsController.php:453` `$this->settingsService->{$cfg['setter']}($validation['valid']);`
- `lib/Repair/MigrateContactsToNc.php:358` `if (method_exists($objectService, 'runAsSystem') === true) {`
- `lib/Repair/MigrateContactsToNc.php:373` `if (is_object($object) === true && method_exists($object, 'getObject') === true) {`
- `lib/Repair/MigrateContactsToNc.php:377` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Repair/MigrateContactsToNc.php:393` `if (is_object($object) === true && method_exists($object, 'getUuid') === true) {`
- `lib/Service/ArchiMateExportService.php:851` `if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {`
- `lib/Service/ArchiMateExportService.php:1035` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/ArchiMateExportService.php:1082` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/ArchiMateExportService.php:1857` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/ArchiMateExportService.php:2015` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/ArchiMateExportService.php:2481` `if (is_object($module) === true && method_exists($module, 'jsonSerialize') === true) {`
- `lib/Service/ArchiMateExportService.php:2494` `if (is_object($usage) === true && method_exists($usage, 'jsonSerialize') === true) {`
- `lib/Service/ArchiMateExportService.php:2560` `if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {`
- `lib/Service/ArchiMateExportService.php:2716` `if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {`
- `lib/Service/ArchiMateExportService.php:3111` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/ArchiMateExportService.php:3127` `if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {`
- `lib/Service/ArchiMateExportService.php:3160` `if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {`
- `lib/Service/ArchiMateExportService.php:3192` `if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {`
- `lib/Service/ArchiMateExportService.php:3221` `if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {`
- `lib/Service/ArchiMateExportService.php:3266` `if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {`
- `lib/Service/ArchiMateImportService.php:4082` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/ArchiMateService.php:422` `if (is_object($mod) === true && method_exists($mod, 'jsonSerialize') === true) {`
- `lib/Service/Cmdb/CmdbImportPackStoreProbe.php:198` `if (method_exists($service, $method) === false) {`
- `lib/Service/Cmdb/CmdbImportProfile.php:1156` `if (is_object($validator) === true && method_exists($validator, 'validate') === true) {`
- `lib/Service/Cmdb/CmdbImportProfile.php:1167` `if ($validator !== null && method_exists($validator, 'validate') === true) {`
- `lib/Service/Cmdb/CmdbWorkbookReader.php:504` `if (method_exists($spreadsheet, 'getExcelCalendar') === false) {`
- `lib/Service/Cmdb/CmdbWorkbookReader.php:905` `if (is_object($value) === true && method_exists($value, 'getPlainText') === true) {`
- `lib/Service/CmdbExportImportService.php:2084` `if (is_object($handler) === false \|\| method_exists($handler, 'archive') === false \|\| method_exists($handler, 'unarchive') === false`
- `lib/Service/CmdbExportImportService.php:2084` `if (is_object($handler) === false \|\| method_exists($handler, 'archive') === false \|\| method_exists($handler, 'unarchive') === false`
- `lib/Service/CmdbExportImportService.php:2085` `\|\| is_object($mapper) === false \|\| method_exists($mapper, 'restoreObject') === false`
- `lib/Service/CmdbExportImportService.php:2086` `\|\| method_exists($mapper, 'findDeletedAcrossAllMagicTables') === false`
- `lib/Service/CmdbExportImportService.php:2158` `if (is_object($restored) === true && method_exists($restored, 'getObject') === true) {`
- `lib/Service/CmdbExportImportService.php:2812` `if (is_object($engine) === true && method_exists($engine, 'mapRow') === true) {`
- `lib/Service/CmdbExportImportService.php:2823` `if ($engine !== null && method_exists($engine, 'mapRow') === true) {`
- `lib/Service/CmdbExportImportService.php:2890` `if (is_object($mapper) === false \|\| method_exists($mapper, 'find') === false) {`
- `lib/Service/EolSyncService.php:463` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/EolSyncService.php:465` `if (method_exists($row, 'getUuid') === true && empty($data['id']) === true) {`
- `lib/Service/FacetService.php:513` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/FacetService.php:517` `if (is_object($object) === true && method_exists($object, 'getObject') === true) {`
- `lib/Service/Federation/FederationService.php:647` `if (is_object($object) === true && method_exists($object, 'getObject') === true) {`
- `lib/Service/Federation/FederationService.php:649` `if (method_exists($object, 'getUuid') === true && empty($data['id']) === true) {`
- `lib/Service/GebruikService.php:197` `if (method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/GebruikService.php:199` `} elseif (method_exists($object, 'getObject') === true) {`
- `lib/Service/IntakeService.php:285` `&& (property_exists($entity, 'uuid') === true \|\| method_exists($entity, 'getUuid') === true)`
- `lib/Service/ItsmFileImportService.php:308` `if (is_object($value) === true && method_exists($value, 'getPlainText') === true) {`
- `lib/Service/MergeOrganisatieService.php:512` `&& method_exists($entity, 'getOrganisation') === false`
- `lib/Service/ModerationService.php:336` `if (is_object($object) === true && method_exists($object, 'getObject') === true) {`
- `lib/Service/ModerationService.php:338` `if (method_exists($object, 'getUuid') === true && empty($data['id']) === true) {`
- `lib/Service/PortfolioReportDerivation.php:246` `if (is_object($object) === true && method_exists($object, 'getObject') === true) {`
- `lib/Service/ReviewAggregateService.php:271` `if (is_object($object) === true && method_exists($object, 'getObject') === true) {`
- `lib/Service/ReviewAggregateService.php:273` `if (method_exists($object, 'getUuid') === true && empty($data['id']) === true) {`
- `lib/Service/ReviewService.php:374` `&& (property_exists($entity, 'uuid') === true \|\| method_exists($entity, 'getUuid') === true)`
- `lib/Service/Settings/SettingsObjectCounts.php:142` `$counts[$countKey] = count($archiMateService->$getter());`
- `lib/Service/SettingsService.php:366` `if (is_object($register) === true && method_exists($register, 'jsonSerialize') === true) {`
- `lib/Service/ViewService.php:276` `&& method_exists(object_or_class: $view, method: 'jsonSerialize') === true`

</details>

Unresolved call sites (receiver type unknown, magic, or spread args): 3, listed in `stackiq.json`.

## launchpad

Local dir `launchpad`, branch `development`, sha `3e7bf07b30f2a422b68b0e492d79126dab2e7acb`.

### Runtime breaks (lib): 0

### Test-suite breaks (tests): 2

| file:line | category | stub | app calls | OR main now | fix |
|---|---|---|---|---|---|
| `tests/phpstan/apphost-symbols.php:38` | parent-missing |  | GenericHealthController extends OCA\OpenRegister\AppHost\Observability\Controller | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |
| `tests/phpstan/apphost-symbols.php:53` | parent-missing |  | GenericMetricsController extends OCA\OpenRegister\AppHost\Observability\Controller | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |

### Test stubs of OR classes that drifted from OR main: 3 of 7

- `tests/Stubs/OpenRegisterStubs.php:29` `OCA\OpenRegister\AppHost\Service\StoreDescriptor` (exists): __construct($appId, $schema, $defaultRegister, $cardFields, $types) vs OR main __construct($appId, $schema, $defaultRegister, $cardFields, $types, $publishFields, $publishGroups) [lib/AppHost/Service/StoreDescriptor.php:64]
- `tests/phpstan/apphost-symbols.php:38` `OCA\OpenRegister\AppHost\Observability\GenericHealthController` (missing): class no longer exists on OR main
- `tests/phpstan/apphost-symbols.php:53` `OCA\OpenRegister\AppHost\Observability\GenericMetricsController` (missing): class no longer exists on OR main

### Implements / extends OR classes: 2

- `tests/phpstan/apphost-symbols.php:38` GenericHealthController extends `OCA\OpenRegister\AppHost\Observability\Controller` (missing)
- `tests/phpstan/apphost-symbols.php:53` GenericMetricsController extends `OCA\OpenRegister\AppHost\Observability\Controller` (missing)

<details><summary>Dynamic call sites in lib/ (method_exists, is_callable, $x->$m(), call_user_func): 4. Always suspect: a renamed OR method makes these silently skip.</summary>

- `lib/Controller/ManifestController.php:424` `if (is_object($item) === true && method_exists($item, 'getObject') === true) {`
- `lib/Controller/ManifestController.php:433` `if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {`
- `lib/Service/Connection/ConnectionObservations.php:225` `if (method_exists($exception, 'getResponse') === false) {`
- `lib/Service/Connection/ConnectionObservations.php:230` `if (is_object($response) === false \|\| method_exists($response, 'getStatusCode') === false) {`

</details>

Unresolved call sites (receiver type unknown, magic, or spread args): 0, listed in `launchpad.json`.

## larpinq

Local dir `larpingapp`, branch `HEAD`, sha `410f14c0875baf56f1dcce19e44ec63cff15545f`.

### Runtime breaks (lib): 0

### Test-suite breaks (tests): 2

| file:line | category | stub | app calls | OR main now | fix |
|---|---|---|---|---|---|
| `tests/unit/Service/RealEvaluatorRegister.php:111` | constructor-shape |  | new \OCA\OpenRegister\Service\Object\PermissionHandler(userSession: $session, userManager: $users, groupManager: $groupManager, schemaMapper: $this->createMock( | OCA\OpenRegister\Service\Object\PermissionHandler::__construct(private readonly PermissionCallerServices $caller, private readonly PermissionDataServices $data, private readonly PermissionRuleServices | UNKNOWN-NAMED-ARG: `userSession:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `userManager:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `groupManager:` (Error: Unknown named paramet |
| `tests/unit/Settings/RuleSchemaAuthorizationTest.php:168` | constructor-shape |  | new \OCA\OpenRegister\Service\Object\PermissionHandler(userSession: $session, userManager: $users, groupManager: $groupManager, schemaMapper: $this->createMock( | OCA\OpenRegister\Service\Object\PermissionHandler::__construct(private readonly PermissionCallerServices $caller, private readonly PermissionDataServices $data, private readonly PermissionRuleServices | UNKNOWN-NAMED-ARG: `userSession:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `userManager:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `groupManager:` (Error: Unknown named paramet |

### Implements / extends OR classes: 9

- `tests/unit/Listener/CharacterRequirementListenerTest.php:50` FakeObjectEntity extends `OCA\OpenRegister\Db\ObjectEntity` (exists)
- `tests/unit/Listener/CharacterStatusListenerTest.php:32` StatusObjectEntity extends `OCA\OpenRegister\Db\ObjectEntity` (exists)
- `tests/unit/Listener/FactionMembershipListenerTest.php:41` ConnectionObjectEntity extends `OCA\OpenRegister\Db\ObjectEntity` (exists)
- `tests/unit/Listener/PlayerReviewListenerTest.php:31` ReviewObjectEntity extends `OCA\OpenRegister\Db\ObjectEntity` (exists)
- `tests/unit/Listener/PortalProfileListenerTest.php:39` PortalObjectEntity extends `OCA\OpenRegister\Db\ObjectEntity` (exists)
- `tests/unit/Listener/UniqueHolderListenerTest.php:39` HeldObjectEntity extends `OCA\OpenRegister\Db\ObjectEntity` (exists)
- `tests/unit/Listener/XpAwardProvenanceListenerTest.php:31` AwardObjectEntity extends `OCA\OpenRegister\Db\ObjectEntity` (exists)
- `tests/unit/Support/InMemoryOpenRegister.php:38` InMemoryObjectEntity extends `OCA\OpenRegister\Db\ObjectEntity` (exists)
- `tests/unit/Support/PaymentWorld.php:67` FakePaymentLeaf implements `OCA\OpenRegister\Service\Integration\IntegrationProvider` (exists)

<details><summary>Dynamic call sites in lib/ (method_exists, is_callable, $x->$m(), call_user_func): 31. Always suspect: a renamed OR method makes these silently skip.</summary>

- `lib/Listener/CharacterRequirementListener.php:253` `if (is_callable([$entity, 'getSchema']) === true) {`
- `lib/Listener/CharacterRequirementListener.php:253` `if (is_callable([$entity, 'getSchema']) === true) {`
- `lib/Listener/CharacterStatusListener.php:81` `if ($schema === '' \|\| is_callable([$entity, 'getSchema']) === false \|\| (string)$entity->getSchema() !== $schema) {`
- `lib/Listener/CharacterStatusListener.php:81` `if ($schema === '' \|\| is_callable([$entity, 'getSchema']) === false \|\| (string)$entity->getSchema() !== $schema) {`
- `lib/Listener/DeepLinkRegistrationListener.php:139` `if (method_exists($event, 'register') === false) {`
- `lib/Listener/FactionMembershipListener.php:178` `if (is_callable([$entity, 'getSchema']) === false) {`
- `lib/Listener/FactionMembershipListener.php:178` `if (is_callable([$entity, 'getSchema']) === false) {`
- `lib/Listener/PaymentRequestListener.php:117` `if ($schema !== '' && is_callable([$entity, 'getSchema']) === true && (string)$entity->getSchema() === $schema) {`
- `lib/Listener/PaymentRequestListener.php:117` `if ($schema !== '' && is_callable([$entity, 'getSchema']) === true && (string)$entity->getSchema() === $schema) {`
- `lib/Listener/PaymentRequestListener.php:144` `if (is_callable([$entity, 'getUuid']) === true && (string)$entity->getUuid() !== '') {`
- `lib/Listener/PaymentRequestListener.php:144` `if (is_callable([$entity, 'getUuid']) === true && (string)$entity->getUuid() !== '') {`
- `lib/Listener/PlayerReviewListener.php:133` `&& is_callable([$entity, 'getSchema']) === true`
- `lib/Listener/PlayerReviewListener.php:133` `&& is_callable([$entity, 'getSchema']) === true`
- `lib/Listener/PortalProfileListener.php:179` `if ($this->isSchema(entity: $entity, key: 'player_schema') === false \|\| is_callable([$entity, 'getObject']) === false) {`
- `lib/Listener/PortalProfileListener.php:179` `if ($this->isSchema(entity: $entity, key: 'player_schema') === false \|\| is_callable([$entity, 'getObject']) === false) {`
- `lib/Listener/PortalProfileListener.php:185` `if (is_callable([$entity, 'getUuid']) === true) {`
- `lib/Listener/PortalProfileListener.php:185` `if (is_callable([$entity, 'getUuid']) === true) {`
- `lib/Listener/PortalProfileListener.php:222` `&& is_callable([$entity, 'getSchema']) === true`
- `lib/Listener/PortalProfileListener.php:222` `&& is_callable([$entity, 'getSchema']) === true`
- `lib/Listener/RegistrationListener.php:209` `if ($schema === '' \|\| is_callable([$entity, 'getSchema']) === false) {`
- `lib/Listener/RegistrationListener.php:209` `if ($schema === '' \|\| is_callable([$entity, 'getSchema']) === false) {`
- `lib/Listener/RegistrationListener.php:227` `if (is_callable([$entity, 'getUuid']) === true && (string)$entity->getUuid() !== '') {`
- `lib/Listener/RegistrationListener.php:227` `if (is_callable([$entity, 'getUuid']) === true && (string)$entity->getUuid() !== '') {`
- `lib/Listener/UniqueHolderListener.php:338` `if (is_callable([$entity, 'getSchema']) === false) {`
- `lib/Listener/UniqueHolderListener.php:338` `if (is_callable([$entity, 'getSchema']) === false) {`
- `lib/Listener/UniqueHolderListener.php:356` `if (is_callable([$entity, 'getUuid']) === true) {`
- `lib/Listener/UniqueHolderListener.php:356` `if (is_callable([$entity, 'getUuid']) === true) {`
- `lib/Listener/XpAwardProvenanceListener.php:134` `&& is_callable([$entity, 'getSchema']) === true`
- `lib/Listener/XpAwardProvenanceListener.php:134` `&& is_callable([$entity, 'getSchema']) === true`
- `lib/Service/RegisterObjectFetcher.php:289` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/SettingsMapBuilder.php:153` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`

</details>

Unresolved call sites (receiver type unknown, magic, or spread args): 8, listed in `larpinq.json`.

## dossiq

Local dir `procest`, branch `HEAD`, sha `00b787be13c2bca353ab7cb103eebe30acd5638a`.

### Runtime breaks (lib): 8

| file:line | category | app calls | OR main now | fix |
|---|---|---|---|---|
| `lib/Service/Settings/OpenRegisterBridge.php:216` | string-lookup-missing (SILENT) | container-get('OCA\OpenRegister\Service\ApprovalService') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `lib/Service/DecisionDocument/DecisionDocumentValidator.php:113` | call-shape (SILENT) | $evaluator->holds($rule['when'], $document, $library) | OCA\OpenRegister\Service\Rules\NamedConditionEvaluator::holds(mixed $node, ConditionEvaluation $evaluation): bool (lib/Service/Rules/NamedConditionEvaluator.php:69) | SILENT-EXTRA-ARGS: 3 positional args, method takes 2 (extra args are silently dropped) |
| `lib/Woo/WooDeliveredSetWriter.php:316` | call-shape (SILENT) | $handler->freeze($setId, 'Woo-levering, publicatie ' . $publicationId, self::FREEZE_STATE, $register, $schema) | OCA\OpenRegister\Service\Object\ArchiveHandler::freeze(ObjectStateRequest $request, ?string $state = null): array (lib/Service/Object/ArchiveHandler.php:218) | SILENT-EXTRA-ARGS: 5 positional args, method takes 2 (extra args are silently dropped) |
| `lib/Service/Access/CaseRecordGrantsBackfill.php:249` | call-shape | $rights->giveInEveryOrganisation(right: self::GRANT_MANAGE, group: BackgroundServiceAccount::GROUP, register: $this->settingsService->getConfigValue('register'), schemas: $schemas) | OCA\OpenRegister\Service\Rbac\OrganisationSpecialRights::giveInEveryOrganisation(SpecialRightGrant $grant): array (lib/Service/Rbac/OrganisationSpecialRights.php:217) | UNKNOWN-NAMED-ARG: `right:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `group:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `register:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `schemas:` (Error: Unknown named parameter); MISSING |
| `lib/Service/Access/CaseTeamAccessProjector.php:383` | call-shape | $baselines->record(subject: $subject, definition: $definition, app: $baseline['app'], appVersion: $baseline['appVersion'],) | OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineStore::record(string $subject, ShippedDefinition $shipped): bool (lib/Service/ShippedBaseline/ShippedBaselineStore.php:148) | UNKNOWN-NAMED-ARG: `definition:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `app:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `appVersion:` (Error: Unknown named parameter); MISSING-REQUIRED-ARG: `$shipped` (ArgumentCountError) |
| `lib/Service/FormsIntakeService.php:282` | call-shape | $submitter->submit(destination: $destination, mapping: $mapping, payload: $answers, scope: $submission['formHash']) | OCA\OpenRegister\Service\Form\FormSubmitService::submit(FormSubmission $submission, ?string $idempotencyKey = null): array (lib/Service/Form/FormSubmitService.php:169) | UNKNOWN-NAMED-ARG: `destination:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `mapping:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `payload:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `scope:` (Error: Unknown named parameter); MI |
| `lib/Service/Task/EngineTaskGateway.php:776` | call-shape | $completion->complete($id, $outcome, null, null, $data, $actor) | OCA\OpenRegister\Service\Task\TaskFormCompletion::complete(string $uuid, TaskAnswer $answer, array $data, ?string $actor): OCA\OpenRegister\Db\Task (lib/Service/Task/TaskFormCompletion.php:92) | SILENT-EXTRA-ARGS: 6 positional args, method takes 4 (extra args are silently dropped); TYPE: passes null literal `null` to `$data` typed `array` (TypeError) |
| `lib/Service/Task/OnboardingSteps.php:226` | call-shape | $completion->complete($taskId, 'done', null, null, [], $actor) | OCA\OpenRegister\Service\Task\TaskFormCompletion::complete(string $uuid, TaskAnswer $answer, array $data, ?string $actor): OCA\OpenRegister\Db\Task (lib/Service/Task/TaskFormCompletion.php:92) | SILENT-EXTRA-ARGS: 6 positional args, method takes 4 (extra args are silently dropped); TYPE: passes string literal `'xxxx'` to `$answer` typed `OCA\OpenRegister\Service\Task\TaskAnswer` (TypeError); TYPE: passes null literal `null` to `$data` typed `array` (T |

### Test-suite breaks (tests): 21

| file:line | category | stub | app calls | OR main now | fix |
|---|---|---|---|---|---|
| `tests/Stubs/Service/Flow/FlowRunService.php:24` | contract-method-missing | stub | FlowRunService implements OCA\OpenRegister\Service\Flow\FlowRunServiceInterface but has no advanceStream() | OCA\OpenRegister\Service\Flow\FlowRunServiceInterface::advanceStream(FlowRun $run, array $flow, object $subject, string $streamId, int\|string $budget): OCA\OpenRegister\Db\FlowRun (lib/Service/Flow/Fl | add the method (class fails to load: fatal) |
| `tests/Stubs/Service/Flow/FlowRunService.php:24` | contract-method-missing | stub | FlowRunService implements OCA\OpenRegister\Service\Flow\FlowRunServiceInterface but has no queue() | OCA\OpenRegister\Service\Flow\FlowRunServiceInterface::queue(string $flowId, array $subject = [], string $trigger = 'manual', array $context = [], ?string $user = null): OCA\OpenRegister\Db\FlowRun (l | add the method (class fails to load: fatal) |
| `tests/Stubs/Service/Flow/FlowRunService.php:24` | contract-method-missing | stub | FlowRunService implements OCA\OpenRegister\Service\Flow\FlowRunServiceInterface but has no hasActiveRun() | OCA\OpenRegister\Service\Flow\FlowRunServiceInterface::hasActiveRun(string $flowId): bool (lib/Service/Flow/FlowRunServiceInterface.php:178) | add the method (class fails to load: fatal) |
| `tests/Stubs/Service/Flow/FlowRunService.php:24` | contract-method-missing | stub | FlowRunService implements OCA\OpenRegister\Service\Flow\FlowRunServiceInterface but has no retry() | OCA\OpenRegister\Service\Flow\FlowRunServiceInterface::retry(FlowRun $run): OCA\OpenRegister\Db\FlowRun\|null (lib/Service/Flow/FlowRunServiceInterface.php:197) | add the method (class fails to load: fatal) |
| `tests/Stubs/Service/Flow/FlowRunService.php:24` | contract-method-missing | stub | FlowRunService implements OCA\OpenRegister\Service\Flow\FlowRunServiceInterface but has no execute() | OCA\OpenRegister\Service\Flow\FlowRunServiceInterface::execute(FlowRun $run, array $flow, object $subject, ?array $seedItems = null, ?string $startAt = null): OCA\OpenRegister\Db\FlowRun (lib/Service/ | add the method (class fails to load: fatal) |
| `tests/Stubs/Service/Flow/FlowRunService.php:24` | contract-method-missing | stub | FlowRunService implements OCA\OpenRegister\Service\Flow\FlowRunServiceInterface but has no executeNode() | OCA\OpenRegister\Service\Flow\FlowRunServiceInterface::executeNode(FlowRun $run, array $flow, object $subject, array $config = []): OCA\OpenRegister\Db\FlowRun (lib/Service/Flow/FlowRunServiceInterfac | add the method (class fails to load: fatal) |
| `tests/Stubs/Service/Flow/FlowRunService.php:24` | contract-method-missing | stub | FlowRunService implements OCA\OpenRegister\Service\Flow\FlowRunServiceInterface but has no seedItemsFrom() | OCA\OpenRegister\Service\Flow\FlowRunServiceInterface::seedItemsFrom(mixed $value): array (lib/Service/Flow/FlowRunServiceInterface.php:329) | add the method (class fails to load: fatal) |
| `tests/Unit/AppInfo/BoundFormsRegistrationTest.php:255` | constructor-shape | stub | new FormDependentsCheck($dispatcher, $validator, $appConfig, $notifications, $logger) | OCA\OpenRegister\Service\Form\FormDependentsCheck::__construct(FormDependentsSignals $signals, private readonly FormDestinationValidator $validator, private readonly IAppConfig $appConfig, private rea | SILENT-EXTRA-ARGS: 5 positional args, method takes 4 (extra args are silently dropped) |
| `tests/Unit/Flow/AskPersonHeartbeatRecoveryTest.php:218` | constructor-shape | stub | new FlowRunService($runs, $this->createMock(FlowStateMapper::class), new FlowEngine(new FlowDefinitionBuilder(), new NullLogger()), new FlowNodeRegistry($dispat | OCA\OpenRegister\Service\Flow\FlowRunService::__construct(private readonly FlowRunStores $stores, private readonly FlowRunWalkServices $walk, private readonly LoggerInterface $logger, private readonly | SILENT-EXTRA-ARGS: 11 positional args, method takes 4 (extra args are silently dropped) |
| `tests/Stubs/Db/Task.php:56` | parent-missing | stub | Task implements OCA\OpenRegister\Db\TaskStubContract | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |
| `tests/Unit/AppInfo/BoundFormsRegistrationTest.php:181` | constructor-shape | stub | new FormDependent(id: $id, app: $app, title: 'Formulier ' . $id, author: 'maker-' . $id, mapping: [ 'fields' => array_map(static fn (string $property): array => | OCA\OpenRegister\Service\Form\FormDependent::__construct(PublishedForm $form, public readonly ?string $author, public readonly array $mapping, public readonly ?Closure $unpublish = null) (lib/Service/ | UNKNOWN-NAMED-ARG: `id:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `app:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `title:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG:  |
| `tests/Unit/Flow/AskPersonFormDeclarationTest.php:188` | constructor-shape | stub | new TaskForm(kind: TaskForm::KIND_FIELDS, schema: 'case', fields: [['field' => 'verslag', 'required' => true]]) | OCA\OpenRegister\Service\Task\TaskForm::__construct(public readonly ?string $kind, TaskFormFields $native = new TaskFormFields(), public readonly ?int $formId = null, public readonly bool $requireChec | UNKNOWN-NAMED-ARG: `schema:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `fields:` (Error: Unknown named parameter) |
| `tests/Unit/Flow/AskPersonFormDeclarationTest.php:229` | constructor-shape | stub | new TaskForm(kind: TaskForm::KIND_FIELDS, schema: 'case') | OCA\OpenRegister\Service\Task\TaskForm::__construct(public readonly ?string $kind, TaskFormFields $native = new TaskFormFields(), public readonly ?int $formId = null, public readonly bool $requireChec | UNKNOWN-NAMED-ARG: `schema:` (Error: Unknown named parameter) |
| `tests/Unit/Flow/AskPersonFormDeclarationTest.php:291` | constructor-shape | stub | new TaskForm(kind: TaskForm::KIND_FIELDS, schema: 'case', fields: [['field' => 'identifier', 'required' => true]]) | OCA\OpenRegister\Service\Task\TaskForm::__construct(public readonly ?string $kind, TaskFormFields $native = new TaskFormFields(), public readonly ?int $formId = null, public readonly bool $requireChec | UNKNOWN-NAMED-ARG: `schema:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `fields:` (Error: Unknown named parameter) |
| `tests/Unit/Flow/AskPersonHeartbeatRecoveryTest.php:229` | constructor-shape |  | new FlowRunCommit(db: $db, runs: $runs, streams: $streamMapper, claims: $claimMapper, steps: $steps, logger: new NullLogger()) | OCA\OpenRegister\Service\Flow\FlowRunCommit::__construct(private readonly IDBConnection $db, private readonly FlowRunCommitRows $rows, private readonly LoggerInterface $logger, private readonly ?FlowH | UNKNOWN-NAMED-ARG: `runs:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `streams:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `claims:` (Error: Unknown named parameter); UNKNOWN-NAME |
| `tests/Unit/Listener/CaseDecisionAccessListenerTest.php:75` | constructor-shape | stub | new ReviewPolicy() | OCA\OpenRegister\Service\Decision\ReviewPolicy::__construct(private readonly bool $deciderOwned, private readonly array $supervisors, private readonly bool $hideOthers) (lib/Service/Decision/ReviewPol | MISSING-REQUIRED-ARG: `$deciderOwned` (ArgumentCountError); MISSING-REQUIRED-ARG: `$supervisors` (ArgumentCountError); MISSING-REQUIRED-ARG: `$hideOthers` (ArgumentCountError) |
| `tests/Unit/Listener/CaseDecisionAccessListenerTest.php:141` | constructor-shape | stub | new ReviewPolicy() | OCA\OpenRegister\Service\Decision\ReviewPolicy::__construct(private readonly bool $deciderOwned, private readonly array $supervisors, private readonly bool $hideOthers) (lib/Service/Decision/ReviewPol | MISSING-REQUIRED-ARG: `$deciderOwned` (ArgumentCountError); MISSING-REQUIRED-ARG: `$supervisors` (ArgumentCountError); MISSING-REQUIRED-ARG: `$hideOthers` (ArgumentCountError) |
| `tests/Unit/Listener/CaseDecisionAccessListenerTest.php:150` | constructor-shape | stub | new ReviewPolicy() | OCA\OpenRegister\Service\Decision\ReviewPolicy::__construct(private readonly bool $deciderOwned, private readonly array $supervisors, private readonly bool $hideOthers) (lib/Service/Decision/ReviewPol | MISSING-REQUIRED-ARG: `$deciderOwned` (ArgumentCountError); MISSING-REQUIRED-ARG: `$supervisors` (ArgumentCountError); MISSING-REQUIRED-ARG: `$hideOthers` (ArgumentCountError) |
| `tests/Unit/Listener/CaseDecisionAccessListenerTest.php:166` | constructor-shape | stub | new ReviewPolicy() | OCA\OpenRegister\Service\Decision\ReviewPolicy::__construct(private readonly bool $deciderOwned, private readonly array $supervisors, private readonly bool $hideOthers) (lib/Service/Decision/ReviewPol | MISSING-REQUIRED-ARG: `$deciderOwned` (ArgumentCountError); MISSING-REQUIRED-ARG: `$supervisors` (ArgumentCountError); MISSING-REQUIRED-ARG: `$hideOthers` (ArgumentCountError) |
| `tests/Unit/Repair/MigrateRegisterApplicationIdTest.php:61` | constructor-shape | stub | new SchemaApplicationMigrator() | OCA\OpenRegister\Service\SchemaApplicationMigrator::__construct(private readonly IDBConnection $db) (lib/Service/SchemaApplicationMigrator.php:55) | MISSING-REQUIRED-ARG: `$db` (ArgumentCountError) |
| `tests/Unit/Service/Gdpr/PlatformDataSubjectRightsTest.php:285` | constructor-shape | stub | new ErasureRefusedException(rule: 'erasure-preview-stale', reason: 'The world moved since this preview was taken.', statusCode: 409,) | OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRefusedException::__construct(private readonly string $rule, string $reason, private readonly array $context = [], ?Throwable $previous = null) (lib/Servic | UNKNOWN-NAMED-ARG: `statusCode:` (Error: Unknown named parameter) |

### Test stubs of OR classes that drifted from OR main: 10 of 91

- `tests/Stubs/Db/Task.php:43` `OCA\OpenRegister\Db\TaskStubContract` (missing): class no longer exists on OR main
- `tests/Stubs/Db/TaskInboxCriteria.php:39` `OCA\OpenRegister\Db\TaskInboxCriteria` (exists): __construct($uid, $groupIds, $isAdmin, $scope, $states, $isTerminal, $priority, $objectUuid, $runUuid, $overdueAt, $dueAfter, $dueBefore, $sort, $sortDescending, $kind, $includeBlocked, $openFirst) vs OR main __construct($audience, $selection) [lib/Db/TaskInboxCriteria.php:199]
- `tests/Stubs/Exception/HookStoppedException.php:34` `OCA\OpenRegister\Exception\HookStoppedException` (exists): __construct($message, $errors, $code, $previous) vs OR main __construct($message, $errors) [lib/Exception/HookStoppedException.php:44]
- `tests/Stubs/Service/Dmn/DecisionTableEvaluator.php:23` `OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator` (exists): ruleMatches($rule, $declaredInputs, $coercedInputs, $ruleIndex) vs OR main ruleMatches($cells, $inputs) [lib/Service/Dmn/DecisionTableEvaluator.php:222]; assertAllOutputsAgree($matchedRules, $declaredOutputs, $matchedIds) vs OR main assertAllOutputsAgree($match) [lib/Service/Dmn/DecisionTableEvaluator.php:258]; collectOutputs($matchedRules, $declaredOutputs) vs OR main collectOutputs($match) [lib/Service/Dmn/DecisionTableEvaluator.php:282]; applyHitPolicy($hitPolicy, $matchedRules, $declaredOutputs) vs OR main applyHitPolicy($hitPolicy, $match) [lib/Service/Dmn/DecisionTableEvaluator.php:360]
- `tests/Stubs/Service/Dmn/UnaryTestEvaluator.php:47` `OCA\OpenRegister\Service\Dmn\UnaryTestEvaluator` (exists): matchesRange($match, $value, $type) vs OR main matchesRange($match, $subject) [lib/Service/Dmn/UnaryTestEvaluator.php:335]; matchesComparison($operator, $remainder, $value, $type) vs OR main matchesComparison($trimmed, $subject) [lib/Service/Dmn/UnaryTestEvaluator.php:359]; equals(): not on OR main
- `tests/Stubs/Service/Flow/FlowValueTemplate.php:31` `OCA\OpenRegister\Service\Flow\FlowValueTemplate` (exists): valueAt(): not on OR main
- `tests/Stubs/Service/Form/FormDependent.php:34` `OCA\OpenRegister\Service\Form\FormDependent` (exists): __construct($id, $app, $title, $author, $mapping, $unpublish, $audience) vs OR main __construct($form, $author, $mapping, $unpublish) [lib/Service/Form/FormDependent.php:73]
- `tests/Stubs/Service/Form/FormDependentsCheck.php:48` `OCA\OpenRegister\Service\Form\FormDependentsCheck` (exists): __construct($dispatcher, $validator, $appConfig, $notifications, $logger) vs OR main __construct($signals, $validator, $appConfig, $logger) [lib/Service/Form/FormDependentsCheck.php:94]; unpublish($form, $schema, $findings) vs OR main unpublish($run) [lib/Service/Form/FormDependentsCheck.php:247]
- `tests/Stubs/Service/Gdpr/Erasure/ErasureRefusedException.php:30` `OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRefusedException` (exists): __construct($rule, $reason, $statusCode, $context, $previous) vs OR main __construct($rule, $reason, $context, $previous) [lib/Service/Gdpr/Erasure/ErasureRefusedException.php:72]
- `tests/Stubs/Service/Task/TaskForm.php:24` `OCA\OpenRegister\Service\Task\TaskForm` (exists): __construct($kind, $schema, $action, $fields, $formId, $requireChecklist) vs OR main __construct($kind, $native, $formId, $requireChecklist) [lib/Service/Task/TaskForm.php:99]

### Implements / extends OR classes: 21

- `lib/BulkAction/LifecycleCasesAction.php:55` LifecycleCasesAction implements `OCA\OpenRegister\BulkAction\BulkActionInterface` (exists)
- `lib/BulkAction/MoveCaseTypeVersionAction.php:66` MoveCaseTypeVersionAction implements `OCA\OpenRegister\BulkAction\BulkActionInterface` (exists)
- `lib/BulkAction/ReassignCasesAction.php:56` ReassignCasesAction implements `OCA\OpenRegister\BulkAction\BulkActionInterface` (exists)
- `lib/BulkAction/SetCaseAttributeAction.php:60` SetCaseAttributeAction implements `OCA\OpenRegister\BulkAction\BulkActionInterface` (exists)
- `lib/BulkAction/TransitionCasesAction.php:56` TransitionCasesAction implements `OCA\OpenRegister\BulkAction\BulkActionInterface` (exists)
- `lib/Controller/HealthController.php:55` HealthController extends `OCA\OpenRegister\AppHost\Controller\GenericHealthController` (exists)
- `lib/Controller/MetricsController.php:55` MetricsController extends `OCA\OpenRegister\AppHost\Controller\GenericMetricsController` (exists)
- `lib/Flow/DossiqAskPersonNode.php:84` DossiqAskPersonNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Flow/DossiqEnsureCommitteeNode.php:51` DossiqEnsureCommitteeNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Flow/DossiqFlowNodeBase.php:49` DossiqFlowNodeBase implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Lifecycle/BezwaarDeadlineGuard.php:43` BezwaarDeadlineGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/CaseActionProvider.php:70` CaseActionProvider implements `OCA\OpenRegister\Lifecycle\LifecycleActionProviderInterface` (exists)
- `lib/Lifecycle/HoorzittingAfzienGuard.php:41` HoorzittingAfzienGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Mcp/DossiqScannableServices.php:49` DossiqScannableServices implements `OCA\OpenRegister\Mcp\IMcpScannableServices` (exists)
- `tests/Stubs/Db/ObjectEntity.php:30` ObjectEntity implements `OCA\OpenRegister\Contract\ObjectEntityInterface` (exists)
- `tests/Stubs/Db/Task.php:43` TaskStubContract implements `OCA\OpenRegister\Kernel\Contracts\TaskInterface` (exists)
- `tests/Stubs/Db/Task.php:56` Task implements `OCA\OpenRegister\Db\TaskStubContract` (missing)
- `tests/Stubs/Service/Flow/FlowRunService.php:24` FlowRunService implements `OCA\OpenRegister\Service\Flow\FlowRunServiceInterface` (exists)
- `tests/Support/RecordingAuditTrailMapper.php:36` RecordingAuditTrailMapper extends `OCA\OpenRegister\Db\AuditTrailMapper` (exists)
- `tests/Support/RecordingBulkActionRegistrationEvent.php:39` RecordingBulkActionRegistrationEvent extends `OCA\OpenRegister\Event\BulkActionRegistrationEvent` (exists)
- `tests/Unit/Flow/AskPersonHeartbeatRecoveryTest.php:98` AskPersonPassNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)

### OR event listeners: 70 registrations

- `OCA\OpenRegister\Event\BulkActionRegistrationEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Registrar/BulkActionRegistrar.php:68`
- `OCA\OpenRegister\Event\DecisionChangeRequestedEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Registrar/CaseAccessListenerRegistrar.php:73`
- `OCA\OpenRegister\Event\DecisionVisibilityRequestedEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Registrar/CaseAccessListenerRegistrar.php:75`
- `OCA\OpenRegister\Event\FlowTimerFiredEvent` (exists, OR main constructed): 2x, e.g. `lib/AppInfo/Registrar/DeadlineTimerRegistrar.php:105`
- `OCA\OpenRegister\Event\ObjectCreatedEvent` (exists, OR main constructed): 19x, e.g. `lib/AppInfo/Registrar/ContactListenerRegistrar.php:53`
- `OCA\OpenRegister\Event\ObjectCreatingEvent` (exists, OR main constructed): 4x, e.g. `lib/AppInfo/Registrar/IntakeFormListenerRegistrar.php:71`
- `OCA\OpenRegister\Event\ObjectDeletedEvent` (exists, OR main constructed): 2x, e.g. `lib/AppInfo/Registrar/ObjectListenerRegistrar.php:155`
- `OCA\OpenRegister\Event\ObjectDeletingEvent` (exists, OR main constructed): 7x, e.g. `lib/AppInfo/Registrar/ImmutabilityListenerRegistrar.php:74`
- `OCA\OpenRegister\Event\ObjectUpdatedEvent` (exists, OR main constructed): 15x, e.g. `lib/AppInfo/Registrar/BezwaarListenerRegistrar.php:89`
- `OCA\OpenRegister\Event\ObjectUpdatingEvent` (exists, OR main constructed): 12x, e.g. `lib/AppInfo/Registrar/CaseTypeListenerRegistrar.php:131`
- `OCA\OpenRegister\Event\ObjectsMergedEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Registrar/CaseMergeRegistrar.php:54`
- `OCA\OpenRegister\Event\OrganisationUpdatedEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Registrar/SaasServiceRegistrar.php:65`
- `OCA\OpenRegister\Event\SchemaUpdatedEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Registrar/PersonListenerRegistrar.php:74`
- `OCA\OpenRegister\Event\TaskTerminalEvent` (exists, OR main constructed): 2x, e.g. `lib/AppInfo/Registrar/TaskListenerRegistrar.php:73`
- `OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Registrar/CrossAppListenerRegistrar.php:123`

### JS/manifest URLs with no matching OR main route: 1

- `src/services/searchIndexApi.js:34` `/apps/openregister/api/settings/search-index/run`

<details><summary>Dynamic call sites in lib/ (method_exists, is_callable, $x->$m(), call_user_func): 362. Always suspect: a renamed OR method makes these silently skip.</summary>

- `lib/BackgroundJob/SentimentAnalysisJob.php:251` `if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/BackgroundJob/SpecialistBeschikbaarheidRefreshJob.php:216` `if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/Command/Backfill/OpenRegisterRowNormaliser.php:76` `if (method_exists($row, 'getObject') === true && is_array($row->getObject()) === true) {`
- `lib/Command/Backfill/OpenRegisterRowNormaliser.php:85` `if ($uuid === '' && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Command/Backfill/OpenRegisterRowNormaliser.php:110` `if ($uuid === '' && method_exists($row, $getter) === true) {`
- `lib/Command/Backfill/OpenRegisterRowNormaliser.php:111` `$uuid = (string)($row->$getter() ?? '');`
- `lib/Command/DrainIntakeObjectsCommand.php:278` `if (method_exists($e, 'getFindings') === true && $e->getFindings() !== []) {`
- `lib/Controller/AcController.php:463` `if (method_exists($consumer, $setter) === true) {`
- `lib/Controller/AcController.php:464` `$consumer->$setter($value);`
- `lib/Controller/AcController.php:899` `if (method_exists($consumer, 'getAuthorizationConfiguration') === true) {`
- `lib/Controller/AccruingAmountController.php:213` `if (is_object($value) === false \|\| is_callable([$value, 'jsonSerialize']) === false) {`
- `lib/Controller/AccruingAmountController.php:213` `if (is_object($value) === false \|\| is_callable([$value, 'jsonSerialize']) === false) {`
- `lib/Controller/AdviceController.php:306` `if (method_exists($this->request, 'getContent') === true) {`
- `lib/Controller/BelplanController.php:266` `if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/Controller/BulkJobHandoffController.php:198` `if (method_exists($exception, 'getReason') === false) {`
- `lib/Controller/BulkJobHandoffController.php:211` `if (method_exists($exception, 'getDetails') === true) {`
- `lib/Controller/DrcController.php:2159` `if (method_exists($objectService, 'getLockInfo') === false) {`
- `lib/Controller/DsoController.php:484` `if (method_exists($this->request, 'getContent') === true) {`
- `lib/Controller/PublicCaseSurvivorController.php:142` `if ($tokens === null \|\| method_exists($tokens, 'resolve') === false) {`
- `lib/Controller/PublicCaseSurvivorController.php:222` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Controller/RoutingController.php:280` `if (method_exists($value, 'jsonSerialize') === true) {`
- `lib/Controller/RoutingController.php:287` `if (method_exists($value, 'toArray') === true) {`
- `lib/Controller/SettingsController.php:189` `if (method_exists($e, 'getRule') === false`
- `lib/Controller/SettingsController.php:190` `\|\| method_exists($e, 'getStatus') === false`
- `lib/Controller/SettingsController.php:191` `\|\| method_exists($e, 'getSentence') === false`
- `lib/Controller/SubsidieRegisterController.php:194` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Flow/AskPersonTaskStore.php:187` `if (is_object($found) === true && method_exists($found, 'getObject') === true) {`
- `lib/Flow/DossiqEnsureCommitteeNode.php:291` `if (is_object($committee) === true && method_exists($committee, 'jsonSerialize') === true) {`
- `lib/Listener/BezwaarLegalHoldListener.php:321` `if ($objection === null \|\| method_exists($objection, 'getObject') === false) {`
- `lib/Listener/BezwaarLegalHoldListener.php:354` `if (method_exists($event, 'getObject') === true) {`
- `lib/Listener/BezwaarLegalHoldListener.php:362` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Listener/BezwaarLifecycleListener.php:137` `if (method_exists($event, 'getObject') === true) {`
- `lib/Listener/BezwaarLifecycleListener.php:145` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Listener/BulkActionRegistrationListener.php:95` `if (method_exists($event, 'registerAction') === false) {`
- `lib/Listener/CaseMergedListener.php:77` `if (method_exists($event, 'getSurvivorUuid') === false`
- `lib/Listener/CaseMergedListener.php:78` `\|\| method_exists($event, 'getMergedFromUuids') === false`
- `lib/Listener/CaseMergedListener.php:79` `\|\| method_exists($event, 'isReversal') === false`
- `lib/Listener/CasePhaseTermListener.php:176` `if (method_exists($event, 'getObject') === false) {`
- `lib/Listener/CasePhaseTermListener.php:185` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Listener/CaseTypeRightsRowsListener.php:96` `\|\| method_exists($event, 'getProvider') === false`
- `lib/Listener/ChecklistRunImmutabilityListener.php:221` `if (method_exists($event, $method) === false) {`
- `lib/Listener/ChecklistRunImmutabilityListener.php:225` `$value = $event->{$method}();`
- `lib/Listener/ChecklistRunImmutabilityListener.php:231` `if (method_exists($value, 'jsonSerialize') === true) {`
- `lib/Listener/ChecklistRunImmutabilityListener.php:238` `if (method_exists($value, 'toArray') === true) {`
- `lib/Listener/ContactMomentPipelinqListener.php:129` `if (method_exists($event, 'getObject') === true) {`
- `lib/Listener/ContactMomentPipelinqListener.php:137` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Listener/ContactMomentTimelineListener.php:230` `if (method_exists($event, 'getObject') === true) {`
- `lib/Listener/ContactMomentTimelineListener.php:238` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Listener/CustodyCaseCreatedListener.php:157` `if (method_exists($event, 'getObject') === false) {`
- `lib/Listener/CustodyCaseCreatedListener.php:166` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Listener/DecisionConcludedListener.php:147` `if (method_exists($event, 'getSourceApp') === false) {`
- `lib/Listener/DecisionConcludedListener.php:280` `if (method_exists($event, $getter) === false) {`
- `lib/Listener/DecisionConcludedListener.php:284` `$value = $event->$getter();`
- `lib/Listener/DecisionConcludedListener.php:305` `if (method_exists($event, 'getSigners') === true) {`
- `lib/Listener/DecisionConcludedListener.php:328` `if (method_exists($event, 'isSigned') === true && $event->isSigned() === true) {`
- `lib/Listener/DeclaredAccessExceptionListener.php:108` `if (is_object($object) === false \|\| method_exists($object, 'jsonSerialize') === false) {`
- `lib/Listener/DeliveryConcludedListener.php:261` `if (is_array($obj) === false && method_exists($obj, 'jsonSerialize') === true) {`
- `lib/Listener/DependentTermListener.php:210` `if (method_exists($event, $method) === false) {`
- `lib/Listener/DependentTermListener.php:214` `$value = $event->{$method}();`
- `lib/Listener/DependentTermListener.php:219` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Listener/DocumentJoinHomingListener.php:118` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Listener/FileRestrictionAdmitListener.php:76` `\|\| method_exists($event, 'getFileId') === false`
- `lib/Listener/FileRestrictionAdmitListener.php:77` `\|\| method_exists($event, 'getUserId') === false`
- `lib/Listener/FileRestrictionAdmitListener.php:78` `\|\| method_exists($event, 'admit') === false`
- `lib/Listener/IntakeFormDependentsListener.php:132` `if (is_callable([$schema, $getter]) === true && (string)$schema->$getter() === $expected) {`
- `lib/Listener/IntakeFormDependentsListener.php:132` `if (is_callable([$schema, $getter]) === true && (string)$schema->$getter() === $expected) {`
- `lib/Listener/IntakeMessageRoutedListener.php:125` `if (method_exists($event, 'getTargetSchema') === false) {`
- `lib/Listener/IntakeMessageRoutedListener.php:143` `if ($caseId === '' \|\| method_exists($event, 'setCreatedRef') === false) {`
- `lib/Listener/IntakeMessageRoutedListener.php:169` `if (method_exists($event, $method) === false) {`
- `lib/Listener/IntakeMessageRoutedListener.php:173` `$value = $event->$method();`
- `lib/Listener/IntakeMessageRoutedListener.php:191` `if (method_exists($event, $method) === false) {`
- `lib/Listener/IntakeMessageRoutedListener.php:195` `$value = $event->$method();`
- `lib/Listener/LinkMarkerListener.php:225` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Listener/MessageReceivedListener.php:165` `if (method_exists($event, 'setOutcome') === false \|\| method_exists($event, 'getMessage') === false) {`
- `lib/Listener/MessageReceivedListener.php:165` `if (method_exists($event, 'setOutcome') === false \|\| method_exists($event, 'getMessage') === false) {`
- `lib/Listener/MessageReceivedListener.php:366` `if (method_exists($event, $method) === false) {`
- `lib/Listener/MessageReceivedListener.php:370` `$value = $event->$method();`
- `lib/Listener/MessageReceivedListener.php:388` `if (method_exists($event, $method) === false) {`
- `lib/Listener/MessageReceivedListener.php:392` `$value = $event->$method();`
- `lib/Listener/PersonLinkListener.php:102` `if (is_callable([$link, 'jsonSerialize']) === false) {`
- `lib/Listener/PersonLinkListener.php:102` `if (is_callable([$link, 'jsonSerialize']) === false) {`
- `lib/Listener/PersonLinkListener.php:106` `$row = call_user_func([$link, 'jsonSerialize']);`
- `lib/Listener/PersonLinkListener.php:106` `$row = call_user_func([$link, 'jsonSerialize']);`
- `lib/Listener/PortalContactDetailsChangedListener.php:90` `if (method_exists($event, 'getSubjectRef') === false \|\| method_exists($event, 'getChannel') === false) {`
- `lib/Listener/PortalContactDetailsChangedListener.php:90` `if (method_exists($event, 'getSubjectRef') === false \|\| method_exists($event, 'getChannel') === false) {`
- `lib/Listener/PortalContactDetailsChangedListener.php:97` `if (method_exists($event, 'getOrganisation') === true) {`
- `lib/Listener/PortalMessageTimelineListener.php:244` `if (method_exists($event, 'getObject') === true) {`
- `lib/Listener/PortalMessageTimelineListener.php:252` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Listener/RoleMutationListener.php:141` `if (method_exists($event, $method) === false) {`
- `lib/Listener/RoleMutationListener.php:145` `$value = $event->{$method}();`
- `lib/Listener/RoleMutationListener.php:151` `if (method_exists($value, 'jsonSerialize') === true) {`
- `lib/Listener/RoleMutationListener.php:158` `if (method_exists($value, 'toArray') === true) {`
- `lib/Listener/StufProviderQueryListener.php:258` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Listener/Support/ObjectUpdatePayload.php:139` `if (method_exists($event, 'getOldObject') === false) {`
- `lib/Listener/Support/ObjectUpdatePayload.php:168` `if (method_exists($value, $method) === true) {`
- `lib/Listener/Support/ObjectUpdatePayload.php:169` `$array = $value->{$method}();`
- `lib/Listener/Support/ReadsEventObjects.php:48` `if (method_exists($event, $method) === true) {`
- `lib/Listener/Support/ReadsEventObjects.php:49` `yield $event->{$method}();`
- `lib/Listener/Support/SavedObjectPayload.php:118` `if (method_exists($event, $method) === false) {`
- `lib/Listener/Support/SavedObjectPayload.php:122` `return $event->{$method}();`
- `lib/Listener/Support/SavedObjectPayload.php:136` `} else if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Listener/TermStatusClockListener.php:151` `if (method_exists($event, $method) === false) {`
- `lib/Listener/TermStatusClockListener.php:155` `$value = $event->{$method}();`
- `lib/Listener/TermStatusClockListener.php:160` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Listener/WooStartFromListener.php:107` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Portal/ApplicantPortalActs.php:267` `if (is_object($object) === false \|\| method_exists($object, 'jsonSerialize') === false) {`
- `lib/Repair/BackfillAdviceRequestObjection.php:141` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Repair/BackfillCaseCustody.php:154` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Repair/CaseFlowSeedDataRepairStep.php:529` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Repair/CaseFlowSeedIndex.php:232` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Repair/DeclareNotificationTemplates.php:137` `if (method_exists($registry, 'gaps') === false) {`
- `lib/Repair/DeclareNotificationTemplates.php:174` `if (method_exists($registry, 'edit') === false) {`
- `lib/Repair/FoldCasePropertiesOntoCase.php:238` `if (is_object($case) === true && method_exists($case, 'jsonSerialize') === true) {`
- `lib/Repair/FoldCasePropertiesOntoCase.php:291` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Repair/MigrateArchivalToOpenRegister.php:210` `\|\| method_exists($entity, 'getConfiguration') === false`
- `lib/Repair/MigrateArchivalToOpenRegister.php:211` `\|\| method_exists($entity, 'setConfiguration') === false`
- `lib/Repair/MigrateCommitteesToDecidiq.php:121` `if (method_exists($objectService, 'runAsSystem') === false) {`
- `lib/Repair/MigrateSubsidieRegelingToCaseType.php:234` `if (is_object($scheme) === true && method_exists($scheme, 'jsonSerialize') === true) {`
- `lib/Repair/MigrateSubsidieRegelingToCaseType.php:406` `if (is_object($created) === true && method_exists($created, 'jsonSerialize') === true) {`
- `lib/Repair/MigrateTenantsToOrganisations.php:185` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Repair/ShipAssistantPrompts.php:114` `if (is_object($library) === false \|\| method_exists($library, 'seed') === false) {`
- `lib/Repair/Support/RunsUnderSystemIdentity.php:62` `if ($objectService !== null && method_exists($objectService, 'runAsSystem') === true) {`
- `lib/Review/DocumentRelevance.php:352` `if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {`
- `lib/Review/DocumentText.php:118` `if (is_object($service) === false \|\| method_exists($service, 'getExtractedText') === false) {`
- `lib/Review/RecallSamples.php:380` `if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {`
- `lib/Review/ReviewBatches.php:489` `if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {`
- `lib/Review/StoppingRules.php:200` `if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {`
- `lib/Service/Access/ObjectReadGrants.php:256` `if (is_object($object) === false \|\| method_exists($object, 'getOwner') === false) {`
- `lib/Service/Access/OpenRegisterGrantsGateway.php:272` `if (method_exists($handler, self::PROVENANCE_METHOD) === false) {`
- `lib/Service/Actions/AutomaticActionFlowMigrator.php:461` `if (is_object($entry) === true && method_exists($entry, 'jsonSerialize') === true) {`
- `lib/Service/Advice/AdviceRepository.php:239` `if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/Service/Assistant/CaseAssistantService.php:227` `if (is_object($case) === true && method_exists($case, 'jsonSerialize') === true) {`
- `lib/Service/BerichtenboxService.php:384` `if (is_object($data) === true && method_exists($data, 'jsonSerialize') === true) {`
- `lib/Service/Beschikking/BeschikkingRepository.php:171` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/BeschikkingGenerationService.php:291` `if (method_exists($service, 'generateDocument') === false) {`
- `lib/Service/Bezwaar/BezwaarCreationHook.php:347` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/Bulk/BulkJobHandoff.php:328` `if (is_object($job) === true && method_exists($job, 'jsonSerialize') === true) {`
- `lib/Service/Bulk/CaseTypeVersionGuard.php:221` `if (is_object($value) === true && method_exists($value, 'getObject') === true) {`
- `lib/Service/Bulk/CaseTypeVersionGuard.php:231` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/CaseAccessGuard.php:374` `if ($resolver === null \|\| method_exists($resolver, 'isGranted') === false) {`
- `lib/Service/CaseAccessPurposeService.php:334` `if (method_exists($entity, 'getUuid') === true) {`
- `lib/Service/CaseAccessPurposeService.php:441` `if (method_exists($entity, 'getObject') === true) {`
- `lib/Service/CaseAccessPurposeService.php:448` `if (method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Service/CaseCollaborationService.php:353` `if (method_exists($mapper, 'findByToken') === false) {`
- `lib/Service/CaseCopyService.php:482` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/CaseDateNormaliser.php:401` `if ($service === null \|\| method_exists($service, 'resolve') === false) {`
- `lib/Service/CaseDateNormaliser.php:407` `\|\| method_exists($calendar, self::ENGINE_CALENDAR_ZONE_METHOD) === false`
- `lib/Service/CaseDefinition/WritesPackageRows.php:118` `if (is_object($stored) === true && method_exists($stored, 'getUuid') === true) {`
- `lib/Service/CaseDefinition/WritesPackageRows.php:122` `if (is_object($stored) === true && method_exists($stored, 'jsonSerialize') === true) {`
- `lib/Service/CaseEmailMatchService.php:225` `if (method_exists($objectService, 'runAs') === false) {`
- `lib/Service/CaseEmailMatchService.php:236` `if ($linkService === null \|\| method_exists($linkService, 'linkEmail') === false) {`
- `lib/Service/CaseEmailMatchService.php:453` `if (method_exists($linkService, 'getLinkedEmails') === false) {`
- `lib/Service/CaseMergeService.php:308` `return method_exists($merger, 'executeMerge');`
- `lib/Service/CasePlanDrain/CasePlanMigrationService.php:218` `if ($plans === null \|\| method_exists($plans, 'ensureItems') === false) {`
- `lib/Service/CasePlanProjectionService.php:408` `if (method_exists($plans, 'createPlanAsSystem') === false) {`
- `lib/Service/CasePlanProjectionService.php:776` `if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {`
- `lib/Service/CaseStageDatesService.php:119` `if (is_object($case) === true && method_exists($case, 'jsonSerialize') === true) {`
- `lib/Service/CaseType/CaseConditionEvaluator.php:59` `return (bool)call_user_func([self::EXPRESSIONS, 'isTrue'], $condition, ['json' => $case]);`
- `lib/Service/CaseType/EngineRunMigration.php:213` `if (method_exists($service, self::MIGRATION_METHOD) === false) {`
- `lib/Service/CaseTypeStore.php:377` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/Catalogue/ObjectTypeSchemaResolver.php:209` `if (method_exists($entity, 'getId') === true) {`
- `lib/Service/ContactMomentService.php:446` `if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/Service/DeadlineMonitoringSeedDataService.php:168` `if (method_exists($objectService, 'findObjects') === false) {`
- `lib/Service/Deelzaak/CaseObjectReader.php:172` `if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {`
- `lib/Service/Deelzaak/SubCaseDeriver.php:356` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/DemoCaseloadGateway.php:190` `if (method_exists($objectService, 'runAsSystem') === false) {`
- `lib/Service/DemoCaseloadGateway.php:290` `if (method_exists($object, 'getObject') === true) {`
- `lib/Service/DemoCaseloadGateway.php:294` `if (method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/DemoCaseloadGateway.php:315` `if (method_exists($object, 'getUuid') === true) {`
- `lib/Service/DemoCaseloadGateway.php:322` `if (method_exists($object, 'getId') === true) {`
- `lib/Service/Dmn/StoredDecisionTables.php:273` `if (is_object($store) === true && method_exists($store, 'evaluate') === true && method_exists($store, 'find') === true) {`
- `lib/Service/Dmn/StoredDecisionTables.php:273` `if (is_object($store) === true && method_exists($store, 'evaluate') === true && method_exists($store, 'find') === true) {`
- `lib/Service/DoorverbindingService.php:291` `if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/Service/DossierCompiler.php:370` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/DsoCaseService.php:444` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/Email/CaseEmailRepository.php:160` `if (is_object($caseObj) === true && method_exists($caseObj, 'jsonSerialize') === true) {`
- `lib/Service/Email/EmailTemplateRepository.php:212` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/Email/NextcloudMailGateway.php:247` `if ($account === null \|\| $manager === null \|\| method_exists($manager, 'getSource') === false) {`
- `lib/Service/Email/NextcloudMailGateway.php:290` `if ($account === null \|\| $manager === null \|\| method_exists($manager, 'getByMessageId') === false) {`
- `lib/Service/Email/NextcloudMailGateway.php:326` `if ($account === null \|\| $manager === null \|\| method_exists($manager, 'moveMessage') === false) {`
- `lib/Service/Email/NextcloudMailGateway.php:368` `if ($validator === null \|\| method_exists($validator, 'validateRaw') === false) {`
- `lib/Service/Email/NextcloudMailGateway.php:412` `if ($service === null \|\| method_exists($service, 'isTrusted') === false) {`
- `lib/Service/Email/NextcloudMailGateway.php:454` `if ($account === null \|\| $manager === null \|\| method_exists($manager, 'getMailAttachments') === false) {`
- `lib/Service/Email/NextcloudMailGateway.php:620` `\|\| method_exists($outbox, 'saveMessage') === false \|\| method_exists($outbox, 'sendMessage') === false`
- `lib/Service/Email/NextcloudMailGateway.php:620` `\|\| method_exists($outbox, 'saveMessage') === false \|\| method_exists($outbox, 'sendMessage') === false`
- `lib/Service/Email/NextcloudMailGateway.php:753` `if (is_callable([$entity, $method]) === false) {`
- `lib/Service/Email/NextcloudMailGateway.php:758` `$value = $entity->{$method}();`
- `lib/Service/Email/NextcloudMailGateway.php:820` `if ($service === null \|\| method_exists($service, 'findById') === false) {`
- `lib/Service/Email/UnmatchedMailIntake.php:357` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/FilinqRedactionClient.php:298` `\|\| method_exists($error, 'getReason') === false`
- `lib/Service/FilinqRedactionClient.php:299` `\|\| method_exists($error, 'getBackend') === false`
- `lib/Service/FilinqRedactionClient.php:332` `if ($short !== 'RedactionNotReviewedException' \|\| method_exists($error, 'getReason') === false) {`
- `lib/Service/Flow/CaseFlowActions.php:484` `if (method_exists($found, 'jsonSerialize') === true) {`
- `lib/Service/Flow/PlannedSeriesLedger.php:242` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Flow/ShippedFlowAdoption.php:230` `if (method_exists($objectService, 'runAs') === false) {`
- `lib/Service/FormsIntakeService.php:281` `if ($submitter !== null && method_exists($submitter, 'submit') === true) {`
- `lib/Service/FormsIntakeService.php:312` `if (method_exists($refusal, 'getFindings') === false) {`
- `lib/Service/FormsIntakeService.php:355` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Gdpr/PlatformDataSubjectRights.php:351` `&& is_callable([$e, 'getRule']) === true`
- `lib/Service/Gdpr/PlatformDataSubjectRights.php:351` `&& is_callable([$e, 'getRule']) === true`
- `lib/Service/Gdpr/PlatformDataSubjectRights.php:352` `&& is_callable([$e, 'getStatusCode']) === true`
- `lib/Service/Gdpr/PlatformDataSubjectRights.php:352` `&& is_callable([$e, 'getStatusCode']) === true`
- `lib/Service/Gdpr/PlatformDataSubjectRights.php:361` `rule: (string)call_user_func([$e, 'getRule']),`
- `lib/Service/Gdpr/PlatformDataSubjectRights.php:361` `rule: (string)call_user_func([$e, 'getRule']),`
- `lib/Service/Gdpr/PlatformDataSubjectRights.php:363` `status: (int)call_user_func([$e, 'getStatusCode']),`
- `lib/Service/Gdpr/PlatformDataSubjectRights.php:363` `status: (int)call_user_func([$e, 'getStatusCode']),`
- `lib/Service/Import/RecordImportStore.php:120` `if (is_object($saved) === true && method_exists($saved, 'getUuid') === true) {`
- `lib/Service/Intake/BoundFormsCheck.php:153` `if (is_callable([$schema, 'getSlug']) === true) {`
- `lib/Service/Intake/BoundFormsCheck.php:153` `if (is_callable([$schema, 'getSlug']) === true) {`
- `lib/Service/Intake/BoundFormsCheck.php:222` `if ($dependents === null \|\| method_exists($dependents, 'apply') === false) {`
- `lib/Service/Intake/CaseCreationAnnouncer.php:107` `if (is_object($saved) === true && method_exists($saved, 'getUuid') === true) {`
- `lib/Service/Intake/CaseCreationAnnouncer.php:118` `if (method_exists($objectService, 'runAsSystem') === true) {`
- `lib/Service/Intake/CaseIntakeFiling.php:277` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/Intake/ChannelIntake.php:486` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/Intake/IntakeFormBindingCheck.php:108` `if ($validator === null \|\| method_exists($validator, 'validate') === false) {`
- `lib/Service/Intake/IntakeFormBindingCheck.php:164` `if ($validator === null \|\| $schema === null \|\| method_exists($validator, 'validate') === false) {`
- `lib/Service/Intake/IntakeFormBindingCheck.php:262` `if ($schemaId === '' \|\| $mapper === null \|\| method_exists($mapper, 'find') === false) {`
- `lib/Service/Intake/IntakeFormBindings.php:114` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Intake/IntakeSourceSeeder.php:343` `if (method_exists($object, 'getObject') === true) {`
- `lib/Service/Intake/IntakeSourceSeeder.php:352` `if (method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/Intake/IntakeSourceSeeder.php:381` `if (method_exists($object, 'getUuid') === true) {`
- `lib/Service/Intake/ReceiptMoment.php:100` `if (is_object($object) === true && method_exists($object, 'isDraft') === true) {`
- `lib/Service/Intake/ReceiptMoment.php:104` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/Intake/ReceiptMoment.php:211` `if (method_exists($event, 'getObject') === false) {`
- `lib/Service/Intake/ReceiptMoment.php:275` `if (is_object($object) === false \|\| method_exists($object, 'jsonSerialize') === false) {`
- `lib/Service/Intake/ReceiptMoment.php:297` `if ($this->isActivation(event: $event) === false \|\| method_exists($event, 'getReceivedAt') === false) {`
- `lib/Service/Intake/SystemCaseStore.php:256` `if (method_exists($objectService, 'runAsSystem') === false) {`
- `lib/Service/Kcc/CallbackService.php:233` `return array_map([$this, 'toArray'], $results);`
- `lib/Service/Kcc/CallbackService.php:316` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/Kcc/ContactMomentService.php:198` `return array_map([$this, 'toArray'], $results);`
- `lib/Service/Kcc/ContactMomentService.php:333` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/Kcc/RoutingRuleService.php:72` `return array_map([$this, 'toArray'], $results);`
- `lib/Service/Kcc/RoutingRuleService.php:182` `return array_map([$this, 'toArray'], $results);`
- `lib/Service/Kcc/RoutingRuleService.php:263` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/KpiAggregationService.php:567` `if (method_exists($object, 'getObject') === true) {`
- `lib/Service/KpiAggregationService.php:571` `if (method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/Lifecycle/CaseArchiveState.php:195` `$answer = $handler->{$verb}($caseId, $reason, $register, $schema);`
- `lib/Service/Notification/NotificationRouting.php:241` `if ($service === null \|\| $userId === '' \|\| method_exists($service, 'setOverride') === false) {`
- `lib/Service/Notification/NotificationRouting.php:276` `if (method_exists($service, 'getEffectiveForUser') === false) {`
- `lib/Service/Obligations/ObligationService.php:429` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/PartnerMigrationService.php:333` `$organisation->{$setter}($value);`
- `lib/Service/PartnerMigrationService.php:361` `if (is_object($value) === true && method_exists($value, 'getObject') === true) {`
- `lib/Service/People/CaseRoleProjection.php:303` `if (is_object($saved) === true && is_callable([$saved, 'getUuid']) === true) {`
- `lib/Service/People/CaseRoleProjection.php:303` `if (is_object($saved) === true && is_callable([$saved, 'getUuid']) === true) {`
- `lib/Service/People/CaseRoleProjection.php:304` `return (string)call_user_func([$saved, 'getUuid']);`
- `lib/Service/People/CaseRoleProjection.php:304` `return (string)call_user_func([$saved, 'getUuid']);`
- `lib/Service/People/CaseRoleVocabulary.php:113` `if (is_object($stored) === false \|\| is_callable([$stored, 'getConfiguration']) === false) {`
- `lib/Service/People/CaseRoleVocabulary.php:113` `if (is_object($stored) === false \|\| is_callable([$stored, 'getConfiguration']) === false) {`
- `lib/Service/People/CaseRoleVocabulary.php:117` `return (array)call_user_func([$stored, 'getConfiguration']);`
- `lib/Service/People/CaseRoleVocabulary.php:117` `return (array)call_user_func([$stored, 'getConfiguration']);`
- `lib/Service/People/CaseRoleVocabulary.php:288` `if (is_callable([$mapper, 'clearFindCache']) === false \|\| is_callable([$schema, 'getId']) === false) {`
- `lib/Service/People/CaseRoleVocabulary.php:288` `if (is_callable([$mapper, 'clearFindCache']) === false \|\| is_callable([$schema, 'getId']) === false) {`
- `lib/Service/People/CaseRoleVocabulary.php:288` `if (is_callable([$mapper, 'clearFindCache']) === false \|\| is_callable([$schema, 'getId']) === false) {`
- `lib/Service/People/CaseRoleVocabulary.php:288` `if (is_callable([$mapper, 'clearFindCache']) === false \|\| is_callable([$schema, 'getId']) === false) {`
- `lib/Service/People/CaseSeats.php:417` `if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {`
- `lib/Service/People/PartyIndicatorReader.php:191` `if (is_callable([$guard, 'indicatorsForObject']) === false`
- `lib/Service/People/PartyIndicatorReader.php:191` `if (is_callable([$guard, 'indicatorsForObject']) === false`
- `lib/Service/People/PartyIndicatorReader.php:192` `\|\| is_callable([$guard, 'sendRefusalFor']) === false`
- `lib/Service/People/PartyIndicatorReader.php:192` `\|\| is_callable([$guard, 'sendRefusalFor']) === false`
- `lib/Service/Pipelinq/PartyKindConsumer.php:359` `if (is_object($service) === false \|\| is_callable([$service, 'saveObject']) === false) {`
- `lib/Service/Pipelinq/PartyKindConsumer.php:359` `if (is_object($service) === false \|\| is_callable([$service, 'saveObject']) === false) {`
- `lib/Service/Pipelinq/PipelinqGateway.php:197` `if (is_callable([$service, $method]) === false) {`
- `lib/Service/Pipelinq/PipelinqGateway.php:237` `$value = $service->{$method}(...$arguments);`
- `lib/Service/Publication/OpenCatalogiApiClient.php:452` `if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {`
- `lib/Service/Publication/OpenCatalogiApiClient.php:474` `if (is_object($entity) === true && method_exists($entity, 'getUuid') === true) {`
- `lib/Service/PublicationService.php:356` `if (is_array($obj) === false && method_exists($obj, 'jsonSerialize') === true) {`
- `lib/Service/Queue/EndOfDayService.php:208` `if (is_object($state) === true && method_exists($state, 'jsonSerialize') === true) {`
- `lib/Service/QuickActionService.php:323` `if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/Service/Recycle/CaseDestructionService.php:310` `if ($service === null \|\| method_exists($service, 'preview') === false) {`
- `lib/Service/Recycle/CaseDestructionService.php:331` `if ($service === null \|\| method_exists($service, 'destroy') === false) {`
- `lib/Service/Recycle/CaseDestructionService.php:364` `if ($recorder !== null && method_exists($recorder, 'record') === true) {`
- `lib/Service/Recycle/CaseDestructionService.php:393` `if ($mapper === null \|\| method_exists($mapper, 'createAuditTrailEntry') === false) {`
- `lib/Service/Recycle/CaseDestructionService.php:510` `if (method_exists($entity, 'getObject') === true) {`
- `lib/Service/Recycle/CaseRecycleService.php:433` `if ($mapper === null \|\| method_exists($mapper, 'createAuditTrailEntry') === false) {`
- `lib/Service/Recycle/CaseRecycleService.php:538` `if (is_object($entity) === false \|\| method_exists($entity, 'getSchema') === false) {`
- `lib/Service/Recycle/DeletionWindowReader.php:98` `if ($service !== null && method_exists($service, 'windowFor') === true) {`
- `lib/Service/Recycle/DeletionWindowReader.php:101` `if ($stated !== null && method_exists($stated, 'toArray') === true) {`
- `lib/Service/Recycle/DeletionWindowReader.php:184` `if (method_exists($entity, 'getDeleted') === false) {`
- `lib/Service/Recycle/DeletionWindowReader.php:206` `if (method_exists($entity, 'getObject') === false) {`
- `lib/Service/Recycle/DeletionWindowReader.php:228` `if (method_exists($entity, 'isSoftDeleted') === true) {`
- `lib/Service/Relation/CaseRelationStore.php:267` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Relation/CaseRelationStore.php:291` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/RoleResolverService.php:364` `if (method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/RoleResolverService.php:371` `if (method_exists($value, 'toArray') === true) {`
- `lib/Service/Settings/SchemaScopeResolver.php:244` `if ($schema !== null && method_exists($schema, 'getId') === true) {`
- `lib/Service/Settings/SchemaSlugResolver.php:111` `if ($ids !== [] && method_exists($schemaMapper, 'findBySlugInIds') === true) {`
- `lib/Service/SettingsService.php:555` `if (method_exists($schemaMapper, 'findByApplicationAndSlug') === false) {`
- `lib/Service/Sharing/OpenRegisterSharingGateway.php:178` `if (method_exists($service, $method) === false) {`
- `lib/Service/Sharing/OpenRegisterSharingGateway.php:207` `if (method_exists($service, 'createOutgoingShare') === false \|\| method_exists($service, 'setStatus') === false) {`
- `lib/Service/Sharing/OpenRegisterSharingGateway.php:207` `if (method_exists($service, 'createOutgoingShare') === false \|\| method_exists($service, 'setStatus') === false) {`
- `lib/Service/Sharing/OpenRegisterSharingGateway.php:235` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/Starter/StarterStore.php:339` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/StateMachineService.php:299` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/Stuf/StufRegisterAccess.php:253` `if (is_object(value: $value) === true && method_exists(object_or_class: $value, method: 'jsonSerialize') === true) {`
- `lib/Service/Support/ConfiguredRegistryService.php:297` `if (method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/Support/FindsFilesById.php:62` `if ($fileService !== null && method_exists($fileService, 'getFileById') === true) {`
- `lib/Service/Support/OwningCaseResolver.php:184` `if (is_object($value) === false \|\| is_callable([$value, 'jsonSerialize']) === false) {`
- `lib/Service/Support/OwningCaseResolver.php:184` `if (is_object($value) === false \|\| is_callable([$value, 'jsonSerialize']) === false) {`
- `lib/Service/Support/SearchesObjects.php:214` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/Support/SearchesObjects.php:262` `if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {`
- `lib/Service/Support/SearchesObjects.php:310` `if (method_exists($objectService, 'patchObject') === true) {`
- `lib/Service/Support/SearchesObjects.php:321` `if (method_exists($objectService, 'find') === false) {`
- `lib/Service/Support/SearchesObjects.php:360` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/Support/SearchesObjects.php:399` `if (method_exists($objectService, 'runAsSystem') === true) {`
- `lib/Service/Support/SearchesObjects.php:463` `if (method_exists($item, 'jsonSerialize') === true) {`
- `lib/Service/Support/TrustedRevealRead.php:72` `if ($reveal === null \|\| method_exists($reveal, 'trustedDuring') === false) {`
- `lib/Service/Support/UnscopedObjectReader.php:89` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/Task/EngineTaskGateway.php:150` `if ($service === null \|\| method_exists($service, 'findByKey') === false) {`
- `lib/Service/Task/EngineTaskGateway.php:684` `return method_exists($service, 'claim');`
- `lib/Service/Task/EngineTaskGateway.php:848` `if (method_exists($task, $getter) === true) {`
- `lib/Service/Task/EngineTaskGateway.php:870` `return (string) ($task->{$getter}() ?? '');`
- `lib/Service/Task/EngineTaskGateway.php:888` `$value = $task->{$getter}();`
- `lib/Service/Task/EngineTaskGateway.php:911` `$value = $task->{$getter}();`
- `lib/Service/Task/EngineTaskInbox.php:291` `if (is_object($r) === true && method_exists($r, $method) === true) {`
- `lib/Service/Task/EngineTaskInbox.php:292` `return (string) ($r->$method() ?? '');`
- `lib/Service/Task/EngineTaskInbox.php:341` `} else if (is_object($row) === true && method_exists($row, 'getChecklist') === true) {`
- `lib/Service/Task/OnboardingSteps.php:291` `if (is_object($row) === true && method_exists($row, 'get'.ucfirst($key)) === true) {`
- `lib/Service/Termijn/TermMoveHistory.php:105` `if ($engine === null \|\| method_exists($engine, 'history') === false) {`
- `lib/Service/Termijn/TermMoveHistory.php:147` `if (is_object($event) === true && method_exists($event, 'jsonSerialize') === true) {`
- `lib/Service/Termijn/WorkingDayRoll.php:247` `if (method_exists($this->calendar, 'getWorkingWeekdays') === false) {`
- `lib/Service/Timeline/CaseTimeline.php:361` `if (is_object($entry) === true && method_exists($entry, 'jsonSerialize') === true) {`
- `lib/Service/Transfer/TransferRegisterGateway.php:126` `if (method_exists($service, 'createOutgoingShare') === false) {`
- `lib/Service/Transfer/TransferRegisterGateway.php:156` `if (method_exists($mapper, 'findByToken') === false) {`
- `lib/Service/Transitions/CapacityGuard.php:285` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Transitions/CaseResultWriter.php:349` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/Transitions/CaseStatusStore.php:422` `if (method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/Transitions/CaseTypeReader.php:238` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/Vth/LhsMatrixDecisionTableMigrator.php:108` `if (method_exists($objectService, 'runAs') === false) {`
- `lib/Service/Vth/LhsRecommendationStore.php:246` `if (method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/Vth/LhsRecommendationStore.php:253` `if (method_exists($value, 'toArray') === true) {`
- `lib/Service/Workflow/WorkflowDefinitionRepository.php:595` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Workflow/WorkflowTemplateFlowMigrator.php:112` `if (method_exists($objectService, 'runAs') === false) {`
- `lib/Service/WorkflowStepAuthorizationResolver.php:201` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/WorkflowTemplateLoader.php:346` `if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {`
- `lib/Service/Zaakdossier/DocumentRecordStore.php:280` `if (is_object($file) === false \|\| is_callable([$file, 'getFileId']) === false) {`
- `lib/Service/Zaakdossier/DocumentRecordStore.php:280` `if (is_object($file) === false \|\| is_callable([$file, 'getFileId']) === false) {`
- `lib/Service/Zaakdossier/DocumentRecordStore.php:284` `return (int)call_user_func([$file, 'getFileId']);`
- `lib/Service/Zaakdossier/DocumentRecordStore.php:284` `return (int)call_user_func([$file, 'getFileId']);`
- `lib/Service/Zaakdossier/DocumentRecordStore.php:377` `if (is_object($saved) === true && is_callable([$saved, 'getUuid']) === true) {`
- `lib/Service/Zaakdossier/DocumentRecordStore.php:377` `if (is_object($saved) === true && is_callable([$saved, 'getUuid']) === true) {`
- `lib/Service/Zaakdossier/DocumentRecordStore.php:378` `return (string)call_user_func([$saved, 'getUuid']);`
- `lib/Service/Zaakdossier/DocumentRecordStore.php:378` `return (string)call_user_func([$saved, 'getUuid']);`
- `lib/Service/Zaakdossier/DocumentRecordStore.php:393` `if (is_object($value) === true && is_callable([$value, 'jsonSerialize']) === true) {`
- `lib/Service/Zaakdossier/DocumentRecordStore.php:393` `if (is_object($value) === true && is_callable([$value, 'jsonSerialize']) === true) {`
- `lib/Service/Zaakdossier/DocumentRecordStore.php:395` `if (isset($row['id']) === false && is_callable([$value, 'getUuid']) === true) {`
- `lib/Service/Zaakdossier/DocumentRecordStore.php:395` `if (isset($row['id']) === false && is_callable([$value, 'getUuid']) === true) {`
- `lib/Service/Zaakdossier/DocumentRecordStore.php:396` `$row['id'] = (string)call_user_func([$value, 'getUuid']);`
- `lib/Service/Zaakdossier/DocumentRecordStore.php:396` `$row['id'] = (string)call_user_func([$value, 'getUuid']);`
- `lib/Service/Zaakdossier/MailHeaderReader.php:162` `if (is_object($extraction) === false \|\| method_exists($extraction, 'parseEmlStructured') === false) {`
- `lib/Service/ZaakdossierService.php:726` `if (is_object($saved) === true && is_callable([$saved, 'getUuid']) === true) {`
- `lib/Service/ZaakdossierService.php:726` `if (is_object($saved) === true && is_callable([$saved, 'getUuid']) === true) {`
- `lib/Service/ZaakdossierService.php:727` `return (string)call_user_func([$saved, 'getUuid']);`
- `lib/Service/ZaakdossierService.php:727` `return (string)call_user_func([$saved, 'getUuid']);`
- `lib/Service/ZgwJwtValidator.php:253` `&& method_exists($this->authorizationService, 'validatePayload') === true`
- `lib/Service/ZgwService.php:877` `if (method_exists($consumer, 'getAuthorizationConfiguration') === false) {`
- `lib/Support/NormalisesObjectRows.php:69` `if (method_exists($row, 'jsonSerialize') === true) {`
- `lib/Woo/WooCollection.php:210` `if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {`
- `lib/Woo/WooCollectionQueries.php:300` `if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {`
- `lib/Woo/WooDossierReturn.php:219` `if (method_exists($objectService, 'patchObject') === true) {`
- `lib/Woo/WooSearchPlans.php:387` `if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {`
- `lib/Woo/WooSources.php:283` `if (method_exists($event, 'isHandled') === false \|\| $event->isHandled() !== true \|\| method_exists($event, 'getResult') === false) {`
- `lib/Woo/WooSources.php:283` `if (method_exists($event, 'isHandled') === false \|\| $event->isHandled() !== true \|\| method_exists($event, 'getResult') === false) {`

</details>

Unresolved call sites (receiver type unknown, magic, or spread args): 91, listed in `dossiq.json`.

## pipelinq

Local dir `pipelinq`, branch `development`, sha `e4d420d4271a6892d4a2b6743057f23ba7a5856d`.

### Runtime breaks (lib): 3

| file:line | category | app calls | OR main now | fix |
|---|---|---|---|---|
| `lib/Controller/SlaPolicyController.php:154` | call-shape | $objectService->find(register: $register, schema: $schema, uuid: $id,) | OCA\OpenRegister\Service\ObjectService::find(int \| string $id, ?array $_extend=[], bool $files=false, Register \| string \| int \| null $register=null, Schema \| string \| int \| null $schema=null, bool $_rbac=true, bool $_multitenancy=true, bool $_render=true, bool | UNKNOWN-NAMED-ARG: `uuid:` (Error: Unknown named parameter); MISSING-REQUIRED-ARG: `$id` (ArgumentCountError) |
| `lib/Lifecycle/PosTransactionConfirmGuard.php:189` | call-shape | $schemaMapper->find($slug, [], null, false, false) | OCA\OpenRegister\Db\SchemaMapper::find(string\|int $id, array\|null $_extend = [], bool $_rbac = true, bool $_multitenancy = true): OCA\OpenRegister\Db\Schema (lib/Db/SchemaMapper.php:245) | SILENT-EXTRA-ARGS: 5 positional args, method takes 4 (extra args are silently dropped); TYPE: passes null literal `null` to `$_rbac` typed `bool` (TypeError) |
| `lib/Service/PosTransactionService.php:1482` | call-shape | $schemaMapper->find($slug, [], null, false, false) | OCA\OpenRegister\Db\SchemaMapper::find(string\|int $id, array\|null $_extend = [], bool $_rbac = true, bool $_multitenancy = true): OCA\OpenRegister\Db\Schema (lib/Db/SchemaMapper.php:245) | SILENT-EXTRA-ARGS: 5 positional args, method takes 4 (extra args are silently dropped); TYPE: passes null literal `null` to `$_rbac` typed `bool` (TypeError) |

### Test-suite breaks (tests): 30

| file:line | category | stub | app calls | OR main now | fix |
|---|---|---|---|---|---|
| `tests/Stubs/Service/ObjectService.php:466` | contract-signature | stub | ObjectService::unlockObject(string\|int $identifier, bool $advisory = false): bool | OCA\OpenRegister\Contract\ObjectServiceInterface::unlockObject(string\|int $identifier, bool $advisory=false, ?string $runUuid=null): bool (lib/Contract/ObjectServiceInterface.php:465) | align the signature with OR main (fatal at class load): takes 2 params, OR declares 3 |
| `tests/Stubs/Service/ObjectService.php:480` | contract-signature | stub | ObjectService::lockObject(string $identifier, ?string $process = null, ?int $duration = null, bool $advisory = false): array | OCA\OpenRegister\Contract\ObjectServiceInterface::lockObject(string $identifier, ?string $process=null, ?int $duration=null, bool $advisory=false, ?string $runUuid=null, ?string $nodeId=null): array ( | align the signature with OR main (fatal at class load): takes 4 params, OR declares 6 |
| `tests/Unit/Controller/LoyaltyControllerTest.php:1415` | contract-signature | stub | LoyaltyObjectStoreFake::find(int\|string $id, ?array $_extend = [], bool $files = false, string\|int\|null $register = null, string\|int\|null $schema = null, bool $ | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::find(int \| string $id, ?array $_extend=[], bool $files=false, Register \| string \| int \| null $register=null, Schema \| string \| int \| null $ | align the signature with OR main (fatal at class load): param 4 `$register` typed `int\|null\|string`, OR has `OCA\OpenRegister\Db\Register\|int\|null\|string`; param 5 `$schema` typed `int\|null\|string`, O |
| `tests/Unit/Controller/LoyaltyControllerTest.php:1485` | contract-signature | stub | LoyaltyObjectStoreFake::saveObject(array $object, ?array $extend = [], string\|int\|null $register = null, string\|int\|null $schema = null, ?string $uuid = null, b | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::saveObject(array \| ObjectEntity $object, ?array $extend=[], Register \| string \| int \| null $register=null, Schema \| string \| int \| null $sc | align the signature with OR main (fatal at class load): takes 12 params, OR declares 15; param 1 `$object` typed `array`, OR has `OCA\OpenRegister\Db\ObjectEntity\|array`; param 3 `$register` typed `in |
| `tests/Unit/Controller/LoyaltyControllerTest.php:1560` | contract-signature |  | LoyaltyObjectStoreFake::deleteObject(string $uuid, string\|int\|null $register = null, string\|int\|null $schema = null, bool $_rbac = true, bool $_multitenancy = t | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::deleteObject(string $uuid, Register \| string \| int \| null $register=null, Schema \| string \| int \| null $schema=null, bool $_rbac=true, bool | align the signature with OR main (fatal at class load): param 2 `$register` typed `int\|null\|string`, OR has `OCA\OpenRegister\Db\Register\|int\|null\|string`; param 3 `$schema` typed `int\|null\|string`, O |
| `tests/Unit/Service/CashShiftServiceTest.php:89` | contract-signature | stub | CashFakeObjectService::find(int\|string $id, ?array $_extend = [], bool $files = false, string\|int\|null $register = null, string\|int\|null $schema = null, bool $_ | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::find(int \| string $id, ?array $_extend=[], bool $files=false, Register \| string \| int \| null $register=null, Schema \| string \| int \| null $ | align the signature with OR main (fatal at class load): param 4 `$register` typed `int\|null\|string`, OR has `OCA\OpenRegister\Db\Register\|int\|null\|string`; param 5 `$schema` typed `int\|null\|string`, O |
| `tests/Unit/Service/CashShiftServiceTest.php:158` | contract-signature | stub | CashFakeObjectService::saveObject(array $object, ?array $extend = [], string\|int\|null $register = null, string\|int\|null $schema = null, ?string $uuid = null, bo | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::saveObject(array \| ObjectEntity $object, ?array $extend=[], Register \| string \| int \| null $register=null, Schema \| string \| int \| null $sc | align the signature with OR main (fatal at class load): takes 12 params, OR declares 15; param 1 `$object` typed `array`, OR has `OCA\OpenRegister\Db\ObjectEntity\|array`; param 3 `$register` typed `in |
| `tests/Unit/Service/KassakoppelingAuditServiceTest.php:95` | contract-signature | stub | FakeAuditObjectService::find(int\|string $id, ?array $_extend = [], bool $files = false, string\|int\|null $register = null, string\|int\|null $schema = null, bool $ | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::find(int \| string $id, ?array $_extend=[], bool $files=false, Register \| string \| int \| null $register=null, Schema \| string \| int \| null $ | align the signature with OR main (fatal at class load): param 4 `$register` typed `int\|null\|string`, OR has `OCA\OpenRegister\Db\Register\|int\|null\|string`; param 5 `$schema` typed `int\|null\|string`, O |
| `tests/Unit/Service/KassakoppelingAuditServiceTest.php:155` | contract-signature | stub | FakeAuditObjectService::saveObject(array $object, ?array $extend = [], string\|int\|null $register = null, string\|int\|null $schema = null, ?string $uuid = null, b | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::saveObject(array \| ObjectEntity $object, ?array $extend=[], Register \| string \| int \| null $register=null, Schema \| string \| int \| null $sc | align the signature with OR main (fatal at class load): takes 12 params, OR declares 15; param 1 `$object` typed `array`, OR has `OCA\OpenRegister\Db\ObjectEntity\|array`; param 3 `$register` typed `in |
| `tests/Unit/Service/PosBookkeepingServiceTest.php:89` | contract-signature | stub | BookkeepingFakeObjectService::find(int\|string $id, ?array $_extend = [], bool $files = false, string\|int\|null $register = null, string\|int\|null $schema = null,  | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::find(int \| string $id, ?array $_extend=[], bool $files=false, Register \| string \| int \| null $register=null, Schema \| string \| int \| null $ | align the signature with OR main (fatal at class load): param 4 `$register` typed `int\|null\|string`, OR has `OCA\OpenRegister\Db\Register\|int\|null\|string`; param 5 `$schema` typed `int\|null\|string`, O |
| `tests/Unit/Service/PosBookkeepingServiceTest.php:148` | contract-signature | stub | BookkeepingFakeObjectService::saveObject(array $object, ?array $extend = [], string\|int\|null $register = null, string\|int\|null $schema = null, ?string $uuid = n | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::saveObject(array \| ObjectEntity $object, ?array $extend=[], Register \| string \| int \| null $register=null, Schema \| string \| int \| null $sc | align the signature with OR main (fatal at class load): takes 12 params, OR declares 15; param 1 `$object` typed `array`, OR has `OCA\OpenRegister\Db\ObjectEntity\|array`; param 3 `$register` typed `in |
| `tests/Unit/Service/PosRefundServiceTest.php:82` | contract-signature | stub | FakeObjectService::find(int\|string $id, ?array $_extend = [], bool $files = false, string\|int\|null $register = null, string\|int\|null $schema = null, bool $_rbac | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::find(int \| string $id, ?array $_extend=[], bool $files=false, Register \| string \| int \| null $register=null, Schema \| string \| int \| null $ | align the signature with OR main (fatal at class load): param 4 `$register` typed `int\|null\|string`, OR has `OCA\OpenRegister\Db\Register\|int\|null\|string`; param 5 `$schema` typed `int\|null\|string`, O |
| `tests/Unit/Service/PosRefundServiceTest.php:151` | contract-signature | stub | FakeObjectService::saveObject(array $object, ?array $extend = [], string\|int\|null $register = null, string\|int\|null $schema = null, ?string $uuid = null, bool $ | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::saveObject(array \| ObjectEntity $object, ?array $extend=[], Register \| string \| int \| null $register=null, Schema \| string \| int \| null $sc | align the signature with OR main (fatal at class load): takes 12 params, OR declares 15; param 1 `$object` typed `array`, OR has `OCA\OpenRegister\Db\ObjectEntity\|array`; param 3 `$register` typed `in |
| `tests/Unit/Service/PosStaffServiceTest.php:71` | contract-signature | stub | StaffFakeObjectService::find(int\|string $id, ?array $_extend = [], bool $files = false, string\|int\|null $register = null, string\|int\|null $schema = null, bool $ | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::find(int \| string $id, ?array $_extend=[], bool $files=false, Register \| string \| int \| null $register=null, Schema \| string \| int \| null $ | align the signature with OR main (fatal at class load): param 4 `$register` typed `int\|null\|string`, OR has `OCA\OpenRegister\Db\Register\|int\|null\|string`; param 5 `$schema` typed `int\|null\|string`, O |
| `tests/Unit/Service/PosStaffServiceTest.php:161` | contract-signature | stub | StaffFakeObjectService::saveObject(array $object, ?array $extend = [], string\|int\|null $register = null, string\|int\|null $schema = null, ?string $uuid = null, b | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::saveObject(array \| ObjectEntity $object, ?array $extend=[], Register \| string \| int \| null $register=null, Schema \| string \| int \| null $sc | align the signature with OR main (fatal at class load): takes 12 params, OR declares 15; param 1 `$object` typed `array`, OR has `OCA\OpenRegister\Db\ObjectEntity\|array`; param 3 `$register` typed `in |
| `tests/Unit/Service/PosStaffServiceTest.php:199` | contract-signature |  | StaffFakeObjectService::deleteObject(string $uuid, string\|int\|null $register = null, string\|int\|null $schema = null, bool $_rbac = true, bool $_multitenancy = t | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::deleteObject(string $uuid, Register \| string \| int \| null $register=null, Schema \| string \| int \| null $schema=null, bool $_rbac=true, bool | align the signature with OR main (fatal at class load): param 2 `$register` typed `int\|null\|string`, OR has `OCA\OpenRegister\Db\Register\|int\|null\|string`; param 3 `$schema` typed `int\|null\|string`, O |
| `tests/Unit/Service/ScheduledTaskServiceTest.php:691` | contract-signature | stub | RecordingObjectService::saveObject(array $object, ?array $extend = [], string\|int\|null $register = null, string\|int\|null $schema = null, ?string $uuid = null, b | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::saveObject(array \| ObjectEntity $object, ?array $extend=[], Register \| string \| int \| null $register=null, Schema \| string \| int \| null $sc | align the signature with OR main (fatal at class load): takes 12 params, OR declares 15; param 1 `$object` typed `array`, OR has `OCA\OpenRegister\Db\ObjectEntity\|array`; param 3 `$register` typed `in |
| `tests/Unit/Service/ScheduledTaskServiceTest.php:731` | contract-signature |  | RecordingObjectService::deleteObject(string $uuid, string\|int\|null $register = null, string\|int\|null $schema = null, bool $_rbac = true, bool $_multitenancy = t | OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::deleteObject(string $uuid, Register \| string \| int \| null $register=null, Schema \| string \| int \| null $schema=null, bool $_rbac=true, bool | align the signature with OR main (fatal at class load): param 2 `$register` typed `int\|null\|string`, OR has `OCA\OpenRegister\Db\Register\|int\|null\|string`; param 3 `$schema` typed `int\|null\|string`, O |
| `tests/Unit/Service/CashShiftServiceTest.php:275` | override-signature | stub | CashFakeWebhookService::dispatchEvent(object $_event, string $eventName, array $payload): void | OCA\OpenRegister\Service\WebhookService::dispatchEvent(Event $_event, string $eventName, array $payload): void (lib/Service/WebhookService.php:514) | align the signature with OR main (fatal at class load): param 1 `$_event` typed `object`, OR has `OCP\EventDispatcher\Event` |
| `tests/Unit/Service/PosRefundServiceTest.php:217` | override-signature | stub | FakeWebhookService::dispatchEvent(object $_event, string $eventName, array $payload): void | OCA\OpenRegister\Service\WebhookService::dispatchEvent(Event $_event, string $eventName, array $payload): void (lib/Service/WebhookService.php:514) | align the signature with OR main (fatal at class load): param 1 `$_event` typed `object`, OR has `OCP\EventDispatcher\Event` |
| `tests/Unit/Service/MessagingLeafDispatchTest.php:78` | constructor-shape | stub | new MessageDispatchProvider() | OCA\OpenRegister\Service\Integration\Providers\MessageDispatchProvider::__construct(private ExternalIntegrationRouterInterface $router, private IAppManager $appManager, private IL10N $l10n, private Lo | MISSING-REQUIRED-ARG: `$router` (ArgumentCountError); MISSING-REQUIRED-ARG: `$appManager` (ArgumentCountError); MISSING-REQUIRED-ARG: `$l10n` (ArgumentCountError); MISSING-REQUIRED-ARG: `$logger` (Arg |
| `tests/Unit/Service/Portal/FakeMainRegisterReader.php:74` | constructor-shape | stub | new ObjectService() | OCA\OpenRegister\Service\ObjectService::__construct(private readonly DataManipulationHandler $dataManipHandler, private readonly DeleteObject $deleteHandler, private readonly GetObject $getHandler, pr | MISSING-REQUIRED-ARG: `$dataManipHandler` (ArgumentCountError); MISSING-REQUIRED-ARG: `$deleteHandler` (ArgumentCountError); MISSING-REQUIRED-ARG: `$getHandler` (ArgumentCountError); MISSING-REQUIRED- |
| `tests/Unit/Service/Portal/FakePortalObjectRepository.php:69` | constructor-shape | stub | new ObjectService() | OCA\OpenRegister\Service\ObjectService::__construct(private readonly DataManipulationHandler $dataManipHandler, private readonly DeleteObject $deleteHandler, private readonly GetObject $getHandler, pr | MISSING-REQUIRED-ARG: `$dataManipHandler` (ArgumentCountError); MISSING-REQUIRED-ARG: `$deleteHandler` (ArgumentCountError); MISSING-REQUIRED-ARG: `$getHandler` (ArgumentCountError); MISSING-REQUIRED- |
| `tests/Unit/Stubs/ObjectServiceRawRowsTest.php:45` | constructor-shape | stub | new ObjectService() | OCA\OpenRegister\Service\ObjectService::__construct(private readonly DataManipulationHandler $dataManipHandler, private readonly DeleteObject $deleteHandler, private readonly GetObject $getHandler, pr | MISSING-REQUIRED-ARG: `$dataManipHandler` (ArgumentCountError); MISSING-REQUIRED-ARG: `$deleteHandler` (ArgumentCountError); MISSING-REQUIRED-ARG: `$getHandler` (ArgumentCountError); MISSING-REQUIRED- |
| `tests/Unit/Stubs/ObjectServiceRawRowsTest.php:56` | constructor-shape | stub | new ObjectService() | OCA\OpenRegister\Service\ObjectService::__construct(private readonly DataManipulationHandler $dataManipHandler, private readonly DeleteObject $deleteHandler, private readonly GetObject $getHandler, pr | MISSING-REQUIRED-ARG: `$dataManipHandler` (ArgumentCountError); MISSING-REQUIRED-ARG: `$deleteHandler` (ArgumentCountError); MISSING-REQUIRED-ARG: `$getHandler` (ArgumentCountError); MISSING-REQUIRED- |
| `tests/Unit/Stubs/ObjectServiceRawRowsTest.php:81` | constructor-shape | stub | new ObjectService() | OCA\OpenRegister\Service\ObjectService::__construct(private readonly DataManipulationHandler $dataManipHandler, private readonly DeleteObject $deleteHandler, private readonly GetObject $getHandler, pr | MISSING-REQUIRED-ARG: `$dataManipHandler` (ArgumentCountError); MISSING-REQUIRED-ARG: `$deleteHandler` (ArgumentCountError); MISSING-REQUIRED-ARG: `$getHandler` (ArgumentCountError); MISSING-REQUIRED- |
| `tests/Unit/Stubs/ObjectServiceRawRowsTest.php:108` | constructor-shape | stub | new ObjectService() | OCA\OpenRegister\Service\ObjectService::__construct(private readonly DataManipulationHandler $dataManipHandler, private readonly DeleteObject $deleteHandler, private readonly GetObject $getHandler, pr | MISSING-REQUIRED-ARG: `$dataManipHandler` (ArgumentCountError); MISSING-REQUIRED-ARG: `$deleteHandler` (ArgumentCountError); MISSING-REQUIRED-ARG: `$getHandler` (ArgumentCountError); MISSING-REQUIRED- |
| `tests/Unit/Stubs/ObjectServiceRawRowsTest.php:68` | method-missing | stub | $stub->rawObjects() on OCA\OpenRegister\Service\ObjectService | OCA\OpenRegister\Service\ObjectService (lib/Service/ObjectService.php) has no method rawObjects | find the replacement method on OR main |
| `tests/Unit/Stubs/ObjectServiceRawRowsTest.php:97` | method-missing | stub | $stub->rawObjects() on OCA\OpenRegister\Service\ObjectService | OCA\OpenRegister\Service\ObjectService (lib/Service/ObjectService.php) has no method rawObjects | find the replacement method on OR main |
| `tests/Unit/Stubs/ObjectServiceRawRowsTest.php:114` | method-missing | stub | $stub->rawObjects() on OCA\OpenRegister\Service\ObjectService | OCA\OpenRegister\Service\ObjectService (lib/Service/ObjectService.php) has no method rawObjects | find the replacement method on OR main |

### Test stubs of OR classes that drifted from OR main: 7 of 51

- `tests/Stubs/Contract/ObjectServiceInterface.php:132` `OCA\OpenRegister\Contract\ObjectServiceInterface` (exists): unlockObject($identifier, $advisory) vs OR main unlockObject($identifier, $advisory, $runUuid) [lib/Contract/ObjectServiceInterface.php:465]; lockObject($identifier, $process, $duration, $advisory) vs OR main lockObject($identifier, $process, $duration, $advisory, $runUuid, $nodeId) [lib/Contract/ObjectServiceInterface.php:481]
- `tests/Stubs/Db/Schema.php:29` `OCA\OpenRegister\Db\Schema` (exists): __construct($properties) vs OR main __construct() [lib/Db/Schema.php:513]
- `tests/Stubs/Event/ObjectUpdatedEvent.php:33` `OCA\OpenRegister\Event\ObjectUpdatedEvent` (exists): __construct($newObject, $oldObject) vs OR main __construct($newObject, $oldObject) [lib/Event/ObjectUpdatedEvent.php:56]
- `tests/Stubs/Mcp/Attribute/McpTool.php:41` `OCA\OpenRegister\Mcp\Attribute\McpTool` (exists): __construct($name, $description, $readOnlyHint, $destructiveHint, $idempotentHint, $scope, $subject, $action) vs OR main __construct($name, $description, $readOnlyHint, $destructiveHint, $idempotentHint, $scope, $subject, $action, $reach, $annotations) [lib/Mcp/Attribute/McpTool.php:130]
- `tests/Stubs/Search/Service/Aggregation/AggregationQuery.php:39` `OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery` (exists): __construct($metric, $field, $filter, $groupBy) vs OR main __construct($metric, $field, $filter, $layout) [lib/Search/Service/Aggregation/AggregationQuery.php:67]; create($metric, $field, $filter, $groupBy) vs OR main create($metric, $field, $filter, $groupBy, $dateBucket, $metrics, $cumulative) [lib/Search/Service/Aggregation/AggregationQuery.php:166]
- `tests/Stubs/Service/Deferral/DeferredListenerContext.php:31` `OCA\OpenRegister\Service\Deferral\DeferredListenerContext` (exists): __construct($userId, $orgUuid, $entries) vs OR main __construct($userId, $orgUuid, $entries) [lib/Service/Deferral/DeferredListenerContext.php:53]
- `tests/Stubs/Service/ObjectService.php:61` `OCA\OpenRegister\Service\ObjectService` (exists): saveObject($object, $extend, $register, $schema, $uuid, $_rbac, $_multitenancy, $silent, $_validation, $uploadedFiles, $currentUser, $failIfExists) vs OR main saveObject($object, $extend, $register, $schema, $uuid, $_rbac, $_multitenancy, $silent, $_validation, $uploadedFiles, $currentUser, $failIfExists, $_unowned, $_dedupOverride, $_owner) [lib/Service/ObjectService.php:2112]; searchObjects($query, $_rbac, $_multitenancy, $ids, $uses, $views) vs OR main searchObjects($query, $_rbac, $_multitenancy, $ids, $uses, $views, $_viewScopeRequired) [lib/Service/ObjectService.php:4771]; rawObjects(): not on OR main; rawRowExpired(): not on OR main; unlockObject($identifier, $advisory) vs OR main unlockObject($identifier, $advisory, $runUuid) [lib/Service/ObjectService.php:5793]; lockObject($identifier, $process, $duration, $advisory) vs OR main lockObject($identifier, $process, $duration, $advisory, $runUuid, $nodeId) [lib/Service/ObjectService.php:5736]

### Implements / extends OR classes: 35

- `lib/BackgroundJob/DeferredObjectListenerJob.php:71` DeferredObjectListenerJob extends `OCA\OpenRegister\BackgroundJob\ActorForwardedJob` (exists)
- `lib/Flow/CompetitorWatchRunNode.php:62` CompetitorWatchRunNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Flow/JourneyActionNode.php:51` JourneyActionNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Flow/JourneyActionNode.php:51` JourneyActionNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys` (exists)
- `lib/Flow/ProvisionContactIdentityNode.php:62` ProvisionContactIdentityNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Flow/ProvisionContactIdentityNode.php:62` ProvisionContactIdentityNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys` (exists)
- `lib/Lifecycle/PosRefundManagerGuard.php:58` PosRefundManagerGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/PosTransactionAccessGuard.php:45` PosTransactionAccessGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/PosTransactionConfirmGuard.php:51` PosTransactionConfirmGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/PosTransactionRefundGuard.php:41` PosTransactionRefundGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Mcp/PipelinqScannableServices.php:49` PipelinqScannableServices implements `OCA\OpenRegister\Mcp\IMcpScannableServices` (exists)
- `lib/Service/Gdpr/PipelinqApRegulatorEscalateProvider.php:56` PipelinqApRegulatorEscalateProvider implements `OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateProvider` (exists)
- `lib/Service/Gdpr/PipelinqBsnIdentityVerifyProvider.php:57` PipelinqBsnIdentityVerifyProvider implements `OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyProvider` (exists)
- `lib/Service/PipelinqEvidenceSourceProvider.php:52` PipelinqEvidenceSourceProvider implements `OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceSourceProvider` (exists)
- `tests/Stubs/Db/ObjectEntity.php:82` ObjectEntity implements `OCA\OpenRegister\Contract\ObjectEntityInterface` (exists)
- `tests/Stubs/Service/Credential/CredentialRelinkRequiredException.php:35` CredentialRelinkRequiredException extends `OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException` (exists)
- `tests/Stubs/Service/ObjectService.php:61` ObjectService implements `OCA\OpenRegister\Contract\ObjectServiceInterface` (exists)
- `tests/Unit/Controller/LoyaltyControllerTest.php:1348` LoyaltyObjectStoreFake extends `OCA\OpenRegister\Service\ObjectService` (exists)
- `tests/Unit/Controller/LoyaltyReportingControllerTest.php:658` LoyaltyGroupedCountRunnerFake extends `OCA\OpenRegister\Service\Aggregation\AggregationRunner` (exists)
- `tests/Unit/Listener/RecordingDeferralService.php:40` RecordingDeferralService extends `OCA\OpenRegister\Service\Deferral\ListenerDeferralService` (exists)
- `tests/Unit/Service/CashShiftServiceTest.php:60` CashFakeObjectService extends `OCA\OpenRegister\Service\ObjectService` (exists)
- `tests/Unit/Service/CashShiftServiceTest.php:211` CashLiveAggregationRunner extends `OCA\OpenRegister\Service\Aggregation\AggregationRunner` (exists)
- `tests/Unit/Service/CashShiftServiceTest.php:241` CashFakeWebhookService extends `OCA\OpenRegister\Service\WebhookService` (exists)
- `tests/Unit/Service/FakeAggregationRunner.php:40` FakeAggregationRunner extends `OCA\OpenRegister\Service\Aggregation\AggregationRunner` (exists)
- `tests/Unit/Service/KassakoppelingAuditServiceTest.php:54` FakeAuditObjectService extends `OCA\OpenRegister\Service\ObjectService` (exists)
- `tests/Unit/Service/PosBookkeepingServiceTest.php:59` BookkeepingFakeObjectService extends `OCA\OpenRegister\Service\ObjectService` (exists)
- `tests/Unit/Service/PosBookkeepingServiceTest.php:194` BookkeepingFakeWebhookService extends `OCA\OpenRegister\Service\WebhookService` (exists)
- `tests/Unit/Service/PosRefundServiceTest.php:53` FakeObjectService extends `OCA\OpenRegister\Service\ObjectService` (exists)
- `tests/Unit/Service/PosRefundServiceTest.php:195` FakeWebhookService extends `OCA\OpenRegister\Service\WebhookService` (exists)
- `tests/Unit/Service/PosRefundServiceTest.php:230` FakeRefundTransitionEngine extends `OCA\OpenRegister\Service\Lifecycle\TransitionEngine` (exists)
- `tests/Unit/Service/PosStaffServiceTest.php:46` StaffFakeObjectService extends `OCA\OpenRegister\Service\ObjectService` (exists)
- `tests/Unit/Service/ProductVendorProviderServiceTest.php:45` FakePvmObjectService extends `OCA\OpenRegister\Service\ObjectService` (exists)
- `tests/Unit/Service/ScheduledTaskServiceTest.php:627` RecordingObjectService extends `OCA\OpenRegister\Service\ObjectService` (exists)
- `tests/Unit/Service/ScheduledTaskServiceTest.php:754` RecordingLanguageService extends `OCA\OpenRegister\Service\LanguageService` (exists)
- `tests/Unit/Support/FakeSlugResolver.php:45` FakeSlugResolver implements `OCA\OpenRegister\Contract\RegisterSlugResolverInterface` (exists)

### OR event listeners: 34 registrations

- `OCA\OpenRegister\Event\ObjectCreatedEvent` (exists, OR main constructed): 8x, e.g. `lib/AppInfo/Application.php:210`
- `OCA\OpenRegister\Event\ObjectCreatingEvent` (exists, OR main constructed): 7x, e.g. `lib/AppInfo/Application.php:229`
- `OCA\OpenRegister\Event\ObjectDeletedEvent` (exists, OR main constructed): 2x, e.g. `lib/AppInfo/Application.php:294`
- `OCA\OpenRegister\Event\ObjectUpdatedEvent` (exists, OR main constructed): 10x, e.g. `lib/AppInfo/Application.php:214`
- `OCA\OpenRegister\Event\ObjectUpdatingEvent` (exists, OR main constructed): 3x, e.g. `lib/AppInfo/Application.php:252`
- `OCA\OpenRegister\Event\ObjectsMergedEvent` (exists, OR main constructed): 2x, e.g. `lib/AppInfo/Application.php:692`
- `OCA\OpenRegister\Event\SchemaUpdatedEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Application.php:581`
- `OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Application.php:391`

<details><summary>Dynamic call sites in lib/ (method_exists, is_callable, $x->$m(), call_user_func): 169. Always suspect: a renamed OR method makes these silently skip.</summary>

- `lib/BackgroundJob/BrpMonitorJob.php:190` `if (method_exists($rec, 'jsonSerialize') === true) {`
- `lib/BackgroundJob/BrpRetentionJob.php:140` `if (method_exists($rec, 'jsonSerialize') === true) {`
- `lib/BackgroundJob/TierDowngradeJob.php:176` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Command/ReferencesAuditRun.php:371` `$out = $row->$method();`
- `lib/Controller/ActivityTimelineController.php:140` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Controller/BrpController.php:839` `if (method_exists($saved, 'jsonSerialize') === true) {`
- `lib/Controller/BrpController.php:882` `if (method_exists($saved, 'jsonSerialize') === true) {`
- `lib/Controller/BrpController.php:918` `} else if (is_object($existing) === true && method_exists($existing, 'jsonSerialize') === true) {`
- `lib/Controller/BrpController.php:968` `} elseif (method_exists($existing, 'jsonSerialize') === true) {`
- `lib/Controller/BrpController.php:1023` `} elseif (method_exists($object, 'jsonSerialize') === true) {`
- `lib/Controller/ContractController.php:299` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Controller/NotesController.php:157` `} elseif (is_object($object) === true && method_exists($object, 'getObject') === true) {`
- `lib/Controller/ProductStockController.php:136` `if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Controller/SemanticHandoffController.php:643` `if (is_object($service) === false \|\| method_exists($service, 'find') === false) {`
- `lib/Controller/SlaPolicyController.php:208` `if (method_exists($existing, 'getObject') === true) {`
- `lib/Controller/SlaPolicyController.php:238` `if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {`
- `lib/Controller/SlaPolicyController.php:247` `if (is_object($saved) === true && method_exists($saved, 'getObject') === true) {`
- `lib/Controller/SlaPolicyController.php:249` `if (method_exists($saved, 'getUuid') === true) {`
- `lib/Listener/BerichtenboxZaakStatusListener.php:175` `if (method_exists($event, 'getNewObject') === false) {`
- `lib/Listener/BerichtenboxZaakStatusListener.php:213` `if (method_exists($event, 'getOldObject') === true) {`
- `lib/Listener/BerichtenboxZaakStatusListener.php:215` `if ($old !== null && method_exists($old, 'getObject') === true) {`
- `lib/Listener/BerichtenboxZaakStatusListener.php:223` `if (method_exists($event, 'getNewObject') === true) {`
- `lib/Listener/BerichtenboxZaakStatusListener.php:225` `if ($new !== null && method_exists($new, 'getObject') === true) {`
- `lib/Listener/LeadStageCreatingListener.php:150` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Listener/PosTransactionCompletedListener.php:176` `if (method_exists($entity, 'getObject') === true) {`
- `lib/Listener/PosTransactionCompletedListener.php:183` `if (method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Listener/SchemaChangeListener.php:104` `if (is_object($schema) === false \|\| method_exists($schema, 'getProperties') === false) {`
- `lib/Mcp/McpAnswer.php:117` `if (is_object($item) === true && method_exists($item, 'getObject') === true) {`
- `lib/Repair/BackfillContactChannelArrays.php:304` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Repair/BackfillLeadRelations.php:126` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Repair/IngestProductVendorMaster.php:327` `if (is_object($created) === true && method_exists($created, 'jsonSerialize') === true) {`
- `lib/Repair/IngestProductVendorMaster.php:501` `if (method_exists($contactsManager, 'isEnabled') === true && $contactsManager->isEnabled() === false) {`
- `lib/Repair/IngestProductVendorMaster.php:749` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Repair/IngestProductVendorMaster.php:789` `if (is_object($first) === true && method_exists($first, 'jsonSerialize') === true) {`
- `lib/Repair/InitSlaStatus.php:222` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Repair/InitSlaStatus.php:227` `if (is_object($row) === true && method_exists($row, 'getUuid') === true) {`
- `lib/Repair/InitSlaStatus.php:286` `if (method_exists($objectService, 'runAsSystem') === true) {`
- `lib/Repair/NormalisePartyFormFields.php:259` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Repair/Support/RunsUnderSystemIdentity.php:62` `if ($objectService !== null && method_exists($objectService, 'runAsSystem') === true) {`
- `lib/Repair/UnifyClientContactIdentity.php:386` `if (method_exists($contactsManager, 'isEnabled') === true && $contactsManager->isEnabled() === false) {`
- `lib/Repair/UnifyClientContactIdentity.php:473` `if (method_exists($objectService, 'runAsSystem') === true) {`
- `lib/Service/ActivityTimelineReader.php:159` `if (method_exists($item, 'getObject') === true) {`
- `lib/Service/ActivityTimelineReader.php:163` `if (isset($value['id']) === false && method_exists($item, 'getUuid') === true) {`
- `lib/Service/ActivityTimelineService.php:743` `if (is_object($saved) === true && method_exists($saved, 'getObject') === true) {`
- `lib/Service/ActivityTimelineService.php:746` `if (isset($value['id']) === false && method_exists($saved, 'getUuid') === true) {`
- `lib/Service/AppointmentCalendarLeafProvider.php:186` `if (method_exists($leaf, 'getEventsForObject') === false) {`
- `lib/Service/AppointmentCalendarLeafProvider.php:271` `if (method_exists($leaf, 'createEvent') === false) {`
- `lib/Service/AppointmentCalendarLeafProvider.php:482` `if (method_exists($leaf, 'unlinkEventsForObject') === false) {`
- `lib/Service/AppointmentDepositService.php:561` `if (is_object($result) === true && isset($result->{$key}) === true) {`
- `lib/Service/AppointmentDepositService.php:562` `return (string)$result->{$key};`
- `lib/Service/AvailabilityService.php:868` `if (method_exists($this->calendarProvider, 'getBlockedTimes') === false) {`
- `lib/Service/BerichtenboxService.php:960` `if (method_exists($router, 'route') === true) {`
- `lib/Service/BookingService.php:1236` `if (method_exists($this->emailProvider, 'sendConfirmation') === false) {`
- `lib/Service/BookingService.php:1266` `if (method_exists($this->calendarProvider, 'pushBookingEvent') === false) {`
- `lib/Service/BookingService.php:1294` `if (method_exists($this->paymentProvider, 'chargeNoShowFee') === false) {`
- `lib/Service/BookingService.php:1322` `if (method_exists($this->paymentProvider, 'chargeCancellationFee') === false) {`
- `lib/Service/BrpCacheService.php:253` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/BsnAuditService.php:267` `} elseif (method_exists($record, 'jsonSerialize') === true) {`
- `lib/Service/CallerObjectReader.php:144` `if (is_object($object) === true && method_exists($object, 'getObject') === true) {`
- `lib/Service/CallerObjectReader.php:148` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/ClientBusinessReader.php:345` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/ClientCaseReader.php:174` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/ConsentMigrationService.php:185` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/ConsentService.php:383` `if (method_exists($objectService, 'deleteObject') === true) {`
- `lib/Service/ContactImportService.php:212` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/ContactSyncService.php:131` `if (is_object($created) === true && method_exists($created, 'jsonSerialize') === true) {`
- `lib/Service/ContactmomentService.php:382` `if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {`
- `lib/Service/ContactmomentService.php:389` `if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Service/ContractService.php:328` `if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {`
- `lib/Service/ContractService.php:363` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/CtiContactMatcher.php:373` `if (method_exists($objectService, 'runAsSystem') === true) {`
- `lib/Service/CtiService.php:1074` `if (method_exists($object, $method) === true) {`
- `lib/Service/CtiService.php:1075` `$value = $object->$method();`
- `lib/Service/CtiService.php:1082` `if (method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/Customer360SummaryService.php:275` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/Demo/DemoRegisterImporter.php:136` `if (method_exists($this->configurationService, 'softDeleteAppImports') === false) {`
- `lib/Service/Egress/ConnectorEgress.php:364` `if (method_exists($callService, 'call') === false) {`
- `lib/Service/EmailMatchService.php:847` `if (method_exists($linkService, 'getLinkedEmails') === false) {`
- `lib/Service/EncryptionService.php:244` `if ($this->vaultProvider !== null && method_exists($this->vaultProvider, 'getEncryptionKey') === true) {`
- `lib/Service/EncryptionService.php:271` `if ($this->vaultProvider !== null && method_exists($this->vaultProvider, 'getDecryptionKeys') === true) {`
- `lib/Service/EncryptionService.php:304` `if ($this->vaultProvider !== null && method_exists($this->vaultProvider, 'getHmacKey') === true) {`
- `lib/Service/EntityActivityService.php:492` `&& method_exists($entity, 'getObject') === true`
- `lib/Service/EntityActivityService.php:526` `if (method_exists($item, 'getObject') === true) {`
- `lib/Service/EntityActivityService.php:530` `&& method_exists($item, 'getUuid') === true`
- `lib/Service/ExistingClientNames.php:146` `if (is_object($client) === true && method_exists($client, 'jsonSerialize') === true) {`
- `lib/Service/GiftCardService.php:348` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/GiftCardService.php:648` `return array_map([$this, 'toArray'], $rowList);`
- `lib/Service/HaalCentraalClient.php:530` `if (method_exists($client, 'setDefaultOptions') === true) {`
- `lib/Service/HaalCentraalClient.php:744` `if (method_exists($response, 'getHeader') === true) {`
- `lib/Service/KccWerkplekService.php:172` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/Lead/LeadRelatedSummary.php:192` `} else if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/Service/LoyaltyAccountService.php:356` `return array_map([$this, 'toArray'], $values);`
- `lib/Service/LoyaltyEngineService.php:394` `return array_map([$this, 'toArray'], $values);`
- `lib/Service/Marketing/DeliverabilityCheckService.php:137` `if (is_object($found) === true && method_exists($found, 'jsonSerialize') === true) {`
- `lib/Service/Marketing/FormDestinationBackfill.php:185` `if (is_object($row) === true && method_exists($row, 'getObject') === true && method_exists($row, 'getUuid') === true) {`
- `lib/Service/Marketing/FormDestinationBackfill.php:185` `if (is_object($row) === true && method_exists($row, 'getObject') === true && method_exists($row, 'getUuid') === true) {`
- `lib/Service/Marketing/JourneyService.php:347` `if (is_object($flow) === true && is_callable([$flow, 'getUuid']) === true) {`
- `lib/Service/Marketing/JourneyService.php:347` `if (is_object($flow) === true && is_callable([$flow, 'getUuid']) === true) {`
- `lib/Service/Marketing/MailTransportService.php:909` `if (method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/Marketing/MailTransportService.php:913` `if (method_exists($value, 'getObject') === true) {`
- `lib/Service/Marketing/RecipientNameReader.php:166` `if (is_object($object) === true && method_exists($object, 'getObject') === true) {`
- `lib/Service/Marketing/Transport/ConnectorSourceTransport.php:434` `if (method_exists($callService, 'call') === false) {`
- `lib/Service/Marketing/Transport/ConnectorSourceTransport.php:493` `if (isset($result->{$key}) === true && is_scalar($result->{$key}) === true && (string)$result->{$key} !== '') {`
- `lib/Service/Marketing/Transport/ConnectorSourceTransport.php:493` `if (isset($result->{$key}) === true && is_scalar($result->{$key}) === true && (string)$result->{$key} !== '') {`
- `lib/Service/Marketing/Transport/ConnectorSourceTransport.php:493` `if (isset($result->{$key}) === true && is_scalar($result->{$key}) === true && (string)$result->{$key} !== '') {`
- `lib/Service/Marketing/Transport/ConnectorSourceTransport.php:494` `return (string)$result->{$key};`
- `lib/Service/Marketing/Transport/ConnectorSourceTransport.php:519` `if (method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/Marketing/Transport/ConnectorSourceTransport.php:523` `if (method_exists($value, 'getObject') === true) {`
- `lib/Service/Marketing/Transport/InstanceMailerTransport.php:194` `if (method_exists($message, 'getSymfonyEmail') === false) {`
- `lib/Service/Mdm/MdmObjectRepository.php:321` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/MockPipelineCleanupService.php:270` `if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/Service/NaviService.php:598` `if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/Service/NaviService.php:605` `if (is_object($result) === true && method_exists($result, 'getObject') === true) {`
- `lib/Service/ObjectEventSilence.php:73` `&& method_exists($this->mapperClass, 'suppressLifecycleEvents') === true;`
- `lib/Service/ObjectEventSilence.php:92` `return is_callable($isActive) === true && call_user_func($isActive) === true;`
- `lib/Service/ObjectEventSilence.php:92` `return is_callable($isActive) === true && call_user_func($isActive) === true;`
- `lib/Service/OptOutService.php:268` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/PointsLedgerService.php:341` `$rows = array_map([$this, 'toArray'], $rowsToMap);`
- `lib/Service/PointsRuleEngine.php:387` `return array_map([$this, 'toArray'], $ruleList);`
- `lib/Service/PosTenderService.php:1186` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/PosTenderService.php:1193` `if (is_object($object) === true && method_exists($object, 'getObject') === true) {`
- `lib/Service/PosTransactionService.php:840` `if (is_object($contextService) === true && method_exists($contextService, 'buildContext') === true) {`
- `lib/Service/ProductCatalogService.php:294` `return [$basePrice, 'base'];`
- `lib/Service/ProductVendorProviderService.php:264` `if (is_object($first) === true && method_exists($first, 'jsonSerialize') === true) {`
- `lib/Service/Provider/MessageDispatchTrait.php:134` `if (is_object($provider) === false \|\| method_exists($provider, 'dispatch') === false) {`
- `lib/Service/RecurringRevenueService.php:233` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/RedemptionService.php:295` `$options = array_map([$this, 'toArray'], $rowList);`
- `lib/Service/RenewalEngineService.php:322` `if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {`
- `lib/Service/RoutingService.php:472` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/ScheduledTaskService.php:767` `if (method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/ScheduledTaskService.php:774` `if (method_exists($object, 'toArray') === true) {`
- `lib/Service/SearchConsole/SearchQueryDailyStore.php:155` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/SegmentService.php:1981` `if (is_object($schema) === false \|\| method_exists($schema, 'getProperties') === false) {`
- `lib/Service/SemanticHandoffService.php:132` `if ($resolver === null \|\| method_exists($resolver, 'findRegisterForSchema') === false) {`
- `lib/Service/SemanticHandoffService.php:264` `if (is_object($service) === false \|\| method_exists($service, $method) === false) {`
- `lib/Service/SettingsLoadService.php:492` `if (is_object($register) === true && method_exists($register, 'jsonSerialize') === true) {`
- `lib/Service/SettingsMapBuilder.php:193` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/Sla/SlaEntitySweeper.php:245` `if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {`
- `lib/Service/Sla/SlaEntitySweeper.php:249` `if (is_object($entity) === true && method_exists($entity, 'getUuid') === true) {`
- `lib/Service/SlaAttainmentService.php:810` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/SlaAttainmentService.php:817` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/SlaEngineService.php:856` `if (is_object($adapter) === false \|\| method_exists($adapter, 'send') === false) {`
- `lib/Service/SlaEngineService.php:1168` `if (method_exists($row, 'getObject') === true) {`
- `lib/Service/SlaEngineService.php:1171` `if (method_exists($row, 'getUuid') === true) {`
- `lib/Service/SlaEngineService.php:1179` `if (method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Social/BrokerCredentialReader.php:273` `if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Service/Store/StoreTemplateAssembler.php:166` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/TeamLoadService.php:150` `if (is_object($profile) === true && method_exists($profile, 'jsonSerialize') === true) {`
- `lib/Service/TierService.php:103` `$rules = array_map([$this, 'toArray'], $rowList);`
- `lib/Service/TimeBillingHandoffService.php:679` `if (is_object($service) === false \|\| method_exists($service, 'ingest') === false) {`
- `lib/Service/TimeBillingHandoffService.php:697` `if (is_object($contextService) === true && method_exists($contextService, 'buildContext') === true) {`
- `lib/Service/UnsubscribeMail.php:123` `if (is_object($helper) === false \|\| method_exists($helper, 'apply') === false) {`
- `lib/Service/WebForm/FormsQuestionReader.php:302` `if (is_callable([$subject, $getter]) === true) {`
- `lib/Service/WebForm/FormsQuestionReader.php:303` `return $subject->$getter();`
- `lib/Service/WebForm/HeldObjectDrain.php:269` `} else if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/WebForm/WebFormFinder.php:244` `} else if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/WebForm/WebFormFinder.php:253` `if (is_object($row) === true && method_exists($row, 'getUuid') === true && (string)$row->getUuid() !== '') {`
- `lib/Service/WebForm/WebFormIntakeService.php:387` `if (method_exists($exception, 'getStatus') === true) {`
- `lib/Service/WebForm/WebFormIntakeService.php:393` `if (method_exists($exception, 'getFindings') === true) {`
- `lib/Service/XWikiService.php:494` `if (method_exists($manager, 'getBaseUrl') === true) {`
- `lib/Service/Zgw/ZgwApiClient.php:369` `if (method_exists($error, 'getResponse') === true) {`
- `lib/Service/Zgw/ZgwApiClient.php:371` `if ($resp !== null && method_exists($resp, 'getStatusCode') === true) {`
- `lib/Service/Zgw/ZgwApiClient.php:375` `if ($resp !== null && method_exists($resp, 'getBody') === true) {`
- `lib/Service/Zgw/ZgwRegisterAccess.php:265` `if (method_exists($candidate, 'jsonSerialize') === true) {`
- `lib/Service/Zgw/ZgwRegisterAccess.php:272` `if (method_exists($candidate, 'toArray') === true) {`
- `lib/Util/EntityAccessorTrait.php:101` `$value = $entity->{$getter}();`
- `lib/Util/EntityPayload.php:49` `if (is_object($value) === false \|\| method_exists($value, 'getObject') === false) {`
- `lib/Util/ResultToArray.php:42` `if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/Util/SerialisedPayload.php:46` `if (is_object($value) === false \|\| method_exists($value, 'jsonSerialize') === false) {`

</details>

Unresolved call sites (receiver type unknown, magic, or spread args): 27, listed in `pipelinq.json`.

## shillinq

Local dir `shillinq`, branch `development`, sha `f1e580b0534cbd05652d034cae935bd7efd31241`.

### Runtime breaks (lib): 9

| file:line | category | app calls | OR main now | fix |
|---|---|---|---|---|
| `lib/Controller/ComplianceExportController.php:235` | dynamic-name-nowhere (SILENT) | method_exists($auditService, 'recordEvent' (receiver $auditService) | no method named recordEvent exists in any OR main class (nor in this app or NC server): the guarded branch never runs | find what replaced it on OR main and call it directly |
| `lib/Service/ComplianceExportService.php:325` | dynamic-name-nowhere (SILENT) | method_exists($auditService, 'findInRange' (receiver $auditService) | no method named findInRange exists in any OR main class (nor in this app or NC server): the guarded branch never runs | find what replaced it on OR main and call it directly |
| `lib/Controller/ComplianceExportController.php:234` | string-lookup-missing (SILENT) | container-get('OCA\OpenRegister\Service\AuditTrailService') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `lib/Repair/InitializeSettings.php:807` | string-lookup-missing (SILENT) | container-get('OCA\OpenRegister\Db\ScheduledWorkflowMapper') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `lib/Repair/InitializeSettings.php:869` | string-lookup-missing (SILENT) | container-get('OCA\OpenRegister\Db\ScheduledWorkflowMapper') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `lib/Repair/InitializeSettings.php:936` | string-lookup-missing (SILENT) | container-get('OCA\OpenRegister\Db\ScheduledWorkflowMapper') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `lib/Repair/InitializeSettings.php:999` | string-lookup-missing (SILENT) | container-get('OCA\OpenRegister\Db\ScheduledWorkflowMapper') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `lib/Service/ComplianceExportService.php:315` | string-lookup-missing (SILENT) | container-get('OCA\OpenRegister\Service\AuditTrailService') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `lib/Service/WidgetAuthService.php:595` | call-shape | $objectService->updateObject(id: $id, object: $data, register: $registerSlug, schema: $schema,) | OCA\OpenRegister\Service\ObjectService::updateObject(string $objectId, array $data, bool $_rbac=true, bool $_multitenancy=true): OCA\OpenRegister\Db\ObjectEntity (lib/Service/ObjectService.php:7466) | UNKNOWN-NAMED-ARG: `id:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `object:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `register:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `schema:` (Error: Unknown named parameter); MISSING-RE |

### Test-suite breaks (tests): 18

| file:line | category | stub | app calls | OR main now | fix |
|---|---|---|---|---|---|
| `tests/Unit/Service/Support/CallCountingObjectServiceDecorator.php:461` | contract-signature | stub | CallCountingObjectServiceDecorator::unlockObject(string\|int $identifier, bool $advisory = false): bool | OCA\OpenRegister\Contract\ObjectServiceInterface::unlockObject(string\|int $identifier, bool $advisory=false, ?string $runUuid=null): bool (lib/Contract/ObjectServiceInterface.php:465) | align the signature with OR main (fatal at class load): takes 2 params, OR declares 3 |
| `tests/Unit/Service/Support/CallCountingObjectServiceDecorator.php:474` | contract-signature | stub | CallCountingObjectServiceDecorator::lockObject(string $identifier, ?string $process = null, ?int $duration = null, bool $advisory = false): array | OCA\OpenRegister\Contract\ObjectServiceInterface::lockObject(string $identifier, ?string $process=null, ?int $duration=null, bool $advisory=false, ?string $runUuid=null, ?string $nodeId=null): array ( | align the signature with OR main (fatal at class load): takes 4 params, OR declares 6 |
| `tests/Unit/Service/Support/DuckObjectServiceAdapter.php:903` | contract-signature | stub | DuckObjectServiceAdapter::unlockObject(string\|int $identifier, bool $advisory = false): bool | OCA\OpenRegister\Contract\ObjectServiceInterface::unlockObject(string\|int $identifier, bool $advisory=false, ?string $runUuid=null): bool (lib/Contract/ObjectServiceInterface.php:465) | align the signature with OR main (fatal at class load): takes 2 params, OR declares 3 |
| `tests/Unit/Service/Support/DuckObjectServiceAdapter.php:918` | contract-signature | stub | DuckObjectServiceAdapter::lockObject(string $identifier, ?string $process = null, ?int $duration = null, bool $advisory = false): array | OCA\OpenRegister\Contract\ObjectServiceInterface::lockObject(string $identifier, ?string $process=null, ?int $duration=null, bool $advisory=false, ?string $runUuid=null, ?string $nodeId=null): array ( | align the signature with OR main (fatal at class load): takes 4 params, OR declares 6 |
| `tests/Unit/Service/Support/FilteredObjectServiceStub.php:512` | contract-signature | stub | FilteredObjectServiceStub::unlockObject(string\|int $identifier, bool $advisory = false): bool | OCA\OpenRegister\Contract\ObjectServiceInterface::unlockObject(string\|int $identifier, bool $advisory=false, ?string $runUuid=null): bool (lib/Contract/ObjectServiceInterface.php:465) | align the signature with OR main (fatal at class load): takes 2 params, OR declares 3 |
| `tests/Unit/Service/Support/FilteredObjectServiceStub.php:527` | contract-signature | stub | FilteredObjectServiceStub::lockObject(string $identifier, ?string $process = null, ?int $duration = null, bool $advisory = false): array | OCA\OpenRegister\Contract\ObjectServiceInterface::lockObject(string $identifier, ?string $process=null, ?int $duration=null, bool $advisory=false, ?string $runUuid=null, ?string $nodeId=null): array ( | align the signature with OR main (fatal at class load): takes 4 params, OR declares 6 |
| `tests/Unit/Service/Support/InMemoryObjectServiceStub.php:748` | contract-signature | stub | InMemoryObjectServiceStub::unlockObject(string\|int $identifier, bool $advisory = false): bool | OCA\OpenRegister\Contract\ObjectServiceInterface::unlockObject(string\|int $identifier, bool $advisory=false, ?string $runUuid=null): bool (lib/Contract/ObjectServiceInterface.php:465) | align the signature with OR main (fatal at class load): takes 2 params, OR declares 3 |
| `tests/Unit/Service/Support/InMemoryObjectServiceStub.php:763` | contract-signature | stub | InMemoryObjectServiceStub::lockObject(string $identifier, ?string $process = null, ?int $duration = null, bool $advisory = false): array | OCA\OpenRegister\Contract\ObjectServiceInterface::lockObject(string $identifier, ?string $process=null, ?int $duration=null, bool $advisory=false, ?string $runUuid=null, ?string $nodeId=null): array ( | align the signature with OR main (fatal at class load): takes 4 params, OR declares 6 |
| `tests/Unit/Service/Support/KnownCostFixtureObjectServiceStub.php:642` | contract-signature | stub | KnownCostFixtureObjectServiceStub::unlockObject(string\|int $identifier, bool $advisory = false): bool | OCA\OpenRegister\Contract\ObjectServiceInterface::unlockObject(string\|int $identifier, bool $advisory=false, ?string $runUuid=null): bool (lib/Contract/ObjectServiceInterface.php:465) | align the signature with OR main (fatal at class load): takes 2 params, OR declares 3 |
| `tests/Unit/Service/Support/KnownCostFixtureObjectServiceStub.php:657` | contract-signature | stub | KnownCostFixtureObjectServiceStub::lockObject(string $identifier, ?string $process = null, ?int $duration = null, bool $advisory = false): array | OCA\OpenRegister\Contract\ObjectServiceInterface::lockObject(string $identifier, ?string $process=null, ?int $duration=null, bool $advisory=false, ?string $runUuid=null, ?string $nodeId=null): array ( | align the signature with OR main (fatal at class load): takes 4 params, OR declares 6 |
| `tests/Unit/Service/Support/RacingDefaultObjectServiceDecorator.php:506` | contract-signature | stub | RacingDefaultObjectServiceDecorator::unlockObject(string\|int $identifier, bool $advisory = false): bool | OCA\OpenRegister\Contract\ObjectServiceInterface::unlockObject(string\|int $identifier, bool $advisory=false, ?string $runUuid=null): bool (lib/Contract/ObjectServiceInterface.php:465) | align the signature with OR main (fatal at class load): takes 2 params, OR declares 3 |
| `tests/Unit/Service/Support/RacingDefaultObjectServiceDecorator.php:519` | contract-signature | stub | RacingDefaultObjectServiceDecorator::lockObject(string $identifier, ?string $process = null, ?int $duration = null, bool $advisory = false): array | OCA\OpenRegister\Contract\ObjectServiceInterface::lockObject(string $identifier, ?string $process=null, ?int $duration=null, bool $advisory=false, ?string $runUuid=null, ?string $nodeId=null): array ( | align the signature with OR main (fatal at class load): takes 4 params, OR declares 6 |
| `tests/Unit/BackgroundJob/TenderNedAwardPromotionJobTest.php:95` | constructor-shape | stub | new OrganisationService() | OCA\OpenRegister\Service\OrganisationService::__construct(OrganisationMapperInterface $organisationMapper, IUserSession $userSession, ISession $session, IConfig $config, IAppConfig $appConfig, IGroupM | MISSING-REQUIRED-ARG: `$organisationMapper` (ArgumentCountError); MISSING-REQUIRED-ARG: `$userSession` (ArgumentCountError); MISSING-REQUIRED-ARG: `$session` (ArgumentCountError); MISSING-REQUIRED-ARG |
| `tests/Unit/Listener/DeepLinkRegistrationListenerTest.php:87` | constructor-shape | stub | new DeepLinkRegistrationEvent() | OCA\OpenRegister\Event\DeepLinkRegistrationEvent::__construct(private readonly DeepLinkRegistryServiceInterface $registry) (lib/Event/DeepLinkRegistrationEvent.php:46) | MISSING-REQUIRED-ARG: `$registry` (ArgumentCountError) |
| `tests/Unit/Listener/DeepLinkRegistrationListenerTest.php:144` | constructor-shape | stub | new DeepLinkRegistrationEvent() | OCA\OpenRegister\Event\DeepLinkRegistrationEvent::__construct(private readonly DeepLinkRegistryServiceInterface $registry) (lib/Event/DeepLinkRegistrationEvent.php:46) | MISSING-REQUIRED-ARG: `$registry` (ArgumentCountError) |
| `tests/Unit/Listener/TenderNedAwardDetectedListenerTest.php:589` | constructor-shape | stub | new ListenerDeferralService(enabled: true) | OCA\OpenRegister\Service\Deferral\ListenerDeferralService::__construct(private readonly IUserSession $userSession, private readonly OrganisationServiceInterface $organisation, private readonly Deferra | UNKNOWN-NAMED-ARG: `enabled:` (Error: Unknown named parameter); MISSING-REQUIRED-ARG: `$userSession` (ArgumentCountError); MISSING-REQUIRED-ARG: `$organisation` (ArgumentCountError); MISSING-REQUIRED- |
| `tests/Unit/Listener/TenderNedAwardDetectedListenerTest.php:628` | constructor-shape | stub | new ListenerDeferralService(enabled: true) | OCA\OpenRegister\Service\Deferral\ListenerDeferralService::__construct(private readonly IUserSession $userSession, private readonly OrganisationServiceInterface $organisation, private readonly Deferra | UNKNOWN-NAMED-ARG: `enabled:` (Error: Unknown named parameter); MISSING-REQUIRED-ARG: `$userSession` (ArgumentCountError); MISSING-REQUIRED-ARG: `$organisation` (ArgumentCountError); MISSING-REQUIRED- |
| `tests/Unit/Listener/TenderNedAwardDetectedListenerTest.php:665` | constructor-shape | stub | new ListenerDeferralService(enabled: false) | OCA\OpenRegister\Service\Deferral\ListenerDeferralService::__construct(private readonly IUserSession $userSession, private readonly OrganisationServiceInterface $organisation, private readonly Deferra | UNKNOWN-NAMED-ARG: `enabled:` (Error: Unknown named parameter); MISSING-REQUIRED-ARG: `$userSession` (ArgumentCountError); MISSING-REQUIRED-ARG: `$organisation` (ArgumentCountError); MISSING-REQUIRED- |

### Test stubs of OR classes that drifted from OR main: 11 of 26

- `tests/stubs/OpenRegister/Contract/ObjectServiceInterface.php:59` `OCA\OpenRegister\Contract\ObjectServiceInterface` (exists): unlockObject($identifier, $advisory) vs OR main unlockObject($identifier, $advisory, $runUuid) [lib/Contract/ObjectServiceInterface.php:465]; lockObject($identifier, $process, $duration, $advisory) vs OR main lockObject($identifier, $process, $duration, $advisory, $runUuid, $nodeId) [lib/Contract/ObjectServiceInterface.php:481]
- `tests/stubs/OpenRegister/Event/ObjectCreatedEvent.php:24` `OCA\OpenRegister\Event\ObjectCreatedEvent` (exists): __construct($object) vs OR main __construct($object) [lib/Event/AbstractObjectEntityEvent.php:39]
- `tests/stubs/OpenRegister/Event/ObjectCreatingEvent.php:29` `OCA\OpenRegister\Event\ObjectCreatingEvent` (exists): __construct($object) vs OR main __construct($object) [lib/Event/AbstractObjectEntityEvent.php:39]
- `tests/stubs/OpenRegister/Event/ObjectTransitionedEvent.php:25` `OCA\OpenRegister\Event\ObjectTransitionedEvent` (exists): __construct($object, $action, $from, $to, $userId, $register, $schema) vs OR main __construct($object, $action, $from, $to, $userId, $register, $schema, $automatic) [lib/Event/ObjectTransitionedEvent.php:65]
- `tests/stubs/OpenRegister/Event/ObjectUpdatedEvent.php:24` `OCA\OpenRegister\Event\ObjectUpdatedEvent` (exists): __construct($object, $oldObject) vs OR main __construct($newObject, $oldObject) [lib/Event/ObjectUpdatedEvent.php:56]
- `tests/stubs/OpenRegister/Event/ObjectUpdatingEvent.php:26` `OCA\OpenRegister\Event\ObjectUpdatingEvent` (exists): __construct($newObject, $oldObject) vs OR main __construct($newObject, $oldObject) [lib/Event/ObjectUpdatingEvent.php:46]
- `tests/stubs/OpenRegister/Exception/HookStoppedException.php:28` `OCA\OpenRegister\Exception\HookStoppedException` (exists): __construct($message, $errors, $code, $previous) vs OR main __construct($message, $errors) [lib/Exception/HookStoppedException.php:44]
- `tests/stubs/OpenRegister/Search/Service/Aggregation/AggregationQuery.php:23` `OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery` (exists): __construct($metric, $field, $filter, $groupBy) vs OR main __construct($metric, $field, $filter, $layout) [lib/Search/Service/Aggregation/AggregationQuery.php:67]; create($metric, $field, $filter, $groupBy) vs OR main create($metric, $field, $filter, $groupBy, $dateBucket, $metrics, $cumulative) [lib/Search/Service/Aggregation/AggregationQuery.php:166]
- `tests/stubs/OpenRegister/Service/Deferral/ListenerDeferralService.php:31` `OCA\OpenRegister\Service\Deferral\ListenerDeferralService` (exists): __construct($enabled) vs OR main __construct($userSession, $organisation, $queue, $argumentLimit) [lib/Service/Deferral/ListenerDeferralService.php:140]
- `tests/stubs/OpenRegister/Service/Integration/LeafDescriptor.php:23` `OCA\OpenRegister\Service\Integration\LeafDescriptor` (exists): __construct($id, $label, $icon, $kinds, $requiredApp, $group, $surfaces, $referenceType, $requiresPermission, $renderMode) vs OR main __construct($id, $label, $icon, $kinds, $requiredApp, $group, $surfaces, $referenceType, $requiresPermission, $renderMode, $loadStrategy) [lib/Service/Integration/LeafDescriptor.php:205]
- `tests/stubs/OpenRegister/Service/OrganisationService.php:25` `OCA\OpenRegister\Service\OrganisationService` (exists): getActiveOrganisationUuid(): not on OR main; setActiveOrganisation($uuid) vs OR main setActiveOrganisation($organisationUuid) [lib/Service/OrganisationService.php:608]

### Implements / extends OR classes: 41

- `lib/BackgroundJob/TenderNedAwardPromotionJob.php:63` TenderNedAwardPromotionJob extends `OCA\OpenRegister\BackgroundJob\ActorForwardedJob` (exists)
- `lib/Integration/ContractLeafProvider.php:47` ContractLeafProvider implements `OCA\OpenRegister\Service\Integration\IntegrationProvider` (exists)
- `lib/Integration/FinancialDocumentLeafProvider.php:48` FinancialDocumentLeafProvider implements `OCA\OpenRegister\Service\Integration\IntegrationProvider` (exists)
- `lib/Integration/PaymentRequestLeafProvider.php:58` PaymentRequestLeafProvider implements `OCA\OpenRegister\Service\Integration\IntegrationProvider` (exists)
- `lib/Lifecycle/Action/AppendReopenHistoryAction.php:64` AppendReopenHistoryAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/Action/ApplyReinvestmentReserveAction.php:36` ApplyReinvestmentReserveAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/Action/ComputeBcfClaimAction.php:38` ComputeBcfClaimAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/Action/ComputeFidoQuarterAction.php:38` ComputeFidoQuarterAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/Action/EvaluateAllocationRulesAction.php:57` EvaluateAllocationRulesAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/Action/ExtraDepreciationAction.php:36` ExtraDepreciationAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/Action/HandToAccountsPayableAction.php:47` HandToAccountsPayableAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/Action/ImportBatchAction.php:36` ImportBatchAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/Action/InterestAllocationAction.php:39` InterestAllocationAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/Action/MaterialiseGlTransactionAction.php:90` MaterialiseGlTransactionAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/Action/RateMeterReadingAction.php:36` RateMeterReadingAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/Action/RealiseReserveMutationAction.php:40` RealiseReserveMutationAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/Action/RecordCommitmentMovementAction.php:42` RecordCommitmentMovementAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/Action/ReviseDepreciationAction.php:36` ReviseDepreciationAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/Action/StampPostingAction.php:41` StampPostingAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/CommitmentGuardAdapter.php:47` CommitmentGuardAdapter implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/DownPaymentGuard.php:50` DownPaymentGuard implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/FourEyesPaymentRunGuard.php:58` FourEyesPaymentRunGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/ImportReverseGuard.php:42` ImportReverseGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/PaymentPlanGuard.php:46` PaymentPlanGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/PaymentRunDuplicateGuard.php:70` PaymentRunDuplicateGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/PaymentRunReconcileGuard.php:40` PaymentRunReconcileGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/PurchaseOrderApprovalGuard.php:42` PurchaseOrderApprovalGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/RegisterRequiresGuardAdapter.php:74` RegisterRequiresGuardAdapter implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/SupplierInvoiceBookingGuard.php:41` SupplierInvoiceBookingGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/SupplierInvoiceExceptionApprovalGuard.php:41` SupplierInvoiceExceptionApprovalGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/SupplierInvoicePayGuard.php:45` SupplierInvoicePayGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Notification/RoleFallbackResolver.php:51` RoleFallbackResolver implements `OCA\OpenRegister\Notifications\Service\Notification\RecipientResolverInterface` (exists)
- `lib/Service/Pipelinq/CustomerBridgeMetricsService.php:74` CustomerBridgeMetricsService implements `OCA\OpenRegister\AppHost\IMetricsProvider` (exists)
- `tests/Unit/Service/Support/CallCountingObjectServiceDecorator.php:48` CallCountingObjectServiceDecorator implements `OCA\OpenRegister\Contract\ObjectServiceInterface` (exists)
- `tests/Unit/Service/Support/DuckObjectServiceAdapter.php:81` DuckObjectServiceAdapter implements `OCA\OpenRegister\Contract\ObjectServiceInterface` (exists)
- `tests/Unit/Service/Support/FilteredObjectServiceStub.php:53` FilteredObjectServiceStub implements `OCA\OpenRegister\Contract\ObjectServiceInterface` (exists)
- `tests/Unit/Service/Support/InMemoryObjectServiceStub.php:63` InMemoryObjectServiceStub implements `OCA\OpenRegister\Contract\ObjectServiceInterface` (exists)
- `tests/Unit/Service/Support/KnownCostFixtureObjectServiceStub.php:60` KnownCostFixtureObjectServiceStub implements `OCA\OpenRegister\Contract\ObjectServiceInterface` (exists)
- `tests/Unit/Service/Support/ObjectEntityStub.php:40` ObjectEntityStub implements `OCA\OpenRegister\Contract\ObjectEntityInterface` (exists)
- `tests/Unit/Service/Support/RacingDefaultObjectServiceDecorator.php:50` RacingDefaultObjectServiceDecorator implements `OCA\OpenRegister\Contract\ObjectServiceInterface` (exists)
- `tests/stubs/OpenRegister/Db/ObjectEntity.php:23` ObjectEntity implements `OCA\OpenRegister\Contract\ObjectEntityInterface` (exists)

### OR event listeners: 31 registrations

- `OCA\OpenRegister\Event\DeepLinkRegistrationEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Application.php:215`
- `OCA\OpenRegister\Event\ObjectCreatedEvent` (exists, OR main constructed): 8x, e.g. `lib/AppInfo/Application.php:259`
- `OCA\OpenRegister\Event\ObjectTransitionedEvent` (exists, OR main constructed): 17x, e.g. `lib/AppInfo/Application.php:235`
- `OCA\OpenRegister\Event\ObjectUpdatedEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/ObjectRequestSettlementRegistration.php:64`
- `OCA\OpenRegister\Event\ObjectUpdatingEvent` (exists, OR main constructed): 3x, e.g. `lib/AppInfo/PaymentControlsRegistration.php:49`
- `OCA\OpenRegister\Event\RegisterLeafProvidersEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Application.php:225`

<details><summary>Dynamic call sites in lib/ (method_exists, is_callable, $x->$m(), call_user_func): 191. Always suspect: a renamed OR method makes these silently skip.</summary>

- `lib/BackgroundJob/AdministrationBackupSchedulerJob.php:387` `} elseif (is_object($entity) === true && method_exists($entity, 'getObject') === true) {`
- `lib/BackgroundJob/CalibrationBatchJob.php:203` `if (method_exists($entity, $getter) === true) {`
- `lib/BackgroundJob/CalibrationBatchJob.php:204` `return $entity->{$getter}();`
- `lib/BackgroundJob/CrisisModeRefreshJob.php:194` `if (method_exists($entity, $getter) === true) {`
- `lib/BackgroundJob/CrisisModeRefreshJob.php:195` `return $entity->{$getter}();`
- `lib/BackgroundJob/DBAFlagGenerationJob.php:418` `if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {`
- `lib/BackgroundJob/DBAPortfolioAggregationJob.php:378` `if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {`
- `lib/BackgroundJob/FxRateImportJob.php:365` `if (method_exists($first, 'getObject') === true) {`
- `lib/BackgroundJob/HorizonRollingJob.php:265` `if (method_exists($entity, 'getObject') === true) {`
- `lib/BackgroundJob/LotExpiryAlertJob.php:347` `if (method_exists($object, 'jsonSerialize') === true) {`
- `lib/BackgroundJob/LotExpiryAlertJob.php:354` `if (method_exists($object, 'getObject') === true) {`
- `lib/BackgroundJob/PipelinqTimelineRetryJob.php:575` `if (method_exists($object, 'jsonSerialize') === true) {`
- `lib/BackgroundJob/PipelinqTimelineRetryJob.php:582` `if (method_exists($object, 'getObject') === true) {`
- `lib/BackgroundJob/PipelinqTimelineRetryJob.php:587` `if (method_exists($object, 'getUuid') === true) {`
- `lib/BackgroundJob/Support/RunsJobAsSystem.php:56` `if ($objectService !== null && method_exists($objectService, 'runAsSystem') === true) {`
- `lib/BackgroundJob/VendorPerformanceAggregationJob.php:255` `if (method_exists($row, 'jsonSerialize') === true) {`
- `lib/Controller/AdministrationExportController.php:217` `if ($node !== null && method_exists($node, 'getContent') === true) {`
- `lib/Controller/AdministrationExportController.php:269` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Controller/BankRuleController.php:447` `if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/Controller/BookingDepthController.php:462` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Controller/BookingDetailController.php:434` `if (method_exists($object, 'jsonSerialize') === true) {`
- `lib/Controller/BookingDetailController.php:441` `if (method_exists($object, 'getObject') === true) {`
- `lib/Controller/CBSSubmissionController.php:651` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Controller/CBSSubmissionController.php:658` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Controller/CalendarController.php:707` `if (method_exists($object, 'jsonSerialize') === true) {`
- `lib/Controller/CalendarController.php:714` `if (method_exists($object, 'getObject') === true) {`
- `lib/Controller/ComplianceExportController.php:235` `if (method_exists($auditService, 'recordEvent') === true) {`
- `lib/Controller/ComplianceExportController.php:240` `if (method_exists($auditService, 'log') === true) {`
- `lib/Controller/ConfirmationApiController.php:590` `if (method_exists($object, 'jsonSerialize') === true) {`
- `lib/Controller/ConfirmationApiController.php:597` `if (method_exists($object, 'getObject') === true) {`
- `lib/Controller/DBAController.php:533` `} elseif (method_exists($row, 'getObject') === true) {`
- `lib/Controller/DBAController.php:556` `} elseif (method_exists($row, 'getObject') === true) {`
- `lib/Controller/DBAController.php:714` `if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {`
- `lib/Controller/InventoryMobileScannerController.php:354` `if (is_object($record) === true && method_exists($record, 'jsonSerialize') === true) {`
- `lib/Controller/TimelineDeadLetterController.php:338` `if (method_exists($result, 'getUuid') === true) {`
- `lib/Controller/TimelineDeadLetterController.php:345` `if (method_exists($result, 'jsonSerialize') === true) {`
- `lib/Controller/TimelineDeadLetterController.php:355` `if (method_exists($result, 'getId') === true) {`
- `lib/Controller/TimelineDeadLetterController.php:395` `if (method_exists($object, 'jsonSerialize') === true) {`
- `lib/Controller/TimelineDeadLetterController.php:402` `if (method_exists($object, 'getObject') === true) {`
- `lib/Controller/TimelineDeadLetterController.php:405` `if (method_exists($object, 'getUuid') === true) {`
- `lib/Controller/WidgetApiController.php:571` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Dashboard/BBVComplianceWidget.php:312` `if (is_object($object) === true && method_exists($object, 'getObject') === true) {`
- `lib/Dashboard/BBVComplianceWidget.php:319` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Guard/ProgrammaLinkGuard.php:251` `if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/Lifecycle/Action/EvaluateAllocationRulesAction.php:392` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Lifecycle/Action/ImportBatchAction.php:77` `return $this->steps->{$step}($objectData);`
- `lib/Lifecycle/Action/MaterialiseGlTransactionAction.php:737` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Lifecycle/AuditorStatementGuard.php:147` `if (is_object($record) === true && method_exists($record, 'getObject') === true) {`
- `lib/Lifecycle/CommitmentGuardAdapter.php:82` `$allowed = ($this->guard->{$this->method}($number, $object) === true);`
- `lib/Lifecycle/FourEyesPaymentRunGuard.php:246` `if (is_object($log) === true && method_exists($log, $getter) === true) {`
- `lib/Lifecycle/FourEyesPaymentRunGuard.php:247` `$value = $log->{$getter}();`
- `lib/Lifecycle/InventoryPostingGuard.php:363` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Lifecycle/InventoryValuationMethodGuard.php:157` `} elseif (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Lifecycle/PeriodCloseGuard.php:484` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Lifecycle/PostingRestrictionGuard.php:307` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Lifecycle/RegisterRequiresGuardAdapter.php:109` `$allowed = ($this->guard->{$this->method}($object) === true);`
- `lib/Lifecycle/RuleComplianceGuard.php:277` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Lifecycle/SubsidieVerantwoordingGuard.php:197` `if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {`
- `lib/Listener/BbvAllocationValidationListener.php:248` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Listener/BookingEventListener.php:109` `if (method_exists(object_or_class: $event, method: 'getSchema') === true) {`
- `lib/Listener/BookingEventListener.php:134` `if (method_exists(object_or_class: $event, method: 'getObject') === true) {`
- `lib/Listener/ContractObligationTaskListener.php:188` `if (method_exists($event, 'getObject') === false) {`
- `lib/Listener/FeeScheduleValidationListener.php:108` `if (method_exists($entity, 'getObject') === true) {`
- `lib/Listener/InnovatieboxAuditTrailListener.php:474` `if (method_exists($entity, 'getObject') === true) {`
- `lib/Listener/InnovatieboxAuditTrailListener.php:481` `if (method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Listener/InnovatieboxAuditTrailListener.php:501` `if (method_exists($event, $method) === false) {`
- `lib/Listener/InnovatieboxAuditTrailListener.php:505` `$prior = $event->{$method}();`
- `lib/Listener/OrderFulfilmentEvidenceListener.php:157` `if (method_exists($entity, 'getObject') === true) {`
- `lib/Listener/ReconciliationMatchToReportListener.php:142` `if (method_exists($event, 'getObject') === false) {`
- `lib/Listener/ReconciliationMatchToReportListener.php:147` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Listener/ReconciliationMatchToReportListener.php:159` `if (method_exists($event, 'getSchema') === true) {`
- `lib/Listener/ReconciliationMatchToReportListener.php:163` `} elseif (is_object($rawSchema) === true && method_exists($rawSchema, 'getSlug') === true) {`
- `lib/Listener/ReconciliationMatchToReportListener.php:378` `if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/Listener/SigningConcludedListener.php:420` `if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/Listener/SignoffDecisionConcludedListener.php:395` `if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/Listener/StockMoveTransitionedListener.php:219` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Listener/StockMoveTransitionedListener.php:228` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/PaymentRun/PaymentRunExportService.php:502` `if (method_exists($e, 'getErrors') === true && $e->getErrors() !== []) {`
- `lib/PaymentRun/PaymentRunExportService.php:509` `if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {`
- `lib/PaymentRun/PaymentRunReconciliationService.php:300` `if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {`
- `lib/Repair/BackfillArInvoiceProvenance.php:224` `if (is_object($log) === true && method_exists($log, 'getAction') === true && $log->getAction() === 'create') {`
- `lib/Repair/FoldExpensesAndHoursIntoProject.php:128` `mapper: [$this, 'mapReceipt'],`
- `lib/Repair/FoldExpensesAndHoursIntoProject.php:140` `mapper: [$this, 'mapMileage'],`
- `lib/Repair/FoldExpensesAndHoursIntoProject.php:152` `mapper: [$this, 'mapPerDiem'],`
- `lib/Repair/InitializeBbvAdministration.php:342` `if (method_exists($object, 'jsonSerialize') === true) {`
- `lib/Repair/InitializeSettings.php:1256` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Repair/InitializeSettings.php:1265` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Repair/Support/RunsUnderSystemIdentity.php:62` `if ($objectService !== null && method_exists($objectService, 'runAsSystem') === true) {`
- `lib/Reporting/Generator/AbstractDocumentReportGenerator.php:610` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Reporting/Generator/AbstractDocumentReportGenerator.php:636` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Reporting/Generator/ReportDataTrait.php:97` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Reporting/ReportGenerationService.php:414` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Reporting/ReportGenerationService.php:641` `if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {`
- `lib/Service/AccountantDashboardService.php:326` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/AdministrationContextService.php:587` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/AdministrationContextService.php:596` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/BankRuleSuggestionService.php:234` `if (method_exists($aiRanker, 'rank') === false) {`
- `lib/Service/BbvMigration/RgsAccountMapper.php:291` `if (method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/BbvProgrammeBudgetReader.php:452` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/Booking/ConfirmationTokenService.php:481` `if ($dispatcher !== null && method_exists($dispatcher, 'dispatch') === true) {`
- `lib/Service/Booking/ConfirmationTokenService.php:537` `if (method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/Booking/ConfirmationTokenService.php:544` `if (method_exists($object, 'getObject') === true) {`
- `lib/Service/BudgetGridReader.php:596` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/BudgetProjectionReader.php:545` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/BudgetScenarioReader.php:248` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/BudgetVsActualsReader.php:490` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/CBSExportService.php:673` `if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {`
- `lib/Service/CashflowExportService.php:313` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/ComplianceDeadlineCalendarService.php:860` `if (is_object($manager) === true && method_exists($manager, 'getCalendarsForPrincipal') === true) {`
- `lib/Service/ComplianceDeadlineCalendarService.php:897` `return is_object($calendar) === true && method_exists($calendar, 'createFromString') === true;`
- `lib/Service/ComplianceDeadlineCalendarService.php:908` `return method_exists($calendar, 'getUri') === true && (string)$calendar->getUri() === self::CALENDAR_URI;`
- `lib/Service/ComplianceDeadlineCalendarService.php:923` `if (method_exists($calendar, 'search') === false) {`
- `lib/Service/ComplianceDeadlineCalendarService.php:1297` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/ComplianceDeadlineCalendarService.php:1305` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/ComplianceExportService.php:325` `if (method_exists($auditService, 'findInRange') === true) {`
- `lib/Service/ComplianceExportService.php:332` `} elseif (method_exists($auditService, 'findAll') === true) {`
- `lib/Service/ComplianceExportService.php:362` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/ComplianceService.php:502` `if (is_object($object) === true && method_exists($object, 'getObject') === true) {`
- `lib/Service/ComplianceService.php:509` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/ConflictDetectionService.php:388` `if (method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/ConflictDetectionService.php:395` `if (method_exists($object, 'getObject') === true) {`
- `lib/Service/ConsolidationMappingService.php:339` `if (is_object($candidate) === true && method_exists($candidate, 'getObject') === true) {`
- `lib/Service/ContributionDebtorResolver.php:353` `return method_exists($event, 'getResult') === true && $event->getResult() === 'ok';`
- `lib/Service/DBAVbarMonitorService.php:177` `} elseif (method_exists($entity, 'getObject') === true) {`
- `lib/Service/FifoValuationService.php:599` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/FifoValuationService.php:608` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/FinancialDashboardService.php:475` `if (is_object($record) === true && method_exists($record, 'jsonSerialize') === true) {`
- `lib/Service/FiscalYearContextService.php:382` `if (is_object($candidate) === true && method_exists($candidate, 'getObject') === true) {`
- `lib/Service/HrmqCostRateAdapter.php:336` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/HrmqCostRateAdapter.php:343` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/Import/ImportPipelineService.php:1096` `if (method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/Import/ImportPipelineService.php:1103` `if (method_exists($object, 'getObject') === true) {`
- `lib/Service/InventoryMobileScannerService.php:881` `if (is_object($record) === true && method_exists($record, 'jsonSerialize') === true) {`
- `lib/Service/InventoryValuationReportService.php:736` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/InventoryValuationReportService.php:745` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/KnownCostBudgetWriter.php:716` `if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/Service/KnownCostBudgetWriter.php:723` `if (is_object($result) === true && method_exists($result, 'getObject') === true) {`
- `lib/Service/KnownCostReader.php:392` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/LandedCostAllocationService.php:517` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/LandedCostAllocationService.php:526` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/LeasePaymentScheduleService.php:199` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/LeasePaymentScheduleService.php:208` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/Lifecycle/ObjectTransitionRunner.php:107` `if (method_exists($engine, 'transitionAsSystem') === false) {`
- `lib/Service/ListenerSchemaResolver.php:167` `$value = $entity->{$getter}();`
- `lib/Service/MovingAverageValuationService.php:296` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/MovingAverageValuationService.php:305` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/NrvWriteDownService.php:371` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/NrvWriteDownService.php:380` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/ObligationTaskBridge.php:193` `if (method_exists($backend, 'createFromString') === false) {`
- `lib/Service/ObligationTaskBridge.php:352` `if (method_exists($backend, 'createFromString') === true) {`
- `lib/Service/ObligationTaskBridge.php:475` `if (is_object($appConfig) === true && method_exists($appConfig, 'getValueString') === true) {`
- `lib/Service/OssRateResolver.php:230` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/OssRateResolver.php:239` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/PeriodCloseService.php:514` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/PeriodCloseService.php:521` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/Pipelinq/PersistentTimelineRetryQueue.php:239` `if (method_exists($result, 'getUuid') === true) {`
- `lib/Service/Pipelinq/PersistentTimelineRetryQueue.php:246` `if (method_exists($result, 'jsonSerialize') === true) {`
- `lib/Service/Pipelinq/PersistentTimelineRetryQueue.php:256` `if (method_exists($result, 'getId') === true) {`
- `lib/Service/Platform/FieldRequirements.php:233` `if (is_object($row) === true && method_exists($row, 'getUuid') === true && (string)$row->getUuid() !== '') {`
- `lib/Service/ProductCatalogService.php:670` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/ReconciliationResolutionService.php:203` `if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {`
- `lib/Service/RuleAuditService.php:214` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/SalesDispatchStockIssueService.php:422` `if (method_exists($engine, 'transition') === false) {`
- `lib/Service/SalesDispatchStockIssueService.php:731` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/SalesDispatchStockIssueService.php:738` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/Seed/SeedImporter.php:183` `if (method_exists($objectService, 'runAsSystem') === true) {`
- `lib/Service/SlotService.php:568` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/SoftCloseExecutor.php:401` `if (method_exists($delegate, 'reval') === false) {`
- `lib/Service/SoftCloseExecutor.php:423` `if (method_exists($delegate, 'cutoff') === false) {`
- `lib/Service/SoftCloseExecutor.php:445` `if (method_exists($delegate, 'post') === false) {`
- `lib/Service/SoftCloseExecutor.php:469` `if (method_exists($delegate, 'matchForPeriod') === false) {`
- `lib/Service/SoftCloseExecutor.php:494` `if (method_exists($delegate, 'generate') === false) {`
- `lib/Service/StandardsPolicyService.php:319` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/SubjectCostService.php:280` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/SubjectCostService.php:287` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/Treasury/FxRevaluationService.php:560` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Treasury/FxRevaluationService.php:569` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/Treasury/RealisedFxSettlementService.php:622` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Treasury/RealisedFxSettlementService.php:631` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/TrialBalanceService.php:302` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/TrialBalanceService.php:311` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/VATReturnService.php:887` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/VATReturnService.php:894` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/Vat/VatLineStamper.php:299` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/WidgetAuthService.php:633` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/WidgetService.php:113` `if (is_object($record) === true && method_exists($record, 'jsonSerialize') === true) {`
- `lib/Util/ObjectIdentifier.php:142` `if (method_exists($entity, 'jsonSerialize') === false) {`
- `lib/Util/ObjectIdentifier.php:175` `return self::stringify(value: $entity->{$getter}());`
- `lib/Util/ObjectIdentifier.php:354` `if (method_exists($scoped, 'getCurrentRegisterEntity') === false \|\| method_exists($scoped, 'getCurrentSchemaEntity') === false) {`
- `lib/Util/ObjectIdentifier.php:354` `if (method_exists($scoped, 'getCurrentRegisterEntity') === false \|\| method_exists($scoped, 'getCurrentSchemaEntity') === false) {`
- `lib/Util/ObjectIdentifier.php:451` `if (is_object($candidate) === true && method_exists($candidate, 'getObject') === true) {`

</details>

Unresolved call sites (receiver type unknown, magic, or spread args): 22, listed in `shillinq.json`.

## learniq

Local dir `scholiq`, branch `development`, sha `e9dd0d0fbac18d22bd8553453b419eab035ca252`.

### Runtime breaks (lib): 0

### Test-suite breaks (tests): 9

| file:line | category | stub | app calls | OR main now | fix |
|---|---|---|---|---|---|
| `tests/Unit/Service/LessonOnboarding/DocumentLessonReaderTest.php:170` | string-lookup-missing |  | string('OCA\OpenRegister\NoSuchExtractor') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/Unit/Service/LessonOnboarding/DocumentLessonReaderTest.php:207` | string-lookup-missing |  | string('OCA\OpenRegister\NoSuchExtractor') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/Unit/Service/LessonOnboarding/DocumentLessonReaderTest.php:228` | string-lookup-missing |  | string('OCA\OpenRegister\NoSuchWordExtractor') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/Unit/Service/LessonOnboarding/OfficeLessonExtractorTest.php:95` | string-lookup-missing |  | string('OCA\OpenRegister\NoSuchDocumentExtractor') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/Unit/Service/LessonOnboarding/OfficeLessonExtractorTest.php:143` | string-lookup-missing |  | string('OCA\OpenRegister\NoSuchWordExtractor') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/Unit/Service/LessonOnboarding/PresentationLessonReaderTest.php:93` | string-lookup-missing |  | string('OCA\OpenRegister\Service\TextExtraction\NoSuchExtractor') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/openregister-harness/EmployerInviteTenancyHarnessTest.php:128` | constructor-shape |  | new PermissionHandler($userSession, $userManager, $groupManager, $this->createMock(originalClassName: SchemaMapper::class), $this->createMock(originalClassName: | OCA\OpenRegister\Service\Object\PermissionHandler::__construct(private readonly PermissionCallerServices $caller, private readonly PermissionDataServices $data, private readonly PermissionRuleServices | SILENT-EXTRA-ARGS: 9 positional args, method takes 4 (extra args are silently dropped) |
| `tests/Support/OrReadVerdict.php:76` | constructor-shape | stub | new ConditionMatcher(userSession: $session, container: $this->createMock(ContainerInterface::class), operatorEvaluator: new OperatorEvaluator(logger: new NullLo | OCA\OpenRegister\Service\ConditionMatcher::__construct(ConditionUserResolvers $users, private readonly ContainerInterface $container, private readonly OperatorEvaluator $operatorEvaluator, private rea | UNKNOWN-NAMED-ARG: `userSession:` (Error: Unknown named parameter); MISSING-REQUIRED-ARG: `$users` (ArgumentCountError) |
| `tests/Unit/Register/ConcernReportReadAccessTest.php:104` | constructor-shape | stub | new ConditionMatcher(userSession: $session, container: $this->createMock(ContainerInterface::class), operatorEvaluator: new OperatorEvaluator(logger: new NullLo | OCA\OpenRegister\Service\ConditionMatcher::__construct(ConditionUserResolvers $users, private readonly ContainerInterface $container, private readonly OperatorEvaluator $operatorEvaluator, private rea | UNKNOWN-NAMED-ARG: `userSession:` (Error: Unknown named parameter); MISSING-REQUIRED-ARG: `$users` (ArgumentCountError) |

### Test stubs of OR classes that drifted from OR main: 5 of 44

- `tests/Stubs/Event/DeepLinkRegistrationEvent.php:19` `OCA\OpenRegister\Event\DeepLinkRegistrationEvent` (exists): register($appId, $registerSlug, $schemaSlug, $urlTemplate) vs OR main register($appId, $registerSlug, $schemaSlug, $urlTemplate, $icon, $displayName) [lib/Event/DeepLinkRegistrationEvent.php:79]
- `tests/Stubs/Mcp/Attribute/McpTool.php:41` `OCA\OpenRegister\Mcp\Attribute\McpTool` (exists): __construct($name, $description, $readOnlyHint, $destructiveHint, $idempotentHint, $scope, $subject, $action) vs OR main __construct($name, $description, $readOnlyHint, $destructiveHint, $idempotentHint, $scope, $subject, $action, $reach, $annotations) [lib/Mcp/Attribute/McpTool.php:130]
- `tests/Stubs/Service/ConditionMatcher.php:47` `OCA\OpenRegister\Service\ConditionMatcher` (exists): __construct($userSession, $container, $operatorEvaluator, $logger, $groupManager) vs OR main __construct($users, $container, $operatorEvaluator, $logger) [lib/Service/ConditionMatcher.php:168]; singleConditionMatches(): not on OR main
- `tests/Stubs/Service/OperatorEvaluator.php:40` `OCA\OpenRegister\Service\OperatorEvaluator` (exists): __construct($logger) vs OR main __construct($logger, $instants) [lib/Service/OperatorEvaluator.php:61]; applySingleOperator(): not on OR main
- `tests/Stubs/Service/SchemaDeletionService.php:25` `OCA\OpenRegister\Service\SchemaDeletionService` (exists): cascadeDeleteSchema($schema, $archivalOverride) vs OR main cascadeDeleteSchema($schema, $override) [lib/Service/SchemaDeletionService.php:344]

### Implements / extends OR classes: 76

- `lib/BackgroundJob/ForwardedJob.php:32` ForwardedJob extends `OCA\OpenRegister\BackgroundJob\ActorForwardedJob` (exists)
- `lib/Lifecycle/AccessibilityStatementPublishGuard.php:62` AccessibilityStatementPublishGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/Action/AssessmentAutoScoreAction.php:47` AssessmentAutoScoreAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/Action/ConferenceInvitationAction.php:49` ConferenceInvitationAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/Action/FraudCaseAppealDeadlineAction.php:47` FraudCaseAppealDeadlineAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/Action/LearningRecordExportGenerateAction.php:46` LearningRecordExportGenerateAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/Action/LearningRecordImportParseAction.php:46` LearningRecordImportParseAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/Action/LinkCoveringCorrectionAction.php:49` LinkCoveringCorrectionAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/Action/PokParentSignatureStampAction.php:46` PokParentSignatureStampAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/Action/StampTransitionActorAction.php:52` StampTransitionActorAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/Action/SupersedePriorLearningPlanAction.php:48` SupersedePriorLearningPlanAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/Action/TenantSignatureAction.php:48` TenantSignatureAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/Action/WalletRevocationPropagationAction.php:47` WalletRevocationPropagationAction implements `OCA\OpenRegister\Lifecycle\LifecycleActionInterface` (exists)
- `lib/Lifecycle/AdmissionsDecisionGuard.php:70` AdmissionsDecisionGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/AssessmentGradeGuard.php:53` AssessmentGradeGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/AssessmentPublishGuard.php:95` AssessmentPublishGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/AssessmentScoringHandler.php:58` AssessmentScoringHandler implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/AssignmentPublishGuard.php:53` AssignmentPublishGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/AttendanceFlagReportGuard.php:54` AttendanceFlagReportGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/AttendanceThresholdCrossingGuard.php:57` AttendanceThresholdCrossingGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/AttestationSigningGuard.php:63` AttestationSigningGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/BpvConfirmationGuard.php:59` BpvConfirmationGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/BsaDecisionGuard.php:62` BsaDecisionGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/BsaWarningSigningGuard.php:67` BsaWarningSigningGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/CohortMembershipGuard.php:49` CohortMembershipGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/ConferenceSignupGuardianGuard.php:56` ConferenceSignupGuardianGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/ConferenceSlotTeacherGuard.php:51` ConferenceSlotTeacherGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/CourseEvaluationEligibilityGuard.php:61` CourseEvaluationEligibilityGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/CoursePublishGuard.php:45` CoursePublishGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/DataCorrectionDecisionGuard.php:44` DataCorrectionDecisionGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/EntitlementPaymentSettledGuard.php:67` EntitlementPaymentSettledGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/ExamAccommodationApprovalGuard.php:58` ExamAccommodationApprovalGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/ExemptionDecisionGuard.php:54` ExemptionDecisionGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/ExternalTrainingVerificationGuard.php:64` ExternalTrainingVerificationGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/FeeItemVoluntaryEntitlementGuard.php:70` FeeItemVoluntaryEntitlementGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/FraudCaseBlockGuard.php:56` FraudCaseBlockGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/FraudCaseDecisionGuard.php:57` FraudCaseDecisionGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/FraudCaseHearingGuard.php:51` FraudCaseHearingGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/FraudCaseInvalidationGuard.php:52` FraudCaseInvalidationGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/HourPlanActivationGuard.php:44` HourPlanActivationGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/InvigilatorResponseGuard.php:39` InvigilatorResponseGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/LearnerMergeGuard.php:38` LearnerMergeGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/LearnerOwnRowGuard.php:46` LearnerOwnRowGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/LearningPlanSignatureGuard.php:55` LearningPlanSignatureGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/LvsResultVerifyGuard.php:55` LvsResultVerifyGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/MunicipalityFeedbackGuard.php:65` MunicipalityFeedbackGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/OsoDossierReviewGuard.php:57` OsoDossierReviewGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/OsoImportAcceptGuard.php:62` OsoImportAcceptGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/OsoImportRejectGuard.php:59` OsoImportRejectGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/PokActivationGuard.php:67` PokActivationGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/PortfolioShareGrantHandler.php:84` PortfolioShareGrantHandler implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/PortfolioSubmissionGuard.php:60` PortfolioSubmissionGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/ProgrammePublishGuard.php:48` ProgrammePublishGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/PupilVoiceGuard.php:58` PupilVoiceGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/RegulationExemptionDecisionGuard.php:43` RegulationExemptionDecisionGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/ReportCardFinaliseGuard.php:51` ReportCardFinaliseGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/ReportCardReopenGuard.php:53` ReportCardReopenGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/ReportCardVisibilityGuard.php:68` ReportCardVisibilityGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/ReportPeriodComposeGuard.php:62` ReportPeriodComposeGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/ReportPeriodLockGuard.php:89` ReportPeriodLockGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/RubricScoresCompletionGuard.php:58` RubricScoresCompletionGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/SchoolAdviesFinalizeGuard.php:55` SchoolAdviesFinalizeGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/SessionChangeGuard.php:58` SessionChangeGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/SubjectChoiceConsentGuard.php:64` SubjectChoiceConsentGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/SubmissionWindowGuard.php:63` SubmissionWindowGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Mcp/LearniqScannableServices.php:38` LearniqScannableServices implements `OCA\OpenRegister\Mcp\IMcpScannableServices` (exists)
- `lib/Repair/InitializeActions.php:38` InitializeActions extends `OCA\OpenRegister\AppHost\Repair\GenericInitializeActions` (exists)
- `lib/Sections/SettingsSection.php:35` SettingsSection extends `OCA\OpenRegister\AppHost\Settings\GenericSettingsSection` (exists)
- `lib/Service/ActionAuthService.php:38` ActionAuthService extends `OCA\OpenRegister\AppHost\Service\GenericActionAuthService` (exists)
- `lib/Service/LearningRecordExportService.php:60` LearningRecordExportService implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Service/LearningRecordImportService.php:58` LearningRecordImportService implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Service/ReportCardPdfDelegationService.php:86` ReportCardPdfDelegationService implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Service/WalletClaimSyncService.php:61` WalletClaimSyncService implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Service/WalletOfferDelegationService.php:92` WalletOfferDelegationService implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Service/WalletRevocationPropagationService.php:78` WalletRevocationPropagationService implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Settings/AdminSettings.php:37` AdminSettings extends `OCA\OpenRegister\AppHost\Settings\GenericAdminSettings` (exists)

### OR event listeners: 97 registrations

- `OCA\OpenRegister\Event\ObjectCreatedEvent` (exists, OR main constructed): 7x, e.g. `lib/AppInfo/Registrar/ConferenceListenerRegistrar.php:81`
- `OCA\OpenRegister\Event\ObjectCreatingEvent` (exists, OR main constructed): 29x, e.g. `lib/AppInfo/Registrar/ConferenceListenerRegistrar.php:66`
- `OCA\OpenRegister\Event\ObjectDeletingEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Registrar/EvidenceFreezeListenerRegistrar.php:65`
- `OCA\OpenRegister\Event\ObjectTransitionedEvent` (exists, OR main constructed): 25x, e.g. `lib/AppInfo/Registrar/CaseListenerRegistrar.php:102`
- `OCA\OpenRegister\Event\ObjectUpdatedEvent` (exists, OR main constructed): 7x, e.g. `lib/AppInfo/Registrar/ConferenceListenerRegistrar.php:77`
- `OCA\OpenRegister\Event\ObjectUpdatingEvent` (exists, OR main constructed): 28x, e.g. `lib/AppInfo/Registrar/AttemptLimitListenerRegistrar.php:56`

### JS/manifest URLs with no matching OR main route: 1

- `src/views/LearniqSettings.vue:757` `/apps/openregister/api/avg/verantwoording`

<details><summary>Dynamic call sites in lib/ (method_exists, is_callable, $x->$m(), call_user_func): 130. Always suspect: a renamed OR method makes these silently skip.</summary>

- `lib/BackgroundJob/LtiAgsScorePollJob.php:563` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Controller/AiProcessingDisclosureController.php:266` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Controller/ElectiveController.php:270` `if (method_exists($exception, 'getErrors') === true) {`
- `lib/Controller/LessonNextStepController.php:291` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Controller/LtiToolPlacementController.php:251` `if (method_exists($event, 'getRefusal') === true) {`
- `lib/Controller/LtiToolPlacementController.php:263` `if (method_exists($event, 'getLoginInitiation') === true) {`
- `lib/Controller/PrivacyGovernanceController.php:275` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Lifecycle/AccessibilityStatementPublishGuard.php:423` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Lifecycle/HourPlanActivationGuard.php:148` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Listener/CohortTalkMembershipHandler.php:238` `if ($participantService === null \|\| method_exists($participantService, 'addUsers') === false) {`
- `lib/Listener/CohortTalkMembershipHandler.php:271` `if ($participantService === null \|\| method_exists($participantService, 'removeUser') === false) {`
- `lib/Listener/CohortTalkMembershipHandler.php:344` `if (method_exists($manager, 'getRoomByToken') === false) {`
- `lib/Listener/ConferenceScheduleGenerator.php:210` `$existingSlots = array_map([$this, 'normalise'], $existingSlots);`
- `lib/Listener/ConferenceScheduleGenerator.php:243` `$signups = array_map([$this, 'normalise'], $signups);`
- `lib/Listener/ExchangeJobConcludedListener.php:124` `if (method_exists($event, $getter) === false) {`
- `lib/Listener/ExchangeJobConcludedListener.php:128` `return $event->$getter();`
- `lib/Listener/HourWeekSubmissionStamp.php:234` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Listener/HourWeekTotalRollup.php:169` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Listener/HourWeekTotalRollup.php:229` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Listener/PeerFeedbackAggregator.php:319` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Listener/WalletOfferConcludedListener.php:99` `if (method_exists($event, 'getExternalReference') === false`
- `lib/Listener/WalletOfferConcludedListener.php:100` `\|\| method_exists($event, 'getStatus') === false`
- `lib/Listener/WerkprocesAssessmentLearnerStamp.php:210` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Portal/BpvPortalInvitation.php:233` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Portal/CallerOrganisations.php:141` `static fn (mixed $organisation): bool => is_object($organisation) === true && is_callable([$organisation, 'getSlug']) === true`
- `lib/Portal/CallerOrganisations.php:141` `static fn (mixed $organisation): bool => is_object($organisation) === true && is_callable([$organisation, 'getSlug']) === true`
- `lib/Portal/EmployerAccounts.php:137` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Portal/EmployerCompanyTenancy.php:243` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Portal/EmployerPortalInvitation.php:252` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Portal/ExamplePortalContent.php:345` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Portal/GuardianDocuments.php:148` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Portal/PortalMessageContacts.php:274` `} else if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Portal/PublicIndexReads.php:176` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Portal/ReadsOrganisationTenant.php:47` `if (is_callable([$organisation, 'getSlug']) === false \|\| is_callable([$organisation, 'getUuid']) === false) {`
- `lib/Portal/ReadsOrganisationTenant.php:47` `if (is_callable([$organisation, 'getSlug']) === false \|\| is_callable([$organisation, 'getUuid']) === false) {`
- `lib/Portal/ReadsOrganisationTenant.php:47` `if (is_callable([$organisation, 'getSlug']) === false \|\| is_callable([$organisation, 'getUuid']) === false) {`
- `lib/Portal/ReadsOrganisationTenant.php:47` `if (is_callable([$organisation, 'getSlug']) === false \|\| is_callable([$organisation, 'getUuid']) === false) {`
- `lib/Repair/BackfillExcuseRequestTeachers.php:154` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Repair/BackfillGradeEntryLearnerRef.php:153` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Repair/BackfillLearnerProfileNames.php:160` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Repair/BackfillLvsResultLearnerRef.php:157` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Repair/BackfillReadableCopies.php:173` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Repair/BackfillReportCardGradeLines.php:218` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Repair/BackfillRequiredLearnerRefs.php:175` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Repair/BackfillSubmissionLearnerRefs.php:156` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Repair/BackfillWerkprocesAssessmentLearner.php:152` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Repair/MigrateDataSubjectRequestsToOpenRegister.php:363` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Repair/MoveInstanceIdTenantToDefaultTenant.php:272` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Repair/MoveTeacherNotesOutOfLessons.php:241` `} else if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/Accessibility/ConformanceEvidence.php:403` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/Attendance/AttendanceSummaryService.php:445` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/Attendance/RollCallReader.php:305` `} else if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/Catalogue/CatalogueReader.php:308` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/Catalogue/CatalogueSignUpService.php:305` `if (is_object($saved) === true && method_exists($saved, 'getUuid') === true) {`
- `lib/Service/Catalogue/CatalogueSignUpService.php:342` `if (method_exists($exception, 'getErrors') === false) {`
- `lib/Service/CheckIn/CheckInService.php:204` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/CohortNameRestamp.php:101` `if (is_object($enrolment) === true && method_exists($enrolment, 'jsonSerialize') === true) {`
- `lib/Service/ContactHoursReader.php:177` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Course/CourseTemplateService.php:372` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/CourseEvaluationAnswerService.php:396` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/CoursePackage/CoursePackageObjectWriter.php:239` `if (is_object($saved) === true && is_callable([$saved, 'getUuid']) === true) {`
- `lib/Service/CoursePackage/CoursePackageObjectWriter.php:239` `if (is_object($saved) === true && is_callable([$saved, 'getUuid']) === true) {`
- `lib/Service/CoursePackageExportService.php:442` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/CourseStore/CourseStoreDescriptor.php:170` `return method_exists(StoreDescriptor::class, $method);`
- `lib/Service/CourseStore/CourseStorePublisher.php:162` `return method_exists($this->storeService, $method);`
- `lib/Service/CourseStore/CourseStorePublisher.php:193` `if (is_object($authorizer) === false \|\| method_exists($authorizer, 'canPublish') === false) {`
- `lib/Service/CredentialReissueService.php:281` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/CredentialReissueService.php:314` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/DisplayScreenBoard.php:333` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/ElectiveService.php:363` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/EnrolmentForecastReader.php:211` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/EuropassIssuer.php:190` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/ExternalTrainingImport.php:333` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/ExternalTrainingLearnerMatch.php:124` `return [$profiles, 'No learner in your organisation has this email address.'];`
- `lib/Service/ExternalTrainingLearnerMatch.php:234` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/ExternalTrainingService.php:423` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/HourPlanActivityService.php:288` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/LearnerGroupLabel.php:260` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/LearnerGroupLabelRestamp.php:161` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/LearnerRefResolver.php:314` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/LearningRecordAggregationService.php:431` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/LearningRecordExportService.php:546` `} elseif (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/LearningRecordImportIntakeService.php:246` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/LessonNoteReader.php:333` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/LessonOnboarding/DocumentLessonReader.php:80` `return class_exists($this->extractorClass) === true && method_exists($this->extractorClass, 'extract') === true;`
- `lib/Service/LessonOnboarding/LessonOnboardingImporter.php:512` `if (is_object($lesson) === true && method_exists($lesson, 'jsonSerialize') === true) {`
- `lib/Service/LessonOnboarding/OfficeLessonExtractor.php:179` `if (class_exists($this->wordExtractorClass) === false \|\| method_exists($this->wordExtractorClass, 'extract') === false) {`
- `lib/Service/LessonOnboarding/PresentationLessonReader.php:75` `return class_exists($this->extractorClass) === true && method_exists($this->extractorClass, 'extract') === true;`
- `lib/Service/ListenerSchemaResolver.php:156` `if ($entity === null \|\| is_callable([$entity, 'getSchema']) === false) {`
- `lib/Service/ListenerSchemaResolver.php:156` `if ($entity === null \|\| is_callable([$entity, 'getSchema']) === false) {`
- `lib/Service/ListenerSchemaResolver.php:192` `if ($entity === null \|\| is_callable([$entity, 'getSchema']) === false) {`
- `lib/Service/ListenerSchemaResolver.php:192` `if ($entity === null \|\| is_callable([$entity, 'getSchema']) === false) {`
- `lib/Service/ListenerSchemaResolver.php:217` `if ($entity === null \|\| is_callable([$entity, 'getRegister']) === false) {`
- `lib/Service/ListenerSchemaResolver.php:217` `if ($entity === null \|\| is_callable([$entity, 'getRegister']) === false) {`
- `lib/Service/ListenerSchemaResolver.php:251` `if ($entity === null \|\| is_callable([$entity, 'getRegister']) === false) {`
- `lib/Service/ListenerSchemaResolver.php:251` `if ($entity === null \|\| is_callable([$entity, 'getRegister']) === false) {`
- `lib/Service/ListenerSchemaResolver.php:404` `if (is_object($entity) === true && is_callable([$entity, 'getSlug']) === true) {`
- `lib/Service/ListenerSchemaResolver.php:404` `if (is_object($entity) === true && is_callable([$entity, 'getSlug']) === true) {`
- `lib/Service/OpenMaterial/CommercialCourseCheck.php:105` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/PeerReviewAllocationService.php:482` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/PersonalTimetableService.php:340` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Portal/BpvPlacementSteps.php:382` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/Portal/EmployerBookingProjection.php:281` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/Portal/PortalAttemptReader.php:219` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Portal/PortalHourWeekApproval.php:293` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/Portal/PortalSubmissionHandIn.php:144` `if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {`
- `lib/Service/Portal/PortalSubmissionHandIn.php:181` `if (method_exists($exception, 'getErrors') === false) {`
- `lib/Service/Portal/PortalWerkprocesAssessment.php:300` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/Programme/ProgrammeProgress.php:209` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/PupilGroupTeachers.php:171` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/QtiExportService.php:228` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/ReadableCopies.php:337` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/ReportCardGradeLines.php:210` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/ReportSubjectGradeRows.php:261` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/RolloverService.php:299` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/RoomUtilisationService.php:376` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/SeedProfileService.php:308` `if (method_exists($service, 'softDeleteAppImports') === false) {`
- `lib/Service/SegmentService.php:439` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/SessionChangeBatchService.php:340` `if (method_exists($exception, 'getErrors') === false) {`
- `lib/Service/SharedCodeFilter.php:157` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/SovereigntyPolicyService.php:134` `if (is_object($row) === false \|\| method_exists($row, 'jsonSerialize') === false) {`
- `lib/Service/StandbyCalendar.php:78` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/SubmissionMarkAllocationService.php:302` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`
- `lib/Service/SubmissionMarkReader.php:158` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/SubstitutionCandidateService.php:358` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/TimetableDirectory.php:369` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/WorkGroup/WorkGroupReader.php:185` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/XapiDocumentStore.php:463` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Timetabling/SessionSeries.php:272` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Timetabling/Source/LocalSessionTimetableSource.php:234` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`

</details>

Unresolved call sites (receiver type unknown, magic, or spread args): 1, listed in `learniq.json`.

## portaliq

Local dir `portaliq`, branch `development`, sha `795da17ba3f56c7ce6f37607a37ac0db0071c822`.

### Runtime breaks (lib): 6

| file:line | category | app calls | OR main now | fix |
|---|---|---|---|---|
| `lib/Service/Intake/DestinationDraft.php:98` | call-shape | $service->saveDraft(destination: $destination, mapping: null, payload: $payload, subject: null, draftId: self::orNull(value: $draftId), scope: $scope, owner: $owner) | OCA\OpenRegister\Service\Form\FormSubmitService::saveDraft(FormSubmission $submission): array (lib/Service/Form/FormSubmitService.php:186) | UNKNOWN-NAMED-ARG: `destination:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `mapping:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `payload:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `subject:` (Error: Unknown named parameter);  |
| `lib/Service/Intake/DestinationDraft.php:137` | call-shape | $service->findDraft(destination: $destination, subject: null, owner: $owner, match: $match) | OCA\OpenRegister\Service\Form\FormSubmitService::findDraft(array $destination, FormSubmitter $submitter, array $match = []): array\|null (lib/Service/Form/FormSubmitService.php:467) | UNKNOWN-NAMED-ARG: `subject:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `owner:` (Error: Unknown named parameter); MISSING-REQUIRED-ARG: `$submitter` (ArgumentCountError) |
| `lib/Service/Intake/DestinationDraft.php:171` | call-shape | $service->submit(destination: $destination, mapping: null, payload: $payload, subject: null, idempotencyKey: self::orNull(value: $idempotencyKey), draftId: $draftId, owner: $own) | OCA\OpenRegister\Service\Form\FormSubmitService::submit(FormSubmission $submission, ?string $idempotencyKey = null): array (lib/Service/Form/FormSubmitService.php:169) | UNKNOWN-NAMED-ARG: `destination:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `mapping:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `payload:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `subject:` (Error: Unknown named parameter);  |
| `lib/Service/Intake/DestinationDraft.php:210` | call-shape | $discard->discard(destination: $destination, draftId: $draftId, owner: $owner) | OCA\OpenRegister\Service\Object\DraftDiscard::discard(DiscardDraftRequest $request): bool (lib/Service/Object/DraftDiscard.php:68) | UNKNOWN-NAMED-ARG: `destination:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `draftId:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `owner:` (Error: Unknown named parameter); MISSING-REQUIRED-ARG: `$request` (ArgumentCountError) |
| `lib/Service/Intake/DestinationSubmit.php:104` | call-shape | $service->submit(destination: $destination, mapping: $mapping, payload: $payload, subject: null, idempotencyKey: $key, scope: $scope) | OCA\OpenRegister\Service\Form\FormSubmitService::submit(FormSubmission $submission, ?string $idempotencyKey = null): array (lib/Service/Form/FormSubmitService.php:169) | UNKNOWN-NAMED-ARG: `destination:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `mapping:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `payload:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `subject:` (Error: Unknown named parameter);  |
| `lib/Listener/FormDestinationDependentsListener.php:133` | constructor-shape | new FormDependent(id: $bindingId, app: Application::APP_ID, title: $this->titleOf(binding: $binding, render: $render), author: $this->authorOf(binding: $binding), mapping: $this-) | OCA\OpenRegister\Service\Form\FormDependent::__construct(PublishedForm $form, public readonly ?string $author, public readonly array $mapping, public readonly ?Closure $unpublish = null) (lib/Service/Form/FormDependent.php:73) | UNKNOWN-NAMED-ARG: `id:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `app:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `title:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `audience:` (Error: Unknown named parameter); MISSING-REQUIR |

### Test-suite breaks (tests): 1

| file:line | category | stub | app calls | OR main now | fix |
|---|---|---|---|---|---|
| `tests/Unit/Listener/FormDestinationDependentsListenerTest.php:171` | constructor-shape |  | new FormDependentsCheck($dispatcher, new FormDestinationValidator($l10n, new FormFieldRules()), $config, $this->createMock(IManager::class), $this->createMock(L | OCA\OpenRegister\Service\Form\FormDependentsCheck::__construct(FormDependentsSignals $signals, private readonly FormDestinationValidator $validator, private readonly IAppConfig $appConfig, private rea | SILENT-EXTRA-ARGS: 5 positional args, method takes 4 (extra args are silently dropped) |

### Test stubs of OR classes that drifted from OR main: 1 of 6

- `tests/Stubs/Mcp/AbstractToolHandler.php:35` `OCA\OpenRegister\Mcp\AbstractToolHandler` (missing): class no longer exists on OR main

### Implements / extends OR classes: 1

- `lib/Service/Proposals/ChangeProposalsProvider.php:44` ChangeProposalsProvider implements `OCA\OpenRegister\Service\Integration\IntegrationProvider` (exists)

### OR event listeners: 2 registrations

- `OCA\OpenRegister\Event\FormDestinationDependentsEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/FormDependentsRegistrar.php:53`
- `OCA\OpenRegister\Event\ObjectCreatedEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Application.php:166`

<details><summary>Dynamic call sites in lib/ (method_exists, is_callable, $x->$m(), call_user_func): 52. Always suspect: a renamed OR method makes these silently skip.</summary>

- `lib/AppInfo/StorePlaneRegistrar.php:142` `\|\| method_exists('OCA\\OpenRegister\\AppHost\\Bootstrap', 'aliasStoreController') === false`
- `lib/Contribution/PortalContributionRegistry.php:135` `if ($provider === null \|\| method_exists($provider, 'getContribution') === false) {`
- `lib/Contribution/PortalContributionRegistry.php:241` `if ($provider === null \|\| method_exists($provider, 'getContribution') === false) {`
- `lib/Contribution/PortalContributionRegistry.php:272` `if ($provider === null \|\| method_exists($provider, 'getContribution') === false) {`
- `lib/Contribution/PortalContributionRegistry.php:309` `if (method_exists($provider, 'getAudiences') === true) {`
- `lib/Contribution/PortalContributionRegistry.php:318` `if (method_exists($provider, 'getAudience') === true) {`
- `lib/Contribution/PortalContributionRegistry.php:344` `if (method_exists($provider, 'getAudiences') === true) {`
- `lib/Contribution/PortalContributionRegistry.php:353` `if (method_exists($provider, 'getAudience') === true) {`
- `lib/Controller/ObjectArrayRow.php:46` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Repair/BackfillCmsPortalReference.php:247` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Repair/MovePortalAuditEntries.php:183` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Repair/PagedSchemaRead.php:62` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Actions/ActionHistory.php:158` `if (method_exists($mapper, 'findChangesForObject') === false) {`
- `lib/Service/Availability/AvailabilityStore.php:278` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/CaseTypeReader.php:168` `if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Service/CollectionDocuments.php:166` `if ($provider === null \|\| (new TimelineProviderMethod())->accepts(name: $method) === false \|\| method_exists($provider, $method) === false) {`
- `lib/Service/CollectionDocuments.php:177` `$allowed = $provider->{$method}(`
- `lib/Service/ExampleResident/ExampleSetResidents.php:257` `if (is_object($service) === true && method_exists($service, 'read') === true) {`
- `lib/Service/GuardianAccountDirectory.php:193` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Identity/PortalAccountsByClaim.php:224` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Identity/PortalRegisteredDetailsService.php:237` `if (is_object($provider) === true && method_exists($provider, $method) === true) {`
- `lib/Service/Identity/PortalRegisteredDetailsService.php:238` `$answer = (array)$provider->{$method}($identifier);`
- `lib/Service/InstanceLoopback.php:357` `if (method_exists($failure, 'getResponse') === true && $failure->getResponse() !== null) {`
- `lib/Service/InstanceLoopback.php:362` `if (method_exists($failure, 'getHandlerContext') === true) {`
- `lib/Service/Intake/DestinationDraft.php:231` `if ($subjectRef === '' \|\| is_callable($factory) === false) {`
- `lib/Service/Intake/DestinationSubmit.php:172` `if (method_exists($exception, 'getStatus') === true && method_exists($exception, 'getFindings') === true) {`
- `lib/Service/Intake/DestinationSubmit.php:172` `if (method_exists($exception, 'getStatus') === true && method_exists($exception, 'getFindings') === true) {`
- `lib/Service/Intake/PortalAddressLookup.php:104` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Intake/PortalPaymentIntents.php:91` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/Intake/PortalReferenceLists.php:140` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/NewsAudienceOptions.php:237` `if (is_object($entity) === false \|\| method_exists($entity, 'getProperties') === false) {`
- `lib/Service/ObjectFileLookup.php:109` `if (method_exists($file, 'getName') === true) {`
- `lib/Service/ObjectFileLookup.php:113` `if (method_exists($file, 'getSize') === true) {`
- `lib/Service/ObjectFileLookup.php:117` `if (method_exists($file, 'getId') === true) {`
- `lib/Service/ObjectRowNormaliser.php:46` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/PortalAuditHook.php:117` `if (is_object($service) === true && method_exists($service, 'record') === true) {`
- `lib/Service/PortalFilteredRows.php:201` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/PortalObjectReader.php:1447` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/PortalOrganisationConfigService.php:563` `if ($mapper === null \|\| method_exists($mapper, 'findBySlug') === false) {`
- `lib/Service/PortalPdfExport.php:99` `if (method_exists($service, self::AVAILABILITY_METHOD) === false) {`
- `lib/Service/PortalPdfExport.php:309` `if (is_object($service) === true && method_exists($service, self::RENDER_METHOD) === true) {`
- `lib/Service/PortalSchemaReader.php:92` `if (is_object($schema) === true && method_exists($schema, 'jsonSerialize') === true) {`
- `lib/Service/RowAsArray.php:48` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Tenancy/OrganisationScopedObjectService.php:64` `operation: static fn () => $objectService->$name(...$arguments)`
- `lib/Service/Tenancy/PortalOrganisationRestamp.php:212` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Tenancy/PortalTenantContext.php:90` `if ($objectService === null \|\| $organisation === '' \|\| method_exists($objectService, self::ACT_FOR) === false) {`
- `lib/Service/Tenancy/PortalTenantContext.php:121` `if ($objectService === null \|\| $organisationUuid === '' \|\| method_exists($objectService, self::ACT_FOR) === false) {`
- `lib/Service/Theme/PortalCustomThemeSets.php:208` `if (method_exists($validator, 'hasDisallowedSelector') === true`
- `lib/Service/Theme/PortalCustomThemeSets.php:224` `if (method_exists($validator, 'getLastError') === true) {`
- `lib/Service/Theme/PortalCustomThemeSets.php:340` `if (is_object($service) === true && method_exists($service, $method) === true) {`
- `lib/Service/Traffic/ExpiredRowPurge.php:67` `if (method_exists($objectService, 'purgeExpiredObjectsRaw') === true) {`
- `lib/Service/Traffic/TrafficEventStore.php:141` `if (method_exists($objectService, 'appendObjectsRaw') === true) {`

</details>

Unresolved call sites (receiver type unknown, magic, or spread args): 16, listed in `portaliq.json`.

## decidiq

Local dir `decidesk`, branch `HEAD`, sha `8375adfca6efb4865df0b8b4af9cb72489538813`.

### Runtime breaks (lib): 5

| file:line | category | app calls | OR main now | fix |
|---|---|---|---|---|
| `lib/Service/AgendaService.php:180` | dynamic-method-gone (SILENT) | method_exists($this->calendarEventService, 'updateMeetingEvent') on OCA\OpenRegister\Service\CalendarEventService | OCA\OpenRegister\Service\CalendarEventService (lib/Service/CalendarEventService.php) has no updateMeetingEvent: branch silently skipped | call the replacement method directly |
| `lib/Service/DecisionIntegrationService.php:548` | dynamic-name-nowhere (SILENT) | method_exists($registry, 'isRegisteredConsumer' (receiver $registry) | no method named isRegisteredConsumer exists in any OR main class (nor in this app or NC server): the guarded branch never runs | find what replaced it on OR main and call it directly |
| `lib/Service/ProcessTemplateService.php:326` | call-shape | $validator->validate(states: array_values(array_filter((array)($stateMachine['states'] ?? []), 'is_array')), initial: $initialState, transitions: $transitions, knownGuards: self::KN) | OCA\OpenRegister\Service\Lifecycle\LifecycleTransitionsValidator::validate(LifecycleGraph $graph): array (lib/Service/Lifecycle/LifecycleTransitionsValidator.php:59) | UNKNOWN-NAMED-ARG: `states:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `initial:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `transitions:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `knownGuards:` (Error: Unknown named parameter |
| `lib/Service/AgendaService.php:181` | method-missing | $this->calendarEventService->updateMeetingEvent() on OCA\OpenRegister\Service\CalendarEventService | OCA\OpenRegister\Service\CalendarEventService (lib/Service/CalendarEventService.php) has no method updateMeetingEvent | find the replacement method on OR main |
| `lib/Service/MailVoteReplyProcessor.php:465` | method-missing | $notificationService->createNotification() on OCA\OpenRegister\Service\NotificationService | OCA\OpenRegister\Service\NotificationService (lib/Service/NotificationService.php) has no method createNotification | find the replacement method on OR main |

### Test-suite breaks (tests): 6

| file:line | category | stub | app calls | OR main now | fix |
|---|---|---|---|---|---|
| `tests/Unit/AppInfo/FlowNodeRegistrarTest.php:84` | string-lookup-missing |  | string('OCA\OpenRegister\Service\Flow\NoSuchEngineEvent') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `tests/Integration/Service/ActionItemAnalyticsServiceIntegrationTest.php:98` | call-shape | stub | $this->objectService->deleteObject(register: 'decidiq', schema: 'action-item', id: $id) | OCA\OpenRegister\Service\ObjectService::deleteObject(string $uuid, Register \| string \| int \| null $register=null, Schema \| string \| int \| null $schema=null, bool $_rbac=true, bool $_multitenancy=true, | UNKNOWN-NAMED-ARG: `id:` (Error: Unknown named parameter); MISSING-REQUIRED-ARG: `$uuid` (ArgumentCountError) |
| `tests/Unit/Listener/MinutesApprovedListenerTest.php:73` | call-shape | stub | $schema->setSlug(['12' => 'minutes', '41' => 'agenda-item'][(string)$id]) | OCA\OpenRegister\Db\Schema::setSlug(?string $slug): void (lib/Db/Schema.php:1485) | TYPE: passes array literal `['xx' => 'xxxxxxx', 'xx' => 'xxxxxxxxxxx'][(string)$id]` to `$slug` typed `null\|string` (TypeError) |
| `tests/Unit/Flow/DecidiqFlowNodeListenerTest.php:59` | constructor-shape | stub | new FlowNodeRegistry() | OCA\OpenRegister\Service\Flow\FlowNodeRegistry::__construct(private readonly IEventDispatcher $dispatcher, private readonly LoggerInterface $logger, private readonly ?IURLGenerator $urls = null, priva | MISSING-REQUIRED-ARG: `$dispatcher` (ArgumentCountError); MISSING-REQUIRED-ARG: `$logger` (ArgumentCountError) |
| `tests/Unit/Flow/DecidiqFlowNodeListenerTest.php:69` | constructor-shape | stub | new FlowNodeRegistry() | OCA\OpenRegister\Service\Flow\FlowNodeRegistry::__construct(private readonly IEventDispatcher $dispatcher, private readonly LoggerInterface $logger, private readonly ?IURLGenerator $urls = null, priva | MISSING-REQUIRED-ARG: `$dispatcher` (ArgumentCountError); MISSING-REQUIRED-ARG: `$logger` (ArgumentCountError) |
| `tests/Integration/Mcp/DecidiqToolProviderIntegrationTest.php:63` | class-missing |  | \OCA\OpenRegister\Mcp\McpToolsService | class does not exist on OR main (no alias) | find the new FQCN on OR main |

### Test stubs of OR classes that drifted from OR main: 8 of 35

- `tests/Stubs/Db/AuditTrailMapper.php:39` `OCA\OpenRegister\Db\AuditTrailMapper` (exists): createAuditTrailEntry($object, $action, $context) vs OR main createAuditTrailEntry($object, $action, $context, $actorId, $actorName, $ipAddress) [lib/Db/AuditTrailMapper.php:2841]
- `tests/Stubs/Event/DeepLinkRegistrationEvent.php:25` `OCA\OpenRegister\Event\DeepLinkRegistrationEvent` (exists): register($appId, $registerSlug, $schemaSlug, $urlTemplate) vs OR main register($appId, $registerSlug, $schemaSlug, $urlTemplate, $icon, $displayName) [lib/Event/DeepLinkRegistrationEvent.php:79]
- `tests/Stubs/Event/ObjectCreatedEvent.php:23` `OCA\OpenRegister\Event\ObjectCreatedEvent` (exists): __construct($object) vs OR main __construct($object) [lib/Event/AbstractObjectEntityEvent.php:39]
- `tests/Stubs/Event/ObjectCreatingEvent.php:24` `OCA\OpenRegister\Event\ObjectCreatingEvent` (exists): __construct($object) vs OR main __construct($object) [lib/Event/AbstractObjectEntityEvent.php:39]
- `tests/Stubs/Event/ObjectDeletedEvent.php:21` `OCA\OpenRegister\Event\ObjectDeletedEvent` (exists): __construct($object) vs OR main __construct($object) [lib/Event/AbstractObjectEntityEvent.php:39]
- `tests/Stubs/Event/ObjectUpdatedEvent.php:23` `OCA\OpenRegister\Event\ObjectUpdatedEvent` (exists): __construct($newObject, $oldObject) vs OR main __construct($newObject, $oldObject) [lib/Event/ObjectUpdatedEvent.php:56]
- `tests/Stubs/Service/Lifecycle/LifecycleTransitionsValidator.php:60` `OCA\OpenRegister\Service\Lifecycle\LifecycleTransitionsValidator` (exists): validate($states, $initial, $transitions, $knownGuards) vs OR main validate($graph) [lib/Service/Lifecycle/LifecycleTransitionsValidator.php:59]; initialErrors(): not on OR main; unreachableErrors(): not on OR main; inspectTransition(): not on OR main; endpoint(): not on OR main; guardErrors(): not on OR main ...
- `tests/Stubs/Service/ObjectService.php:48` `OCA\OpenRegister\Service\ObjectService` (exists): find($id, $_extend, $files, $register, $schema, $_rbac, $_multitenancy, $_render) vs OR main find($id, $_extend, $files, $register, $schema, $_rbac, $_multitenancy, $_render, $_audit) [lib/Service/ObjectService.php:1264]; saveObject($object, $extend, $register, $schema, $uuid, $_rbac, $_multitenancy, $silent, $uploadedFiles, $currentUser, $failIfExists) vs OR main saveObject($object, $extend, $register, $schema, $uuid, $_rbac, $_multitenancy, $silent, $_validation, $uploadedFiles, $currentUser, $failIfExists, $_unowned, $_dedupOverride, $_owner) [lib/Service/ObjectService.php:2112]; deleteObject($uuid, $register, $schema, $_rbac, $_multitenancy, $_retentionSweep, $currentUser) vs OR main deleteObject($uuid, $register, $schema, $_rbac, $_multitenancy, $_retentionSweep, $currentUser, $permanent) [lib/Service/ObjectService.php:3827]

### Implements / extends OR classes: 5

- `lib/Flow/DecidiqRequestDecisionNode.php:62` DecidiqRequestDecisionNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Flow/DecidiqRequestDecisionNode.php:62` DecidiqRequestDecisionNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys` (exists)
- `lib/Mcp/DecidiqToolProvider.php:56` DecidiqToolProvider implements `OCA\OpenRegister\Mcp\IMcpToolProvider` (exists)
- `tests/Stubs/Db/ObjectEntity.php:51` ObjectEntity implements `OCA\OpenRegister\Contract\ObjectEntityInterface` (exists)
- `tests/Unit/Support/FakeSlugResolver.php:43` FakeSlugResolver implements `OCA\OpenRegister\Contract\RegisterSlugResolverInterface` (exists)

### OR event listeners: 5 registrations

- `OCA\OpenRegister\Event\DeepLinkRegistrationEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Registrar/AppHostRegistrar.php:141`
- `OCA\OpenRegister\Event\ObjectCreatingEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Registrar/PlatformIntegrationRegistrar.php:148`
- `OCA\OpenRegister\Event\RegisterLeafProvidersEvent` (exists, OR main constructed): 2x, e.g. `lib/AppInfo/Registrar/IntegrationLeafRegistrar.php:82`
- `OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Registrar/FlowNodeRegistrar.php:84`

<details><summary>Dynamic call sites in lib/ (method_exists, is_callable, $x->$m(), call_user_func): 130. Always suspect: a renamed OR method makes these silently skip.</summary>

- `lib/BackgroundJob/ConvertPaperToPdfJob.php:157` `if (is_object($pdf) === false \|\| method_exists($pdf, 'getId') === false) {`
- `lib/BackgroundJob/TranscriptRetentionJob.php:463` `if (method_exists($entity, $method) === true) {`
- `lib/BackgroundJob/TranscriptRetentionJob.php:464` `return (array)$entity->$method();`
- `lib/Lifecycle/QesGuard.php:234` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Listener/AgendaItemChangeListener.php:173` `if (method_exists($entity, 'getObject') === true) {`
- `lib/Listener/AgendaItemChangeListener.php:177` `if ($row === [] && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Listener/ApprovalTaskDecisionListener.php:145` `if (method_exists($event, 'getTask') === false) {`
- `lib/Listener/ApprovalTaskDecisionListener.php:267` `if (method_exists($object, $getter) === false) {`
- `lib/Listener/ApprovalTaskDecisionListener.php:271` `$value = $object->$getter();`
- `lib/Listener/DocumentTypeFieldsGuardListener.php:247` `if (method_exists($entity, 'getObject') === true) {`
- `lib/Listener/DocumentTypeFieldsGuardListener.php:251` `if (method_exists($entity, 'getUuid') === true && isset($row['id']) === false) {`
- `lib/Listener/GovernanceRoleProjectionListener.php:143` `if (method_exists($entity, 'getObject') === true) {`
- `lib/Listener/GovernanceRoleProjectionListener.php:147` `if ($row === [] && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Listener/MeetingFolderListener.php:127` `if (method_exists($entity, 'getObject') === true) {`
- `lib/Listener/MeetingFolderListener.php:131` `if ($row === [] && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Listener/MotionAdviceGuard.php:143` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Listener/PlanningCycleCreatedListener.php:159` `if (method_exists($entity, 'getObject') === true) {`
- `lib/Listener/PlanningCycleCreatedListener.php:163` `if ($row === [] && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Listener/PortalCreateOpenParentGuardListener.php:217` `if (method_exists($entity, 'getObject') === true) {`
- `lib/Listener/PortalCreateOpenParentGuardListener.php:354` `if (method_exists($entity, 'getSchemaSlug') === true) {`
- `lib/Listener/PortalCreateOpenParentGuardListener.php:361` `if (method_exists($entity, 'getSchema') === true) {`
- `lib/Listener/PortalCreateOpenParentGuardListener.php:468` `if (method_exists($parentEntity, 'jsonSerialize') === true) {`
- `lib/Listener/PortalCreateOpenParentGuardListener.php:470` `} elseif (method_exists($parentEntity, 'getObject') === true) {`
- `lib/Listener/SubmissionDeadlineListener.php:212` `if (method_exists($entity, 'getObject') === true) {`
- `lib/Listener/SubmissionDeadlineListener.php:216` `if ($row === [] && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Listener/SubmissionDeadlineListener.php:245` `if (method_exists($entity, $getter) === false) {`
- `lib/Listener/SubmissionDeadlineListener.php:249` `$value = $entity->{$getter}();`
- `lib/Listener/TechnicalQuestionListener.php:207` `if (method_exists($entity, 'getObject') === true) {`
- `lib/Listener/TechnicalQuestionListener.php:211` `if ($row === [] && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Mcp/McpSourceFormatter.php:65` `if (is_object(value: $item) === true && method_exists($item, 'getObject') === true) {`
- `lib/Mcp/McpSourceFormatter.php:69` `if (is_object(value: $item) === true && method_exists($item, 'jsonSerialize') === true) {`
- `lib/Migration/MigrateCommentsToTalkLeaf.php:368` `if (method_exists($talkManager, 'getRoomForObject') === true) {`
- `lib/Migration/MigrateCommentsToTalkLeaf.php:379` `if (method_exists($talkManager, 'createConversationFromObject') === true) {`
- `lib/Migration/MigrateCommentsToTalkLeaf.php:480` `if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Migration/MigrateCommentsToTalkLeaf.php:489` `if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {`
- `lib/Migration/MigrateEmailLinksToRegistry.php:300` `if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Migration/MigrateEmailLinksToRegistry.php:309` `if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {`
- `lib/Migration/MigrateLegacyTemplatesToDecisionTemplate.php:672` `if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Migration/MigrateLegacyTemplatesToDecisionTemplate.php:681` `if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {`
- `lib/Migration/ReadsLegacyRows.php:471` `if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Repair/MigrateBoardProxyToProxyAuthorization.php:364` `if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Repair/MigrateBoardProxyToProxyAuthorization.php:371` `if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {`
- `lib/Repair/RepointConflictOfInterestBoardMember.php:268` `if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Repair/RepointConflictOfInterestBoardMember.php:275` `if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {`
- `lib/Service/AgendaPapers.php:101` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/AgendaPapers.php:241` `if (is_object($service) === false \|\| method_exists($service, 'publishFile') === false) {`
- `lib/Service/AgendaRules.php:74` `if (method_exists($item, 'getObject') === true) {`
- `lib/Service/AgendaService.php:180` `if (method_exists($this->calendarEventService, 'updateMeetingEvent') === true) {`
- `lib/Service/ApprovalStageTaskProjector.php:224` `if ($assignee === '' \|\| method_exists($tasks, 'import') === false) {`
- `lib/Service/ApprovalStageTaskProjector.php:249` `if (is_object($task) === true && method_exists($task, 'getUuid') === true) {`
- `lib/Service/ApprovalStageTaskProjector.php:282` `if (method_exists($tasks, 'consume') === true) {`
- `lib/Service/CaseExchangeRecords.php:143` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/CitizenAdviceService.php:292` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/CitizenAdviceService.php:296` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/ConfidentialityRestrictions.php:97` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/ConflictOfInterestService.php:451` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/DecisionIntegrationService.php:548` `if ($registry !== null && method_exists($registry, 'isRegisteredConsumer') === true) {`
- `lib/Service/DecisionListService.php:266` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/EIDASSignatureService.php:203` `if (method_exists($entity, 'getObject') === true) {`
- `lib/Service/EIDASSignatureService.php:524` `if (is_object($item) === false \|\| method_exists($item, 'getObject') === false) {`
- `lib/Service/EngagementService.php:147` `if (is_object($saved) === true && method_exists($saved, 'getObject') === true) {`
- `lib/Service/EngagementService.php:283` `if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {`
- `lib/Service/ExportBundleService.php:187` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/ExportBundleWriter.php:257` `if (method_exists($error, 'getStatus') === true) {`
- `lib/Service/FullExportService.php:234` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/GovernanceReportingService.php:457` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/GuestInvitationService.php:353` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/GuestInvitationService.php:393` `if (is_object($entity) === true && method_exists($entity, 'getUuid') === true) {`
- `lib/Service/GuestInvitationService.php:398` `if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Service/IntegriqConnection.php:86` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/IntegriqConnection.php:112` `if (is_object($response) === true && method_exists($response, 'getResponse') === true) {`
- `lib/Service/IntegriqConnection.php:116` `if ($raw === [] && is_object($response) === true && method_exists($response, 'getObject') === true) {`
- `lib/Service/ListenerSchemaResolver.php:207` `if (method_exists($entity, $getter) === false`
- `lib/Service/ListenerSchemaResolver.php:214` `$value = $entity->{$getter}();`
- `lib/Service/MeetingAttendanceReader.php:169` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/MeetingCostService.php:419` `if (is_object($result) === true && method_exists($result, 'getObject') === true) {`
- `lib/Service/MeetingCostService.php:442` `if (method_exists($entity, 'getUuid') === true) {`
- `lib/Service/MeetingFileService.php:420` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/MeetingPackageService.php:171` `if (method_exists($entity, 'getObject') === true) {`
- `lib/Service/MeetingPackageService.php:408` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/MeetingPackageWriter.php:166` `return is_object($node) === true && method_exists($node, 'getName') === true && method_exists($node, 'getContent') === true;`
- `lib/Service/MeetingPackageWriter.php:166` `return is_object($node) === true && method_exists($node, 'getName') === true && method_exists($node, 'getContent') === true;`
- `lib/Service/MeetingSeriesService.php:409` `if (method_exists($entity, 'getObject') === true) {`
- `lib/Service/MinutesDraftService.php:282` `if (is_object($provider) === true && method_exists($provider, 'getId') === true) {`
- `lib/Service/MinutesDraftService.php:328` `} elseif (method_exists($entity, 'getObject') === true) {`
- `lib/Service/MinutesDraftService.php:330` `} elseif (method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Service/MinutesGenerationService.php:373` `if (is_object($saved) === true && method_exists($saved, 'getObject') === true) {`
- `lib/Service/MinutesGenerationService.php:522` `if (method_exists($entity, 'getObject') === true) {`
- `lib/Service/MotionLinkResolver.php:130` `if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Service/MultilingualReconciliationService.php:233` `if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {`
- `lib/Service/MultilingualReconciliationService.php:457` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/NotificationPreferenceService.php:148` `if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {`
- `lib/Service/NotificationPreferenceService.php:188` `if (is_object($saved) === true && method_exists($saved, 'getObject') === true) {`
- `lib/Service/OpenCatalogiPublisher.php:122` `if (method_exists($publication, 'getUuid') === true) {`
- `lib/Service/OpenCatalogiPublisher.php:129` `if (method_exists($publication, 'jsonSerialize') === true) {`
- `lib/Service/OriSerializer.php:394` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/PaperSummaryAccess.php:242` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/PaperText.php:83` `if (is_object($node) === true && method_exists($node, 'getId') === true && (int)$node->getId() === $fileId) {`
- `lib/Service/ParticipantToPersonMembershipResolver.php:422` `if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Service/ParticipantToPersonMembershipResolver.php:429` `if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {`
- `lib/Service/ProcessTemplateService.php:102` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/ProofPackageService.php:536` `if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Service/ProxyVoteService.php:512` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/PublicationDigestService.php:373` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/PublicationDigestService.php:375` `if (method_exists($row, 'getUuid') === true) {`
- `lib/Service/PublicationDigestService.php:379` `if (method_exists($row, 'getOwner') === true) {`
- `lib/Service/PublicationPayloadService.php:313` `if (is_object($entity) === false \|\| method_exists($entity, 'jsonSerialize') === false) {`
- `lib/Service/PublicationRepository.php:244` `if (method_exists($saved, 'getUuid') === true) {`
- `lib/Service/PublicationRepository.php:251` `if (method_exists($saved, 'jsonSerialize') === false) {`
- `lib/Service/QuorumVerificationService.php:224` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/QuorumVerificationService.php:306` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/Records/OpenRegisterArchive.php:250` `if (is_object($entity) === false \|\| method_exists($entity, 'getConfiguration') === false) {`
- `lib/Service/Records/OpenRegisterArchive.php:275` `if (is_object($service) === false \|\| method_exists($service, $method) === false) {`
- `lib/Service/RegisterObjectStore.php:209` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/RegulatorExportService.php:176` `if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {`
- `lib/Service/RegulatorExportService.php:266` `if (method_exists($entity, 'getObject') === true) {`
- `lib/Service/RegulatorExportService.php:432` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/SigningAnswer.php:69` `if (method_exists($response, 'getObject') === true) {`
- `lib/Service/SigningAnswer.php:77` `if (method_exists($response, 'getResponse') === true) {`
- `lib/Service/SigningRoundService.php:363` `if (method_exists($entity, 'getObject') === true) {`
- `lib/Service/SigningRoundService.php:367` `if (method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Service/SigningRoundService.php:382` `if (is_object($item) === true && method_exists($item, 'getUuid') === true) {`
- `lib/Service/SubstitutionResolver.php:288` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/TranscriptRepository.php:178` `if (method_exists($entity, 'getObject') === true) {`
- `lib/Service/TranscriptRepository.php:183` `if (method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Service/TranscriptRepository.php:214` `if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {`
- `lib/Service/TranscriptRepository.php:218` `if (is_object($saved) === true && method_exists($saved, 'getObject') === true) {`
- `lib/Service/TranscriptionService.php:288` `if (is_object($first) === true && method_exists($first, 'getName') === true) {`
- `lib/Service/TranscriptionStaffGuard.php:334` `if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {`
- `lib/Service/VoteCastingService.php:272` `if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {`

</details>

Unresolved call sites (receiver type unknown, magic, or spread args): 68, listed in `decidiq.json`.

## buildiq

Local dir `openbuild`, branch `development`, sha `ffb6d2e35c060e1a0552705e7254da051c9a2b41`.

### Runtime breaks (lib): 7

| file:line | category | app calls | OR main now | fix |
|---|---|---|---|---|
| `lib/Service/VersionPromotionService.php:546` | dynamic-method-gone (SILENT) | method_exists($this->objectService, 'getLockInfo') on OCA\OpenRegister\Contract\ObjectServiceInterface | OCA\OpenRegister\Contract\ObjectServiceInterface (lib/Contract/ObjectServiceInterface.php) has no getLockInfo: branch silently skipped | call the replacement method directly |
| `lib/Service/ApplicationInsightsService.php:1008` | dynamic-name-nowhere (SILENT) | method_exists($this->auditTrailMapper, 'countByRegisterAndWindow' (receiver $this->auditTrailMapper) | no method named countByRegisterAndWindow exists in any OR main class (nor in this app or NC server): the guarded branch never runs | find what replaced it on OR main and call it directly |
| `lib/Resources/template/tests/unit/AppInfo/OpenRegisterAutoloaderTest.php:78` | string-lookup-missing (SILENT) | class_exists('OCA\OpenRegister\Fake\Probe') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |
| `lib/Listener/FormDestinationDependentsListener.php:116` | constructor-shape | new FormDependent(id: (string)($form['id'] ?? ''), app: 'buildiq', title: (string)($form['name'] ?? ''), author: $this->authorOf(form: $form), mapping: $this->forms->mappingOf(fo) | OCA\OpenRegister\Service\Form\FormDependent::__construct(PublishedForm $form, public readonly ?string $author, public readonly array $mapping, public readonly ?Closure $unpublish = null) (lib/Service/Form/FormDependent.php:73) | UNKNOWN-NAMED-ARG: `id:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `app:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `title:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `audience:` (Error: Unknown named parameter); MISSING-REQUIR |
| `lib/Service/VersionPromotionService.php:599` | method-missing | $this->objectService->getLockInfo() on OCA\OpenRegister\Contract\ObjectServiceInterface | OCA\OpenRegister\Contract\ObjectServiceInterface (lib/Contract/ObjectServiceInterface.php) has no method getLockInfo | find the replacement method on OR main |
| `lib/Service/ApplicationVersionService.php:840` | entity-accessor-missing | $register->getMetadata() on OCA\OpenRegister\Db\Register | OCA\OpenRegister\Db\Register (lib/Db/Register.php) declares no property $metadata and no method getMetadata: Entity::__call throws BadFunctionCallException | use the accessor/property that replaced it |
| `lib/Service/ApplicationVersionService.php:849` | entity-accessor-missing | $register->setMetadata() on OCA\OpenRegister\Db\Register | OCA\OpenRegister\Db\Register (lib/Db/Register.php) declares no property $metadata and no method setMetadata: Entity::__call throws BadFunctionCallException | use the accessor/property that replaced it |

### Test-suite breaks (tests): 9

| file:line | category | stub | app calls | OR main now | fix |
|---|---|---|---|---|---|
| `tests/stubs/openregister-leaf.stub.php:183` | parent-missing |  | RegisterLeafProvidersEvent extends OCA\OpenRegister\Service\Integration\Event | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |
| `tests/Unit/Listener/ApprovalOutcomeListenerTest.php:163` | constructor-shape | stub | new TaskSequenceCompletedEvent(sequence: $this->sequenceFor($chain, 'object-uuid-1')) | OCA\OpenRegister\Event\TaskSequenceCompletedEvent::__construct(TaskSequence $sequence, private readonly Task $finalTask, private readonly ?string $decider, private readonly string $statusOnApprove) (l | MISSING-REQUIRED-ARG: `$finalTask` (ArgumentCountError); MISSING-REQUIRED-ARG: `$decider` (ArgumentCountError); MISSING-REQUIRED-ARG: `$statusOnApprove` (ArgumentCountError) |
| `tests/Unit/Listener/ApprovalOutcomeListenerTest.php:208` | constructor-shape | stub | new TaskTerminalEvent(task: $this->taskFor('object-uuid-1', $chain), outcome: 'rejected') | OCA\OpenRegister\Event\TaskTerminalEvent::__construct(Task $task, private readonly bool $committed = true) (lib/Event/TaskTerminalEvent.php:66) | UNKNOWN-NAMED-ARG: `outcome:` (Error: Unknown named parameter) |
| `tests/Unit/Listener/ApprovalOutcomeListenerTest.php:249` | constructor-shape | stub | new TaskSequenceCompletedEvent(sequence: $this->sequenceFor($chain, 'object-uuid-1')) | OCA\OpenRegister\Event\TaskSequenceCompletedEvent::__construct(TaskSequence $sequence, private readonly Task $finalTask, private readonly ?string $decider, private readonly string $statusOnApprove) (l | MISSING-REQUIRED-ARG: `$finalTask` (ArgumentCountError); MISSING-REQUIRED-ARG: `$decider` (ArgumentCountError); MISSING-REQUIRED-ARG: `$statusOnApprove` (ArgumentCountError) |
| `tests/Unit/Listener/ApprovalOutcomeListenerTest.php:266` | constructor-shape | stub | new TaskSequenceCompletedEvent(sequence: $this->sequenceFor($chain, 'object-uuid-1')) | OCA\OpenRegister\Event\TaskSequenceCompletedEvent::__construct(TaskSequence $sequence, private readonly Task $finalTask, private readonly ?string $decider, private readonly string $statusOnApprove) (l | MISSING-REQUIRED-ARG: `$finalTask` (ArgumentCountError); MISSING-REQUIRED-ARG: `$decider` (ArgumentCountError); MISSING-REQUIRED-ARG: `$statusOnApprove` (ArgumentCountError) |
| `tests/Unit/Listener/ApprovalOutcomeListenerTest.php:300` | constructor-shape | stub | new TaskTerminalEvent(task: $this->taskFor('object-uuid-5'), outcome: 'approved') | OCA\OpenRegister\Event\TaskTerminalEvent::__construct(Task $task, private readonly bool $committed = true) (lib/Event/TaskTerminalEvent.php:66) | UNKNOWN-NAMED-ARG: `outcome:` (Error: Unknown named parameter) |
| `tests/Unit/Listener/ApprovalOutcomeListenerTest.php:321` | constructor-shape | stub | new TaskTerminalEvent(task: $this->taskFor('object-uuid-6'), outcome: null) | OCA\OpenRegister\Event\TaskTerminalEvent::__construct(Task $task, private readonly bool $committed = true) (lib/Event/TaskTerminalEvent.php:66) | UNKNOWN-NAMED-ARG: `outcome:` (Error: Unknown named parameter) |
| `tests/Unit/Listener/ApprovalOutcomeListenerTest.php:348` | constructor-shape | stub | new TaskTerminalEvent(task: $task, outcome: 'rejected') | OCA\OpenRegister\Event\TaskTerminalEvent::__construct(Task $task, private readonly bool $committed = true) (lib/Event/TaskTerminalEvent.php:66) | UNKNOWN-NAMED-ARG: `outcome:` (Error: Unknown named parameter) |
| `tests/Unit/Listener/ApprovalOutcomeListenerTest.php:367` | constructor-shape | stub | new TaskTerminalEvent(task: $task, outcome: 'rejected') | OCA\OpenRegister\Event\TaskTerminalEvent::__construct(Task $task, private readonly bool $committed = true) (lib/Event/TaskTerminalEvent.php:66) | UNKNOWN-NAMED-ARG: `outcome:` (Error: Unknown named parameter) |

### Test stubs of OR classes that drifted from OR main: 24 of 66

- `tests/stubs/openregister-apphost.stub.php:112` `OCA\OpenRegister\AppHost\Settings\GenericAdminSettings` (exists): __construct($appId, $sectionId, $priority, $appManager, $initialState, $appConfig) vs OR main __construct($appId, $sectionId, $priority, $appManager, $initialState, $appConfig) [lib/AppHost/Settings/GenericAdminSettings.php:64]
- `tests/stubs/openregister-apphost.stub.php:194` `OCA\OpenRegister\AppHost\Settings\StoreDescriptor` (missing): class no longer exists on OR main
- `tests/stubs/openregister-apphost.stub.php:217` `OCA\OpenRegister\AppHost\Settings\GenericStoreService` (missing): class no longer exists on OR main
- `tests/stubs/openregister-apphost.stub.php:283` `OCA\OpenRegister\AppHost\Settings\StoreActionAuthorizer` (missing): class no longer exists on OR main
- `tests/stubs/openregister-apphost.stub.php:304` `OCA\OpenRegister\AppHost\Settings\Bootstrap` (missing): class no longer exists on OR main
- `tests/stubs/openregister-form.stub.php:37` `OCA\OpenRegister\Service\Form\FormDependent` (exists): __construct($id, $app, $title, $author, $mapping, $unpublish, $audience) vs OR main __construct($form, $author, $mapping, $unpublish) [lib/Service/Form/FormDependent.php:73]
- `tests/stubs/openregister-leaf.stub.php:101` `OCA\OpenRegister\Service\Integration\LeafDescriptor` (exists): __construct($id, $label, $icon, $kinds, $requiredApp, $group, $surfaces, $referenceType, $requiresPermission, $renderMode) vs OR main __construct($id, $label, $icon, $kinds, $requiredApp, $group, $surfaces, $referenceType, $requiresPermission, $renderMode, $loadStrategy) [lib/Service/Integration/LeafDescriptor.php:205]; getRequiresPermission(): not on OR main
- `tests/stubs/openregister-leaf.stub.php:183` `OCA\OpenRegister\Service\Integration\RegisterLeafProvidersEvent` (missing): class no longer exists on OR main
- `tests/stubs/openregister-stubs.php:1578` `OCA\OpenRegister\Db\SchemaMapper` (exists): delete($entity) vs OR main delete($entity, $force) [lib/Db/SchemaMapper.php:1582]
- `tests/stubs/openregister-stubs.php:1657` `OCA\OpenRegister\Db\AuditTrailMapper` (exists): createAuditTrailEntry($object, $action, $context) vs OR main createAuditTrailEntry($object, $action, $context, $actorId, $actorName, $ipAddress) [lib/Db/AuditTrailMapper.php:2841]
- `tests/stubs/openregister-stubs.php:1704` `OCA\OpenRegister\Db\ApprovalChain` (missing): class no longer exists on OR main
- `tests/stubs/openregister-stubs.php:1955` `OCA\OpenRegister\Db\ApprovalChainMapper` (missing): class no longer exists on OR main
- `tests/stubs/openregister-stubs.php:2016` `OCA\OpenRegister\Db\ApprovalStep` (missing): class no longer exists on OR main
- `tests/stubs/openregister-stubs.php:2196` `OCA\OpenRegister\Db\ApprovalStepMapper` (missing): class no longer exists on OR main
- `tests/stubs/openregister-stubs.php:2273` `OCA\OpenRegister\Service\ObjectService` (exists): searchObjects($query, $_rbac, $_multitenancy, $ids, $uses, $views) vs OR main searchObjects($query, $_rbac, $_multitenancy, $ids, $uses, $views, $_viewScopeRequired) [lib/Service/ObjectService.php:4771]; find($id, $_extend, $files, $register, $schema, $_rbac, $_multitenancy) vs OR main find($id, $_extend, $files, $register, $schema, $_rbac, $_multitenancy, $_render, $_audit) [lib/Service/ObjectService.php:1264]; saveObject($object, $extend, $register, $schema, $uuid, $_rbac, $_multitenancy, $silent, $uploadedFiles, $currentUser, $failIfExists) vs OR main saveObject($object, $extend, $register, $schema, $uuid, $_rbac, $_multitenancy, $silent, $_validation, $uploadedFiles, $currentUser, $failIfExists, $_unowned, $_dedupOverride, $_owner) [lib/Service/ObjectService.php:2112]; deleteObject($uuid, $register, $schema, $_rbac, $_multitenancy, $_retentionSweep) vs OR main deleteObject($uuid, $register, $schema, $_rbac, $_multitenancy, $_retentionSweep, $currentUser, $permanent) [lib/Service/ObjectService.php:3827]; lockObject($identifier, $process, $duration, $advisory) vs OR main lockObject($identifier, $process, $duration, $advisory, $runUuid, $nodeId) [lib/Service/ObjectService.php:5736]; unlockObject($identifier) vs OR main unlockObject($identifier, $advisory, $runUuid) [lib/Service/ObjectService.php:5793]
- `tests/stubs/openregister-stubs.php:2579` `OCA\OpenRegister\Service\ApprovalService` (missing): class no longer exists on OR main
- `tests/stubs/openregister-stubs.php:2638` `OCA\OpenRegister\Service\Task\TaskSequenceService` (exists): provision($template, $anchorObjectUuid, $requesterId, $tierPositions, $registerId) vs OR main provision($chain, $anchor) [lib/Service/Task/TaskSequenceService.php:94]
- `tests/stubs/openregister-stubs.php:2741` `OCA\OpenRegister\Event\ObjectTransitionedEvent` (exists): __construct($object, $action, $from, $to, $userId, $register, $schema) vs OR main __construct($object, $action, $from, $to, $userId, $register, $schema, $automatic) [lib/Event/ObjectTransitionedEvent.php:65]
- `tests/stubs/openregister-stubs.php:2980` `OCA\OpenRegister\Event\DeepLinkRegistrationEvent` (exists): register($appId, $route, $label, $metadata) vs OR main register($appId, $registerSlug, $schemaSlug, $urlTemplate, $icon, $displayName) [lib/Event/DeepLinkRegistrationEvent.php:79]
- `tests/stubs/openregister-stubs.php:3042` `OCA\OpenRegister\Event\TaskSequenceCompletedEvent` (exists): __construct($sequence) vs OR main __construct($sequence, $finalTask, $decider, $statusOnApprove) [lib/Event/TaskSequenceCompletedEvent.php:55]
- `tests/stubs/openregister-stubs.php:3068` `OCA\OpenRegister\Event\TaskTerminalEvent` (exists): __construct($task, $outcome) vs OR main __construct($task, $committed) [lib/Event/TaskTerminalEvent.php:66]
- `tests/stubs/openregister-stubs.php:3108` `OCA\OpenRegister\Event\ApprovalStepApprovedEvent` (missing): class no longer exists on OR main
- `tests/stubs/openregister-stubs.php:3154` `OCA\OpenRegister\Event\ApprovalStepRejectedEvent` (missing): class no longer exists on OR main
- `tests/stubs/openregister-stubs.php:3416` `OCA\OpenRegister\AppHost\Service\StoreDescriptor` (exists): __construct($appId, $schema, $defaultRegister, $cardFields) vs OR main __construct($appId, $schema, $defaultRegister, $cardFields, $types, $publishFields, $publishGroups) [lib/AppHost/Service/StoreDescriptor.php:64]

### Implements / extends OR classes: 12

- `lib/Integration/PageLayoutLeafProvider.php:66` PageLayoutLeafProvider implements `OCA\OpenRegister\Service\Integration\IntegrationProvider` (exists)
- `lib/Integration/RegistrationFormLeafProvider.php:55` RegistrationFormLeafProvider implements `OCA\OpenRegister\Service\Integration\IntegrationProvider` (exists)
- `lib/Lifecycle/ApplicationVersionOwnerGuard.php:68` ApplicationVersionOwnerGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Mcp/BuildiqToolProvider.php:72` BuildiqToolProvider implements `OCA\OpenRegister\Mcp\IMcpToolProvider` (exists)
- `lib/Sections/SettingsSection.php:39` SettingsSection extends `OCA\OpenRegister\AppHost\Settings\GenericSettingsSection` (exists)
- `lib/Settings/AdminSettings.php:40` AdminSettings extends `OCA\OpenRegister\AppHost\Settings\GenericAdminSettings` (exists)
- `tests/Unit/Support/FakeSlugResolver.php:42` FakeSlugResolver implements `OCA\OpenRegister\Contract\RegisterSlugResolverInterface` (exists)
- `tests/stubs/openregister-leaf.stub.php:183` RegisterLeafProvidersEvent extends `OCA\OpenRegister\Service\Integration\Event` (missing)
- `tests/stubs/openregister-stubs.php:318` ObjectEntity implements `OCA\OpenRegister\Contract\ObjectEntityInterface` (exists)
- `tests/stubs/openregister-stubs.php:460` Register implements `OCA\OpenRegister\Kernel\Contracts\RegisterInterface` (exists)
- `tests/stubs/openregister-stubs.php:738` Schema implements `OCA\OpenRegister\Kernel\Contracts\SchemaInterface` (exists)
- `tests/stubs/openregister-stubs.php:1843` Task implements `OCA\OpenRegister\Kernel\Contracts\TaskInterface` (exists)

### OR event listeners: 17 registrations

- `OCA\OpenRegister\Event\DeepLinkRegistrationEvent` (exists, OR main constructed): 1x, e.g. `lib/Resources/template/lib/AppInfo/Application.php:71`
- `OCA\OpenRegister\Event\FormDestinationDependentsEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Application.php:689`
- `OCA\OpenRegister\Event\ObjectCreatedEvent` (exists, OR main constructed): 2x, e.g. `lib/AppInfo/Application.php:668`
- `OCA\OpenRegister\Event\ObjectCreatingEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Application.php:632`
- `OCA\OpenRegister\Event\ObjectDeletedEvent` (exists, OR main constructed): 2x, e.g. `lib/AppInfo/Application.php:698`
- `OCA\OpenRegister\Event\ObjectTransitionedEvent` (exists, OR main constructed): 2x, e.g. `lib/AppInfo/Application.php:702`
- `OCA\OpenRegister\Event\ObjectUpdatedEvent` (exists, OR main constructed): 2x, e.g. `lib/AppInfo/Application.php:694`
- `OCA\OpenRegister\Event\ObjectUpdatingEvent` (exists, OR main constructed): 3x, e.g. `lib/AppInfo/Application.php:604`
- `OCA\OpenRegister\Event\RegisterLeafProvidersEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Application.php:678`
- `OCA\OpenRegister\Event\TaskSequenceCompletedEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Application.php:719`
- `OCA\OpenRegister\Event\TaskTerminalEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Application.php:723`

<details><summary>Dynamic call sites in lib/ (method_exists, is_callable, $x->$m(), call_user_func): 51. Always suspect: a renamed OR method makes these silently skip.</summary>

- `lib/Controller/ApplicationsController.php:2432` `if (is_object($register) === true && method_exists($register, 'getOwner') === true) {`
- `lib/Controller/ApplicationsController.php:2439` `if (is_object($register) === true && method_exists($register, 'jsonSerialize') === true) {`
- `lib/Controller/ExportsController.php:193` `if (method_exists($service, 'searchObjectsBySlug') === false) {`
- `lib/Controller/ExportsController.php:295` `if (method_exists($service, 'find') === false) {`
- `lib/Controller/ExportsController.php:307` `} elseif (method_exists($found, 'jsonSerialize') === true) {`
- `lib/Controller/ExportsController.php:354` `if (method_exists($service, 'searchObjectsBySlug') === false) {`
- `lib/Listener/FiredAutomationTrigger.php:229` `if (method_exists($entity, $getter) === true) {`
- `lib/Listener/FiredAutomationTrigger.php:230` `$value = $entity->$getter();`
- `lib/Listener/HybridMetadataLockListener.php:195` `if (method_exists($event, 'setErrors') === true) {`
- `lib/Listener/ProductionVersionGuardListener.php:152` `if (method_exists($event, 'setErrors') === true) {`
- `lib/Listener/ProductionVersionGuardListener.php:186` `if (is_callable([$entity, 'getUuid']) === true) {`
- `lib/Listener/ProductionVersionGuardListener.php:186` `if (is_callable([$entity, 'getUuid']) === true) {`
- `lib/Service/AppRepoSerializer.php:719` `if (is_object($result) === true && method_exists($result, 'getObject') === true) {`
- `lib/Service/ApplicationDeletionService.php:247` `if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {`
- `lib/Service/ApplicationDeletionService.php:493` `if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {`
- `lib/Service/ApplicationInsightsService.php:878` `if (method_exists($this->auditTrailMapper, 'getDistinctActorCount') === false) {`
- `lib/Service/ApplicationInsightsService.php:962` `if (method_exists($this->auditTrailMapper, 'getStatisticsGroupedBySchema') === false) {`
- `lib/Service/ApplicationInsightsService.php:1008` `if (method_exists($this->auditTrailMapper, 'countByRegisterAndWindow') === true) {`
- `lib/Service/ApplicationVersionService.php:839` `if (method_exists($register, 'getMetadata') === true) {`
- `lib/Service/ApplicationVersionService.php:848` `if (method_exists($register, 'setMetadata') === true) {`
- `lib/Service/AutomationCompilerService.php:1900` `assert(is_object($compiler) && method_exists($compiler, 'templateIdFor'));`
- `lib/Service/AutomationCompilerService.php:1907` `assert(is_object($sequences) && method_exists($sequences, 'findNewestForTemplate'));`
- `lib/Service/Connection/ConnectionObservations.php:186` `if (method_exists($exception, 'getResponse') === false) {`
- `lib/Service/Connection/ConnectionObservations.php:191` `if (is_object($response) === false \|\| method_exists($response, 'getStatusCode') === false) {`
- `lib/Service/CopilotService.php:1512` `if (interface_exists('OCP\\TaskProcessing\\ISynchronousProvider') === false \|\| method_exists($manager, 'runTask') === false) {`
- `lib/Service/DocumentGenerationService.php:552` `if (method_exists($provider, 'generateToken') === false) {`
- `lib/Service/DocumentGenerationService.php:586` `if (method_exists($provider, 'invalidateToken') === true) {`
- `lib/Service/ExportAppSchemaReader.php:153` `$value = $schema->$getter();`
- `lib/Service/ExportJobService.php:239` `if (method_exists($service, 'saveObject') === true) {`
- `lib/Service/ExportJobService.php:332` `if (method_exists($engine, 'transition') === false) {`
- `lib/Service/ExportJobService.php:391` `if (method_exists($service, 'find') === false \|\| method_exists($service, 'saveObject') === false) {`
- `lib/Service/ExportJobService.php:391` `if (method_exists($service, 'find') === false \|\| method_exists($service, 'saveObject') === false) {`
- `lib/Service/ExportJobService.php:405` `if (method_exists($existing, 'getObject') === true) {`
- `lib/Service/ExportJobService.php:474` `if (method_exists($service, 'find') === false) {`
- `lib/Service/ExportJobService.php:483` `if (method_exists($object, 'getObject') === true) {`
- `lib/Service/FormDestinationCheck.php:174` `if (is_object($validator) === false \|\| method_exists($validator, 'validate') === false) {`
- `lib/Service/JobOwnerImpersonator.php:140` `if (method_exists($service, 'find') === false) {`
- `lib/Service/ObjectSchemaSlugResolver.php:161` `if (is_callable([$entity, $accessor]) === false) {`
- `lib/Service/ObjectSchemaSlugResolver.php:166` `$value = $entity->$accessor();`
- `lib/Service/PageLayoutAuthoringService.php:159` `if ($registry === null \|\| method_exists($registry, 'listIds') === false) {`
- `lib/Service/StoredDecisionTables.php:256` `if (is_object($entity) === false \|\| method_exists($entity, 'getObject') === false) {`
- `lib/Service/TemplateSeedService.php:339` `if (is_object($first) === true && method_exists($first, 'jsonSerialize') === true) {`
- `lib/Service/VersionPromotionService.php:546` `if (method_exists($this->objectService, 'getLockInfo') === true) {`
- `lib/Service/VersionPromotionService.php:687` `if (is_object($row) === true && method_exists($row, 'getUuid') === true) {`
- `lib/Service/VersionPromotionService.php:694` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/VersionPromotionService.php:721` `if (is_object($row) === true && method_exists($row, 'getSchema') === true) {`
- `lib/Service/VersionPromotionService.php:748` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Service/VersionPromotionService.php:755` `if (is_object($object) === true && method_exists($object, 'getObject') === true) {`
- `lib/Support/ObjectRecord.php:55` `if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {`
- `lib/Support/ObjectRecord.php:96` `if (is_object($object) === true && method_exists($object, 'getObject') === true) {`
- `lib/Support/ObjectRecord.php:132` `if (method_exists($entity, 'getObject') === true) {`

</details>

Unresolved call sites (receiver type unknown, magic, or spread args): 4, listed in `buildiq.json`.

## keepiq

Local dir `doriath`, branch `development`, sha `bc7867fcf465230d5d5798ea94ec7da2fd2aed1a`.

### Runtime breaks (lib): 0

### Test-suite breaks (tests): 0

### Test stubs of OR classes that drifted from OR main: 1 of 2

- `tests/stubs/openregister-mcp.stub.php:28` `OCA\OpenRegister\Mcp\Attribute\McpTool` (exists): __construct($name, $description, $readOnlyHint, $destructiveHint, $idempotentHint, $scope, $subject, $action) vs OR main __construct($name, $description, $readOnlyHint, $destructiveHint, $idempotentHint, $scope, $subject, $action, $reach, $annotations) [lib/Mcp/Attribute/McpTool.php:130]

### Implements / extends OR classes: 1

- `lib/Mcp/KeepiqScannableServices.php:31` KeepiqScannableServices implements `OCA\OpenRegister\Mcp\IMcpScannableServices` (exists)

<details><summary>Dynamic call sites in lib/ (method_exists, is_callable, $x->$m(), call_user_func): 2. Always suspect: a renamed OR method makes these silently skip.</summary>

- `lib/Service/SecretService.php:1214` `'items' => $this->withTags(items: array_map([$this, 'serialiseWithBlocking'], $secrets), userId: $userId),`
- `lib/Service/SecretService.php:1251` `'items' => $this->withTags(items: array_map([$this, 'serialiseWithBlocking'], $window), userId: $userId),`

</details>

Unresolved call sites (receiver type unknown, magic, or spread args): 0, listed in `keepiq.json`.

## hermiq

Local dir `hermiq`, branch `development`, sha `9ef2b578bf3d8bebad9743be9f8be5d792a06ad1`.

### Runtime breaks (lib): 0

### Test-suite breaks (tests): 2

| file:line | category | stub | app calls | OR main now | fix |
|---|---|---|---|---|---|
| `tests/Unit/Repair/AssignToolAccessRequestsToSystemTest.php:157` | constructor-shape | stub | new OrganisationService() | OCA\OpenRegister\Service\OrganisationService::__construct(OrganisationMapperInterface $organisationMapper, IUserSession $userSession, ISession $session, IConfig $config, IAppConfig $appConfig, IGroupM | MISSING-REQUIRED-ARG: `$organisationMapper` (ArgumentCountError); MISSING-REQUIRED-ARG: `$userSession` (ArgumentCountError); MISSING-REQUIRED-ARG: `$session` (ArgumentCountError); MISSING-REQUIRED-ARG |
| `tests/Unit/Settings/ToolAccessRequestPropertyRulesTest.php:130` | constructor-shape |  | new PropertyRbacHandler(userSession: $session, groupManager: $groups, conditionMatcher: $this->createMock(originalClassName: ConditionMatcher::class), logger: n | OCA\OpenRegister\Service\PropertyRbacHandler::__construct(private readonly PropertyRbacCaller $caller, private readonly PropertyRbacRules $rules, private readonly LoggerInterface $logger, private read | UNKNOWN-NAMED-ARG: `userSession:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `groupManager:` (Error: Unknown named parameter); UNKNOWN-NAMED-ARG: `conditionMatcher:` (Error: Unknown named pa |

### Test stubs of OR classes that drifted from OR main: 18 of 57

- `tests/Stubs/Db/AuditTrailMapper.php:31` `OCA\OpenRegister\Db\AuditTrailMapper` (exists): createAuditTrailEntry($object, $action, $context) vs OR main createAuditTrailEntry($object, $action, $context, $actorId, $actorName, $ipAddress) [lib/Db/AuditTrailMapper.php:2841]
- `tests/Stubs/Db/FlowMapper.php:37` `OCA\OpenRegister\Db\FlowMapper` (exists): findAllFlows($app, $organisation, $enabled, $limit, $offset) vs OR main findAllFlows($app, $applicationSlug, $organisation, $enabled, $limit, $offset) [lib/Db/FlowMapper.php:134]
- `tests/Stubs/Db/OrganisationMapper.php:30` `OCA\OpenRegister\Db\OrganisationMapper` (exists): findAll($limit, $offset) vs OR main findAll($limit, $offset, $filters) [lib/Db/OrganisationMapper.php:565]
- `tests/Stubs/Db/RegisterMapper.php:20` `OCA\OpenRegister\Db\RegisterMapper` (exists): find($id) vs OR main find($id, $_rbac, $_multitenancy) [lib/Db/RegisterMapper.php:238]; update($register) vs OR main update($entity) [lib/Db/RegisterMapper.php:858]
- `tests/Stubs/Event/AgentRunRequestedEvent.php:32` `OCA\OpenRegister\Event\AgentRunRequestedEvent` (exists): __construct($subjectUuid, $subjectRegister, $subjectSchema, $agent, $skill, $prompt, $resultField, $requiresApproval, $mode, $flowName, $correlationId) vs OR main __construct($subjectUuid, $subjectRegister, $subjectSchema, $agent, $skill, $prompt, $resultField, $requiresApproval, $mode, $flowName) [lib/Event/AgentRunRequestedEvent.php:74]
- `tests/Stubs/Exception/HookStoppedException.php:32` `OCA\OpenRegister\Exception\HookStoppedException` (exists): __construct($message, $errors, $code, $previous) vs OR main __construct($message, $errors) [lib/Exception/HookStoppedException.php:44]
- `tests/Stubs/Mcp/AbstractToolHandler.php:35` `OCA\OpenRegister\Mcp\AbstractToolHandler` (missing): class no longer exists on OR main
- `tests/Stubs/Mcp/Service/ChatService.php:27` `OCA\OpenRegister\Mcp\Service\ChatService` (exists): processMessage($conversationId, $userId, $userMessage, $selectedViews, $selectedTools, $ragSettings, $context) vs OR main processMessage($conversationId, $userId, $userMessage, $selectedViews, $selectedTools, $ragSettings, $context, $channel) [lib/Mcp/Service/ChatService.php:132]
- `tests/Stubs/Service/Capability/ToolGrantCodec.php:35` `OCA\OpenRegister\Service\Capability\ToolGrantCodec` (exists): refuse(): not on OR main
- `tests/Stubs/Service/Capability/ToolGrantResolver.php:55` `OCA\OpenRegister\Service\Capability\ToolGrantResolver` (exists): refuse(): not on OR main
- `tests/Stubs/Service/Capability/ToolGrantSet.php:35` `OCA\OpenRegister\Service\Capability\ToolGrantSet` (exists): refuse(): not on OR main
- `tests/Stubs/Service/Capability/ToolReachResolver.php:35` `OCA\OpenRegister\Service\Capability\ToolReachResolver` (exists): refuse(): not on OR main
- `tests/Stubs/Service/Credential/CredentialBrokerService.php:40` `OCA\OpenRegister\Service\Credential\CredentialBrokerService` (exists): resolveInjectable($credentialId, $appId, $actingUserId) vs OR main resolveInjectable($credentialId, $appId, $actingUserId, $actingOrganisationId) [lib/Service/Credential/CredentialBrokerService.php:354]
- `tests/Stubs/Service/Flow/FlowVersionService.php:41` `OCA\OpenRegister\Service\Flow\FlowVersionService` (exists): publish($flow, $publishedBy) vs OR main publish($flow, $publishedBy, $bump) [lib/Service/Flow/FlowVersionService.php:224]
- `tests/Stubs/Service/Integration/LeafDescriptor.php:29` `OCA\OpenRegister\Service\Integration\LeafDescriptor` (exists): __construct($id, $label, $icon, $kinds, $requiredApp, $group, $surfaces, $referenceType, $requiresPermission, $renderMode) vs OR main __construct($id, $label, $icon, $kinds, $requiredApp, $group, $surfaces, $referenceType, $requiresPermission, $renderMode, $loadStrategy) [lib/Service/Integration/LeafDescriptor.php:205]
- `tests/Stubs/Service/ObjectService.php:33` `OCA\OpenRegister\Service\ObjectService` (exists): deleteObject($uuid, $register, $schema, $_rbac, $_multitenancy) vs OR main deleteObject($uuid, $register, $schema, $_rbac, $_multitenancy, $_retentionSweep, $currentUser, $permanent) [lib/Service/ObjectService.php:3827]
- `tests/Stubs/Service/SchemaDeletionService.php:30` `OCA\OpenRegister\Service\SchemaDeletionService` (exists): cascadeDeleteSchema($schema) vs OR main cascadeDeleteSchema($schema, $override) [lib/Service/SchemaDeletionService.php:344]
- `tests/Stubs/Service/Task/TaskService.php:40` `OCA\OpenRegister\Service\Task\TaskService` (exists): refuse(): not on OR main; import($data, $actor) vs OR main import($data, $actor, $mayReadBlocker, $createdAt) [lib/Service/Task/TaskService.php:282]

### Implements / extends OR classes: 14

- `lib/Flow/HermiqAgentNode.php:70` HermiqAgentNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Flow/HermiqAgentNode.php:70` HermiqAgentNode implements `OCA\OpenRegister\Service\Flow\IFlowNodeLogActions` (exists)
- `lib/Flow/HermiqScheduleDispatchNode.php:62` HermiqScheduleDispatchNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Flow/HermiqWorkloadCollectNode.php:69` HermiqWorkloadCollectNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Flow/HermiqWorkloadNode.php:82` HermiqWorkloadNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Flow/OrganisationKillSwitchCheck.php:59` OrganisationKillSwitchCheck implements `OCA\OpenRegister\Service\Flow\IFlowOversightCheck` (exists)
- `lib/Lifecycle/AiFeatureDpoAckGuard.php:54` AiFeatureDpoAckGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Mcp/HermiqToolProvider.php:160` HermiqToolProvider implements `OCA\OpenRegister\Mcp\IMcpToolProvider` (exists)
- `lib/Service/Config/HermiqSkillShareableConfigType.php:55` HermiqSkillShareableConfigType implements `OCA\OpenRegister\Service\Config\IShareableConfigType` (exists)
- `tests/Stubs/Event/DraftCreatedEvent.php:24` DraftCreatedEvent extends `OCA\OpenRegister\Event\ObjectCreatedEvent` (exists)
- `tests/Stubs/Event/DraftUpdatedEvent.php:24` DraftUpdatedEvent extends `OCA\OpenRegister\Event\ObjectUpdatedEvent` (exists)
- `tests/Unit/Service/EvalRunServicePairedTest.php:60` RecordingObjectService extends `OCA\OpenRegister\Service\ObjectService` (exists)
- `tests/Unit/Service/Literacy/InMemoryObjects.php:31` InMemoryObjects extends `OCA\OpenRegister\Service\ObjectService` (exists)
- `tests/Unit/Support/FakeSlugResolver.php:43` FakeSlugResolver implements `OCA\OpenRegister\Contract\RegisterSlugResolverInterface` (exists)

### OR event listeners: 12 registrations

- `OCA\OpenRegister\Event\AgentRunRequestedEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Application.php:147`
- `OCA\OpenRegister\Event\DraftCreatedEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Application.php:473`
- `OCA\OpenRegister\Event\DraftUpdatedEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Application.php:474`
- `OCA\OpenRegister\Event\ObjectCreatedEvent` (exists, OR main constructed): 2x, e.g. `lib/AppInfo/Application.php:467`
- `OCA\OpenRegister\Event\ObjectDeletedEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Application.php:469`
- `OCA\OpenRegister\Event\ObjectUpdatedEvent` (exists, OR main constructed): 2x, e.g. `lib/AppInfo/Application.php:468`
- `OCA\OpenRegister\Event\RegisterLeafProvidersEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Application.php:211`
- `OCA\OpenRegister\Service\Config\RegisterShareableConfigTypesEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Application.php:243`
- `OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Application.php:182`
- `OCA\OpenRegister\Service\Flow\RegisterFlowOversightEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Application.php:196`

<details><summary>Dynamic call sites in lib/ (method_exists, is_callable, $x->$m(), call_user_func): 19. Always suspect: a renamed OR method makes these silently skip.</summary>

- `lib/Controller/AgentTemplateController.php:167` `$templates = array_map([$this, 'shape'], $this->templateService->list());`
- `lib/Controller/AiFeatureController.php:110` `$features = array_map([$this, 'shape'], $this->aiFeatureService->listFeatures());`
- `lib/Controller/MemoryController.php:249` `$profiles = array_map([$this, 'shape'], $this->memoryService->listUserProfiles(agentId: $agentId));`
- `lib/Controller/MemoryController.php:280` `$sessions = array_map([$this, 'shape'], $this->memoryService->listSessions(agentId: $agentId));`
- `lib/Controller/MemoryController.php:356` `$turns = array_map([$this, 'shape'], $this->memoryService->recallSessions(agentId: $agentId, query: $q));`
- `lib/Controller/SkillController.php:158` `$skills = array_map([$this, 'shape'], $this->skillService->listSkills());`
- `lib/Listener/TalkApprovalReactionListener.php:324` `if (method_exists($event, 'getBotUrl') === false \|\| TalkBridge::isHermiqBotUrl((string)$event->getBotUrl()) === false) {`
- `lib/Listener/TalkApprovalReactionListener.php:328` `if (method_exists($event, 'getMessage') === false \|\| $this->bridge->isAvailable() === false) {`
- `lib/Listener/TalkBotInvokeListener.php:221` `if (method_exists($event, 'addReaction') === true) {`
- `lib/Listener/TalkBotInvokeListener.php:254` `if (method_exists($event, 'getBotUrl') === false \|\| TalkBridge::isHermiqBotUrl((string)$event->getBotUrl()) === false) {`
- `lib/Listener/TalkBotInvokeListener.php:258` `if (method_exists($event, 'getMessage') === false \|\| $this->bridge->isAvailable() === false) {`
- `lib/Listener/TaskTerminalListener.php:236` `if (is_callable($callable) === false) {`
- `lib/Mcp/HermiqToolProvider.php:1306` `$title = call_user_func([$board, 'getTitle']);`
- `lib/Mcp/HermiqToolProvider.php:1306` `$title = call_user_func([$board, 'getTitle']);`
- `lib/Repair/Support/RunsUnderSystemIdentity.php:67` `if ($objectService !== null && method_exists($objectService, 'runAsSystem') === true) {`
- `lib/Service/ComplianceService.php:247` `[$this, 'shapeApprovalForFactsheet'],`
- `lib/Service/ComplianceService.php:251` `[$this, 'shapeIncidentForFactsheet'],`
- `lib/Service/OrganisationOpsService.php:339` `return ['incidents' => array_map([$this, 'shapeIncident'], $incidents)];`
- `lib/Service/ToolAccessRequestService.php:536` `if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {`

</details>

Unresolved call sites (receiver type unknown, magic, or spread args): 7, listed in `hermiq.json`.

## humaniq

Local dir `hrmq`, branch `development`, sha `00d1b6e14c885331eb94e8256c8adc9221ccd929`.

### Runtime breaks (lib): 1

| file:line | category | app calls | OR main now | fix |
|---|---|---|---|---|
| `lib/Service/WorkingCalendarReader.php:77` | string-lookup-missing (SILENT) | string('OCA\OpenRegister\Service\Calendar\WorkingCalendarService') | class does not exist on OR main (no alias) | replace with the new FQCN (prefer ::class so static analysis sees it) |

### Test-suite breaks (tests): 4

| file:line | category | stub | app calls | OR main now | fix |
|---|---|---|---|---|---|
| `tests/stubs/OpenRegisterLeafStub.php:99` | parent-missing |  | RegisterLeafProvidersEvent extends OCA\OpenRegister\Service\Integration\Event | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |
| `tests/stubs/OpenRegisterObjectEventsStub.php:216` | parent-missing | stub | ObjectCreatingEvent extends OCA\OpenRegister\Event\StubPreSaveEvent | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |
| `tests/stubs/OpenRegisterObjectEventsStub.php:239` | parent-missing | stub | ObjectUpdatingEvent extends OCA\OpenRegister\Event\StubPreSaveEvent | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |
| `tests/stubs/OpenRegisterObjectEventsStub.php:271` | parent-missing | stub | ObjectDeletingEvent extends OCA\OpenRegister\Event\StubPreSaveEvent | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |

### Test stubs of OR classes that drifted from OR main: 7 of 27

- `tests/stubs/OpenRegisterDmn/DecisionTableEvaluator.php:38` `OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator` (exists): ruleMatches($rule, $declaredInputs, $coercedInputs, $ruleIndex) vs OR main ruleMatches($cells, $inputs) [lib/Service/Dmn/DecisionTableEvaluator.php:222]; assertAllOutputsAgree($matchedRules, $declaredOutputs, $matchedIds) vs OR main assertAllOutputsAgree($match) [lib/Service/Dmn/DecisionTableEvaluator.php:258]; collectOutputs($matchedRules, $declaredOutputs) vs OR main collectOutputs($match) [lib/Service/Dmn/DecisionTableEvaluator.php:282]; applyHitPolicy($hitPolicy, $matchedRules, $declaredOutputs) vs OR main applyHitPolicy($hitPolicy, $match) [lib/Service/Dmn/DecisionTableEvaluator.php:360]
- `tests/stubs/OpenRegisterDmn/UnaryTestEvaluator.php:72` `OCA\OpenRegister\Service\Dmn\UnaryTestEvaluator` (exists): matchesRange($match, $value, $type) vs OR main matchesRange($match, $subject) [lib/Service/Dmn/UnaryTestEvaluator.php:335]; matchesComparison($operator, $remainder, $value, $type) vs OR main matchesComparison($trimmed, $subject) [lib/Service/Dmn/UnaryTestEvaluator.php:359]; equals(): not on OR main
- `tests/stubs/OpenRegisterFlowStub.php:116` `OCA\OpenRegister\Service\Flow\FlowNodeRegistry` (exists): __construct($dispatcher, $logger) vs OR main __construct($dispatcher, $logger, $urls, $taxonomy) [lib/Service/Flow/FlowNodeRegistry.php:117]
- `tests/stubs/OpenRegisterLeafStub.php:44` `OCA\OpenRegister\Service\Integration\LeafDescriptor` (exists): __construct($id, $label, $icon, $kinds, $requiredApp, $group, $surfaces, $referenceType, $renderMode) vs OR main __construct($id, $label, $icon, $kinds, $requiredApp, $group, $surfaces, $referenceType, $requiresPermission, $renderMode, $loadStrategy) [lib/Service/Integration/LeafDescriptor.php:205]
- `tests/stubs/OpenRegisterLeafStub.php:99` `OCA\OpenRegister\Service\Integration\RegisterLeafProvidersEvent` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterObjectEventsStub.php:148` `OCA\OpenRegister\Event\StubPreSaveEvent` (missing): class no longer exists on OR main
- `tests/stubs/OpenRegisterWorkingCalendarStub.php:42` `OCA\OpenRegister\Service\WorkingCalendarService` (missing): class no longer exists on OR main

### Implements / extends OR classes: 28

- `lib/Flow/AnonymiseExitInterviewsNode.php:38` AnonymiseExitInterviewsNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Flow/PayrollFlowNodeBase.php:63` PayrollFlowNodeBase implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Flow/RevokeAccessNode.php:38` RevokeAccessNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Flow/TeamDigestNode.php:43` TeamDigestNode implements `OCA\OpenRegister\Service\Flow\IFlowNode` (exists)
- `lib/Lifecycle/ChangeApproverRoleGuard.php:43` ChangeApproverRoleGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/CompEffectiveDateGuard.php:59` CompEffectiveDateGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/DecisionReasonGuard.php:44` DecisionReasonGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/HandoffCloseGuard.php:35` HandoffCloseGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/HandoffReleaseGuard.php:35` HandoffReleaseGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/HrReopenGuard.php:43` HrReopenGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/LeaveBuySellApprovalGuard.php:76` LeaveBuySellApprovalGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/LeaveSettlementPeriodGuard.php:57` LeaveSettlementPeriodGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/LeaveTypeConditionGuard.php:60` LeaveTypeConditionGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/LoonaangifteMessageGuard.php:38` LoonaangifteMessageGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/NoSelfApprovalGuard.php:69` NoSelfApprovalGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/PayrollRunApprovedGuard.php:60` PayrollRunApprovedGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/RightToWorkGuard.php:51` RightToWorkGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/RosterCompetenceGuard.php:55` RosterCompetenceGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/SurveyOpenGuard.php:39` SurveyOpenGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/TimesheetNotEmptyGuard.php:48` TimesheetNotEmptyGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Lifecycle/UbdReportReadyGuard.php:36` UbdReportReadyGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Notification/CaseManagerResolver.php:47` CaseManagerResolver implements `OCA\OpenRegister\Notifications\Service\Notification\RecipientResolverInterface` (exists)
- `lib/Notification/ManagerOrDeputyRecipientResolver.php:44` ManagerOrDeputyRecipientResolver implements `OCA\OpenRegister\Notifications\Service\Notification\RecipientResolverInterface` (exists)
- `tests/Unit/Support/FakeSlugResolver.php:39` FakeSlugResolver implements `OCA\OpenRegister\Contract\RegisterSlugResolverInterface` (exists)
- `tests/stubs/OpenRegisterLeafStub.php:99` RegisterLeafProvidersEvent extends `OCA\OpenRegister\Service\Integration\Event` (missing)
- `tests/stubs/OpenRegisterObjectEventsStub.php:216` ObjectCreatingEvent extends `OCA\OpenRegister\Event\StubPreSaveEvent` (missing)
- `tests/stubs/OpenRegisterObjectEventsStub.php:239` ObjectUpdatingEvent extends `OCA\OpenRegister\Event\StubPreSaveEvent` (missing)
- `tests/stubs/OpenRegisterObjectEventsStub.php:271` ObjectDeletingEvent extends `OCA\OpenRegister\Event\StubPreSaveEvent` (missing)

### OR event listeners: 5 registrations

- `OCA\OpenRegister\Event\ObjectCreatingEvent` (exists, OR main constructed): 1x, e.g. `tests/Unit/OpenRegisterPreSaveMutationContractTest.php:199`
- `OCA\OpenRegister\Event\ObjectUpdatingEvent` (exists, OR main constructed): 1x, e.g. `tests/Unit/OpenRegisterPreSaveMutationContractTest.php:237`
- `OCA\OpenRegister\Event\RegisterLeafProvidersEvent` (exists, OR main constructed): 2x, e.g. `lib/AppInfo/Application.php:435`
- `OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent` (exists, OR main constructed): 1x, e.g. `lib/AppInfo/Application.php:462`

<details><summary>Dynamic call sites in lib/ (method_exists, is_callable, $x->$m(), call_user_func): 43. Always suspect: a renamed OR method makes these silently skip.</summary>

- `lib/Command/ReferencesAuditCommand.php:416` `$out = $row->$method();`
- `lib/Controller/LoonaangifteMessageController.php:148` `$data = (is_object($filing) === true && method_exists($filing, 'jsonSerialize') === true) ? (array)$filing->jsonSerialize() : (array)$filing;`
- `lib/Controller/ThirdPartyReportController.php:156` `$report = (is_object($report) === true && method_exists($report, 'jsonSerialize') === true) ? (array)$report->jsonSerialize() : (array)$report;`
- `lib/Lifecycle/PayrollRunApprovedGuard.php:160` `if (is_object($run) === true && method_exists($run, 'jsonSerialize') === true) {`
- `lib/Listener/CostAllocationGuardListener.php:223` `if (method_exists($event, 'setErrors') === false \|\| method_exists($event, 'stopPropagation') === false) {`
- `lib/Listener/CostAllocationGuardListener.php:223` `if (method_exists($event, 'setErrors') === false \|\| method_exists($event, 'stopPropagation') === false) {`
- `lib/Listener/ResourceBookingOverlapListener.php:180` `if (method_exists($event, 'setErrors') === false \|\| method_exists($event, 'stopPropagation') === false) {`
- `lib/Listener/ResourceBookingOverlapListener.php:180` `if (method_exists($event, 'setErrors') === false \|\| method_exists($event, 'stopPropagation') === false) {`
- `lib/Listener/TimeEntryStampListener.php:477` `if (method_exists($event, 'setErrors') === false \|\| method_exists($event, 'stopPropagation') === false) {`
- `lib/Listener/TimeEntryStampListener.php:477` `if (method_exists($event, 'setErrors') === false \|\| method_exists($event, 'stopPropagation') === false) {`
- `lib/Listener/TimeEstimateListener.php:199` `if (method_exists($event, 'setErrors') === false \|\| method_exists($event, 'stopPropagation') === false) {`
- `lib/Listener/TimeEstimateListener.php:199` `if (method_exists($event, 'setErrors') === false \|\| method_exists($event, 'stopPropagation') === false) {`
- `lib/Listener/TimesheetProcessStampListener.php:407` `if (method_exists($event, 'setErrors') === true) {`
- `lib/Listener/TimesheetProcessStampListener.php:411` `if (method_exists($event, 'stopPropagation') === true) {`
- `lib/Listener/TravelAmountListener.php:289` `if (method_exists($event, 'setErrors') === false) {`
- `lib/Listener/WorkingPatternOverlapListener.php:218` `if (method_exists($event, 'setErrors') === false \|\| method_exists($event, 'stopPropagation') === false) {`
- `lib/Listener/WorkingPatternOverlapListener.php:218` `if (method_exists($event, 'setErrors') === false \|\| method_exists($event, 'stopPropagation') === false) {`
- `lib/Service/CostRateAccess.php:365` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/HrDocumentService.php:646` `$filePath = (is_object($file) === true && method_exists($file, 'getPath') === true) ? (string)$file->getPath() : $fileName;`
- `lib/Service/HrDocumentSigningService.php:269` `$fileId = (is_object($file) === true && method_exists($file, 'getId') === true) ? (int)$file->getId() : 0;`
- `lib/Service/LeaveCalendarService.php:412` `$path = (method_exists($backend, 'getCalendarObjectByUID') === true)`
- `lib/Service/LeaveCalendarService.php:433` `if (method_exists($target->backend, 'createCalendarObject') === false) {`
- `lib/Service/LeaveCalendarService.php:456` `if (method_exists($target->backend, 'updateCalendarObject') === false) {`
- `lib/Service/LeaveCalendarService.php:511` `if (method_exists($target->backend, 'deleteCalendarObject') === false) {`
- `lib/Service/LeaveCalendarService.php:540` `$objects = (method_exists($backend, 'getCalendarObjects') === true) ? $backend->getCalendarObjects($run->target->calendarId) : [];`
- `lib/Service/LeaveCalendarService.php:616` `if (method_exists($target->backend, 'getCalendarObject') === false) {`
- `lib/Service/LeaveCalendarService.php:874` `if (method_exists($backend, $method) === false) {`
- `lib/Service/OfferLetterService.php:180` `$fileId = (is_object($file) === true && method_exists($file, 'getId') === true) ? (int)$file->getId() : 0;`
- `lib/Service/PayrollAuditVerificationService.php:202` `$id = (method_exists($entry, 'getId') === true) ? $entry->getId() : null;`
- `lib/Service/PayrollAuditVerificationService.php:279` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/PayrollAuditVerificationService.php:283` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/PayrollReproduceService.php:318` `$expected[$field] = $this->euros((int)$result->{$resultProperty});`
- `lib/Service/PayrollReproduceService.php:452` `if (method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/PayrollReproduceService.php:456` `if (method_exists($row, 'getObject') === true) {`
- `lib/Service/RegisterLookupGateway.php:109` `$data = method_exists($log, 'getObject') === true ? ($log->getObject() ?? []) : [];`
- `lib/Service/ShillinqPayables.php:163` `if (is_object($row) === true && (method_exists($row, 'jsonSerialize') === true \|\| method_exists($row, 'getObject') === true)) {`
- `lib/Service/ShillinqPayables.php:163` `if (is_object($row) === true && (method_exists($row, 'jsonSerialize') === true \|\| method_exists($row, 'getObject') === true)) {`
- `lib/Service/ShillinqPayables.php:164` `$data = (method_exists($row, 'jsonSerialize') === true ? (array)$row->jsonSerialize() : (array)$row->getObject());`
- `lib/Service/ShillinqPayables.php:165` `if (isset($data['id']) === false && isset($data['@self']['id']) === false && method_exists($row, 'getUuid') === true) {`
- `lib/Service/WorkingCalendarReader.php:123` `if (method_exists($service, self::CANDIDATE_METHOD) === false) {`
- `lib/Support/ConvertsRowsToArrays.php:45` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Support/RegisterSlugLookup.php:256` `if (is_object($mapper) === false \|\| method_exists($mapper, 'findIdsBySlugs') === false) {`
- `lib/Support/RegisterSlugLookup.php:283` `if (is_object($resolver) === false \|\| method_exists($resolver, 'resolve') === false) {`

</details>

Unresolved call sites (receiver type unknown, magic, or spread args): 11, listed in `humaniq.json`.

## versioniq

Local dir `app-versions`, branch `fix/spec-tags-on-version-compare`, sha `64693db57a2d6398c8e611d99741dc68ad159804`.

### Runtime breaks (lib): 0

### Test-suite breaks (tests): 0

Unresolved call sites (receiver type unknown, magic, or spread args): 0, listed in `versioniq.json`.

## planninq

Local dir `planix`, branch `development`, sha `19c35bed88b9d10f519fae6826e1d3726187e0e6`.

### Runtime breaks (lib): 0

### Test-suite breaks (tests): 8

| file:line | category | stub | app calls | OR main now | fix |
|---|---|---|---|---|---|
| `tests/stubs/openregister-apphost.stub.php:76` | parent-missing |  | GenericDashboardController extends OCA\OpenRegister\AppHost\Observability\Controller | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |
| `tests/stubs/openregister-apphost.stub.php:110` | parent-missing |  | GenericPreferencesController extends OCA\OpenRegister\AppHost\Observability\Controller | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |
| `tests/stubs/openregister-apphost.stub.php:153` | parent-missing |  | GenericHealthController extends OCA\OpenRegister\AppHost\Observability\Controller | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |
| `tests/stubs/openregister-apphost.stub.php:183` | parent-missing |  | GenericMetricsController extends OCA\OpenRegister\AppHost\Observability\Controller | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |
| `tests/stubs/openregister-apphost.stub.php:227` | parent-missing |  | GenericAdminSettings implements OCA\OpenRegister\AppHost\Observability\IDelegatedSettings | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |
| `tests/stubs/openregister-apphost.stub.php:292` | parent-missing |  | GenericSettingsSection implements OCA\OpenRegister\AppHost\Observability\IIconSection | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |
| `tests/stubs/openregister-apphost.stub.php:359` | parent-missing |  | GenericDeepLinkRegistrationListener implements OCA\OpenRegister\AppHost\Observability\IEventListener | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |
| `tests/stubs/openregister-apphost.stub.php:523` | parent-missing |  | RegisterLeafProvidersEvent extends OCA\OpenRegister\AppHost\Observability\Event | does not exist on OR main: class fails to load (fatal on autoload) | implement/extend the new contract |

### Test stubs of OR classes that drifted from OR main: 15 of 33

- `tests/stubs/openregister-apphost.stub.php:76` `OCA\OpenRegister\AppHost\Observability\GenericDashboardController` (missing): class no longer exists on OR main
- `tests/stubs/openregister-apphost.stub.php:110` `OCA\OpenRegister\AppHost\Observability\GenericPreferencesController` (missing): class no longer exists on OR main
- `tests/stubs/openregister-apphost.stub.php:153` `OCA\OpenRegister\AppHost\Observability\GenericHealthController` (missing): class no longer exists on OR main
- `tests/stubs/openregister-apphost.stub.php:183` `OCA\OpenRegister\AppHost\Observability\GenericMetricsController` (missing): class no longer exists on OR main
- `tests/stubs/openregister-apphost.stub.php:227` `OCA\OpenRegister\AppHost\Observability\GenericAdminSettings` (missing): class no longer exists on OR main
- `tests/stubs/openregister-apphost.stub.php:292` `OCA\OpenRegister\AppHost\Observability\GenericSettingsSection` (missing): class no longer exists on OR main
- `tests/stubs/openregister-apphost.stub.php:359` `OCA\OpenRegister\AppHost\Observability\GenericDeepLinkRegistrationListener` (missing): class no longer exists on OR main
- `tests/stubs/openregister-apphost.stub.php:402` `OCA\OpenRegister\AppHost\Observability\NotAuthorizedException` (missing): class no longer exists on OR main
- `tests/stubs/openregister-apphost.stub.php:405` `OCA\OpenRegister\AppHost\Observability\ValidationException` (missing): class no longer exists on OR main
- `tests/stubs/openregister-apphost.stub.php:408` `OCA\OpenRegister\AppHost\Observability\CustomValidationException` (missing): class no longer exists on OR main
- `tests/stubs/openregister-apphost.stub.php:411` `OCA\OpenRegister\AppHost\Observability\ProviderUnavailableException` (missing): class no longer exists on OR main
- `tests/stubs/openregister-apphost.stub.php:442` `OCA\OpenRegister\AppHost\Observability\IntegrationProvider` (missing): class no longer exists on OR main
- `tests/stubs/openregister-apphost.stub.php:462` `OCA\OpenRegister\AppHost\Observability\LeafDescriptor` (missing): class no longer exists on OR main
- `tests/stubs/openregister-apphost.stub.php:523` `OCA\OpenRegister\AppHost\Observability\RegisterLeafProvidersEvent` (missing): class no longer exists on OR main
- `tests/stubs/openregister/Service/Integration/LeafDescriptor.php:38` `OCA\OpenRegister\Service\Integration\LeafDescriptor` (exists): getRequiresPermission(): not on OR main

### Implements / extends OR classes: 12

- `lib/Lifecycle/PhaseConcludingDocumentGuard.php:42` PhaseConcludingDocumentGuard implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (exists)
- `lib/Listener/DeepLinkRegistrationListener.php:47` DeepLinkRegistrationListener extends `OCA\OpenRegister\AppHost\Listener\GenericDeepLinkRegistrationListener` (exists)
- `lib/Notification/EmailOptInRecipientResolver.php:43` EmailOptInRecipientResolver implements `OCA\OpenRegister\Notifications\Service\Notification\RecipientResolverInterface` (exists)
- `lib/Settings/AdminSettings.php:46` AdminSettings extends `OCA\OpenRegister\AppHost\Settings\GenericAdminSettings` (exists)
- `tests/stubs/openregister-apphost.stub.php:76` GenericDashboardController extends `OCA\OpenRegister\AppHost\Observability\Controller` (missing)
- `tests/stubs/openregister-apphost.stub.php:110` GenericPreferencesController extends `OCA\OpenRegister\AppHost\Observability\Controller` (missing)
- `tests/stubs/openregister-apphost.stub.php:153` GenericHealthController extends `OCA\OpenRegister\AppHost\Observability\Controller` (missing)
- `tests/stubs/openregister-apphost.stub.php:183` GenericMetricsController extends `OCA\OpenRegister\AppHost\Observability\Controller` (missing)
- `tests/stubs/openregister-apphost.stub.php:227` GenericAdminSettings implements `OCA\OpenRegister\AppHost\Observability\IDelegatedSettings` (missing)
- `tests/stubs/openregister-apphost.stub.php:292` GenericSettingsSection implements `OCA\OpenRegister\AppHost\Observability\IIconSection` (missing)
- `tests/stubs/openregister-apphost.stub.php:359` GenericDeepLinkRegistrationListener implements `OCA\OpenRegister\AppHost\Observability\IEventListener` (missing)
- `tests/stubs/openregister-apphost.stub.php:523` RegisterLeafProvidersEvent extends `OCA\OpenRegister\AppHost\Observability\Event` (missing)

<details><summary>Dynamic call sites in lib/ (method_exists, is_callable, $x->$m(), call_user_func): 37. Always suspect: a renamed OR method makes these silently skip.</summary>

- `lib/Controller/FlowController.php:191` `if (method_exists($objectService, 'clearCurrents') === true) {`
- `lib/Controller/TimelineController.php:185` `if (method_exists($objectService, 'clearCurrents') === true) {`
- `lib/Controller/TimelineController.php:640` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Listener/TaskScopeResolver.php:226` `if (is_object($entity) === true && is_callable([$entity, 'getSlug']) === true) {`
- `lib/Listener/TaskScopeResolver.php:226` `if (is_object($entity) === true && is_callable([$entity, 'getSlug']) === true) {`
- `lib/Service/DependencyRepository.php:276` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/FlowHistoryService.php:449` `if (is_callable([$entity, 'getObject']) === true) {`
- `lib/Service/FlowHistoryService.php:449` `if (is_callable([$entity, 'getObject']) === true) {`
- `lib/Service/FlowHistoryService.php:454` `if (is_callable([$entity, 'getUuid']) === true) {`
- `lib/Service/FlowHistoryService.php:454` `if (is_callable([$entity, 'getUuid']) === true) {`
- `lib/Service/FlowHistoryService.php:460` `if (is_callable([$entity, 'getCreated']) === true) {`
- `lib/Service/FlowHistoryService.php:460` `if (is_callable([$entity, 'getCreated']) === true) {`
- `lib/Service/LabelService.php:378` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/MsProjectImportService.php:361` `if (is_object($row) === true && is_callable([$row, 'getUuid']) === true) {`
- `lib/Service/MsProjectImportService.php:361` `if (is_object($row) === true && is_callable([$row, 'getUuid']) === true) {`
- `lib/Service/MsProjectImportService.php:380` `if (is_object($row) === true && is_callable([$row, 'getObject']) === true) {`
- `lib/Service/MsProjectImportService.php:380` `if (is_object($row) === true && is_callable([$row, 'getObject']) === true) {`
- `lib/Service/ProjectMembershipService.php:672` `if (is_object($row) === true && is_callable([$row, 'getUuid']) === true) {`
- `lib/Service/ProjectMembershipService.php:672` `if (is_object($row) === true && is_callable([$row, 'getUuid']) === true) {`
- `lib/Service/ProjectMembershipService.php:691` `if (is_object($row) === true && method_exists($row, 'getObject') === true) {`
- `lib/Service/ReadsObjectRowId.php:94` `if (is_callable([$entity, $getter]) === false) {`
- `lib/Service/ReadsObjectRowId.php:99` `return $entity->$getter();`
- `lib/Service/RegisterImportService.php:228` `if (is_object($mapper) === false \|\| method_exists($mapper, 'clearFindCache') === false) {`
- `lib/Service/RiskScaleService.php:305` `if (is_object($row) === true && is_callable([$row, 'getUuid']) === true) {`
- `lib/Service/RiskScaleService.php:305` `if (is_object($row) === true && is_callable([$row, 'getUuid']) === true) {`
- `lib/Service/TaskCalendarExportService.php:411` `if (is_object($backend) === false \|\| method_exists($backend, $method) === false) {`
- `lib/Service/TaskCalendarTaskStore.php:160` `if (is_object($row) === true && is_callable([$row, 'getUuid']) === true) {`
- `lib/Service/TaskCalendarTaskStore.php:160` `if (is_object($row) === true && is_callable([$row, 'getUuid']) === true) {`
- `lib/Service/TaskCalendarTaskStore.php:212` `if (is_object($row) === true && is_callable([$row, 'getObject']) === true) {`
- `lib/Service/TaskCalendarTaskStore.php:212` `if (is_object($row) === true && is_callable([$row, 'getObject']) === true) {`
- `lib/Service/TaskCalendarTaskStore.php:216` `if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {`
- `lib/Service/TimetableScenarioStore.php:259` `if (method_exists($objectService, 'clearCurrents') === true) {`
- `lib/Service/TimetableSessionRows.php:131` `if (is_object($row) === true && is_callable([$row, 'getObject']) === true) {`
- `lib/Service/TimetableSessionRows.php:131` `if (is_object($row) === true && is_callable([$row, 'getObject']) === true) {`
- `lib/Service/TimetableSessionRows.php:250` `if (is_callable([$entity, $getter]) === false) {`
- `lib/Service/TimetableSessionRows.php:255` `$value = $entity->$getter();`
- `lib/Service/TimetableSessionService.php:641` `if (method_exists($objectService, 'clearCurrents') === true) {`

</details>

Unresolved call sites (receiver type unknown, magic, or spread args): 19, listed in `planninq.json`.

