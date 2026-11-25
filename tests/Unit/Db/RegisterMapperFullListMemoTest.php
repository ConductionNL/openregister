<?php

/**
 * RegisterMapper reads every register once per request for an unfiltered list,
 * and reads it again after a register is cleared from the request cache.
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
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterMapperServices;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\RegisterMapper
 * @uses   \OCA\OpenRegister\Db\RequestIdentityMap
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\MultiTenancyTrait
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses   \OCA\OpenRegister\Db\MappingMapper
 * @uses   \OCA\OpenRegister\Db\RegisterLookup
 * @uses   \OCA\OpenRegister\Db\RegisterMapperServices
 * @uses   \OCA\OpenRegister\Db\Tenancy\OrganisationFilterOptions
 * @uses   \OCA\OpenRegister\Db\Tenancy\TenancyServices
 * @uses   \OCA\OpenRegister\Db\Tenancy\OrganisationFilterTrait
 * @uses   \OCA\OpenRegister\Db\Tenancy\TenancyIdentityTrait
 */
final class RegisterMapperFullListMemoTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private function executedQueries(): int {
		return count(array_filter($this->queryLog, static fn (string $line): bool => str_ends_with($line, ' executeQuery()')));
	}//end executedQueries()

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function rows(): array {
		return [['id' => 3, 'uuid' => 'u3', 'slug' => 'three'], ['id' => 4, 'uuid' => 'u4', 'slug' => 'four']];
	}//end rows()

	private static function ids(array $registers): array {
		return array_map(static fn ($register): ?int => $register->getId(), $registers);
	}//end ids()

	public function testEveryRowIsReadOncePerRequest(): void {
		$mapper = $this->makeMapper();
		$this->queuedRows = [self::rows()];

		$first = $mapper->findAll(_multitenancy: false);
		$second = $mapper->findAll(_rbac: false, _multitenancy: false);

		$this->assertSame(1, $this->executedQueries());
		$this->assertSame([3, 4], self::ids($first));
		$this->assertSame([3, 4], self::ids($second));
		$this->assertNotSame($first[0], $second[0]);
	}//end testEveryRowIsReadOncePerRequest()

	public function testAChangeToAHandedOutRegisterDoesNotReachTheNextCaller(): void {
		$mapper = $this->makeMapper();
		$this->queuedRows = [self::rows()];

		$mapper->findAll(_multitenancy: false)[0]->setSlug('changed');

		$this->assertSame('three', $mapper->findAll(_multitenancy: false)[0]->getSlug());
	}//end testAChangeToAHandedOutRegisterDoesNotReachTheNextCaller()

	public function testANarrowerListIsReadEachTime(): void {
		$mapper = $this->makeMapper();
		$this->queuedRows = [self::rows(), self::rows(), self::rows(), self::rows(), [], self::rows()];

		$mapper->findAll(limit: 5, _multitenancy: false);
		$mapper->findAll(limit: 5, _multitenancy: false);
		$mapper->findAll(offset: 2, _multitenancy: false);
		$mapper->findAll(filters: ['slug' => 'three'], _multitenancy: false);
		$mapper->findAll(searchConditions: ['slug = :s'], searchParams: ['s' => 'x'], _multitenancy: false);
		$every = $mapper->findAll(_multitenancy: false);

		$this->assertSame(6, $this->executedQueries());
		$this->assertSame([3, 4], self::ids($every));
	}//end testANarrowerListIsReadEachTime()

	public function testTheOrganisationScopesAreReadApart(): void {
		$mapper = $this->makeMapper();
		$this->queuedRows = [self::rows(), [['id' => 5, 'slug' => 'five']]];

		$unscoped = $mapper->findAll(_multitenancy: false);
		$scoped = $mapper->findAll(_multitenancy: true);
		$mapper->findAll(_multitenancy: true);

		$this->assertSame(2, $this->executedQueries());
		$this->assertSame([3, 4], self::ids($unscoped));
		$this->assertSame([5], self::ids($scoped));
	}//end testTheOrganisationScopesAreReadApart()

	public function testClearingARegisterReadsTheListAgain(): void {
		$mapper = $this->makeMapper();
		$this->queuedRows = [self::rows(), [['id' => 3, 'slug' => 'renamed']]];

		$mapper->findAll(_multitenancy: false);
		$mapper->clearFindCache(3);
		$after = $mapper->findAll(_multitenancy: false);

		$this->assertSame(2, $this->executedQueries());
		$this->assertSame('renamed', $after[0]->getSlug());
	}//end testClearingARegisterReadsTheListAgain()

	private function makeMapper(): RegisterMapper {
		$this->queryLog = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());

		return new RegisterMapper(
			db: $db,
			tenancy: new TenancyServices(
				organisationMapper: $this->createMock(OrganisationMapper::class),
				userSession: $this->createMock(IUserSession::class),
				groupManager: $this->createMock(IGroupManager::class)
			),
			appConfig: $this->createMock(IAppConfig::class),
			services: new RegisterMapperServices(
				schemaMapper: $this->createMock(SchemaMapper::class),
				container: $this->createMock(ContainerInterface::class),
				eventDispatcher: $this->createMock(IEventDispatcher::class),
				logger: $this->createMock(LoggerInterface::class)
			)
		);
	}//end makeMapper()
}//end class
