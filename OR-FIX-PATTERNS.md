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
