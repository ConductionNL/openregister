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

## Constructor reshaped on the PR, main added a test using the old signature
- Symptom: TypeError in a test file the PR never touched (e.g. 'Argument #4 must be of type ?X, MockObject_Y given').
- Cause: main added a test after the PR branched, calling the old positional constructor.
- Fix: update that main test to the PR's new signature (grep tests/ for 'new <Class>('), in the PR branch after the main merge. (or-fix-4, #9824)

## ComponentDefinitionTest: class matches no component / yaml is stale (or-fix-3)
- Symptom: ComponentDefinitionTest::testYamlIsTheGeneratorsOutput (or 3 cases) red: a new lib class has a component-map.csv row but the components yaml lacks it, or a moved file still has a pattern.
- Cause: the PR (or the main merge) added/moved lib files without rerunning the generator.
- Fix: python3 scripts/component-patterns.py, commit the regenerated yaml (on a yaml merge conflict: take main's copy, then regenerate). 'stale row' lines are warnings only.

## PR code or tests still name a class main replaced with a Core port (or-fix-14)
- Symptom: 'Class ...\FileSettingsHandler not found' in a service now typed on a port, or ArgumentCountError on a constructor (e.g. DetectorVersionReader, AnonymisationBackendCollaborators) in the PR's own tests.
- Cause: main moved the dependency behind a Kernel/Contracts port and the PR's new lines still use the old class, its constants or the old constructor arity.
- Fix: read the constructor on main; pass the real adapter (e.g. new PatternSetVersion()) or a mock of the port in the PR's tests, as main's own tests do; restate constants the port does not expose as a private const rather than importing the concrete class across areas.

## Concrete entity type left after main moved a class to a Kernel interface (or-fix-11)
- Symptom: TypeError '... must be of type OCA\OpenRegister\Service\<Area>\<Entity>, OCA\OpenRegister\Db\<Entity> given'; phpstan argument.type naming a class in the file's own namespace.
- Cause: main retyped the file on Kernel\Contracts\Core\<Entity>Interface and dropped 'use ...\Db\<Entity>'; the PR's new methods still say <Entity>, which now resolves to a non-existent same-namespace class. Auto-merges clean.
- Fix: type the PR's methods (and @param) on the interface main uses; phpstan on changed lib files finds them all. (#10531 FederatedShare)

## Migration version clash, or a new step not targeted (or-fix-0)
- Symptom: add/add conflict in lib/Migration/Version1Date<ts>.php, or SchemaReuseHygieneTest::testEveryStepAfterRelease210IsTargeted names the PR's migration.
- Cause: main took the same version name; and every step after 2.1.0 must extend TargetedMigrationStep (#10612).
- Fix: keep main's file; move the PR's to a later timestamp (class, test file/class, component-map row, comments); extend TargetedMigrationStep and rename postSchemaChange/preSchemaChange/changeSchema to afterTargetedSchemaChange/beforeTargetedSchemaChange/changeTargetedSchema (protected). Example: #10540.

## MagicSearchHandler needs the property search profile (or-fix-0)
- Symptom: LogicException 'MagicSearchHandler needs the property search profile to search properties.' in a PR's new test.
- Cause: main added PropertySearchProfileInterface to MagicSearchHandler; MagicMapper passes it, tests must too.
- Fix: in the test's constructor call add searchProfile: new \OCA\OpenRegister\Search\Service\Search\PropertySearchProfile(). Example: #10540.

## Main adds tests constructing a value object whose constructor the PR split (or-fix-17)
- Symptom: after merging main, 'Unknown named parameter $appId' or TypeError 'Argument #1 ($listing) must be of type StoreListing, string given' in tests main added (e.g. GenericStoreServiceFetchGoldenTest); parked as bisect-park.
- Cause: the PR replaced a flat constructor with part objects (StoreDescriptor(listing:, sharing:)); main kept adding tests on the flat form.
- Fix: grep 'new <Class>(' across lib and tests, wrap the old arguments in the part object(s), add #[UsesClass] for the parts; keep any new main helper (offeredTypes) inside the PR's form in conflict hunks (#9817).

## ObjectEntity gains a transient @self field: row-to-entity golden breaks (or-fix-19)
- Symptom: MagicSearchHandlerRowToEntityTest::testRowHydratesTheRecordedEntity fails for ~79 of 82 data sets; the actual entity has one extra field.
- Cause: tests/Unit/Db/MagicMapper/fixtures/row-to-entity.php pins every entity property positionally.
- Fix: re-record by calling the test's public runCase() for each cases() entry and var_export into the fixture (keep its header); the diff must be only the new field (see #10239).

## Schema-save check conflicts with main's runSlots/accessSteps (SchemaMapper)
- Symptom: conflict in SchemaMapper where main has $this->runSlots(steps: $this->*Steps(...)) and the PR adds a $access->checkX() line.
- Fix: take main's call; add a SchemaDeclarationOrder constant between its neighbours (e.g. 355), add the step to the matching *Steps() array, add the name to SchemaDeclarationOrderTest::testTheSlotsRunInThisOrder.

## Annotation key conflict in Db/Schema/AnnotationVocabulary.php
- Cause: main lists annotation keys as Schema::*_ANNOTATION constants; Db may not import Service classes.
- Fix: add the key as a Schema constant, point the Service class's ANNOTATION at it, list the Schema constant.

## Person-link events return ContactLinkInterface on main
- Fix: same as the organisation entry: instanceof Db\\ContactLink, skip otherwise, add a test with a createMock(ContactLinkInterface).

## Main's newer tests and helpers built for the old shape of what the PR changed
- Symptom: after the merge, ArgumentCountError in a test main added for a controller the PR gave new constructor params; TypeError "ObjectsControllerFactory::args(): Argument #14 ($eventDispatcher) ... WebhookService given" in the PR's own tests; "Class OCA\OpenRegister\Files\Service\Sharing\AccessLink not found".
- Cause: main moved AccessLinkReader into Files (it reads AccessLinkInterface constants, so a constant the PR added on Db\AccessLink resolves to a missing class); main dropped WebhookService from ObjectsControllerFactory (14th positional is IEventDispatcher).
- Fix: pass the new mocks in main's test; give positional factory calls `createMock(IEventDispatcher::class)`; put a new subject constant on Kernel\Contracts\AccessLinkInterface and alias it on Db\AccessLink. component-map.csv conflicts: `python3 ~/q/bin/component-map-merge.py scripts/component-map.csv` (union, main's row wins per file).

## New test builds a constructor main changed (or-fix-18)
- Symptom: TypeError 'Argument #14 ($eventDispatcher) must be of type ?IEventDispatcher, MockObject_WebhookService given' from ObjectsControllerFactory, or ArgumentCountError on FacetHandler::__construct (searchProfile).
- Cause: main 08711497dff replaced ObjectsController's WebhookService with IEventDispatcher (webhooks answer a request event); FacetHandler gained a required PropertySearchProfileInterface.
- Fix: in the PR's new tests pass createMock(IEventDispatcher::class) in that slot, and searchProfile: new PropertySearchProfile(); see #10538 and, for AggregationQuery::create(groupBy:) -> layout: new AggregationLayout(...), #9818.

## Schema save checks moved out of SchemaMapper::checkDataDeclarations (or-fix-12)
- Symptom: conflict in lib/Db/SchemaMapper.php (checkDataDeclarations is now runSlots/dataSteps) and in lib/Db/SchemaMapper/RecordAnnotationRefusals.php, for a PR that added a $records->checkX() call.
- Cause: main runs save checks in numbered slots (SchemaDeclarationOrder); area checks come from listeners on SchemaDeclarationChecksEvent (archival/retention: ArchivalDeclarationRefusals + ArchivalDeclarationChecksListener).
- Fix: take main's SchemaMapper and RecordAnnotationRefusals; move the PR's method into the owning area's refusals class and call it from that listener, inside the neighbouring slot's closure (keeps SchemaDeclarationOrderTest's slot list) or as a new slot (then update that test and the listener test); rename the test's subject class; add a listener test that the slot runs it. See #10132, #10232.

## Method not found on a narrowed Kernel port after merging main (or-fix-19)
- Symptom: phpstan method.notFound on e.g. RedactionSettingsInterface::personRegister() in a class whose constructor main retyped from a concrete handler to a Kernel/Contracts port.
- Cause: the modularity work narrowed cross-area dependencies to ports declaring only the methods main used; a PR calls more of the concrete class.
- Fix: declare the PR's extra methods on that port when its bound implementation already has them (check ContractBindings), keeping signatures identical; do not retype back to the concrete class (see #10499).

## A10/C12InterfaceBindingsTest: a new outside caller names a concrete class (or-fix-3)
- Symptom: testOnlyTheKeptCallersNameTheClass / testNoOutsideFileNamesTheClass lists a PR file naming e.g. SlaCalculator or WorkingCalendar.
- Fix: inject the interface (through the caller's DI-built sources bundle when the caller is built by hand, never a 'new Concrete()' default); when the interface lacks a method the caller needs and the concrete class already has it, declare it on the interface and widen the value objects that carry it. Never add the file to the kept list.

## SchemaReuseHygieneTest::testEveryStepAfterRelease210IsTargeted (or-fix-3)
- Symptom: a PR's new migration extends SimpleMigrationStep.
- Fix: extend TargetedMigrationStep, rename changeSchema() to protected changeTargetedSchema(), drop the SimpleMigrationStep import.

## A binding conflicts in Service/ServiceRegistrar.php (or-fix-19)
- Symptom: content conflict in lib/Service/ServiceRegistrar.php and ServiceRegistrarTest's expected list; main's side drops seams such as EmailLinkReaderInterface, CredentialStore, SourceFetcherRegistry.
- Cause: main moved non-Core seams to area wirings (e.g. Service/Integration/IntegrationWiring::registerSeams); ServiceRegistrar only names Core classes.
- Fix: take main's ServiceRegistrar and its test; add the PR's registerService beside its sibling seam in the area wiring and add the interface to that wiring's order test (IntegrationRegistrarTest) (see #10539).

## ContractBindings use-block conflict with an empty main side (or-fix-2)
- Symptom: CONFLICT in lib/Service/ContractBindings.php use block; main's side of the hunk is empty, HEAD lists ExternalLink/Federation/FieldEncryption imports plus the PR's new ones.
- Cause: main moved those bindings to area registrars and dropped their imports; the PR's import lines sit inside the same hunk.
- Fix: take main's (empty) side and re-add only the PR's own use lines; the PR's binding rows auto-merge. php -l, phpstan, Architecture (see #10182 de9d4f8b).

## Migration version shared by several open PRs, or no info.xml bump (or-fix-16)
- Symptom: none on the PR alone; php scripts/check-migration-version-bump.php --base=origin/main fails ('adds N migration(s) but does not move the app version'), and the same lib/Migration/Version1Date<ts>.php is added by other open PRs (whichever lands second gets an add/add conflict or a duplicate class).
- Find: fetch open PR heads and list `git diff --name-only --diff-filter=A origin/main...<head> -- lib/Migration` per head; count duplicates.
- Fix: git mv the step and its test to an unused version (keep the order of the PR's own steps; e.g. suffix the lane number in the seconds), sed the class name in csv/tasks.md, python3 scripts/component-patterns.py, bump appinfo/info.xml <version> (dev.N+1), rerun the check (#10496, #10516, #10156).

## Constructor golden cannot double a new nullable param (or-fix-11)
- Symptom: FileServiceConstructorGoldenTest (or a sibling *ConstructorGoldenTest) UnknownTypeException 'Class or interface "?OCA\...\X" does not exist'.
- Cause: the golden's argumentsFor() uses (string)$parameter->getType(), which yields '?X' for a nullable param the PR added to a platform bundle.
- Fix: read ReflectionNamedType::getName() (fallback (string) for unions); rerun with UPDATE_GOLDEN=1 and check the fixture diff is only the PR's new property (#10451 35eb59e9).

## Stale component dependency baseline after a main merge (or-fix-4)
- Symptom: ComponentDependencyRulesTest::testNoComponentEdgeBeyondTheBaseline red on a PR that ships tests/Unit/Architecture/component-dependency-baseline.json (#8084).
- Cause: every modularity merge on main changes the module edge set; the committed baseline goes stale on each main merge.
- Fix: on the merged branch run python3 scripts/component-deps.py --write-baseline, check the test is green, commit the json alone (see #8084 7459edd3).

## Clean auto-merge leaves a duplicate property or import (or-fix-13)
- Symptom: merge with main reports no conflict in a controller, but php -l fails 'Cannot use ... because the name is already in use' or 'Cannot redeclare ...::$logger'; tests error on load.
- Cause: main and the PR each added the same dependency (e.g. a logger) through different bundles (main: AuditTrailLog->logger, PR: AuditTrailControllerServices->logger); git merges both property blocks and both assignments.
- Fix: php -l every PR-touched lib file after the merge; keep main's wiring (required, non-null), drop the PR's duplicate property, import, assignment and bundle param, and the factory arg in tests. HandlesExceptionsTrait reads any $logger property. See #10553.

## Migration added without an info.xml version move (or-fix-18)
- Symptom: php scripts/check-migration-version-bump.php --base=origin/main exits 1 ('adds N migration(s) but does not move the app version'); no unit test catches it.
- Cause: build PRs add a lib/Migration/Version*.php and leave appinfo/info.xml <version> at main's value, so occ upgrade would never run the step.
- Fix: after merging main, bump <version> one step past main's (e.g. 2.2.0-dev.3 -> 2.2.0-dev.4) in its own commit, rerun the check (see #10398, #10518). Two PRs bumping to the same value conflict on info.xml later: take the higher and bump once more.

## component-map-merge.py prints 'duplicate rows' after a csv union (or-fix-3)
- Symptom: the helper reports e.g. duplicate rows: ['Service/OwnedWebhookView.php']; the yaml then moves that file to the PR's component.
- Cause: the PR's conflict hunk carries a row for a file main maps elsewhere in the csv (outside the hunk), so the per-hunk union keeps both.
- Fix: delete the PR's copy (main's row wins), rerun python3 scripts/component-patterns.py; the yaml diff vs main must show only the PR's own files (#10523).

## Save-check test still mocks the dispatcher after the check moved to a slot (or-fix-9)
- Symptom: 'Failed asserting that exception of type Exception is thrown' in a tests/Unit/Db/SchemaMapper*Test that builds SchemaMapper with eventDispatcher: createMock(IEventDispatcher::class); red on the PR head itself.
- Cause: the check was moved (often by a fix lane) into a SchemaDeclarationChecksEvent listener slot; a mocked dispatcher never runs the listeners, so nothing refuses.
- Fix: pass eventDispatcher: new \OCA\OpenRegister\Tests\Unit\Support\ProcessDeclarationChecksDispatcher() (as SchemaMapperAutoTransitionValidationTest does); the listener must be in its listeners() list. (#10529)

## SchemaAuthorizationGoldenTest key list shifts after adding an authorization control key (or-fix-1)
- Symptom: tests/Unit/Db/SchemaAuthorizationGoldenTest::testAuthorizationOutcomesMatchGolden, array_keys differ by one 'validate control <key> as text' entry.
- Cause: the test builds one case per PermissionCatalogue::CONTROL_KEYS entry; a PR adding a control key adds a case.
- Fix: UPDATE_GOLDEN=1 on that file; the fixture diff must be only the new key's case (check its outcome is intended); commit the json (see #10381 797aae8b).

## PublishedSignaturesTest: the published contract names a Kernel class (or-fix-3)
- Symptom: PublishedSignaturesTest::testTheContractNamesOnlyPublishedClasses ('<method>() names OCA\OpenRegister\Kernel\Contracts\X, which a consuming app cannot load'), often after an ObjectServiceInterface use-block conflict.
- Cause: main (e1fd76341bf) made lib/Contract/ObjectServiceInterface name only lib/Contract classes; a PR that gives a contract method a Kernel value object now fails.
- Fix: a value new on the PR: move it to lib/Contract (namespace, callers, Adr011 VALUE_OBJECTS, drop from KERNEL_LEAVES). A Kernel leaf main already uses: add a small final published value in lib/Contract for that method (Kernel leaves may not extend Contract). Add each new file to ContractIsALeafTest::PUBLISHED, import it in Kernel ObjectOperationsInterface, migrate test doubles; pin the params in PublishedSignaturesTest (#9103).

## PR test saves through SchemaMapper::cleanObject but its check moved to a slot (or-fix-9)
- Symptom: a PR's SchemaMapper<Check>Test expects an Exception from cleanObject() on a bare SchemaMapper and gets none ("Failed asserting that exception ... is thrown"), after the check was moved onto a declaration-check slot.
- Cause: main's SchemaMapper only runs slots contributed via SchemaDeclarationChecksEvent; a bare mapper in a unit test has no listeners, so the check never runs.
- Fix: in the test, build the owning listener (e.g. SchemaDeclarationChecksListener with mocked logger/container), handle() a new SchemaDeclarationChecksEvent, pick the check whose position() is the slot constant and call check(schema:, discarded: new DiscardedAnnotations()). See #10529.

## Hand-built service factory drops a new nullable constructor param (or-fix-9)
- Symptom: reviewer BLOCK 'feature ticked but unwired'; unit tests green because the param is nullable and the feature degrades to off.
- Cause: main registers the service by hand in an area wiring (e.g. FlowWiring::buildTaskInboxService) with named args; a PR param added to the constructor is never passed.
- Fix: add the named arg in the wiring builder ($container->get(X::class)) and a wiring test that reads the private property of the built service (ReflectionProperty) and asserts the instance. See #10269.

## PR's version bump absorbed by main (or-fix-8)
- Symptom: unit suite green after the main merge, but php scripts/check-migration-version-bump.php --base=origin/main exits 1 on a PR that once bumped info.xml.
- Cause: main later moved <version> to the same value the PR had picked, so after the merge the PR adds migrations at main's version.
- Fix: bump <version> one past main's in its own commit after the merge and rerun the check (#5968, #10568). Run the check on every migration PR even when tests are green.

## Version bump silently undone by a clean main merge (or-fix-14)
- Symptom: merge-tree with main is clean and every unit suite is green, but check-migration-version-bump.php exits 1: '<version>: 2.2.0-dev.3 at the merge base, 2.2.0-dev.3 here'.
- Cause: the PR bumped info.xml to 2.2.0-dev.3 earlier and main has since bumped to the same value, so the merge leaves no version move relative to main.
- Fix: run the check after EVERY main merge, even a clean one; bump one step past main (2.2.0-dev.4) in its own commit (#10154, #10314, #10494).

## Inherited red on main: AnonymousEnvelopeReadPathsTest (or-fix-13)
- Symptom: tests/Unit/Middleware/AnonymousEnvelopeReadPathsTest (4 cases) TypeError: ObjectsControllerFactory::args() Argument #14 ($eventDispatcher) gets a WebhookService mock.
- Cause: red on plain main (21 Nov, behind main HEAD at 10493 merge); the test passes positional args that no longer line up with the factory's signature.
- Fix: none on PR branches: inherited; fix belongs in its own main PR. Confirm by running the test on origin/main.

## CatalogueQueryScopeTest names a PR's information_schema query (or-fix-17)
- Symptom: tests/Unit/Db/CatalogueQueryScopeTest::testEveryCatalogueQueryNamesItsSchema lists lib/<PR file>:<line> after merging main.
- Cause: main (5c53389fca4, upgrade-performance) requires every FROM information_schema.* query to be limited to the instance's own database.
- Fix: append ' AND '.(new CatalogueScope(db: $this->db))->condition() (OCA\OpenRegister\Db\CatalogueScope) to the WHERE, as RegisterService/MagicMapper do; assert 'table_schema = DATABASE()' in the class's test (a mocked IDBConnection is not Postgres). (#10537)
## Clean main merge, but four open PRs add the same Version1Date20261114100000 (or-fix-3)
- Symptom: lane green after the merge; check-migration-version-bump exits 1, and a scan of open PR heads finds #10451, #10499, #10518 and #10523 each adding a DIFFERENT step under Version1Date20261114100000.
- Fix: per PR in your lane, git mv the step (and test) to Version1Date20261114100<lane>, sed csv/tasks.md, add a migration test if missing, bump info.xml one past main. #10523 moved to ...100003 (75f015a0); the other three still clash.

## New controller find() trips the recent-lens sweep (or-fix-18)
- Symptom: RecentLensLookupCallSitesTest::testEveryInternalFindHasADecision lists 'lib/Controller/<New>Controller.php::<method>'.
- Cause: main requires every ObjectService find() in lib/ to be decided: a person opening the object, a lookup, or audit-skipped.
- Fix: a read whose response is the record itself (show, single-record export) goes in the test's OPENED list (as TmloController::exportSingle); a read that only checks or resolves gets WriteCause::asLookup(fn () => ...) or ObjectFindOptions audit: false. See #10498 786b83ff.

## AnonymousEnvelopeReadPathsTest red on main e158c36a (or-fix-9)
- Symptom: tests/Unit/Middleware/AnonymousEnvelopeReadPathsTest 4 errors: TypeError ObjectsControllerFactory::args() Argument #14 ($eventDispatcher) must be ?IEventDispatcher, MockObject_WebhookService given.
- Cause: the test's positional factory args lag ObjectsControllerFactory on main; red on plain main, not a PR defect.
- Fix: inherited for PR fixes; do not fix it in a PR branch.

## RecentLensLookupCallSitesTest names a PR's new ->find( call (or-fix-14)
- Symptom: tests/Unit/Service/Interaction/RecentLensLookupCallSitesTest::testEveryInternalFindHasADecision lists 'lib/<file>.php::<method>' from the PR.
- Cause: main scans lib/ for $this->objectService / $objectService / $this->objects ->find( and requires each to be a lookup, _audit: false, or a listed open.
- Fix: a person opening the object: add to OPENED; a background/system read through ObjectService: wrap in WriteCause::asLookup(fn () => ...) or pass _audit: false; a receiver that is not ObjectService (e.g. MagicObjectFinderInterface): add to NOT_OBJECT_SERVICE. (#10534)

## Inherited red on main: FlowEngineOversightTest (or-fix-3)
- Symptom: tests/Unit/Service/Flow/FlowEngineOversightTest (5 cases) Error: Class "Unit\Service\Flow\FlowSubject" not found.
- Cause: red on plain main e158c36ac (21 Nov); the test's namespace is Unit\Service\Flow (no OCA\OpenRegister\Tests prefix) and it names FlowSubject unqualified. Also red on main then: AreaMapperRegistrarsTest::testEachInterfaceIsBoundOnce, AnonymousEnvelopeReadPathsTest (4).
- Fix: none on PR branches: inherited; confirm on origin/main.

## Inherited red on main: FlowEngineOversightTest (or-fix-9)
- Symptom: tests/Unit/Service/Flow/FlowEngineOversightTest (5 cases) Error: Class "Unit\Service\Flow\FlowSubject" not found.
- Cause: red on plain main e158c36ac (21 Nov); the test resolves FlowSubject in its own (non-OCA) namespace. Not a PR defect.
- Fix: none on PR branches: inherited; confirm on origin/main. Also still red on main: AreaMapperRegistrarsTest::testEachInterfaceIsBoundOnce, AnonymousEnvelopeReadPathsTest.

## Inherited red on main: ReservedColumnNamesTest (or-fix-3)
- Symptom: tests/Unit/Migration/ReservedColumnNamesTest (5 errors) RuntimeException: raw.githubusercontent.com/MariaDB/server/10.5/sql/lex.h answered HTTP 404.
- Cause: red on plain main e158c36ac (22 Nov); tests/Support/ReservedWordSource fetches an upstream file that moved. Not a PR defect.
- Fix: none on PR branches: inherited; the source URL needs updating on main.

## Test env trap: symlinked vendor in a worktree runs main's lib (or-fix-3)
- Symptom: in a second worktree, PR tests fail with TypeErrors against the old signature, or A18InterfaceBindingsTest lists a class's own file as a caller.
- Cause: a symlinked vendor/ makes composer's autoloader resolve lib/ from the symlink target checkout, not the worktree.
- Fix: never symlink vendor; copy it with cp -al (hardlinks) into each worktree.
## ControllerServicesWiringTest 'loses <X>::$field' for a new Controller\Support collaborator (or-fix-6)
- Symptom: ControllerServicesWiringTest::testEveryBundledServiceReachesTheController '<Controller> loses OwnMailReverseLookup::$linkedEntityService'.
- Cause: every class in a Controller\Support namespace counts as a bundle; reaches() read bundles with get_object_vars (public only), so a Support collaborator with private promoted fields hid its services.
- Fix: main's test now (via #11006 b4268150) reads a bundle's initialised fields incl. private; until that lands, apply the same one-line change or make the fields public readonly. Also add the class's component-map row.

## New nullable constructor param missing from BuiltinIntegrationServices::FACTORIES (or-fix-5)
- Symptom: reviewer BLOCK 'never active in production'; unit tests green because the PR's test builds the service by hand with the new param, which defaults to null.
- Cause: integration providers and link services are built from the BuiltinIntegrationServices::FACTORIES table (named args), not by DI autowiring; a param added to the constructor is never passed.
- Fix: add 'param' => <Interface>::class to the class's FACTORIES entry; BuiltinIntegrationServicesTableTest pins a sha256 of the table: copy the actual hash; add a test that BuiltinIntegrationServices::build() hands the instance in (ReflectionProperty on the built service). See #11005 1b1ba39a.

## Order-dependent red: ExampleSetTalkStoreBehaviourTest when running all of tests/Unit/Service (or-fix-9)
- Symptom: testTalkIsUnavailableWhenItsServicesDoNotLoad fails 'this test needs an instance without Talk classes' only when a wide directory (tests/Unit/Service) runs in one process.
- Cause: an earlier test in the same process defines OCA\Talk stub classes; class_exists then stays true. Green alone and on main; not a PR defect.
- Fix: none; rerun tests/Unit/Service/ExampleSet alone to confirm.

## Test env trap: process-isolated tests lose the OCP prepend (or-fix-12)
- Symptom: ClassAliasMapBehaviourTest (2), ClassAliasMapEagerOrderTest (2) and FlowEngineRegistrationListenerTest::testTheOperationIsResolvedByItsClassName error with Class "OCP\EventDispatcher\Event" not found in tests/stubs/Integriq/Event/ConnectionStatusReportedEvent.php.
- Cause: these run in a separate process, which does not inherit the -d auto_prepend_file OCP autoloader; red identically on plain main 223a653f2 (23 Nov). Not a PR defect.
- Fix: none on PR branches: inherited env artefact; compare against plain main before chasing it.
## Version1Date20261121170000 shared by #10314 and #10531 (or-fix-11)
- Same clash as the 20261114100000 entry: two open PRs add different steps under one class name. #10531 moved to ...170011 (94e3905e); #10451 moved its 100000 step to ...100011 (2f378e57). Still clashing: #10314 none now; #10499 and #10518 still share 20261114100000 with each other.

## Open product question already on the PR: do not ask again (or-fix-1)
- Symptom: a BLOCK that is a maintainer decision (e.g. #10641 stylelint-config-recommended-scss 18 needs stylelint 18; Nextcloud config caps at 17) gets the same question re-commented every run; #10641 has five copies.
- Cause: each lane run re-checks the PR and asks again without reading its comments.
- Fix: read the PR comments first; if the question is there and unanswered, leave the BLOCK and add nothing. Re-check only when a dependency released a version that changes the answer.
## Trap: git mv then sed, then git checkout -- <file> reverts the class rename (or-fix-18)
- Symptom: after moving a migration step to a new version, phpunit dies 'Cannot declare class <OldName>, because the name is already in use in <NewName>.php'; check-migration-version-bump counts one migration too many.
- Cause: git mv stages the old content; a later git checkout -- <new file> (e.g. undoing a break-the-subject check) restores the staged pre-sed body with the old class name.
- Fix: re-run the sed on the new file and git add it; undo a break check with the reverse sed, not checkout. Also inherited on main 223a653f2: AppHost/BootstrapTest::testSettingsPlaneRegistrationIsLazy (process-isolated, OCP prepend lost), FileVersioningHandlerGoldenTest (7 errors). #10518 moved to Version1Date20261114100018 (83d66ed4); #10499 now the only holder of ...100000.

## package-lock.json changed without sbom.cdx.json (or-fix-12)
- Symptom: none in the unit suite; integrate.sh auto-parks the PR 'precheck-park: sbom: package-lock.json changed without sbom.cdx.json' (dependabot npm bumps, build PRs adding an npm package such as leaflet-draw).
- Cause: the integrator's sbom gate requires every lockfile change against main to come with a matching sbom.cdx.json.
- Fix: after merging main run `bash ~/q/bin/regen-sbom.sh <checkout>` (then --check prints 'current'), commit sbom.cdx.json alone. Scan your share with `git diff --name-only origin/main...pr<N> -- package-lock.json composer.lock sbom.cdx.json`. (#10632 937960a2, #10552 29bba216)
## Test env trap: composer install aborts on phpstan/phpstan (or-fix-5)
- Symptom: composer install (plain or --prefer-source) ends 'Could not authenticate against github.com' at phpstan/phpstan; other packages log 403 on api.github.com zipballs and fall back to slow source clones; vendor/autoload.php never appears.
- Cause: this environment refuses api.github.com/codeload downloads for repos not added to the session; phpstan/phpstan ships dist-only, so there is no source fallback.
- Fix: install from a temp copy with phpstan dropped (python: pop phpstan/phpstan from composer.json require-dev and composer.lock packages-dev into composer.tmp.json/.lock; COMPOSER=composer.tmp.json COMPOSER_PROCESS_TIMEOUT=0 composer install --no-scripts), delete the tmp files, then git clone --depth 1 --branch <locked version> https://github.com/phpstan/phpstan vendor/phpstan/phpstan and ln -s ../phpstan/phpstan/phpstan vendor/bin/phpstan. phpstan analyse -c phpstan.neon then runs normally.

## ClassAliasStubTest red after a main merge on #8106 (or-fix-6)
- Symptom: tests/Unit/Architecture/ClassAliasStubTest::testStubMatchesTheAliasMap 'tests/stubs/ClassAliases.php does not match appinfo/class-aliases.json'.
- Cause: #8106 ships a stub generated from appinfo/class-aliases.json; every main change to that json (e.g. new Notifications/Files controller aliases) makes it stale.
- Fix: python3 scripts/class-alias-stubs.py, commit the stub; rerun Architecture (see #8106 1000ddfa).
## Jest initial-data golden misses a component's new data() fields (or-fix-14)
- Symptom: src/services/initial-data.golden.spec.js '<Component> starts from the recorded state' fails, snapshot + only the PR's new data() keys; PHP suites all green, so PHP-only checks miss it.
- Cause: the golden pins every settings component's data() shape; a PR adding fields to e.g. FileConfiguration.vue never re-recorded it.
- Fix: npx jest --findRelatedTests <PR's src files> on the merged tree (npm ci --ignore-scripts, ~30s); then npx jest src/services/initial-data.golden.spec.js -u, check the .snap diff is only the PR's keys, commit (#10494 90b4b7a5).

## Recorded-call goldens pin a method's argument list (or-fix-3)
- Symptom: MagicMapperSearchObjectsPaginatedTest (4 data sets, 'findMultiple [["u1"],false,null]' vs '...,null,null]') and RelationHandlerLookupsGoldenTest red on a PR that adds an optional trailing param to findMultiple/findMultipleAcrossAllMagicTables.
- Cause: those tests record func_get_args of mapper doubles; a forwarding call that now passes the new param (null) adds an element.
- Fix: sed the 'findMultiple [...]' strings in tests/Unit/Db/MagicMapper/fixtures/search-objects-paginated.php; OR_GOLDEN_RECORD=1 phpunit RelationHandlerLookupsGoldenTest; the diff must be only the added nulls (#11003 962507b4).

## Perf PR swaps per-id SchemaMapper::find() for findMultipleOptimized() (or-fix-15)
- Symptom: reviewer BLOCK 'findMultipleOptimized returns rows unresolved (no allOf merge)'; unit tests green because mocks return finished schemas.
- Cause: find() runs SchemaComposition::resolve (allOf merge, oneOf/anyOf checks, throws on a broken parent); the batch query returns stored rows, so an allOf child has only its delta properties/config.
- Fix: keep the batch for plain schemas; send rows with non-empty getAllOf/getOneOf/getAnyOf (and non-numeric ids) back through find() in a try/catch, in the original order; add a test where the batch returns a delta-only allOf row and find() the resolved one (#11015 02fa7bee).
## EntityInterfacesTest: a PR's new public entity method is not on the Kernel interface (or-fix-13)
- Symptom: tests/Unit/Kernel/EntityInterfacesTest::testEveryExplicitPublicMethodIsOnTheInterface with data set "Schema" (or Register, ...), actual [ '<method>' ].
- Cause: main requires every explicit, non-static public method of a Db entity on its Kernel/Contracts/<Entity>Interface; a PR adds e.g. Schema::getPreviewUrl() to the entity only. Clean auto-merge.
- Fix: declare the method with the same signature on lib/Kernel/Contracts/<Entity>Interface.php (no other implementors besides the entity; check with grep 'implements.*<Entity>Interface'); a test reading it through the interface. Not ENTITY_ONLY unless it is a mapper-only helper (#10533 06f240b0).

## A manifest.d page edit fails main's page spec (or-fix-3)
- Symptom: PHP suites green, but jest src/tests/<page>.spec.js red on the merge (e.g. archival-overview-page: 'reads every list ... from the overview endpoint' or 'translates every label ... in both catalogues', nl[s] undefined). --findRelatedTests on a .json finds nothing.
- Cause: main added specs that pin a manifest page's widget ids/endpoints and require every label in l10n/nl.json (backend) as well as nl.js; a PR adding a widget never sees them.
- Fix: grep -rl 'manifest.d/<file>' src tests; run those specs; admit the PR's widget in the spec's list/endpoint check and add its labels to nl.json + en.json beside the page's others (values from nl.js). Compare test:l10n:parity with main (#10483 694c3ecc).
## Version1Date20261121170000 still shared by #10277 and #10314 (or-fix-17)
- Correction to the or-fix-11 entry: #10314 still adds its own ...170000 step. #10277 moved to Version1Date20261121170017 (d61aadea); #10314 is now the only holder. Before trusting a 'none now' note, diff --diff-filter=A lib/Migration across the open heads again.
## Fix for the process-isolation OCP trap: PHP_INI_SCAN_DIR (or-fix-9)
- Symptom: process-isolated tests (SftpTransportGoldenTest, TablesTableReader*Test, ClassAliasMap*Test, FlowEngineRegistrationListenerTest) error 'Class "OCP\EventDispatcher\Event" not found' under -d auto_prepend_file.
- Cause: child processes do not get -d flags, but they do read ini files.
- Fix: mkdir ~/w/ini; echo auto_prepend_file=<your OCP autoloader> > ~/w/ini/ocp.ini; run phpunit with PHP_INI_SCAN_DIR=":$HOME/w/ini" (keeps the system ini dir); those tests then run for real (green on #10509, #9029, #10549 merged with main 223a653f2).
## Dev-only npm/composer bump: SBOM already current, nothing to commit (or-fix-7)
- Symptom: a dependabot PR bumping a devDependency (e.g. #10627 @stoplight/spectral-cli) changes package-lock.json without sbom.cdx.json; the sbom gate's name check would park it.
- Cause: regen-sbom.sh omits dev deps, so after merging main it prints 'unchanged' / --check 'current': there is no real sbom change to commit.
- Fix: do not fabricate an sbom edit and do not push a bare main merge; leave the PR as is and note it. Precedent: integrator landed dev-only bumps with 'SBOM current' (#7741, #10944).
## PlatformFacingContractsTest: a PR's new controller carries the maintenance marker (or-fix-13)
- Symptom: tests/Unit/Kernel/Contracts/Configuration/PlatformFacingContractsTest::testExactlyTheOperationsControllersCarryTheMarker, actual list has one more Operations*Controller.
- Cause: main pins every lib/Controller class implementing Kernel\Contracts\Configuration\MaintenanceReachableInterface; a PR adding an operations controller (meant to stay reachable in maintenance mode, also listed in F09 and MaintenanceModeMiddlewareTest) is not in it.
- Fix: when the PR lists the controller in F09ControllerBoundaryTest/MaintenanceModeMiddlewareTest too, add it to the expected list (sorted) and the docblock count (#10493 07823483). Otherwise it is a product question: comment, do not add.
## Migration name clash scan, 23 Nov (or-fix-18)
- Open PRs adding the same lib/Migration class: #10513/#10568 (Version1Date20261114170000), #10154/#10526 (...20261114180000); #10247/#10398 resolved by moving #10398 to ...20261121180018 (562ec796). #10398 and #10518 both bump info.xml to 2.2.0-dev.4: whichever lands second needs dev.5.
- Trap: check-migration-version-bump.php counts committed AND working-tree additions, so after an uncommitted git mv it reports one migration too many; commit before rechecking.
## ComponentDependencyRulesTest: a shared helper in one component now crosses into another (or-fix-11)
- Symptom: ComponentDependencyRulesTest::testNoComponentEdgeBeyondTheBaseline lists 'lib/<A>.php -> lib/<B>.php (Integrations -> Core)' for a file the PR added, after merging main (clean or csv-only conflict).
- Cause: main remapped files to components (e.g. AuthenticationService is now Integrations); a PR helper both components use (constants list, small value) sits in one of them, so the other gains a forbidden edge.
- Fix: move a pure constant/value holder into lib/Kernel/Contracts (namespace, imports, csv row 'Kernel/Contracts/X.php,,Kernel,,,,,Kernel', python3 scripts/component-patterns.py); never add the edge to the baseline. Behavioural code instead gets a Kernel interface. (#9131 8058b9a3)
## Runtime npm dep added, SBOM still current (or-fix-15)
- Symptom: a build PR adds a runtime dependency to package.json/package-lock.json (e.g. #10395 @nextcloud/files) without sbom.cdx.json; the sbom name gate may park it.
- Cause: the package was already in the lock transitively (via @nextcloud/vue), so the omit-dev SBOM component set does not change.
- Fix: merge main, bash ~/q/bin/regen-sbom.sh <checkout>: 'unchanged' means nothing to commit; do not push a bare main merge. Note it on a park instead.
## ComponentDependencyRulesTest: a PR's new file adds a cross-component edge (or-fix-10)
- Symptom: tests/Unit/Architecture/ComponentDependencyRulesTest::testNoComponentEdgeBeyondTheBaseline lists '<PR file> -> <file> (Processes -> Core)' or '(Configuration -> Processes)' after merging main; green on main. Never add the edge to the baseline.
- Fix: (a) the file really belongs to the other side (an event adapter of a Core feature): change its component-map.csv component, regenerate the yaml, and register it via that area's registrar slot called from the old spot (CoreListenerRegistrar::identityClaims precedent; order and listener golden unchanged) (#10450 c58dde99). (b) a Processes class calling Core services: put a port in lib/Kernel/Contracts/ ROOT (Kernel/Contracts/Core counts as Core!) with a Core adapter holding the old body, bind it in CoreServiceBindings; a Processes binding moves from ContractBindings to FlowWiring::BINDINGS (+ FlowWiringTest list) (#10190 1e1e593e).
- Trap: scripts/component-deps.py reads git-tracked files only; git add new files before running Architecture, or testPhpReaderAgreesWithTheScript fails on them.
- (or-fix-11 addendum) Only lib/Kernel/Contracts/<File>.php is Kernel; lib/Kernel/Contracts/<Area>/ (Core, Integrations, ...) belongs to that area, so a port there does not remove the edge. A Kernel contract may not name Db classes (ContractIsALeafTest::testKernelContractsNameNoDbClassBeyondTheShrinkingList): type it on Kernel\Contracts\SchemaInterface / Contract\ObjectEntityInterface and narrow with instanceof in the implementor. First try remapping the PR's new files to the component of their only callers (#10531 4478e2f1). testPhpReaderAgreesWithTheScript fails while a new lib file is untracked: component-deps.py reads git-tracked files; git add first.
- (or-fix-11) Recipe that cleared 19 edges on #10451 (a2499fe7): remap the PR's NEW files to the component of their callers (csv last column, component-patterns.py); type collaborators on the area's own Kernel/Contracts/<Area> port (widen it with methods the bound class already has); read objects via Kernel ObjectOperationsInterface::findSilent instead of Db MagicObjectFinderInterface; restate a foreign class constant as a private const. Iterate with: phpunit --filter testNoComponentEdgeBeyondTheBaseline tests/Unit/Architecture/ComponentDependencyRulesTest.php (5 s).
## Migration clash update, 23 Nov (or-fix-13)
- #10513 moved its step from Version1Date20261114170000 to ...170013 (4a6dc524); #10568 is now the only holder of ...170000. Inherited on main ec3f0f77: AreaMapperRegistrarsTest::testEachInterfaceIsBoundOnce (Translation*Interface in ConfigurationMapperRegistrar), AnonymousEnvelopeReadPathsTest (4), ReservedColumnNamesTest (5).
## Short-name clash: a PR class and a main class with the same name in parent/child namespaces (or-fix-18)
- Symptom: ComponentDependencyRulesTest::testNoComponentEdgeBeyondTheBaseline lists 'lib/Service/X.php -> lib/Service/Foo.php (Core -> Integrations)' although X imports Service\Sub\Foo.
- Cause: component-deps.py resolves bare identifiers in the file's own namespace even when a use import shadows them; main added Service\Foo while the PR has Service\Sub\Foo (#9798 UserDirectory).
- Fix: rename the PR's class (file, csv row, imports, new X( in tests), component-patterns.py; keep the named argument. Never touch the baseline (#9798 8d078016).
## Notifications reaching Core lookups: route through root Kernel ports (or-fix-10)
- Symptom: ComponentDependencyRulesTest lists '<Notifications file> -> lib/Db/AccessLinkMapperInterface.php | lib/Service/Object/PermissionHandlerInterface.php | lib/Db/MagicMapper/MagicRelationReaderInterface.php (Notifications -> Core)', or a listener -> a pure event/interface counted Files/Core.
- Fix: a pure event or interface naming only OCP/Kernel classes (e.g. Event/ObjectNoteAddedEvent, Service/Deferral/DeferredEntryObjectResolverInterface) gets component Kernel in the csv like its siblings. Behaviour: one-method port in lib/Kernel/Contracts root typed on Contract\ObjectEntityInterface/Kernel SchemaInterface, Core adapter (Service/Object or Service/Sharing, instanceof-narrow to Db classes), bind in CoreServiceBindings, csv rows, component-patterns.py; tests wrap their old mocks in the real adapter. Main already has AccessLinkNames/ObjectReadRights/ReferringObjects only once #10530 lands (b659cd66).
## Main remapped FileService to Files: a PR's Core helper calling it trips ComponentDependencyRulesTest (or-fix-5)
- Symptom: testNoComponentEdgeBeyondTheBaseline lists 'lib/Service/Object/<New>.php -> lib/Service/FileService.php (Core -> Files)' after a clean main merge; green on the PR head.
- Cause: main moved Service/FileService.php (and FileServiceInterface) to the Files component; a PR helper in Service/Object (Core) that lists/copies files now crosses components. Remapping the helper alone just moves the edge to its Core caller.
- Fix: remap the helper to Files in component-map.csv, give it a root lib/Kernel/Contracts/<X>Interface typed on Contract\ObjectEntityInterface (narrow to Db ObjectEntity inside), bind it in Files/FilesRegistrar::BINDINGS (+ FilesRegistrarTest count), have the Core caller get the interface from the container; component-patterns.py; check with python3 scripts/component-deps.py (#10525 2be9b176).
## A Notifications (or other area) PR file names flow-timer classes: Notifications -> Processes edges (or-fix-3)
- Symptom: ComponentDependencyRulesTest::testNoComponentEdgeBeyondTheBaseline lists 'lib/Notifications/... -> lib/Service/Flow/Timer/SlaCalculatorInterface.php (Notifications -> Processes)' (WorkingCalendarService/Interface, SlaAddition) after merging main.
- Fix: main now has Kernel\Contracts\BusinessTimeInterface (calendarFor(?org), add(start, value, unit, ?BusinessCalendarInterface)) once #10523 lands; until then copy it from #10523 70138a32: WorkingCalendarInterface extends BusinessCalendarInterface, adapter Service/Flow/Timer/BusinessTime bound in FlowWiring::BINDINGS (+FlowWiringTest list), unit constants from Kernel\Contracts\Notifications\TimerUnits.
## ContractBindings is Configuration: a PR's binding there becomes a component edge (or-fix-12)
- Symptom: ComponentDependencyRulesTest::testNoComponentEdgeBeyondTheBaseline lists 'lib/Service/ContractBindings.php -> lib/<Area file>Interface.php (Configuration -> <Area>)' after merging main; often with edges to Db/StateHistoryMapperInterface (Core) or Files/Db/ChunkStoreInterface (Files).
- Fix: move the binding row into the owning area's map (Compliance: Service/ComplianceServiceBindings, test in ComplianceServiceRegistrarTest); for Core/Files stores add a Kernel/Contracts root port (the store interface extends it, DbRegistrar/FilesRegistrar binds it) or widen Kernel ChunkMapperInterface with a method ChunkMapper already has. See #10132 c7436a55.
## Configuration area reaching flows trips ComponentDependencyRulesTest (or-fix-8)
- Symptom: after merging main, testNoComponentEdgeBeyondTheBaseline lists 'lib/Service/Configuration/<Import|Export>Handler.php -> lib/Db/FlowMapperInterface.php (Configuration -> Processes)' etc.
- Fix: main now has Kernel/Contracts/ConfigurationFlowTransferInterface (on #5968 01a558e4 until it lands) with adapter Service/Flow/ConfigurationFlowTransfer bound in FlowWiring::BINDINGS (+FlowWiringTest); move the flow bodies there, inject the port (setter on ImportHandler, ctor on ExportHandler), wrap test mapper mocks in new ConfigurationFlowTransfer(flowMapper:, logger:).
## Main's dependency baseline regenerated: approved PRs 70+ commits behind now fail ComponentDependencyRulesTest (or-fix-19, 23 Nov)
- Symptom: a PR approved at its head and merging clean is red on the merge only in testNoComponentEdgeBeyondTheBaseline (main regenerated tests/Unit/Architecture/component-dependency-baseline.json, 7459edd3). Check every approved PR in your share, not only parked/BLOCK ones: #10499, #10539 and #10999 all had it.
- Fix order that worked: (1) remap the PR's new helpers to their only caller's component (csv last column), incl. a main helper whose only caller is the PR's code (MailThreadAssembler); (2) pure values/static normalisers: map to Kernel in place, or git mv into lib/Kernel/Contracts when a root Kernel contract names them (testContractsNameOnlyKernelTypes demands a Kernel\\Contracts namespace); (3) a service call across areas: a root Kernel/Contracts port, implemented by the owning class, bound in that area's BINDINGS/ContractBindings, ctor param stays nullable; (4) python3 tools/sig/modularity_check.py --write-baseline and tools/sig/deptrac_areas.py, and drop dependency-baseline rows of moved files. Port returning a Db row: make it void if no caller uses the row (#10539 310911d0, #10499 0a4c400b, #10999 8b6dda9a).
## Main regenerated the dependency baseline: an approved PR's edges are suddenly new (or-fix-0)
- Symptom: ComponentDependencyRulesTest::testNoComponentEdgeBeyondTheBaseline lists ~10 edges of the PR's own new files after a merge of main 7459edd3+ (and testNoNewFileNamesTheBootstrapNamespace for AppInfo\Application::APP_ID).
- Fix (#10260 f8482987): AppIdentity::APP_ID; a throttle constant another component already exposes (AccessLinkServiceInterface::THROTTLE_ACTION == ThrottledSurfacesInterface::ACCESS_LINK) or restate it as the controller's own const with a test pinning equality; inline a shared trait (ValidatesPreWriteObject) into the new listener; route a Core value (DraftOwner) through a Processes sibling already in the baseline (FormSubmitter::rescoped); remap dependency-free leaves (exceptions, pure value parsers) to Kernel in the csv. Iterate with ~/w test of that one file (5 s).
## New file duplicates a baselined sibling's cross-component calls (or-fix-7)
- Symptom: ComponentDependencyRulesTest::testNoComponentEdgeBeyondTheBaseline lists a PR's new lib/Mcp|Service class -> lib/Db/... (Integrations -> Core/Compliance), plus lib/Service/ServiceRegistrar.php -> <that class> (Core -> Integrations).
- Cause: the new class copies what a main sibling in its own component already does (e.g. ToolInvocationAudit, whose Db edges are baselined); the copy gets new edges, and ServiceRegistrar may only name Core classes.
- Fix: delegate to the sibling (same-component edge is free; add a small additive method such as withSource() and a constructor default so DI autowires it), and move the binding into the area's wiring (IntegrationWiring::BINDINGS); revert ServiceRegistrar(+Test) to main. Never extend the baseline (#10567 1316708d).

## New binding in ContractBindings trips ComponentDependencyRulesTest (or-fix-2)
- Symptom: Architecture ComponentDependencyRulesTest::testNoComponentEdgeBeyondTheBaseline lists 'lib/Service/ContractBindings.php -> lib/Service/<Area>/X.php (Configuration -> <Component>)'.
- Cause: main gates ADR-013 Rule 1 as a ratchet; ContractBindings is Configuration, so a PR adding an Interface => Class pair for another component's class adds a new edge (old pairs are baselined).
- Fix: move the pair (and its use lines) to that component's own bindings list, e.g. Service/Flow/FlowWiring::BINDINGS for Flow nodes (as LockObjectNodeInterface); add it to FlowWiringTest's pinned BINDINGS array in the same order and a test asserting it. Never add to the baseline. (#10182 0259bb89)
## ADR-013 dependency gate landed on main 23 Nov 17:38 (#8084, 190e6313): approved PRs now red (or-fix-14)
- Symptom: after merging main, ComponentDependencyRulesTest::testNoComponentEdgeBeyondTheBaseline lists the PR's edges, and/or testNoNewFileNamesTheBootstrapNamespace lists PR files importing AppInfo\Application; green at the PR head because the test was not on main yet.
- Cause: #8084 put the ratchet gate and a shrunk baseline on main; every open PR adding a cross-component edge now fails, including PRs already APPROVEd (lane 14: 5 of 9 merged trees).
- Fix: recipes in the or-fix-10/or-fix-11 entries above (restate a foreign constant as a private const, remap the PR's new files, Kernel/Contracts root port); Application::APP_ID -> Kernel\Contracts\AppIdentity::APP_ID. Run Architecture on EVERY merged tree, even approved clean ones (#10214 a8320c8b).
## Trap: refs/pull/<N>/head lags the PR branch (or-fix-6)
- Symptom: a red the PR already fixed (e.g. #11006 ControllerServicesWiringTest) reappears on the merge; HEAD^1 is an older sha than the PR's listed head.
- Cause: GitHub's pull/<N>/head ref was not updated after the last push (seen 23 Nov on #11006: pull ref edcb5f89, branch b4268150).
- Fix: compare git ls-remote origin refs/pull/<N>/head with refs/heads/<branch>; always fetch +refs/heads/<branch> as the lane says.
## Integrations Support class typed on a Core read gate after main's edge baseline (or-fix-6)
- Symptom: ComponentDependencyRulesTest::testNoComponentEdgeBeyondTheBaseline 'lib/Controller/Support/<X>.php -> lib/Service/Object/ObjectReadAccessInterface.php (Integrations -> Core)'.
- Fix: type it on Kernel\Contracts\ObjectReadCheckInterface::isReadable(ObjectRef): bool (added by #11006 4b537d27; ObjectReadAccess implements it, bound in CoreServiceBindings). Until #11006 lands, add the same port (root Kernel/Contracts, csv row ',,Kernel,,,,,Kernel') and a bool wrapper over readable().
## Constructor bundle relocates a grandfathered cross-component edge (or-fix-1)
- Symptom: ComponentDependencyRulesTest::testNoComponentEdgeBeyondTheBaseline lists 'lib/<Area>/<NewBundle>.php -> lib/<OtherArea>/...Interface.php' on a gi3/ctor-bundle PR; main's baseline has the same edge from the class the bundle feeds.
- Cause: the baseline is per file pair; moving a foreign-typed collaborator into a new bundle file is a new edge even though the dependency is unchanged.
- Fix: keep that collaborator a direct constructor param of the original class (named, after the bundle), drop it from the bundle, move the tests' 'x:' arg out of the bundle call (line-based script); never add to the baseline (#9801 fe7aaf5f).
## ComponentDependencyRulesTest is new on main (23 Nov): APPROVED PRs go red on merge (or-fix-13)
- Symptom: a PR APPROVE'd before ~23 Nov 11:00 merges clean but testNoComponentEdgeBeyondTheBaseline lists its edges; the verdict at head looks green. python3 scripts/component-deps.py | sed -n '/not in tests/,$p' finds them in 5 s, so sweep every approved PR in your share with it.
- Fixes used: Controller Application::APP_ID -> Kernel\Contracts\AppIdentity; Listener const -> its Kernel/Contracts/Core source (HeldChangeCodes); Files->Core service -> root Kernel port with primitive/array returns + Contract\ObjectEntityInterface, implemented by the Core service, bound in CoreServiceBindings (#10533 4cd72cf3 DraftPreviewInterface); Processes job JobRuntime -> ITimeFactory+LoggerInterface; Processes node bindings ContractBindings -> FlowWiring::BINDINGS + FlowWiringTest alias list (#10513 b6f7d6c0).
## A PR's shared Core helper becomes ten cross-component edges after main's area remap (or-fix-18)
- Symptom: ComponentDependencyRulesTest lists many '<area file> -> lib/Service/.../<Helper>.php (<Area> -> Core)' edges, all to one new PR helper.
- Fix: when the helper depends only on Kernel-mapped classes (Db/Register, Db/Schema, Db/*MapperInterface are Kernel in the csv), set its csv component to Kernel and regenerate the yaml; no move needed (#10538 SchemaRefInRegister).
## New feature files mapped to one area but straddling Core after main's remap (or-fix-12)
- Symptom: ComponentDependencyRulesTest lists a PR's new store/helper -> Db/MagicMapperInterface or Service/FileService (Compliance -> Core/Files) and SaveObject -> <PR helper> (Core -> Compliance).
- Fix: remap by dependencies, not by feature: a static reader over entities only (Schema/ObjectEntity/Register are Kernel) and an interface naming only entities -> Kernel; the persistence adapter -> Core, bound in CoreServiceBindings; type its collaborators on Core interfaces (ObjectFilesInterface for FileService, AuditTrailMapperInterface for AuditTrailMapper). csv last column + component-patterns.py. See #10232 1047370c.
## Application registers a PR's dashboard widget: Platform -> <Area> edge after remapping the widget (or-fix-17)
- Symptom: after remapping a PR's new Dashboard widget to its collaborators' area, testNoComponentEdgeBeyondTheBaseline lists 'lib/AppInfo/Application.php -> lib/Dashboard/<Widget>.php (Platform -> Integrations)'.
- Fix: register the widget in that area's registrar (e.g. AppHost/ObservabilityRegistrar::register) guarded by $appId === AppIdentity::APP_ID (leaf apps call the same registrar), drop it from Application, test with RecordingRegistrationContext->widgets for both app ids. A Core caller of an Integrations recorder: root Kernel port bound in IntegrationWiring::BINDINGS (#10537 8fc5925c). A Core helper naming Processes calendars: remap it to Processes, Core types on two root Kernel ports bound in FlowWiring::BINDINGS (+FlowWiringTest alias list) (#10557 88374492).
## ComponentDependencyRulesTest after main merge: bootstrap name or Core value object in a non-Core file (or-fix-9)
- Symptom: testNoNewFileNamesTheBootstrapNamespace / testNoComponentEdgeBeyondTheBaseline list PR files, e.g. 'X.php -> lib/AppInfo/Application.php (Core -> Platform)' or '-> lib/Db/AuditTrail/AuditedChange.php (Compliance -> Core)'.
- Fix: Application::APP_ID -> Kernel\Contracts\AppIdentity::APP_ID (#9869 b3af5697). A refactor that only swaps a Core call form in another component's file: keep main's old call form there (and main's tests for it) when it builds the same thing (#9029 67c68243).
## New admin controller in a non-Core area names AdministratorOnlyGate / Application / OpenRegisterAdmin (or-fix-3)
- Symptom: ComponentDependencyRulesTest testNoComponentEdgeBeyondTheBaseline ('<Controller> -> lib/AppInfo/Application.php (X -> Platform)', '-> Controller/Support/AdministratorOnlyGate.php (X -> Core)', '-> Settings/OpenRegisterAdmin.php') and testNoNewFileNamesTheBootstrapNamespace.
- Fix: AppIdentity::APP_ID; #[AuthorizedAdminSetting(settings: AdminSettingDelegation::OPEN_REGISTER_ADMIN)] (Kernel\Contracts); inject IGroupManager and do the gate's 401 'Authentication required' / 403 check in-body; update tests that pass new AdministratorOnlyGate(...). (#10343 3f516dd0)
## Non-Core controllers adopting HandlesExceptionsTrait trip ComponentDependencyRulesTest (or-fix-13)
- Symptom: 'lib/Controller/<X>Controller.php -> lib/Controller/Trait/HandlesExceptionsTrait.php (<Area> -> Core)' for each controller a PR moves onto the trait.
- Fix (#10553 44725b55): set the csv component to Kernel for the trait and the leaf exceptions it names (Exception/AppendOnly, DatabaseConstraint(+Messages), SchemaNotFound, Service/Resolver/Exception/*: add rows for Resolver{ConfigException,ConfigKey}, UnresolvedValueException), regenerate the yaml; the trait matches root Kernel\Contracts\UnavailableServiceExceptionInterface, which Kernel\Contracts\Core\ServiceUnavailableExceptionInterface extends (a csv row cannot remap a Kernel/Contracts/Core file). Once that lands on main, other PRs need only the merge.
## Grandfathered edge splits into a new file edge after a value-object refactor (or-fix-18)
- Symptom: ComponentDependencyRulesTest lists '<main caller> -> <PR's new value object> (<A> -> <B>)' while the baseline already holds '<main caller> -> <class the value belongs to>'.
- Cause: the PR introduced a part object (e.g. AggregationLayout) that callers in another area must now name.
- Fix: give the grandfathered class a small named constructor taking the plain values (AggregationQuery::grouped(groupBy:)) and use it in the outside caller; test it equals the create() form. Never extend the baseline (#9818).
## New-file edges to dependency-free Core/Files values: map them Kernel (or-fix-6)
- Symptom: ComponentDependencyRulesTest lists '<PR file> -> lib/Service/File/FileTarget.php|FileUpload.php|Service/Object/FileWriteGuardInterface.php (Files -> Core)', or a PR's own value/exception crossing components.
- Cause: main's FilesController etc. carry these edges in the baseline; any NEW file naming them is refused, though they depend only on Kernel (ObjectEntity, ObjectStateWriteException).
- Fix: give the file a csv row with component Kernel (Kernel files may live outside lib/Kernel, cf. Db/ObjectEntity, Exception/*), check its own use lines are Kernel/OCP only, python3 scripts/component-patterns.py. Constants+reader a new file needs from a Configuration class: a root Kernel/Contracts port carrying the constants, implemented by that class (its constants then inherited). See #10526 3aacce0a, #10506 300667ab (TablePdfRendererInterface over ExportService).
## Migration clash: #10526 moved off Version1Date20261114180000 (or-fix-6)
- #10526 now adds Version1Date20261114180006 (37c23ac1f652be9dd08296d9e1857515cabd31ae); #10154 is the only holder of ...180000.
## A feature used by every area (load shedding) trips ComponentDependencyRulesTest from each caller (or-fix-13)
- Symptom: '<Core|Search|Integrations file> -> lib/Service/Resilience/<X>.php (<Area> -> Platform)' for each caller, plus Platform -> Integrations/Core edges from the feature's own reporter/audit calls.
- Fix (#10493 e4b9738b): one root Kernel port per role (call/allow/record + key builders as instance methods; a refusal marker interface the exception implements; reporter/audit ports taking primitives), a thin adapter in the owning area bound in its *ServiceBindings/*Wiring; tests wrap their registry mock in the adapter. A pure attribute (Sheddable) just maps to Kernel in the csv. A Platform controller that must carry Kernel/Contracts/Configuration/MaintenanceReachableInterface stays Configuration and reads Platform through a Kernel port. Audit from outside Core: Core adapter over AuditEntryWriterInterface (keeps rows identical; tests can chain new AuditEntryWriter(mapper: mock)).
## Dependency ratchet now on main (ca0cfc43): new file names AppInfo\Application, or Compliance/Configuration names a pure Core event or Compliance class (or-fix-15)
- Symptom: ComponentDependencyRulesTest::testNoNewFileNamesTheBootstrapNamespace lists a PR file; testNoComponentEdgeBeyondTheBaseline lists '<file> -> lib/AppInfo/Application.php (X -> Platform)', '-> lib/Event/Organisation*Event.php (Compliance -> Core)', '-> lib/Service/Audit/<X>.php (Configuration -> Compliance)'. Green on the PR head; red once merged with main.
- Fix: Application::APP_ID -> Kernel\Contracts\AppIdentity::APP_ID. An event that names only OCP + Kernel interfaces (Organisation*Event, AbstractOrganisationEvent) gets component Kernel in the csv like Register/Schema events; type consumers on the Kernel interface and read fields from jsonSerialize() (OrganisationInterface has no getters) instead of instanceof Db\Organisation. A pure rule both sides need (config key, default, validation) moves to a Kernel/Contracts root final class naming only OCP; the old class delegates (#10515 5a459b73+f8898c61). A Files class asking Core permissions: one-method Kernel port typed on Kernel SchemaInterface, Core adapter instanceof-narrowing to Db\Schema, bound in CoreServiceBindings (#10395 8c6ac6f0). A Configuration class naming a Processes constant: restate it privately, pin it with a test (#9915 76504447).
- Trap: commit scripts/component-map.csv and the regenerated yaml together with the lib change; Architecture passes on the worktree even when they are left unstaged.
## New flow node bound in ContractBindings / using WriteCause after main's area split (or-fix-18)
- Symptom: ComponentDependencyRulesTest: 'lib/Service/ContractBindings.php -> lib/Service/Flow/Nodes/<X>Node.php (Configuration -> Processes)' and '<X>Node.php -> lib/Service/WriteCause.php (Processes -> Core)'.
- Fix: move '<X>NodeInterface::class => <X>Node::class' into FlowWiring::BINDINGS (+ FlowWiringTest list) as LockObjectNode; replace WriteCause::asLookup(fn () => $objects->find(...)) with $objects->findSilent(id:) (audit-skipped read, RecentLens accepts it) and adapt the node test (#10358).
## Trap: the overlap guard reads commit subjects, but st_append writes 'state: append <file>' (or-fix-3)
- Symptom: two sessions of one lane run at once; a push is rejected non-fast-forward because the other session pushed the same fix minutes earlier (#10363 66a5487e, #10543 eff058c2 on 23 Nov).
- Cause: the lane text greps `git log --format=%s` for 'or-fix-K', but verdict/pattern rows land under generic subjects, so the guard never fires.
- Fix: check content instead: `git -C ~/q log --since='50 minutes ago' -p -- verdicts.txt OR-FIX-PATTERNS.md | grep -c 'or-fix-K'`; non-zero means another session of the lane is active: end.
## ADR-013 gate fixes that worked on lane 14 (or-fix-14)
- Port shapes: a Kernel/Contracts ROOT interface with a Core/Compliance/Integrations adapter holding the old body (#10534 ObjectActivityAudit, #10494 DetectorRecord, #10514 ConsumerGrantListing); or let the existing class implement the Kernel port and bind it in the area's map (CoreServiceBindings, ComplianceServiceBindings, FilesRegistrar::BINDINGS + its count test, Service/Bindings/AccessBindings), never ContractBindings (Configuration).
- A request-shared service (TokenGrantSource, registered in RbacWiring) gets its port as an alias in CoreServiceBindings, so the alias resolves to the same instance.
- Constants other areas compare against: move them to a Kernel interface and let the area interface extend it (BackendMethodsInterface extends Kernel AnonymisationMethodsInterface): every Old::CONST keeps resolving.
- A plain exception mapped to an area (Exception/AuthenticationException was Integrations) can be remapped to Kernel in the csv when it has no lib deps.
- Helper: a 6-line python that imports scripts/component-deps.py (load_tool()[0], component_function, php_dependencies) prints every edge of the PR's added files with components; much faster than reading the 8-min Architecture run.
## searchObjectsBySlug(registerSlug:, schemaSlug:) gone on main (or-fix-19)
- Symptom: after merging main, 'Unknown named parameter $registerSlug' in a PR's tests, phpstan argument.unknown 'Missing parameter $target (Contract\RegisterSchemaSlugs)' on ObjectOperationsInterface::searchObjectsBySlug.
- Cause: main 3e6d79b7 ('the search methods take published values') made the slugs one value object.
- Fix: target: new RegisterSchemaSlugs(register: X, schema: Y) (use OCA\OpenRegister\Contract\RegisterSchemaSlugs); test callbacks take (RegisterSchemaSlugs $target, array $filters = [], ...) and read $target->register/->schema; add #[UsesClass(RegisterSchemaSlugs::class)]. Grep a PR diff for '^+.*registerSlug:' (#10139 3d6ddaf4).
- Same PR: an Integrations service writing a system audit line (new AuditTrail() + AuditTrailMapperInterface::insert) -> root Kernel SystemAuditLineInterface::recordSystemLine(action, userName, changed), implemented by Service/Audit/AuditEntryWriter, bound in ComplianceServiceBindings (lands with #10139; copy it from there until then).
## Writing an audit row from outside Compliance: new AuditTrail() is a Core/X -> Compliance edge (or-fix-3)
- Symptom: ComponentDependencyRulesTest lists '<file> -> lib/Db/AuditTrail.php (Core -> Compliance)' for a PR audit helper doing new AuditTrail() + AuditTrailMapperInterface::insertAuditTrails.
- Fix: build a Kernel\Contracts\Core\AuditEntry and call AuditEntryWriterInterface::writeAuditEntries (Core); tests wrap the mapper mock in new AuditEntryWriter(mapper: ...). AuditEntry has no organisation field on main: #10283 (or-fix-3) adds optional trailing organisationId stamped by AuditEntryWriter. Also: a Core class reading a Search value (BoundedRun) gets a Kernel-root interface carrying its constants.
## Trap: component-deps.py reports 0 for files you have not git-added; Kernel/Contracts/Core is Core (or-fix-9)
- Symptom: python3 scripts/component-deps.py says 0 new edges, yet ComponentDependencyRulesTest lists edges to your new port, and testPhpReaderAgreesWithTheScript fails with a huge array diff.
- Cause: the script reads git-tracked files only; the PHP reader walks lib/. And lib/Kernel/Contracts/Core/* maps to Core, not Kernel: a Processes caller of a port there is still Processes -> Core.
- Fix: git add new files before running the script; put a cross-area port in root lib/Kernel/Contracts (csv ',,Kernel,Kernel,...,0,,Kernel'), implement it in the owning area, bind it there (#10269 4b09b541 MandateEvidenceInterface).
## Inherited red on main 6e870ca5: ComponentDependencyRulesTest 4 Files -> Core edges (or-fix-4)
- Symptom: testNoComponentEdgeBeyondTheBaseline lists DecisionMetadataEdit -> EntityRelation, ManagedFiles -> DocumentStore / ManagedFileOwnerLookup, Kernel/Contracts/Files/EntityMentionDecisionsInterface -> EntityRelation (Files -> Core).
- Same four on plain main (python3 scripts/component-deps.py in a main worktree): inherited, do not fix in a PR. Also inherited there: ReservedColumnNamesTest (MariaDB lex.h 404), AnonymousEnvelopeReadPathsTest (4), phpstan ApiCallerMiddleware $version.
## Inherited red on main 6e870ca5: AreaMapperRegistrarsTest::testEachInterfaceIsBoundOnce (or-fix-8)
- Symptom: ConfigurationMapperRegistrar binds an interface bound elsewhere: TranslationMapperInterface, TranslationSourceLanguageBackfillInterface.
- Cause: red on origin/main itself (checked on a detached main checkout), not on PR branches. Do not fix in a PR; treat as inherited like the 4 Files->Core edges.
## ResolvedConsumer: Unknown named parameter $source after a main merge (or-fix-4)
- Symptom: a PR test errors 'Unknown named parameter $source' at new ResolvedConsumer(source:, uuid:, name:, userId:, authorizationType:, configuration:, record:).
- Cause: main e0d1d3b4 builds ResolvedConsumer from ConsumerIdentity(source, uuid, name) + ConsumerAuthorization(userId, authorizationType, configuration), record stays.
- Fix: wrap the arguments in those two value objects, import them and add #[UsesClass] for both (#10514). grep -rn -A1 'new ResolvedConsumer(' lib tests | grep source: finds the rest.

## Class "OCA\OpenRegister\Mcp\Service\ChatMessageRequest" not found in a PR test (or-fix-3)
- Symptom: Error: Class "OCA\OpenRegister\Mcp\Service\ChatMessageRequest" not found (tests calling ResponseGenerationHandler/ChatService).
- Cause: main moved the value object to lib/Kernel/Contracts/Value/ChatMessageRequest.php (same constructor).
- Fix: change the test's use line and @uses to OCA\OpenRegister\Kernel\Contracts\Value\ChatMessageRequest (see #10493).
## Inherited on main 6e870ca5 (23 Nov 21:30Z): 4 Files -> Core edges (or-fix-2)
- ComponentDependencyRulesTest::testNoComponentEdgeBeyondTheBaseline is red on plain main: DecisionMetadataEdit -> Db/EntityRelation, Files/Service/File/ManagedFiles -> Service/File/DocumentStore and ManagedFileOwnerLookup, Kernel/Contracts/Files/EntityMentionDecisionsInterface -> Db/EntityRelation. Inherited; subtract these from a PR merge's list, do not fix them in a PR.

## Inherited red on main 6e870ca5: ComponentDependencyRulesTest 4 Files -> Core edges (or-fix-6)
- Symptom: testNoComponentEdgeBeyondTheBaseline lists DecisionMetadataEdit/EntityMentionDecisionsInterface -> Db/EntityRelation and ManagedFiles -> DocumentStore/ManagedFileOwnerLookup (Files -> Core) on every merged PR.
- Cause: red on main itself (6e870ca5). Inherited; count the PR's edges with python3 scripts/component-deps.py (4 = none of its own).

## A feature cluster mapped Configuration extends a Core feature (or-fix-6)
- Symptom: ~10 edges '<PR file> -> lib/Service/Schemas/GeneratedIdentifier*.php (Configuration -> Core)' plus '-> SequenceServiceInterface (Configuration -> Processes)'.
- Fix: remap the whole PR cluster (its csv rows only) to the component it extends (Core); a Processes SequenceService call with SequenceScope(0,0,key) becomes Core's Kernel/Contracts/Core/RunningNumbersInterface (same scope); controller AppIdentity + AdminSettingDelegation::OPEN_REGISTER_ADMIN (#10516 7aa7241c).
## Area-map PR moves baselined ContractBindings pairs into <Area>ServiceBindings (or-fix-1)
- Symptom: ComponentDependencyRulesTest lists 'lib/Service/ComplianceServiceBindings.php -> lib/Kernel/Contracts/Core/...Interface.php (Compliance -> Core)' or lib/Service/*Interface.php on a cloud/mod-X08 PR; main's baseline has the same pair from lib/Service/ContractBindings.php.
- Cause: the baseline is per file pair; a binding row whose interface is Core-mapped is a new edge once it moves out of ContractBindings (Configuration).
- Fix: leave those rows in ContractBindings (FQCN lines, drop now-unused uses in the area map), point their binding tests (C01/C03/C07/C09/F12, Kernel *BindingTest) back at ContractBindings, rerun tools/sig/modularity_check.py --write-baseline. Stacked PRs: merge the fixed parent head first (#11001 on #11000, 5fa43f9a).
## Inherited red on main 6e870ca5: ComponentDependencyRulesTest 4 Files -> Core edges (or-fix-1)
- Symptom: every merged PR lists DecisionMetadataEdit/EntityMentionDecisionsInterface -> Db/EntityRelation and Files ManagedFiles -> Service/File/DocumentStore, ManagedFileOwnerLookup (Files -> Core).
- Cause: red on plain main 6e870ca5 (from 9272bccc6 Files-owned ports); not the PR's. Compare the edge list to these four before fixing anything.
## searchObjectsBySlug: Unknown named parameter $registerSlug (or-fix-4)
- Symptom: a PR's lib call $objects->searchObjectsBySlug(registerSlug:, schemaSlug:, ...) fails at runtime (repair step logs 'Unknown named parameter $registerSlug'); phpstan on the file may stay quiet; tests mocking ->with('reg','schema',...) then fail on the expected output.
- Cause: main's Kernel ObjectOperationsInterface::searchObjectsBySlug takes RegisterSchemaSlugs $target (lib/Contract/RegisterSchemaSlugs: register, schema).
- Fix: target: new RegisterSchemaSlugs(register: X, schema: Y); tests ->with(new RegisterSchemaSlugs(...), ...) + #[UsesClass] (#10154). Look for it with grep -rn 'registerSlug:' lib on the merge.

## A flow node and its Integrations service cross Processes <-> Integrations (or-fix-6)
- Symptom: ~30 edges: <X>Node (Processes) -> Db/Source, Service/Sync/* ; Service/Sync/<X>Flow -> Db/Flow, FlowServiceInterface, FlowVersionService... ; FlowsListenerRegistrar -> an Integrations listener.
- Fix (#10156 07d17afb): node calls a root Kernel port whose Integrations adapter holds the old body and throws a Kernel refusal the node maps to FlowStop; the Integrations side writes flows through a root Kernel port (put/remove/startManual) implemented in Service/Flow; register the Integrations listener from IntegrationWiring (+ IntegrationRegistrarTest list, listener golden back to main's). Dependency-free files (Db/SourceMapperInterface, DMN evaluator closure, OutboundAddressGuard, *ValidatorInterface, PermissionCheckInterface) map Kernel.
## Inherited red on main 6e870ca5: ComponentDependencyRulesTest lists 4 Files -> Core edges (or-fix-9)
- Symptom: testNoComponentEdgeBeyondTheBaseline on every PR merged with main 6e870ca5: DecisionMetadataEdit -> EntityRelation, ManagedFiles -> DocumentStore / ManagedFileOwnerLookup, Kernel/Contracts/Files/EntityMentionDecisionsInterface -> EntityRelation (all Files -> Core).
- Cause: red on plain main 6e870ca5 itself (23 Nov 21:30Z). Not a PR defect: filter those four lines and judge only the rest.
## Integrations caller of Core PermissionCheckInterface::hasPermission(schema, 'read') (or-fix-9)
- Fix: root Kernel SchemaReadCheckInterface::canReadSchema(SchemaInterface, register): bool, Core Service/Object/SchemaReadCheck wraps the same call (fails closed on a non-Schema), bound in CoreServiceBindings; tests wrap the mock: new SchemaReadCheck(permissions: $mock) keeps hasPermission expectations. Lands with #10509 fbb985d2; copy it from there. Also a Platform Repair step naming DescriptorConfigIdsInterface::X: restate the string with a pin test. A Processes ListenerRegistrar line registering a Core listener: add a CoreListenerRegistrar::<name>() and call it at the same spot (order golden unchanged, #10549 0243d6d7).
## Platform UserMigration reaching other areas' entities (or-fix-4)
- Symptom: testNoComponentEdgeBeyondTheBaseline lists lib/Service/UserMigration/PersonalState{Reader,Migrator}.php -> Db/CalendarFeedToken, Notifications/Db/TimelineEntry, Service/Interaction/ReadHistoryService (Platform -> Processes/Notifications).
- Fix (#10524 c0a0420e): the reader returns plain arrays read from the rows instead of Entity::fromRow (datetime columns -> new DateTime), restate a foreign constant as a public const with a pin test, and type the read history on root Kernel/Contracts/RecentLensInterface (Kernel/Contracts/Core/ReadHistoryInterface extends it; FlowWiring::BINDINGS + FlowWiringTest list). Kernel/Contracts/Core/* counts as Core, so it does not help a Platform caller.

## New Configuration -> Core component edge (or-fix-7)
- Symptom: ComponentDependencyRulesTest::testNoComponentEdgeBeyondTheBaseline lists 'lib/Service/<Area>/X.php -> lib/Service/<OtherArea>/Y.php (<Area> -> Core)' beyond main's own edges.
- Cause: the PR's code names another component's class; main only allows edges into the Kernel component, which is lib/Kernel/Contracts/*.php at the TOP level (Kernel/Contracts/Core/ etc. count as that area).
- Fix: add a narrow interface in lib/Kernel/Contracts/ (csv row 'Kernel/Contracts/X.php,,Kernel,Kernel,,,,Kernel'), let the provider implement/extend it, bind it in its bindings, inject via setter/registrar. git add new files before testing: component-deps.py reads tracked files only, else testPhpReaderAgreesWithTheScript fails. (#10427)

## l10n:selfcheck 'serializer round-trip byte-exact' FAIL after a PR adds keys (or-fix-3)
- Symptom: npm run l10n:selfcheck -- <loc> adds 'FAIL serializer round-trip byte-exact' vs main; often with one new 'identical, no recorded reason' in parity.
- Cause: the PR appended keys to l10n/<loc>.js by hand, not in serializeJs key order.
- Fix: node -e: c=loadJsTranslations(f); write serializeJs({app:c.app,translations:c.translations,pluralForm:c.pluralForm}) (scripts/l10n/lib.js) for each touched bundle; diff is order only. A genuine cognate goes in scripts/l10n/locales/<loc>.json "cognates" (see #11023).

## csv union resurrects rows main's #10996 revert removed (or-fix-3)
- Symptom: after merging main e353e627+ the csv keeps rows for Files/Service/File/ManagedFiles.php and Files/Service/FileSettingRules.php (no such file), or Db/DecisionMetadataEdit.php stays on Files; PRs that merged main 01cb1831 carry them.
- Cause: main 5f24d440 reverted the Files-owned ports merge; the PR side of the csv conflict hunk still has the reverted rows.
- Fix: after bin/component-map-merge.py, delete those two rows (main's Core row for DecisionMetadataEdit wins), regenerate the yaml; the csv diff vs main must show only the PR's own files.
## Main e353e627 (#11024) reverted the Files-owned ports: conflicts in FilesRegistrar, its test, component-map.csv (or-fix-5)
- Symptom: a PR that merged main 6e870ca5 conflicts in lib/Files/FilesRegistrar.php BINDINGS/uses, FilesRegistrarTest assertCount, and csv rows Files/Service/File/ManagedFiles.php, Files/Service/FileSettingRules.php.
- Cause: #11024 deleted ManagedFiles, FileSettingRules and Kernel/Contracts/Files/{CallerFileRights,EntityMentionDecisions,FileSettingRules,ManagedFiles,OfficeDocumentConversion,RegisterFolderProvisioning}Interface; main's FilesRegistrar has 32 bindings again; the 4 Files->Core edges are gone (Architecture green on main).
- Fix: take main's side, re-add only the PR's own bindings/uses/csv rows, count = 32 + PR's own; drop the ManagedFiles/FileSettingRules csv rows; run component-patterns.py (#10525, #5968).

## tools/sig/modularity-baseline.json conflict on a main merge (or-fix-0)
- Symptom: merge conflict in tools/sig/modularity-baseline.json (exposed_loc, kernel_loc, total_loc, violation_degree hunks).
- Cause: generated numbers; main records a new baseline on nearly every modularity merge.
- Fix: git checkout --theirs the json, run python3 tools/sig/modularity_check.py --check on the merged tree (must pass), then --write-baseline and commit it with the merge (#11000 d86305b2).
## Main e353e627 (#11024) reverted the Files-owned ports: PRs merged with 6e870ca5 conflict in the generated components yaml (or-fix-6)
- Symptom: CONFLICT only in the repo-root components yaml; csv auto-merges.
- Cause: #11024 dropped lib/Kernel/Contracts/Files/* and their csv rows, regenerating the yaml.
- Fix: git checkout --theirs the yaml, python3 scripts/component-patterns.py; grep lib/tests for the removed Files interfaces (none in #10156/#10516/#10526). Architecture green after.

## Main #9053 (85629dd4): hasPermission(PermissionAsk) - a PR's NEW callers fail silently (or-fix-6)
- Symptom: after merging main, the PR's own tests fail with 'You may not change/read ...' refusals or empty lists; no 'Unknown named parameter' visible because the caller's try/catch(Throwable) returns false (fail closed). phpstan argument.unknown on the call.
- Cause: PermissionCheckInterface/PermissionHandlerInterface::hasPermission(schema:, action:, userId:, objectOwner:, object:, register:) became hasPermission(PermissionAsk $ask, bool $_rbac=true).
- Fix: grep the PR's diff for hasPermission; call with ask: (new PermissionAsk(schema: $s, action: 'update'))->askedBy(userId: $u, objectOwner: $owner)->about(object: $obj, register: null) (use OCA\OpenRegister\Service\Object\PermissionAsk); test closures take (PermissionAsk $ask) and read $ask->objectOwner etc.; ->with($schema, 'create') becomes $this->callback(fn (PermissionAsk $a) => $a->action === 'create'). Remember the use line in the test (#10156).

## Dependabot composer.lock bump without sbom.cdx.json (or-fix-9)
- Symptom: bin/regen-sbom.sh --check on the PR merged with main lists removed/added pkg:composer purls (e.g. #10639 mail-mime-parser).
- Cause: dependabot changes composer.lock only; the committed root sbom.cdx.json still names the old versions.
- Fix: merge main, bash ~/q/bin/regen-sbom.sh ., commit sbom.cdx.json alone, --check must say current (#10639 153e1019).
## Mail-sidebar PR red on main's ObjectsTab.links.golden.spec.js (or-fix-1)
- Symptom: after merging main, 4 snapshots in src/mail-sidebar/components/ObjectsTab.links.golden.spec.js fail with an extra ['objects', [...]] entry under 'emitted'.
- Cause: main added the golden while the mail-panel PRs (#11021, #11022, stacked siblings) made ObjectsTab $emit('objects', ...) for MailSidebar.
- Fix: npx jest <that spec> -u, check the snap diff is only the added 'objects' emissions, run jest src/mail-sidebar src/dialogs/mail (see #11021 88982e78).
## hasPermission() named-argument call after main's PermissionAsk refactor (or-fix-9)
- Symptom: phpstan argument.unknown / 'Missing parameter $ask' on PermissionCheckInterface::hasPermission(); at runtime an unknown-named-parameter Error; tests with ->with($schema, 'read', ...) fail 'Parameter count ... too low'.
- Cause: main (475feecd, 198fb738) made hasPermission(PermissionAsk $ask, bool $_rbac = true); PRs still pass schema:/action:/userId:/object:/register:.
- Fix: hasPermission(ask: (new PermissionAsk(schema: ..., action: ...))->askedBy(userId: ..., objectOwner: null)->about(object: ..., register: ...)); tests ->with(...PermissionAskArgs::with(<old positional args>)) (tests/Unit/Service/Object), with register null when an object is given (about() takes the object's). Fixed #10509, #10269.

## Controller test passes 'openregister' to a ...ControllerFactory::create() (or-fix-3)
- Symptom: TypeError ...ControllerFactory::args(): Argument #1 ($request) must be of type OCP\IRequest, string given.
- Cause: main (5ceb54df) made controllers take the app id from AppIdentity/Application::APP_ID, so tests/Unit/Controller/Support/*ControllerFactory::args() lost the $appName parameter.
- Fix: drop the leading 'openregister' argument from the PR's new tests' Factory::create(...) calls (see #10543).

## Controller factory refuses the app name after a main merge (or-fix-7)
- Symptom: TypeError in a test: <X>ControllerFactory::args(): Argument #1 ($request) must be of type OCP\IRequest, string given.
- Cause: main dropped the $appName constructor argument from controllers (cloud/wr-wr5-controller-app-id); tests/Unit/Controller/Support/*Factory::create() now starts at the request.
- Fix: delete the leading 'openregister' argument from the PR's create()/new <X>Controller(...) calls; nothing else changes (#10587 cc99fda0).

## PR code or tests call hasPermission() with the old named arguments (or-fix-5)
- Symptom: after merging main, 'Unknown named parameter $schema' in the PR's tests, or a PR adapter's verdict silently false (its try/catch swallows the Error, e.g. AttachTargetSearch offered no schemas); phpstan argument.unknown on the PR file.
- Cause: main (#9053) moved PermissionHandler/PermissionCheckInterface::hasPermission() to one PermissionAsk value object; older PRs added callers of the old form (#10395 SchemaWriteRights, #10535 slot/break-glass tests).
- Fix: `hasPermission(ask: (new PermissionAsk(schema: $s, action: $a))->askedBy(userId: $u, objectOwner: $o)->about(object: $obj, register: $r))` (about() needs both args, null where absent); willReturnCallback closures take PermissionAsk and read ->schema/->action/->register; ->with(positional) becomes one $this->callback() over those fields; add #[UsesClass(PermissionAsk::class)].
## Controllers lost the $appName argument (main 5ceb54df) (or-fix-4)
- Symptom: after a main merge, TypeError in a PR's own controller test: '<X>ControllerFactory::args(): Argument #1 ($request) must be of type OCP\IRequest, string given' or an ArgumentCountError/TypeError on new <X>Controller('openregister', ...).
- Cause: main 5ceb54df made Credential, Deleted, DsarCase, Forms, GdprEntities, Integrations, Retention, Rules, Schemas, Transition and Webhooks controllers take Application::APP_ID internally; constructors and test factories start at IRequest.
- Fix: drop the leading 'openregister' (or $appName) argument from the PR's call sites of those controllers/factories; if the PR adds a constructor param, keep it after IRequest (#10244).
## A PR's Core listener registered directly in an area Registrar/*ListenerRegistrar (or-fix-8)
- Symptom: after a clean main merge, testNoComponentEdgeBeyondTheBaseline lists 'lib/Listener/Registrar/<Area>ListenerRegistrar.php -> lib/Listener/<New>Listener.php (Processes -> Core)'.
- Cause: main moved registrations into per-area registrars; a listener of another component must go through that component's slot class (e.g. CoreListenerRegistrar::identityClaims).
- Fix: add a public static slot to lib/Listener/CoreListenerRegistrar.php holding the PR's registerEventListener line, call it from the area registrar at the same position, add the slot row to tests/Unit/Listener/CoreListenerRegistrarTest::slots(); order golden unchanged (#10458 aa9653a1).

## Inherited on main 85629dd4: modularity_check.py --check fails on PermissionAsk/PermissionCheck (or-fix-0)
- Symptom: python3 tools/sig/modularity_check.py --check on a PR merged with main prints 'new in Kernel: lib/Service/Object/PermissionAsk.php', '.../PermissionCheck.php' and 'newly exposed ... PermissionCheck.php'.
- Cause: #9053 added them on main without a baseline rewrite; plain main fails the same 3 lines.
- Fix: none in a PR unless the PR owns tools/sig/modularity-baseline.json (then take main's json, --write-baseline on the merged tree, #11000 0ef5a9a6). Also still red on 85629dd4 main: AnonymousEnvelopeReadPathsTest (4 errors), AreaMapperRegistrarsTest::testEachInterfaceIsBoundOnce.

## AggregationQuery::create() takes one AggregationLayout (or-fix-1)
- Symptom: phpstan 'Unknown parameter $groupBy in call to static method AggregationQuery::create()'; tests 'Error: Unknown named parameter $groupBy'.
- Cause: main (a16b1e9d) folded groupBy, dateBucket, metrics and cumulative into Search\Service\Aggregation\AggregationLayout: create(metric, field, filter, layout).
- Fix: wrap those arguments in layout: new AggregationLayout(groupBy: ..., metrics: ...); callers outside Search that only group use AggregationQuery::grouped(). See #10541 a0a55797.

## RecentLensLookupCallSitesTest flags a mapper find (or-fix-5)
- Symptom: RecentLensLookupCallSitesTest::testEveryInternalFindHasADecision lists 'lib/<File>.php::<method>' for a PR's new ->find( call.
- Cause: the sweep counts every ->find( in lib as a possible ObjectService read; a find on MagicMapperInterface (or another mapper) writes no read row.
- Fix: when the receiver is not ObjectService, add the file/method to NOT_OBJECT_SERVICE in that test (as SharerAccessSweepJob); when it is ObjectService, wrap in WriteCause::asLookup or pass audit: false. (#10535 a02052cf)

## Conflict in an area *ServiceBindings.php map after main widened it (or-fix-2)
- Symptom: content conflict in lib/Service/<Area>ServiceBindings.php (use block and BINDINGS const), e.g. after main f1c351de moved Compliance service interfaces into ComplianceServiceBindings.
- Cause: the PR added one binding row to the small old map; main rewrote the map with many rows (often already containing the PR's other rows).
- Fix: take main's side whole, re-add only the PR's rows main lacks (and their use lines, alphabetical); php -l, the area's *ServiceRegistrarTest and Architecture (#10132 95435873).

## Criss-cross merge conflicts in files the PR never touched (or-fix-8)
- Symptom: merging main gives CONFLICTs in many files outside the PR's change (ObjectsController, ObjectService, UserService tests...); `git merge-base --all origin/main pr` prints 2 or more bases.
- Cause: an earlier main merge into the PR plus main's own history give several merge bases; the virtual base made from them conflicts. For each such file one side still equals a merge base.
- Fix: per conflicted file, if the PR blob equals any merge base blob take main (--theirs); if main equals a base take the PR (--ours); resolve the rest by hand. Then check `git diff origin/main` on the merge equals `git diff <newest base> pr`. (#10358 81cf4d5c, #10398 06df77ae, #10188 6714145c)

## Cloud composer install fails: 'Could not authenticate against github.com' (or-fix-0)
- Symptom: composer install stops on a 403 from api.github.com zipball for phpstan/phpstan; a source clone times out (the repo carries phar history).
- Cause: phpstan/phpstan is the only dist-only package in composer.lock; unauthenticated GitHub API is refused here.
- Fix: composer config -g use-github-api false; install --prefer-source --no-scripts --ignore-platform-reqs; for phpstan, git fetch --depth 1 the locked commit, git archive it to a zip, point that package's lock dist at the local zip (type zip, drop source), install, then restore composer.lock.
## Conflicts only in files the PR never touched (main's 5f24d440 revert, criss-cross bases) (or-fix-4)
- Symptom: merging main conflicts in ObjectsController, LockHandler, EntityRelationMapperInterface, DeleteObjectDeleteGoldenTest, UserServicePreferencesCharacterisationTest etc., none of them the PR's feature files; git merge-base --all shows two bases.
- Cause: the PR's side holds an older main (e.g. 6e870ca5 with the Files-ports merge main later reverted, or UserService's pre-bundle constructor); not PR intent.
- Fix: own=$(git diff --name-only <last main merged into the PR> HEAD); for each conflicted file not in own: git checkout --theirs; then git diff --cached --name-only origin/main must be a subset of own (#10154, #10244, #10514).

## A component-map row the PR remapped, that main later moved (or-fix-8)
- Symptom: after a main merge, CoreServiceBindingsTest::testEveryClassCountsToCore lists '<class> counts to Kernel' (or another area), and/or ComponentDependencyRulesTest names a new edge from the PR's file.
- Cause: an earlier main merge on the PR changed a csv row's area (e.g. the RegisterScopedSchemaResolver trio to Kernel) to silence an edge; main later moved and bound that class in Core, and the union kept the PR's row.
- Fix: diff scripts/component-map.csv vs origin/main for rows that are not the PR's own files and restore main's; rerun component-patterns.py. If the PR's file then crosses areas, reach it through a Kernel contract (here ObjectOperationsInterface setRegister/setSchema + getCurrent*Entity, as ObjectExportController does), never the baseline (see #10498 6b20d3ad).
## False red from a shared vendor/ between checkouts (or-fix-1)
- Symptom: A18InterfaceBindingsTest::testOnlyTheKeptFilesNameTheClass lists the class's own file (e.g. Service/Settings/ObjectRetentionHandler.php) as a caller; other reflection-based Architecture tests may follow.
- Cause: vendor/ symlinked from another checkout: its composer autoloader maps OCA\\OpenRegister\\ to that checkout's lib/, so ReflectionClass::getFileName points outside the tree under test. Not a PR or main defect.
- Fix: give every checkout its own vendor (bash ~/q/bin/composer-cloud.sh <checkout>, which copies the cached vendor quickly). Architecture in one phpunit run can pass 600 s here: run it as 4 parallel shards of symlinked test files (~3 min).
- Also: test:l10n:parity is red on main for every non-nl locale (179 missing at cac830e0); a PR adding en+nl only is the inherited state, not a new red. Compare per-locale missing counts with main, not the raw diff.
## Clean main merge, components yaml stale anyway (or-fix-3)
- Symptom: on an APPROVED PR that merges clean, ComponentDefinitionTest says 'lib/Service/Audit/AuditResealInterface.php matches no component' and ComponentDependencyRulesTest shows new edges.
- Cause: git auto-merges sigrid.components.yaml line by line, so main's new pattern lines drop out where the PR rewrote the same component's list.
- Fix: the PR counts as red on main: merge main, run python3 scripts/component-patterns.py, commit the yaml with the merge (#10493). Run DbalObjectSource* tests serially: in parallel they share one sqlite file and fail with 'table already exists'.

## False merge conflicts from a shallow checkout (or-fix-3)
- Symptom: `git merge-tree --write-tree origin/main <head>` lists dozens of conflicts (routes.php, Application.php, every *InterfaceBindingsTest) for a PR that reviewers call clean.
- Cause: the checkout is shallow (.git/shallow exists, e.g. the session's preinstalled repo); merge-base resolution is wrong without full history.
- Fix: use a full-history blobless clone (`git clone --filter=blob:none`) for merge-tree and merges; never resolve conflicts seen only in a shallow repo.
- Also: tests with @runInSeparateProcess fatal on 'OCP\EventDispatcher\Event not found' when auto_prepend is passed with -d; put it in an ini under PHP_INI_SCAN_DIR (see entry above).

## tests/Unit/Architecture exceeds the 600 s Bash timeout (or-fix-5)
- Symptom: one phpunit call over tests/Unit/Architecture is killed at the timeout; no failure printed.
- Cause: every *InterfaceBindingsTest::testNoOutsideFileNamesTheClass case walks all of lib/ (~1 s each); phpunit 10.5 has no --exclude-filter.
- Fix: run each Architecture test file as its own process in parallel (ls tests/Unit/Architecture/*Test.php | xargs -P $(nproc) ...), resumable; all 201 files finish in ~9 min on 4 cores.

## Open PRs pick distinct info.xml versions after an absorbed bump (or-fix-8)
- Symptom: several clean, approved migration PRs all sit at main's <version> after main moved to the value they picked; check-migration-version-bump.php exits 1 on each merged tree.
- Cause: main absorbs one shared bump, leaving the others with migrations that never run.
- Fix: merge main (a bump-only commit conflicts with main's info.xml hunk), then bump each PR to its own distinct value past main (this run: #10398 dev.5, #10568 dev.6, #5968 dev.7, #10518 dev.8), so the one that lands second only needs one re-bump.
## Inherited phpstan on main: FlowStateMapperInterface::deleteByFlow() undefined (or-fix-3)
- Symptom: phpstan on lib/Service/Flow/FlowService.php: 'Call to an undefined method OCA\OpenRegister\Db\FlowStateMapperInterface::deleteByFlow()' (method.notFound).
- Cause: main c557d73ad93 narrowed the flow-state mapper to an interface without deleteByFlow; FlowStateMapper still has it, so it runs. Red on plain main aa1f24b9.
- Fix: none in a PR that does not own the interface; inherited. Same for DeletedController array_map argument.type, WebhookMapper property.onlyWritten, GraphQLService/WebhooksController catch.neverThrown.

## Second checkout with a symlinked vendor tests the other checkout (or-fix-0)
- Symptom: a golden or test reproduces main's (or another PR's) result exactly after a merge, as if the PR's code were missing.
- Cause: composer's autoload_psr4 resolves OCA\\OpenRegister\\ relative to the real vendor dir, so a git worktree with vendor symlinked to another checkout loads that checkout's lib/.
- Fix: cp -r the vendor into each worktree (or run composer-cloud.sh there), never symlink it.

## Built-in flow node count after a main merge (or-fix-3)
- Symptom: FlowNodeBuiltInRegistrationTest::testRegistersEveryBuiltInOnceInCoreActionBoundaryOrder / FlowNodeRegistrationListenerTest::testEveryBuiltInReachesTheRegistryPortalTaskIncluded: 'actual size N+1 matches expected size N'.
- Cause: main added a built-in (AuditTrailNode, 33) while the PR added its own node against the older count.
- Fix: assertCount(main's count + the PR's new nodes) in both tests (message text too); also run tests/Unit/Docs (FlowsDocumentationTest) for the node's flows.md paragraph (#10363).
## sbom auto-park when package.json only promotes a transitive dep (or-fix-5)
- Symptom: parked 'sbom: package-lock.json changed without sbom.cdx.json', but the lock diff only adds a name to the root package's dependencies.
- Cause: the gate checks file names first; the components are unchanged, so regen-sbom.sh leaves sbom.cdx.json untouched and there is nothing to commit.
- Fix: run bin/regen-sbom.sh --check on the PR head; 'current' means no fix: record a NOTE verdict, do not fake an sbom change (#10395).

## Parked 'precheck-red' on ObjectsList.spec.js paging (or-fix-9)
- Symptom: parked.txt says precheck-red alone on main: FAIL src/views/object/ObjectsList.spec.js ('a new page size refetches page 1 once': expected 1 refreshObjectList call, got 3) on a PR that does not touch src/views/object.
- Cause: load-dependent flake in the full jest run; the spec passes alone on the same tree. Not a PR defect.
- Fix: rerun the spec alone (npx jest src/views/object/ObjectsList.spec.js) to confirm; merge main for a new head, diff check, push, REVERIFY (see #10499 59f3959f).

## l10n/en.json + nl.json (backend) conflict on a main merge (or-fix-2)
- Symptom: CONFLICT in l10n/en.json and l10n/nl.json only; both sides appended keys at the end of "translations" (missing comma / adjacent blocks).
- Cause: the backend catalogues are hand-maintained JSON; PRs and main append at the same spot. l10n-merge.js handles the .js bundles only.
- Fix: three-way per key from index stages (git show :1:/:2:/:3:), main wins a key both changed, ours-added keys appended, json.dumps(indent=4, ensure_ascii=False)+newline (assert it round-trips main's stage first); git diff origin/main --numstat -- l10n/*.json must show only the PR's additions (#11012 16f0ecf6, #11022 f2304a9e). Then diff node tests/l10n/check-l10n.js and test:l10n:parity against a main worktree: a new t() key absent from en.js that no locale ever translated (e.g. IBAN) gets unwrapped (+ l10n-ai.js rm), an English-identical genuine cognate gets a scripts/l10n/locales/<loc>.json cognates entry (#11022 0a0239b9).
## component-map.csv conflict where the PR REMAPPED rows, not just added them (or-fix-3)
- Symptom: csv conflict hunk where HEAD holds existing rows with a changed area (e.g. Service/Resolver/Exception/* Core -> Kernel) beside main's new rows; a blind union of both sides duplicates those paths with two areas.
- Cause: the PR re-areas shared rows on purpose (#10553 44725b55: exceptions reachable from every area); main appended rows next to them.
- Fix: row-level merge from index stages: main's file in its order (keep its duplicate rows), apply only the PR's own row changes (stage 1 -> 2) by path, append PR-added rows; git diff origin/main -- scripts/component-map.csv must equal the PR's own row changes. Then take main's components yaml and rerun scripts/component-patterns.py (#10553 1d4baa7e). Script: ~/w/bin/csv3.py as written in this run (not committed).
## Precheck red: main's src/services/initial-data.golden.spec.js after a PR adds a component data() field (or-fix-1)
- Symptom: precheck front.log FAIL src/services/initial-data.golden.spec.js, '+ "<newField>": ...' in one component's recorded state; the PR's own specs are green.
- Cause: main records every settings/view component's initial data() in one golden; a PR adding a field there predates it.
- Fix: npx jest src/services/initial-data.golden.spec.js -u; the snapshot diff must be only the PR's own field(s) (#10451 914835d2). Run the whole jest suite (not just the PR's dirs) before calling a frontend PR green.

## Exact-array createShare expectation misses main's 'crosses' param (or-fix-7)
- Symptom: FederationController*Test create test 'Failed asserting that 500 is identical to 201' (the controller catches the mock's ExpectationFailedException as Throwable and answers 500).
- Cause: main's FederationController::createShare now passes 'crosses' => getParam('crosses') to createOutgoingShare.
- Fix: add 'crosses' => null (or the request's value) to the test's ->with([...]) array (see #10587 3b25f4b6).

## A later main merge silently keeps an earlier merge's lost main line (or-fix-3)
- Symptom: BLOCK 'main merge drops main's <X>' although the merge had no conflict there (e.g. a Repair step missing from appinfo/info.xml).
- Cause: an earlier main merge on the branch resolved a hunk to the PR side, so git now treats main's line as a PR-side deletion and every later merge keeps it deleted.
- Fix: after merging, `comm -23 <(git diff --name-only origin/main | sort) <(git diff --name-only $(git merge-base origin/main <orig PR head>) <orig PR head> | sort)` must be empty, and `git diff origin/main -- <file>` of each shared file must show only the PR's own lines; re-add what main has.
## False 'sbom' auto-park on every lockfile PR (or-fix-7)
- Symptom: parked.txt 'auto-park: sbom: package-lock.json|composer.lock changed without sbom.cdx.json' (or 'does not match') although bash bin/regen-sbom.sh --check <checkout at the PR head> says current; sbom.out lists dozens of 'removed: pkg:composer/...'.
- Cause: integrate.sh sbom_gate made its check tree with `git clone --shared` of the blobless $C, which cannot read the missing blobs, so regen-sbom ran on an empty tree; and it parked any untouched sbom by file name, unlike merge_gate.py.
- Fix: fixed in bin/integrate.sh (worktree checkout; untouched sbom judged by --check alone). Refresh $SIG_WORK/bin from ~/q/bin, delete the PR's park line (and its RED line in queue/prechecked.txt) and precheck it again. Seen on #10627; #10395, #10634, #10640 carry the same park.

## Precheck park on Service/Chat ResponseGeneration*Test: unpatched llphant in vendor (or-fix-6)
- Symptom: ResponseGenerationBreakerTest (2) and ResponseGenerationGoldenTest (5 ollama sets) red: 'Undefined property LLPhant\Chat\OllamaChat::$lastUsage' / 'Cannot assign null to ...::$lastUsage'.
- Cause: NOT main. A vendor built by bin/composer-cloud.sh for main's lock (llphant 1.10.12, #10981) came out without the composer.json patches (patches/llphant-*.patch, phpspreadsheet-zipstream3-prefer.patch); they still apply cleanly to 1.10.12.
- Fix: in vendor/theodo-group/llphant and vendor/phpoffice/phpspreadsheet run patch -p1 < patches/<file> (also in ~/w/vendor-openregister-<hash>); Chat goes 93/93 green. Unpark any PR parked on it at its head.

## PR builds what a sibling change already landed on main (or-fix-8)
- Symptom: a dozen conflicts in lib/Db/SchemaMapper.php, RegisterMapper.php, SchemaLookup.php and their goldens on a build PR (e.g. #10568 object-write-sub-500ms vs main's #10520 object-write-at-instance-floor, both splitting the id/uuid/slug lookup into arms).
- Cause: two openspec changes implemented the same step; main's landed first.
- Fix: take main's side for the duplicated part (git checkout --theirs on those files and goldens), re-apply only the PR's own additions onto main's structure, adapt the PR's tests to main's behaviour (e.g. IdentifierArms order: slug before uuid for a non-uuid id), re-record goldens and check the fixture diff is only the PR's addition, note it in the change's tasks.md, and rerun check-migration-version-bump.php.

## Lock-only dependabot PR auto-parked 'composer.lock changed without sbom.cdx.json' (or-fix-9)
- Symptom: parked.txt auto-park on a dependabot composer PR, even dev-only bumps; regen-sbom --check says stale.
- Cause: main's committed sbom.cdx.json is stale on the npm side (@conduction/nextcloud-vue 2.180.0 recorded, 2.203.0 locked; seen 24 Nov at main 0bdacec6+), so every lock PR's head fails the check.
- Fix: merge main, bash ~/q/bin/regen-sbom.sh <checkout>, commit sbom.cdx.json with [skip ci], --check current, push, REVERIFY (#11079). A main PR regenerating the SBOM once would end it.

## Duplicate wiring binding after an earlier build PR of the same change landed (or-fix-3)
- Symptom: phpstan 'Array has 2 duplicate keys' in a *Wiring::BINDINGS after merging main; the wiring test may stay green because PHP keeps the first key position.
- Cause: a build/<change>-2 PR landed part of the change (FQCN binding appended at the end); the -3 PR binds the same interface with a use import higher up.
- Fix: drop the PR's binding, its now-unused use lines and its row in the wiring test's expected list; keep main's.

## appinfo/info.xml version conflict: do not take one side's whole file (or-fix-6)
- Symptom: CONFLICT in appinfo/info.xml on the <version> line only; 'git checkout --ours' resolves it but silently drops main's other new lines (e.g. <command>...EvaluateDetectorCommand</command>).
- Fix: rebuild from main: git show origin/main:appinfo/info.xml, set the PR's <version> (must be > main's), re-add the PR's own <job>/<command>/<step> lines; check with git diff origin/main -- appinfo/info.xml (only the PR's lines) and php scripts/check-migration-version-bump.php --base=origin/main.

## Main's new test names a class the PR moved (or-fix-9)
- Symptom: after the main merge, Error: Class "OCA\OpenRegister\<old ns>\X" not found in a test that came from main (e.g. OfficialsSourceTest, CapacityPolicy).
- Cause: the PR moved X (e.g. Service/Anonymisation/CapacityPolicy -> Kernel/Contracts); main added tests or @uses with the old name after the PR branched.
- Fix: grep -rn the old FQCN in lib/ tests/ (use lines and @uses docblocks), point them at the new name, rerun the dir (#10499).

## Main baseline in a worktree must not symlink the PR checkout's vendor (or-fix-6)
- Symptom: a test 'red on plain main too', or a main-vs-PR dump showing no difference, while the PR clearly changes it.
- Cause: composer's autoload_psr4 resolves $baseDir from vendor's real path, so a main worktree with vendor -> <pr>/vendor loads the PR's lib/.
- Fix: cp -a the vendor into the main worktree (or run composer-cloud.sh there) before any baseline run.
## Inherited phpstan red: TaskSequenceMapperInterface::update() (or-fix-6)
- Symptom: phpstan method.notFound at Service/Task/TaskSequenceService.php (3x $this->services->sequences->update()) on any PR that touches that file.
- Cause: main c557d73 narrowed the mapper to TaskSequenceMapperInterface (findByUuid, findNewestForAnchor, insert) without update(); main's own lines.
- Fix: none in a PR (inherited); belongs in a main fix adding update(Entity): Entity to the interface. Seen on #11086.
## Processes/other-area CSV export needs the formula rule (or-fix-9)
- Symptom: BLOCK 'CSV cells written raw, route through SpreadsheetCell::csvRow'; doing so trips testNoComponentEdgeBeyondTheBaseline '<file> -> lib/Service/Export/SpreadsheetCell.php (Processes -> Core)'.
- Fix: call Kernel\Contracts\CsvCell::row()/value() (root Kernel, on #11059 7bfad984 until it lands: SpreadsheetCell delegates to it; copy the class + csv row 'Kernel/Contracts/CsvCell.php,,Kernel,,,,,Kernel' if main lacks it).
- defaultShapes golden (or-fix-8, 25 Nov): symptom FAIL src/store/defaultShapes.golden.spec.js 'registers every page as an async component' with + an entry for a page the PR adds. Cause: main's golden snapshots every page in src/registry.js; a PR adding a page predates it. Fix: on the PR branch run `npx jest src/store/defaultShapes.golden.spec.js -u`, check the diff adds only the PR's page(s), commit the .snap.
## bin/l10n-merge.js moves integer-like keys to the top of en.js/nl.js (or-fix-3)
- Symptom: after l10n-merge.js, git diff origin/main -- l10n/en.js l10n/nl.js shows "3" and "30" removed near line 20 and re-added at line 4.
- Cause: the unsorted (main-order) branch builds a JS object; JS iterates integer-like keys first, so main's line order is lost for them. Content is identical, the diff is noise.
- Fix: after the merge, reorder each key line to git show origin/main:<file>'s line order, new keys after, last line without comma (a 20-line python over the raw lines); expect numstat <PR keys> 1 (the comma). Used on #10533 0ec16721, #11023 83371131.

## l10n/*.json conflicts at the end of the translations block (or-fix-2)
- Symptom: CONFLICT in l10n/en.json and l10n/nl.json, both sides appended keys after the same last entry.
- Cause: the backend catalogues are hand-maintained and appended; main's en.json can hold a duplicate key, so json.load + json.dumps does not round-trip and must not be used.
- Fix: textual union per conflict hunk: ours lines, then theirs lines minus simple "key": "value" lines already in ours; add the missing comma on ours' last line. Check json.load passes and that neither side's key set lost a key against the merged file.
## DocumentReferenceMapTest::testEveryFileIdColumnIsMapped after a main merge (or-fix-1)
- Symptom: 'A column holds a file id the move would not rewrite' listing a table.column the PR's migration adds (file_id, *_file_id, folder, node_id).
- Cause: main's register-document-store (f1327b39) scans every migration for id-shaped columns; a PR adding such a column must declare it.
- Fix: a Nextcloud file/folder id: add a row to DocumentReferenceMap::COLUMNS (KIND_FILE/KIND_FOLDER); anything else: add it to NOT_FILE_IDS with the reason. Used on #10451 e27167ee.

## dexie bump fails npm run build at check-single-dexie.js (or-fix-6)
- Symptom: precheck front=red; front.log ends 'x dexie singleton: js/...dexie_import-wrapper_mjs.js embeds Dexie (sentinel found) but no semVer literal matched'.
- Cause: dexie >= 4.4.7 keeps its version in a constant, so the minified chunk has semVer:Jt plus Jt="4.4.7" instead of semVer:"x.y.z".
- Fix: scripts/check-single-dexie.js chunkVersions() follows semVer:<ident> to <ident>="x.y.z" (see #11076 c42498d6); cherry-pick that commit onto any other dexie bump.

## ReservedColumnNamesTest errors 'lex.h answered HTTP 404' in a cloud session (or-fix-6)
- Symptom: every tests/Unit/Migration/ReservedColumnNamesTest case throws RuntimeException '...MariaDB/server/10.5/sql/lex.h answered HTTP 404'.
- Cause: tests/Support/ReservedWordSource sends GITHUB_TOKEN as a Bearer header to raw.githubusercontent.com; the session's placeholder token makes it answer 404. Not a PR defect.
- Fix: run the suite with `env -u GITHUB_TOKEN -u GH_TOKEN php vendor/bin/phpunit ...`; it then passes.

## New constructor entry on a built-in integration service (or-fix-5)
- Symptom: BuiltinIntegrationProviderRegistrationTest::testEveryFactoryBuildsItsServiceFromTheExpectedEntries: arrays differ, Actual adds a class under one service (e.g. OCP\ICacheFactory on XwikiLinkService).
- Cause: the PR added a parameter to the service and its entry in lib/Service/Integration/BuiltinIntegrationServices.php, but the test pins every factory's resolved entries.
- Fix: add the class to that service's expected list in the test, in the Actual order (see #11005 228baa2c); BuiltinIntegrationServicesTableTest may also need its wiring hash recomputed.

## New list-page action breaks listActions.dom.golden (or-fix-5)
- Symptom: precheck-red 'FAIL src/views/listActions.dom.golden.spec.js', the Received snapshot adds an NcActionButton (e.g. 'Restore with related records' under 'deleted').
- Cause: main pins every list page's action menu in one golden; a PR adding an action updates only its own view's snapshot.
- Fix: on the PR merged with main, npx jest src/views/listActions.dom.golden.spec.js -u, check the diff only adds the PR's action, commit with the main merge (see #11055 18c4aaad).

## l10n key silently dropped by a clean merge (or-fix-3, #11023)
- Symptom: after a main merge, test:l10n:parity shows '<loc>: 1 missing key' plus 'stale cognate record' for a key the PR uses; no conflict in l10n/<loc>.js.
- Cause: main cleaned the key out of a locale as unused while the PR (re)uses it; the 3-way merge applies main's delete.
- Fix: diff parity output against a main worktree; for each key the PR's head has but the merge lost, echo '{"<key>":"<pr value>"}' > p.json; node scripts/l10n/apply.js <loc> p.json --apply.
- PRECHECK deps failed (or-fix-8, 25 Nov): symptom: precheck/integrate NORESULT 'deps failed', logs/deps.log says vimeo/psalm 7.16.0 requires php-64bit ~8.3.16 (sandbox 8.3.6); main bumped psalm at 9cb4b4e0. Fix (local vendor only, nothing committed): after integrate.sh prepare, in $SIG_WORK/or run 'env -u GH_TOKEN -u GITHUB_TOKEN COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_PROCESS_TIMEOUT=0 composer install --no-interaction --no-progress --prefer-source --ignore-platform-req=php-64bit --ignore-platform-req=php' (~12 min; the post-install 'bin all' auth error is expected), then 'sha256sum composer.lock | cut -d" " -f1 > vendor/.sig-lock' so composer_deps skips; precheck then runs GREEN (10568, 11088).
## ObjectServiceSaveObjectGoldenTest records a new mapper call (or-fix-4, 25 Nov)
- Symptom: ObjectServiceSaveObjectGoldenTest::testSaveObjectMatchesGolden fails with an extra call (e.g. schemaMapper.findByFormerSlugInIds) in the recorded sequence.
- Cause: main's saveObject golden (tests/Unit/Service/fixtures/object-service-save-object-golden.json) records every mapper call; a PR that adds a lookup on the save path changes the sequence.
- Fix: when the new call is the PR's intended behaviour and 'after'/result are unchanged, rerun the test with UPDATE_GOLDEN=1, check the fixture diff only adds that call, commit. #11064 cbe33557. #11067 (same change) likely needs the same.

## Main test pins SQL the PR parameterised (or-fix-3)
- Symptom: precheck red on a main-added query-shape test (e.g. ApiCallRecordMapperPeriodQueriesTest::testCountIncrementsAKnownKey: expected 'call_count + 1', actual 'call_count + :p0'; insert values shifted by one).
- Cause: main added a golden of the old query after the PR changed it (here #11093 binds the call count).
- Fix: merge main, update main's test to the PR's query (extra bound value first, array_slice offset +1); keep the asserted columns. (#11093 40a91690)

## Two PRs add AuditTrail columns: conflicts in the entity, field types and both goldens (or-fix-5)
- Symptom: after main #11071 (ruleDigest/ruleVersion) a PR adding its own AuditTrail column conflicts in lib/Db/AuditTrail.php (@method list and property), AuditTrailFieldTypes::TYPES, AuditTrailSealedFormGoldenTest::testFieldTypesInOrder and fixtures/entity-hydration-golden.json.
- Fix: keep both, main's columns first then the PR's, in the same order in all four files (fix trailing commas in the json); EntityHydrationGoldenTest, AuditTrailSealedFormGoldenTest and tests/Unit/Db --filter AuditTrail green after. Then rerun check-migration-version-bump: main reached dev.17, so a PR at dev.17 needs a free number (dev.18/19 taken by open PRs on 25 Nov; #10515 went to dev.20).
## A new Core ServiceRegistrar binding to an area class (or-fix-9, #11139)
- Symptom: on the merge with main, ServiceRegistrarTest::testRegistersTheServicesInOrder gains an entry and ComponentDependencyRulesTest::testNoComponentEdgeBeyondTheBaseline lists 'lib/Service/ServiceRegistrar.php -> lib/<Area>/... (Core -> <Area>)'.
- Cause: Core may not reference an area's class; the binding belongs to the owning area's registrar.
- Fix: keep main's ServiceRegistrar and its test; add Contract => Class to the area's registrar BINDINGS (e.g. FilesRegistrar), bump its test's assertCount and assert the pair; add component-map rows for new lib files; never extend the dependency baseline.
## Architecture suite exceeds the 600 s Bash timeout (or-fix-3/8, 25 Nov)
- Symptom: phpunit tests/Unit/Architecture alone runs ~19 min in the cloud container and is killed or backgrounded.
- Fix: pass the test files in batches as positional args (phpunit -c phpunit-unit.xml $(ls tests/Unit/Architecture/*Test.php | sed -n 1,70p)); 70/35/35/rest+C12 each finish under 600 s; total 2388 tests at main 1e257c72.
