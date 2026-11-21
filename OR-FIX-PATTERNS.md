# OpenRegister fix patterns

Recurring causes of red openregister PRs after the restructure, with their fix recipe. Fix lanes append here; read before fixing.

## Seed: duplicate container bindings after an area move
- Symptom: AreaMapperRegistrarsTest::testEachInterfaceIsBoundOnce or a registrar test counting bindings fails.
- Cause: an interface moved to another area registrar (e.g. Core DbRegistrar) while the old area still binds it, or a registrar gained a binding without its test count.
- Fix: keep the binding only in the owning area (see scripts/component-map.csv), update the registrar test count (see main #10975).

## New migration fails SchemaReuseHygieneTest after main merge
- Symptom: SchemaReuseHygieneTest testNoChangeSchemaReturnsNull / testEveryStepAfterRelease210IsTargeted name the PR's lib/Migration/Version*.php.
- Cause: main requires every new step to extend TargetedMigrationStep and return $schema at every exit (upgrade-performance spec).
- Fix: extends TargetedMigrationStep, rename changeSchema to protected changeTargetedSchema, drop the SimpleMigrationStep import, every 'return null;' becomes 'return $schema;', update its test's assertNull to assertSame($schema, ...). (or-fix-4, #10244)

## New entity column fails goldens and EntityInterfacesTest
- Symptom: EntityHydrationGoldenTest / SchemaHydratePlumbingGoldenTest arrays differ; EntityInterfacesTest::testEveryColumnAccessorIsOnTheInterface lists get/set of the new column.
- Cause: main pins entity field types in goldens and requires column accessors on the Kernel/Contracts/<Entity>Interface @method list.
- Fix: UPDATE_GOLDEN=1 phpunit tests/Unit/Db/EntityHydrationGoldenTest.php; add the columns to SchemaHydratePlumbingGoldenTest in field order; add the @method lines to lib/Kernel/Contracts/<Entity>Interface.php. (or-fix-4, #10244)

## Parked 'precheck-red' on TalkGatewaySetDescriptionTest (or-fix-3)
- Symptom: parked.txt says precheck-red alone on main: TalkGatewaySetDescriptionTest::testWithoutTalkNothingIsResolved; locally 'Class OCP\EventDispatcher\Event not found' from tests/stubs/Integriq.
- Cause: the test runs in a separate process; a -d auto_prepend_file OCP autoloader on the php command line is not inherited by the child. Not a PR defect; green on main and on the PRs.
- Fix: put auto_prepend_file in an ini via PHP_INI_SCAN_DIR=<default scan dir>:<dir with zz-ocp.ini>, rerun; merge main, push, REVERIFY. Note AreaMapperRegistrarsTest::testEachInterfaceIsBoundOnce (Translation*Interface in ConfigurationMapperRegistrar) is red on main b85c154e: inherited.

## New migration step is not targeted / clashes with a main migration (or-fix-7)
- Symptom: SchemaReuseHygieneTest::testEveryStepAfterRelease210IsTargeted lists the PR's lib/Migration/Version*.php; or an add/add conflict on a Version1Date*.php main also added.
- Cause: main now requires every new migration to extend TargetedMigrationStep (implement changeTargetedSchema, not changeSchema); main also took some version numbers PR branches picked.
- Fix: rename the PR's step to a version later than main's newest (class name too), extends TargetedMigrationStep, changeSchema -> protected changeTargetedSchema (same body), drop the SimpleMigrationStep import, add its scripts/component-map.csv row, add a unit test (mock ISchemaWrapper + OCP\DB\Schema\ITable; call changeSchema directly).

## Application::APP_ID gone after a main merge
- Symptom: Error: Class "OCA\OpenRegister\<Ns>\Application" not found (phpstan may stay clean), usually where a merge conflict sat in the use block.
- Cause: main replaced `use OCA\OpenRegister\AppInfo\Application` + Application::APP_ID with Kernel\Contracts\AppIdentity::APP_ID across lib/ (ADR-011 Rule 4); the merge keeps the PR's Application::APP_ID lines but main's imports.
- Fix: in the PR's lib files, swap the import for `use OCA\OpenRegister\Kernel\Contracts\AppIdentity;` and Application::APP_ID for AppIdentity::APP_ID (see #10526 b1ec3389).

## Merge conflicts in scripts/component-map.csv and the generated components yaml (or-fix-14)
- Symptom: merging main conflicts in scripts/component-map.csv (rows added next to each other) and in the generated components yaml at the repo root.
- Cause: every PR adds csv rows for its new lib files; main adds others at the same spots; the yaml is generated from the csv.
- Fix: union the csv hunks keyed on the first column (main's row wins on the same file), then `git checkout --theirs` the yaml and run `python3 scripts/component-patterns.py`; the yaml diff vs main should only show the PR's own files. Format-list conflicts (PropertyValidatorHandler, property-validate-golden.json): keep both formats in handler order.

## ListenerRegistrationOrderGoldenTest conflict or red after adding a listener (or-fix-10)
- Symptom: merge conflict on LISTENER_COUNT / SEQUENCE_SHA256 in tests/Unit/Listener/ListenerRegistrationOrderGoldenTest.php, or the test red on the merged tree.
- Cause: the PR registers a new listener; main changed the count and hash for its own registrations meanwhile (and fixtures/listener-order.json auto-merges).
- Fix: `git checkout --theirs` the test, set LISTENER_COUNT to main's + the PR's new registrations, run the test and copy the Actual sha256 into SEQUENCE_SHA256 (count assertion passes first), then run tests/Unit/Listener (see #10450 merge).

## A PR that changes a method signature, and main added new callers since
- Symptom: after merging main, phpstan argument.unknown/argument.missing on the changed method; tests fail with "Unknown named parameter $x" or a TypeError inside a willReturnCallback closure typed with the old parameters; ->with(old positional args) expectations fail.
- Cause: main kept adding callers and test doubles of the old signature (e.g. #9053 hasPermission(PermissionAsk): 10 new lib callers, ~22 tests).
- Fix: phpstan the files that grep the method name (container->get() callers are mixed-typed: also grep `method(name:`); convert each to the PR's form with the same arguments; closures take the new value object; ->with(...) becomes one $this->callback() over its fields. Then diff the unit failures against a main worktree with its OWN vendor copy (a symlinked vendor autoloads the other checkout's lib/).
- Also seen: Db\ListQuery moved to Kernel\Contracts\ListQuery on main; a use-line union then imports both and php -l fails on the duplicate alias: keep the Kernel one.

## New lib file without a component-map row
- Symptom: Architecture ComponentDefinitionTest::testEveryFileMatchesExactlyOneComponent / testEveryFileHasACsvRowAndTheYamlAgrees name a lib file that 'matches no component'.
- Cause: main now checks every lib file against scripts/component-map.csv; PRs older than that check add files without a row.
- Fix: append `<path under lib>,,<area>,,,,,<component>` (component as its siblings' rows), run `python3 scripts/component-patterns.py`, commit csv + generated yaml (or-fix-9, #9869).

## Migration class name taken on main / no version bump
- Symptom: add/add conflict on lib/Migration/Version1Date<ts>.php, or SchemaReuseHygieneTest::testEveryStepAfterRelease210IsTargeted, or BLOCK 'migration without info.xml bump'.
- Cause: main landed a different migration under the same timestamp; main requires every new step to extend TargetedMigrationStep (changeTargetedSchema).
- Fix: take main's file, re-add the PR's step under a new later timestamp extending TargetedMigrationStep (protected changeTargetedSchema), rename its component-map row, bump appinfo/info.xml <version>, check with php scripts/check-migration-version-bump.php --base=origin/main (see #10277).

## New test builds a class with its pre-modularity constructor (or-fix-11)
- Symptom: after the main merge a PR's new test errors with TypeError / ArgumentCountError on the constructor (e.g. RechainAuditTrailPreVerifyTest passing RepairPlatform).
- Cause: a cloud/mod-* PR on main changed the class to take its ports directly (e.g. #10815: IDBConnection, IAppConfig, LoggerInterface, AuditRechainInterface instead of RepairPlatform + AuditHashService).
- Fix: read the constructor on main, pass its arguments in order, mock the interface it now types (not the concrete service), drop the stale @uses. (#10491 6d52f2fd)

## Component patterns not regenerated after adding lib files
- Symptom: ComponentDefinitionTest::testEveryFileMatchesExactlyOneComponent / testEveryFileHasACsvRowAndTheYamlAgrees: 'lib/X.php matches no component' or 'is Core in component-map.csv but matches []'.
- Cause: the PR (often after a component-map.csv conflict merge) has csv rows but the generated components yaml at the repo root was not regenerated.
- Fix: keep both sides' csv rows, run python3 scripts/component-patterns.py, commit the regenerated yaml with the merge (see #10525).

## New built-in flow node trips three registry sweeps (or-fix-18)
- Symptom: FlowsDocumentationTest::testEveryRegisteredNodeIsDocumented, FlowNodeFormSweepTest::testEveryBuiltInPaletteEntryCarriesAForm and/or RecentLensLookupCallSitesTest::testEveryInternalFindHasADecision name the new node.
- Cause: main's sweeps walk every registered openregister.* node: each needs a paragraph in docs/features/flows.md, an IFlowNodeConfigForm::configForm() (keys = configKeys(), labels via IL10N), and every internal ObjectService find() a decision.
- Fix: add the doc paragraph next to its siblings; implement IFlowNodeConfigForm; wrap the node's find in WriteCause::asLookup(fn () => ...) as LinkObjectNode does (or pass _audit: false). See #10358.

## phpstan argument.type on AuditRecordInterface records (or-fix-10)
- Symptom: phpstan 'array_map expects callable(...Kernel\Contracts\Core\AuditRecordInterface)..., Closure(AuditTrail) given' in a PR's new code that consumes DeletedObjectAuthorizer or audit lookups.
- Cause: main retyped those returns to the Core marker port AuditRecordInterface (no methods).
- Fix: narrow with array_filter($records, fn (AuditRecordInterface $r): bool => $r instanceof AuditTrail) before mapping; add a test that a non-AuditTrail record is skipped (see #10490 70ae2ae5). DeletedController::1077 has the same error on main: inherited.

## Outside lib/Service naming an ObjectAction class directly
- Symptom: C12InterfaceBindingsTest::testNoOutsideFileNamesTheClass with data set ...\ObjectAction\ObjectActionDefinition (or another class in tests/Unit/Architecture/C12/*.php).
- Cause: main forbids files outside lib/Service from naming the concrete class; only the listed 'kept' files may.
- Fix: use the static facade (ObjectActionDeclarations::declarations/parse) or type against the interface the C12 data file names; never add the file to 'kept'.

## Test env: OCP\\EventDispatcher\\Event not found in process-isolated tests (e.g. TalkGatewaySetDescriptionTest)
- Cause: -d auto_prepend_file is not passed to PHPUnit's isolated child processes, so the OCP autoloader is missing there; not a PR defect.
- Fix: put auto_prepend_file in an ini dir and export PHP_INI_SCAN_DIR=/etc/php/8.3/cli/conf.d:<that dir>; such parked reds pass on the merge.

## Conflict in the generated components yaml
- Symptom: content conflict in the repo-root *.components.yaml (huge alternation lines).
- Cause: generated from scripts/component-map.csv; main and the PR both regenerated it.
- Fix: resolve scripts/component-map.csv (keep both sides' rows), then python3 scripts/component-patterns.py and --check; never hand-merge the yaml (see #10537).

## Test builds a class with a mapper where main now injects a Kernel port (or-fix-7)
- Symptom: TypeError in a test constructor, e.g. TokenResolver argument #4 must be AccountConsumerInterface, ConsumerMapperInterface mock given.
- Cause: main put a Kernel/Contracts port between the class and the mapper (e.g. AccountConsumerInterface, implemented by Service/Consumer/AccountConsumerLookup).
- Fix: keep the test's mapper mock and wrap it in the real port implementation (new AccountConsumerLookup($mapperMock)), add #[UsesClass] for it, so the test still drives the same failure paths.

## Conflict in SchemaMapper::checkDataDeclarations / RecordAnnotationRefusals (or-fix-0)
- Symptom: merge conflict in lib/Db/SchemaMapper.php (checkDataDeclarations now runSlots) and RecordAnnotationRefusals.php; a PR added a `$records->checkX()` save check.
- Cause: main runs schema-save checks in slots of Db/SchemaMapper/SchemaDeclarationOrder; non-Db areas contribute via a SchemaDeclarationChecksEvent listener (see Listener/ReferenceCardDeclarationCheckListener).
- Fix: take main's SchemaMapper and RecordAnnotationRefusals; add a slot constant (e.g. 235 between neighbours) to SchemaDeclarationOrder and to SchemaDeclarationOrderTest's list; put the check in a listener in its area, registered with registerEventListener; add the listener to tests/Unit/Support/ProcessDeclarationChecksDispatcher::listeners(); component-map row + python3 scripts/component-patterns.py. Example: #10300.

## l10n/*.js conflicts on every locale after a build PR merges main
- Symptom: CONFLICT in all 37 l10n/*.js (adjacent key blocks of the PR's and main's new strings).
- Cause: both sides added keys in the same sorted region; the files are generated (apply.js is the only writer), so hand-editing is wrong.
- Fix: during the conflicted merge run `node ~/q/bin/l10n-merge.js <checkout> $(git diff --name-only --diff-filter=U | grep '^l10n/.*\.js$')`: three-way per key from index stages 1/2/3 (main wins a key both changed), re-serialized with scripts/l10n/lib.js serializeJs; expect `git diff origin/main --numstat -- l10n` to be only the PR's additions. Parity counts vs a main checkout show nothing new missing.

## Controller lists replaced by markers on main
- Symptom: modify/delete conflict on lib/Controller/Support/MaintenanceReachableControllersInterface.php; F09ControllerBoundaryTest or MaintenanceModeMiddlewareTest pin three controllers.
- Cause: main replaced ALWAYS_REACHABLE with Kernel\Contracts\Configuration\MaintenanceReachableInterface on each controller.
- Fix: git rm the list file; the PR's new controller `implements MaintenanceReachableInterface`; add it to the F09 loop and the middleware test's expected list. Similarly ObjectsController::export moved to ObjectExportController: move attributes such as #[Sheddable] there and repoint tests.

## Mapper contracts return Kernel entity interfaces (main ce0ef67a1da)
- Symptom: phpstan on PR files: 'should return OCA\OpenRegister\Db\Source but returns ...Kernel\Contracts\SourceInterface' or argument.type on Source/Application.
- Cause: SourceMapperInterface and ApplicationMapperInterface now declare Kernel SourceInterface/ApplicationInterface with a covariant template.
- Fix: type the PR's mapper property/param docblock as SourceMapperInterface<Source> (ApplicationMapperInterface<Application>), as main's callers do; no casts.

## New migration step not targeted
- Symptom: SchemaReuseHygieneTest::testEveryStepAfterRelease210IsTargeted lists the PR's lib/Migration/VersionX.php.
- Cause: main requires every new step to extend TargetedMigrationStep.
- Fix: extends TargetedMigrationStep, rename public changeSchema() to protected changeTargetedSchema(), drop the SimpleMigrationStep import.

## Builders moved out of ServiceRegistrar into area wiring
- Symptom: merge conflict in lib/Service/ServiceRegistrar.php where main's side is empty.
- Cause: main moved build*() factories into area classes (Service/Flow/FlowWiring, Service/Integration/IntegrationWiring, ...).
- Fix: take main's ServiceRegistrar, port the PR's change of that builder into the area wiring file (git grep the builder name on origin/main).
## Undefined constant JobHandles::<X> (or-fix-10)
- Symptom: Error: Undefined constant OCA\OpenRegister\BackgroundJob\JobHandles::<X> in a PR's test or lib code after a main merge.
- Cause: main split the job handle constants per area into lib/BackgroundJob/<Area>JobHandlesInterface.php (Processes, Compliance, Configuration, Integrations).
- Fix: `grep -rn 'const <X>' lib/BackgroundJob` and swap the import and the constant prefix to that interface (see #10530 ef7ee364).

## New lib file without a component row (or-fix-8)
- Symptom: Architecture ComponentDefinitionTest testEveryFileMatchesExactlyOneComponent / testEveryFileHasACsvRowAndTheYamlAgrees name a new lib/ file.
- Cause: main maps every lib file to a component; a PR (or your fix) adds a file without a scripts/component-map.csv row.
- Fix: add the row (copy a sibling's area/component), run python3 scripts/component-patterns.py, commit the regenerated components yaml with it. On merge, component-map.csv conflicts at its tail: keep both sides' rows.

## Migration version taken on main (or-fix-18)
- Symptom: add/add conflict on lib/Migration/Version1Date<ts>.php and its test; or SchemaReuseHygieneTest::testEveryStepAfterRelease210IsTargeted names the PR's step.
- Cause: main shipped a different step under the same version; and every step after 2.1.0 must extend TargetedMigrationStep (changeTargetedSchema, not changeSchema).
- Fix: keep main's file, move the PR's step (class, test, component-map row) to a fresh version newer than main's newest, extend TargetedMigrationStep and rename changeSchema to protected changeTargetedSchema; the test calling changeSchema() keeps working. See #10398.

## File guards resolved through Kernel Core contracts (or-fix-11)
- Symptom: 'Call to undefined method MockObject_PartyIndicatorGuard_...::isRestricted()' in a PR test of FilePublishingHandler; or conflicts in its use block / FileServicePlatform (ShareUrlBuilderInterface).
- Cause: main reads Kernel\Contracts\Core\FileReadRestrictionInterface (was Files FileRestrictionGuardInterface), PublicationRefusalInterface (was PartyIndicatorGuard) and ShareLinkUrlInterface from the container; PipelineSteps lost 'implementedBy' to Files\Service\File\PipelineStepImplementers.
- Fix: keep main's imports plus the PR's own; in tests key the container match on the Core interfaces and mock them; a new pipeline step gets its class in PipelineStepImplementers::IMPLEMENTED_BY and in PipelineStepImplementersTest. (#10451 fd1b51db)

## Main adds callers of a signature the PR changed (or-fix-3)
- Symptom: after merging main: phpstan argument.unknown/argument.missing, 'Unknown named parameter $x', 'Parameter count ... is too low', or callback doubles with the old parameter list failing.
- Cause: the PR moved a method to a value-object parameter (SearchOptions, RegisterSchemaRef, ExampleSetInstallInput...) while main added new callers/tests on the old form.
- Fix: phpstan on all of lib (about 1 min) diffed against main's output finds every lib caller; the whole unit suite runs in about 3 min per top dir with xargs -P4 (Architecture about 8 min) and finds the doubles. Migrate them to the PR's form, keep main's booleans and semantics.

## Migration version taken on main, or a migration step fails SchemaReuseHygieneTest (or-fix-14)
- Symptom: add/add conflict on lib/Migration/Version1DateXXXX.php, or SchemaReuseHygieneTest (return null / not targeted) red after merging main.
- Cause: main landed another step with the same class name; main now requires steps after 2.10 to extend TargetedMigrationStep (protected changeTargetedSchema) and every return to be `return $schema;`.
- Fix: keep main's file, move the PR's step (and its test, tasks.md mention, component-map row) to a new unused Version1Date<today>; extend TargetedMigrationStep, rename changeSchema to protected changeTargetedSchema, turn `return null;` into `return $schema;` and assertNull into assertSame($schema, ...).

## CredentialBrokerService built positionally in a test (or-fix-19)
- Symptom: TypeError: CredentialBrokerService::__construct() Argument #1 ($sources) must be CredentialSources, ObjectService mock given.
- Cause: main bundles the constructor into CredentialSources(objectService, credentialStore, catalogue), CredentialPrincipals(userSession, organisationService, ...) and CredentialOutbound(clientService, logger, ...).
- Fix: wrap the test's existing mocks in those three bundles with named arguments (see main's CredentialBrokerOutsideVaultTest, #9419).
## SchemaMapper declaration checks moved to slots
- Symptom: conflict in lib/Db/SchemaMapper.php checkBehaviourDeclarations / SchemaMapperServices / MapperWiring (objectActions gone on main).
- Cause: main runs the schema-save checks by slot (SchemaDeclarationOrder), contributed via SchemaDeclarationChecksEvent listeners (Processes: lib/Listener/SchemaDeclarationChecksListener.php).
- Fix: take main's three files; add a slot constant for each new check next to its neighbour (extend the section's last slot if at an end), contribute it from the owning area's listener (lazy collaborators via container closure), add the name to SchemaDeclarationOrderTest's list (or-fix-9, #10529).

## New lib file has its csv row but no component pattern (or-fix-7)
- Symptom: ComponentDefinitionTest 'matches no component (Remainder)' / 'matches [] in the components yaml' for a file the PR added, although scripts/component-map.csv has its row (main sometimes carries the row already).
- Fix: python3 scripts/component-patterns.py (rewrites the components yaml), commit the one-line yaml change. Also check registrar order tests (e.g. tests/Unit/Service/ServiceRegistrarTest::testRegistersTheServicesInOrder) for a binding the PR added.

## New migration extends SimpleMigrationStep (or-fix-8)
- Symptom: tests/Unit/Migration/SchemaReuseHygieneTest::testEveryStepAfterRelease210IsTargeted lists the PR's lib/Migration/Version1Date2026....php.
- Cause: main requires every step after 2.1.0 to extend TargetedMigrationStep (its preSchemaChange/changeSchema/postSchemaChange are final).
- Fix: extends TargetedMigrationStep, drop the SimpleMigrationStep import, rename changeSchema->changeTargetedSchema / postSchemaChange->afterTargetedSchemaChange / preSchemaChange->beforeTargetedSchemaChange and make them protected; tests calling the public post/changeSchema keep working.
- Note: ReservedColumnNamesTest errors with an upstream HTTP 404 on MariaDB lex.h are network/environment, not the PR.

## Golden counts and hashes that both sides changed
- Symptom: conflict on ListenerRegistrationOrderGoldenTest LISTENER_COUNT / SEQUENCE_SHA256 (or a similar pinned count).
- Fix: take main's values, add the PR's delta to the count, then dump the recorded sequence on the merged branch and on a main worktree (a throwaway subclass test calling recordedSequence() by reflection) and diff: when the diff is exactly the PR's registrations, take the new hash from the failing assertion. Conflicts in the components yaml: resolve scripts/component-map.csv (union of rows), take main's yaml, run `python3 scripts/component-patterns.py`.

## Test fixture under tests/fixtures matches a .gitignore rule (or-fix-19)
- Symptom: a PR's test fails on a fresh checkout with 'cannot be read: <file>.csv' (or file not found) while it passed for its author.
- Cause: .gitignore ignores *.csv (and similar) repo-wide; the author's local fixture was never committed.
- Fix: git check-ignore -v <path>; recreate the fixture to what the tests assert and commit it with git add -f (see #10139).

## Listener registration conflict in ListenerRegistrar after the area split
- Symptom: conflict in lib/Listener/ListenerRegistrar.php where main has ComplianceListenerRegistrar::<slot>(context: $context) (or another Registrar/*ListenerRegistrar).
- Fix: take main's call; add the PR's registrations inside that area slot method in lib/Listener/Registrar/, update its slot test (tests/Unit/Listener/<Area>ListenerRegistrarTest) and ListenerRegistrationOrderGoldenTest count+sha (run it, copy the actual sha).

## Audit rows built with new AuditTrail() + mapper vs main's Core audit port
- Symptom: conflict in a *Auditor (new AuditTrail()/AuditTrailMapperInterface vs new AuditEntry()/AuditEntryWriterInterface), or a test failing 'Unknown named parameter $mapper'.
- Fix: take main's AuditEntry; per-row fields the port lacks (e.g. category) come from AuditTrailMapper::insertAuditTrails/stampCategory; tests construct the auditor with trail: new AuditEntryWriter(mapper: <mock>).

## Organisation events return OrganisationInterface on main
- Symptom: phpstan argument.type 'OrganisationInterface&Entity given' where Db\\Organisation expected.
- Fix: narrow with instanceof Db\\Organisation in the caller (only implementor) and skip otherwise; never change the event signature.

## ComponentDefinitionTest: new lib file matches no component / yaml disagrees
- Fix: add the scripts/component-map.csv row the message names, then run python3 scripts/component-patterns.py and commit the regenerated yaml.
- (or-fix-16) TargetedMigrationStep addendum: a step with preSchemaChange()/postSchemaChange() renames them to protected beforeTargetedSchemaChange()/afterTargetedSchemaChange() (the base's are final); tests calling the public hooks keep working.
## Listener golden after a merge (ListenerRegistrationOrderGoldenTest)
- Symptom: conflict in LISTENER_COUNT / SEQUENCE_SHA256, or 'actual size N matches expected size M'.
- Fix: take main's test, dump the registration sequence (patch the test to file_put_contents the calls), check that dropping the PR's listener lines reproduces main's sha, then set count and sha to the merged values.
