<?php

/**
 * SchemaMapper reads every schema once per request for an unfiltered list, and
 * reads it again after a schema is written or cleared.
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
final class SchemaMapperFullListMemoTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private function executedQueries(): int {
		return count(array_filter($this->queryLog, static fn (string $line): bool => str_ends_with($line, ' executeQuery()')));
	}//end executedQueries()

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function rows(): array {
		return [['id' => 7, 'uuid' => 'u7', 'slug' => 'seven'], ['id' => 8, 'uuid' => 'u8', 'slug' => 'eight']];
	}//end rows()

	private static function ids(array $schemas): array {
		return array_map(static fn ($schema): ?int => $schema->getId(), $schemas);
	}//end ids()

	public function testEveryRowIsReadOncePerRequest(): void {
		$mapper = $this->makeMapper();
		$this->queuedRows = [self::rows()];

		$first = $mapper->findAll(_multitenancy: false);
		$second = $mapper->findAll(_rbac: false, _multitenancy: false);

		$this->assertSame(1, $this->executedQueries());
		$this->assertSame([7, 8], self::ids($first));
		$this->assertSame([7, 8], self::ids($second));
		$this->assertNotSame($first[0], $second[0]);
	}//end testEveryRowIsReadOncePerRequest()

	public function testAChangeToAHandedOutSchemaDoesNotReachTheNextCaller(): void {
		$mapper = $this->makeMapper();
		$this->queuedRows = [self::rows()];

		$mapper->findAll(_multitenancy: false)[0]->setSlug('changed');

		$this->assertSame('seven', $mapper->findAll(_multitenancy: false)[0]->getSlug());
	}//end testAChangeToAHandedOutSchemaDoesNotReachTheNextCaller()

	public function testANarrowerListIsReadEachTime(): void {
		$mapper = $this->makeMapper();
		$this->queuedRows = [self::rows(), self::rows(), self::rows(), self::rows(), [], self::rows()];

		$mapper->findAll(limit: 5, _multitenancy: false);
		$mapper->findAll(limit: 5, _multitenancy: false);
		$mapper->findAll(offset: 2, _multitenancy: false);
		$mapper->findAll(filters: ['slug' => 'seven'], _multitenancy: false);
		$mapper->findAll(searchConditions: ['slug = :s'], searchParams: ['s' => 'x'], _multitenancy: false);
		$every = $mapper->findAll(_multitenancy: false);

		$this->assertSame(6, $this->executedQueries());
		$this->assertSame([7, 8], self::ids($every));
	}//end testANarrowerListIsReadEachTime()

	public function testTheOrganisationScopesAreReadApart(): void {
		$mapper = $this->makeMapper();
		$this->queuedRows = [self::rows(), [['id' => 9, 'slug' => 'nine']]];

		$unscoped = $mapper->findAll(_multitenancy: false);
		$scoped = $mapper->findAll(_multitenancy: true);
		$mapper->findAll(_multitenancy: true);

		$this->assertSame(2, $this->executedQueries());
		$this->assertSame([7, 8], self::ids($unscoped));
		$this->assertSame([9], self::ids($scoped));
	}//end testTheOrganisationScopesAreReadApart()

	public function testClearingASchemaReadsTheListAgain(): void {
		$mapper = $this->makeMapper();
		$this->queuedRows = [self::rows(), [['id' => 7, 'slug' => 'renamed']]];

		$mapper->findAll(_multitenancy: false);
		$mapper->clearFindCache(7);
		$after = $mapper->findAll(_multitenancy: false);

		$this->assertSame(2, $this->executedQueries());
		$this->assertSame('renamed', $after[0]->getSlug());
	}//end testClearingASchemaReadsTheListAgain()

	public function testAnEmptyTableIsRememberedToo(): void {
		$mapper = $this->makeMapper();
		$this->queuedRows = [[]];

		$this->assertSame([], $mapper->findAll(_multitenancy: false));
		$this->assertSame([], $mapper->findAll(_multitenancy: false));
		$this->assertSame(1, $this->executedQueries());
	}//end testAnEmptyTableIsRememberedToo()

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
