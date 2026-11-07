<?php

/**
 * MagicMapperInterface is the union of small role interfaces, each bound to MagicMapper.
 *
 * A caller that needs one role of the mapper types against that role. Each role
 * is aliased to MagicMapper in DbRegistrar, so every role resolves to the one
 * shared mapper instance; a missing alias is a runtime 500 that no other unit
 * test sees, so this test pins the binding, the split and the callers.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Db\DbRegistrar;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicAccessPredicateInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicColumnDriftInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicCrossTableReaderInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectDeletionInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectLockInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectSearchInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectWriterInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicRelationReaderInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicSlugStoreInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicStatisticsInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicTableLocatorInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicTableMaintenanceInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicTableRowReaderInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @coversNothing
 */
final class A03InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * The role interfaces MagicMapperInterface extends.
	 *
	 * @var list<class-string>
	 */
	private const ROLES = [
		MagicAccessPredicateInterface::class,
		MagicColumnDriftInterface::class,
		MagicCrossTableReaderInterface::class,
		MagicObjectDeletionInterface::class,
		MagicObjectFinderInterface::class,
		MagicObjectLockInterface::class,
		MagicObjectSearchInterface::class,
		MagicObjectWriterInterface::class,
		MagicRelationReaderInterface::class,
		MagicSlugStoreInterface::class,
		MagicStatisticsInterface::class,
		MagicTableLocatorInterface::class,
		MagicTableMaintenanceInterface::class,
		MagicTableRowReaderInterface::class,
	];

	/**
	 * Callers under lib/ that type against one role instead of MagicMapperInterface.
	 *
	 * @var array<string, class-string>
	 */
	private const CALLERS = [
		'Command/MagicTableSelection.php' => MagicTableLocatorInterface::class,
		'Controller/CalculationsController.php' => MagicTableRowReaderInterface::class,
		'Controller/ContentReportController.php' => MagicObjectFinderInterface::class,
		'Controller/EntityRelationsController.php' => MagicObjectFinderInterface::class,
		'Controller/TranslationController.php' => MagicObjectFinderInterface::class,
		'Files/Service/TextExtraction/ObjectHandler.php' => MagicObjectFinderInterface::class,
		'Listener/UniqueConstraintListener.php' => MagicObjectSearchInterface::class,
		'Notifications/BackgroundJob/ScheduledNotificationJob.php' => MagicObjectFinderInterface::class,
		'Notifications/Service/EmailService.php' => MagicRelationReaderInterface::class,
		'Search/BackgroundJob/ReportRenderJob.php' => MagicTableRowReaderInterface::class,
		'Search/Controller/ReportsController.php' => MagicObjectFinderInterface::class,
		'Service/Calculation/TemporalCalculationSweepService.php' => MagicTableRowReaderInterface::class,
		'Service/Configuration/ObjectChangePreview.php' => MagicObjectFinderInterface::class,
		'Service/File/FilePublishingHandler.php' => MagicObjectFinderInterface::class,
		'Service/File/ManagedFileOwnerLookup.php' => MagicObjectFinderInterface::class,
		'Service/File/ReadFileHandler.php' => MagicObjectFinderInterface::class,
		'Service/Hinge/InheritedGeoCollector.php' => MagicObjectFinderInterface::class,
		'Service/Hinge/LensResolver.php' => MagicObjectFinderInterface::class,
		'Service/Lifecycle/LifecycleFinalStateResolver.php' => MagicObjectFinderInterface::class,
		'Service/Object/FacetCalculation.php' => MagicObjectSearchInterface::class,
		'Service/Object/GetObject.php' => MagicObjectFinderInterface::class,
		'Service/Object/ObjectSlugBackfillService.php' => MagicSlugStoreInterface::class,
		'Service/Object/ObjectSlugBackfillWriter.php' => MagicSlugStoreInterface::class,
		'Service/Object/SaveObject/ComputedFieldHandler.php' => MagicObjectFinderInterface::class,
		'Service/Object/SaveObject/InverseWriteBack.php' => MagicObjectFinderInterface::class,
		'Service/Object/SaveObject/LifecycleSeed.php' => MagicObjectFinderInterface::class,
		'Service/Object/SaveObject/ObjectSlugStore.php' => MagicSlugStoreInterface::class,
		'Service/Object/SaveObject/ReferenceExistence.php' => MagicObjectFinderInterface::class,
		'Service/Object/SaveObject/RelationCascadeHandler.php' => MagicObjectFinderInterface::class,
		'Service/Object/ValidateObject.php' => MagicObjectSearchInterface::class,
		'Service/ObjectSource/ViewObjectSourceProvider.php' => MagicTableRowReaderInterface::class,
		'Service/Relation/RelationGraphService.php' => MagicObjectFinderInterface::class,
		'Service/Rules/RuleTrialService.php' => MagicTableRowReaderInterface::class,
		'Service/Search/SearchDictionaryProvider.php' => MagicObjectSearchInterface::class,
		'Service/Vocabulary/ConceptRepository.php' => MagicObjectSearchInterface::class,
	];

	/**
	 * One case per role interface.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function roles(): array {
		$cases = [];
		foreach (self::ROLES as $role) {
			$cases[$role] = [$role];
		}

		return $cases;
	}//end roles()

	/**
	 * One case per caller that moved to a role.
	 *
	 * @return array<string, array{string, class-string}>
	 */
	public static function callers(): array {
		$cases = [];
		foreach (self::CALLERS as $file => $role) {
			$cases[$file] = [$file, $role];
		}

		return $cases;
	}//end callers()

	/**
	 * The role is an interface MagicMapperInterface extends and MagicMapper implements.
	 *
	 * @param class-string $role The role interface.
	 *
	 * @return void
	 */
	#[DataProvider('roles')]
	public function testTheRoleIsPartOfTheMapper(string $role): void {
		$this->assertTrue(interface_exists($role), $role . ' exists');
		$this->assertContains($role, class_implements(MagicMapperInterface::class));
		$this->assertContains($role, class_implements(MagicMapper::class));
	}//end testTheRoleIsPartOfTheMapper()

	/**
	 * The role is bound to MagicMapper in DbRegistrar, so it resolves to the shared mapper.
	 *
	 * @param class-string $role The role interface.
	 *
	 * @return void
	 */
	#[DataProvider('roles')]
	public function testTheRoleIsBound(string $role): void {
		$this->assertArrayHasKey($role, DbRegistrar::BINDINGS);
		$this->assertSame(MagicMapper::class, DbRegistrar::BINDINGS[$role]);
	}//end testTheRoleIsBound()

	/**
	 * Every method of MagicMapperInterface is declared by exactly one role, and by none of its own.
	 *
	 * @return void
	 */
	public function testTheRolesPartitionTheInterface(): void {
		$declared = [];
		foreach (self::ROLES as $role) {
			foreach ((new ReflectionClass($role))->getMethods() as $method) {
				$this->assertSame($role, $method->getDeclaringClass()->getName(), $method->getName() . ' is declared once');
				$declared[] = $method->getName();
			}
		}

		$all = array_map(
			static fn (ReflectionMethod $method): string => $method->getName(),
			(new ReflectionClass(MagicMapperInterface::class))->getMethods()
		);
		sort($declared);
		sort($all);
		$this->assertSame($all, $declared);
		$this->assertCount(count($all), array_unique($declared));
	}//end testTheRolesPartitionTheInterface()

	/**
	 * A caller that moved to a role names that role and no longer names MagicMapperInterface.
	 *
	 * @param string       $file The caller, relative to lib/.
	 * @param class-string $role The role it types against.
	 *
	 * @return void
	 */
	#[DataProvider('callers')]
	public function testTheCallerTypesAgainstItsRole(string $file, string $role): void {
		$source = (string) file_get_contents(self::LIB . '/' . $file);
		$this->assertStringContainsString('use ' . $role . ';', $source);
		$this->assertStringNotContainsString('MagicMapperInterface', $source);
	}//end testTheCallerTypesAgainstItsRole()
}//end class
