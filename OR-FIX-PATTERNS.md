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
