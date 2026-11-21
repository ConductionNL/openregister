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
