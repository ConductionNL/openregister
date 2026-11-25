<?php

/**
 * SchemaMapper reads a schema once per request, whatever RBAC flag asks for it,
 * and loads a composition parent once however many children extend it.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaMapper\SchemaMapperServices;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\SchemaMapper
 * @uses   \OCA\OpenRegister\Db\RequestIdentityMap
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaLookup
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\WritePhaseProbe
 * @uses   \OCA\OpenRegister\Db\MultiTenancyTrait
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaComposition
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\ParentReference
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\PropertyMerge
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\PropertyDelta
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\ExtendedByReads
 * @uses   \OCA\OpenRegister\Db\MappingMapper
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaMapperServices
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaReadTrace
 * @uses   \OCA\OpenRegister\Db\SchemaPropertyInheritance
 * @uses   \OCA\OpenRegister\Db\Tenancy\OrganisationFilterOptions
 * @uses   \OCA\OpenRegister\Db\Tenancy\TenancyServices
 * @uses   \OCA\OpenRegister\Service\WritePhase
 * @uses   \OCA\OpenRegister\Db\Tenancy\OrganisationFilterTrait
 * @uses   \OCA\OpenRegister\Db\Tenancy\TenancyIdentityTrait
 */
class SchemaMapperIdentityMapTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const PARENT = ['id' => 2, 'uuid' => 'uuid-2', 'slug' => 'person', 'properties' => '{"name":{"type":"string"}}'];

	private function child(int $id): array {
		return ['id' => $id, 'uuid' => 'uuid-' . $id, 'slug' => 'child-' . $id, 'all_of' => '["person"]', 'properties' => '{"own":{"type":"string"}}'];
	}//end child()

	private function executedQueries(): int {
		return count(array_filter($this->queryLog, static fn (string $line): bool => str_ends_with($line, ' executeQuery()')));
	}//end executedQueries()

	public function testDifferingRbacFlagsShareOneRead(): void {
		$mapper = $this->makeMapper();
		$this->queuedRows = [[['id' => 7, 'uuid' => 'uuid-7', 'slug' => 'seven']]];

		$first = $mapper->find('seven', [], true, true);
		$second = $mapper->find('seven', [], false, true);
		$byUuid = $mapper->find('UUID-7', [], false, true);

		$this->assertSame(1, $this->executedQueries());
		$this->assertSame($first, $second);
		$this->assertSame($first, $byUuid);
	}//end testDifferingRbacFlagsShareOneRead()

	public function testTheOrganisationScopeStillSeparatesReads(): void {
		$mapper = $this->makeMapper();
		$this->queuedRows = [[['id' => 7, 'slug' => 'seven']], [['id' => 8, 'slug' => 'seven']]];

		$scoped = $mapper->find('seven', [], true, true);
		$unscoped = $mapper->find('seven', [], true, false);

		$this->assertSame(2, $this->executedQueries());
		$this->assertSame(7, $scoped->getId());
		$this->assertSame(8, $unscoped->getId());
	}//end testTheOrganisationScopeStillSeparatesReads()

	public function testACompositionParentIsLoadedOnceForEveryChild(): void {
		$mapper = $this->makeMapper();
		$this->queuedRows = [[$this->child(1)], [self::PARENT], [$this->child(3)]];

		$first = $mapper->find(1);
		$second = $mapper->find(3);

		$this->assertSame(3, $this->executedQueries());
		$this->assertSame(['name', 'own'], array_keys($first->getProperties()));
		$this->assertSame(['name', 'own'], array_keys($second->getProperties()));
	}//end testACompositionParentIsLoadedOnceForEveryChild()

	public function testClearingASchemaLoadsItsParentAgain(): void {
		$mapper = $this->makeMapper();
		$this->queuedRows = [[$this->child(1)], [self::PARENT], [$this->child(3)], [self::PARENT]];

		$mapper->find(1);
		$mapper->clearFindCache(2);
		$mapper->find(3);

		$this->assertSame(4, $this->executedQueries());
	}//end testClearingASchemaLoadsItsParentAgain()

	public function testClearingASchemaDropsItFromTheMap(): void {
		$mapper = $this->makeMapper();
		$this->queuedRows = [[['id' => 7, 'slug' => 'seven']], [['id' => 7, 'slug' => 'seven']]];

		$before = $mapper->find('seven');
		$mapper->clearFindCache(7);
		$after = $mapper->find(7);

		$this->assertSame(2, $this->executedQueries());
		$this->assertNotSame($before, $after);
	}//end testClearingASchemaDropsItFromTheMap()

	private function makeMapper(): SchemaMapper {
		$this->queryLog = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());

		return new SchemaMapper(
			db: $db,
			tenancy: new TenancyServices(
				organisationMapper: $this->createMock(OrganisationMapper::class),
				userSession: $this->createMock(IUserSession::class),
				groupManager: $this->createMock(IGroupManager::class)
			),
			appConfig: $this->createMock(IAppConfig::class),
			services: new SchemaMapperServices(
				eventDispatcher: $this->createMock(IEventDispatcher::class),
				validator: $this->createMock(PropertyValidatorHandler::class),
				logger: $this->createMock(LoggerInterface::class)
			)
		);
	}//end makeMapper()
}//end class
