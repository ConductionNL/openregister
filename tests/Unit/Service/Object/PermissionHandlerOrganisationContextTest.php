<?php

/**
 * Unit tests for the bulk-delete organisation check inside an organisation context.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Object
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\OrganisationContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Inside an organisation context a bulk delete keeps only the context's rows,
 * also when the caller switched multitenancy off.
 *
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Service\Object\BulkCaller
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionCallerServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionDataServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionDenyServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionDerivedGrantServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionGrantServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionRuleServices
 * @uses   \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses \OCA\OpenRegister\Service\Object\BulkCaller
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionCallerServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionDataServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionDenyServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionDerivedGrantServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionGrantServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionRuleServices
 */
class PermissionHandlerOrganisationContextTest extends TestCase {

	/**
	 * The handler over three objects: one in org-a, one in org-b, one without organisation.
	 *
	 * @param array<int, string> $activeOrgs What the organisation service answers.
	 *
	 * @return PermissionHandler
	 */
	private function handler(array $activeOrgs): PermissionHandler {
		$objects = [];
		foreach (['own' => 'org-a', 'other' => 'org-b', 'none' => null] as $uuid => $organisation) {
			$object = new ObjectEntity();
			$object->setUuid($uuid);
			$object->setOrganisation($organisation);
			$objects[] = $object;
		}

		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('findMultipleAcrossAllMagicTables')->willReturn($objects);

		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getUserActiveOrganisations')->willReturn($activeOrgs);
		$organisations->method('getActiveOrganisation')->willReturn(null);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($organisations);

		return \OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory::create(
			userSession: $this->createMock(IUserSession::class),
			userManager: $this->createMock(IUserManager::class),
			groupManager: $this->createMock(IGroupManager::class),
			schemaMapper: $this->createMock(SchemaMapper::class),
			objectEntityMapper: $mapper,
			conditionMatcher: $this->createMock(ConditionMatcher::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
			container: $container,
		);
	}//end handler()

	public function testOutsideAContextMultitenancyOffKeepsEveryRow(): void {
		$kept = $this->handler(['org-a'])->filterUuidsForPermissions(uuids: ['own', 'other', 'none'], _rbac: false, _multitenancy: false);

		$this->assertSame(['own', 'other', 'none'], $kept);
	}//end testOutsideAContextMultitenancyOffKeepsEveryRow()

	public function testInsideAContextAnotherOrganisationsRowIsDropped(): void {
		$handler = $this->handler(['org-a']);
		$kept = OrganisationContext::run(
			'org-a',
			static fn (): array => $handler->filterUuidsForPermissions(uuids: ['own', 'other', 'none'], _rbac: false, _multitenancy: false)
		);

		$this->assertSame(['own', 'none'], $kept);
	}//end testInsideAContextAnotherOrganisationsRowIsDropped()

	public function testInsideAContextForAnUnknownOrganisationNoOrganisationsRowIsKept(): void {
		$handler = $this->handler([]);
		$kept = OrganisationContext::run(
			'org-x',
			static fn (): array => $handler->filterUuidsForPermissions(uuids: ['own', 'other', 'none'], _rbac: false, _multitenancy: true)
		);

		$this->assertSame(['none'], $kept);
	}//end testInsideAContextForAnUnknownOrganisationNoOrganisationsRowIsKept()
}//end class
