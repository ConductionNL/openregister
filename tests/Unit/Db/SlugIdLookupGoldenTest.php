<?php

/**
 * Golden test for the slug and id lookups of RegisterMapper and SchemaMapper.
 *
 * Records the query each of getIdToSlugMap(), getSlugToIdMap() and
 * findIdsBySlugs() builds and what it returns, for empty, single, repeated and
 * mixed-case rows. Re-record with UPDATE_GOLDEN=1.
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

use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Db\SchemaMapper\SchemaMapperServices;
use OCA\OpenRegister\Db\RegisterMapperServices;

/**
 * @covers \OCA\OpenRegister\Db\RegisterMapper
 * @covers \OCA\OpenRegister\Db\SchemaMapper
 * @covers \OCA\OpenRegister\Db\SlugIdLookupTrait
 * @uses   \OCA\OpenRegister\Db\MultiTenancyTrait
 */
class SlugIdLookupGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/slug-id-lookup-golden.json';

	private static function rowSets(): array {
		return [
			'none'     => [],
			'one'      => [['id' => 3, 'slug' => 'alpha']],
			'repeated' => [
				['id' => 3, 'slug' => 'Alpha'],
				['id' => '4', 'slug' => 'alpha'],
				['id' => 5, 'slug' => 'beta'],
				['id' => 6, 'slug' => null],
			],
		];
	}//end rowSets()

	private static function slugSets(): array {
		return [
			'empty'      => [],
			'single'     => ['alpha'],
			'mixed case' => ['ALPHA', 'alpha', 'Beta', 'gamma'],
		];
	}//end slugSets()

	public function testLookupsMatchGolden(): void {
		$actual = [];
		foreach (['register' => fn () => $this->registerMapper(), 'schema' => fn () => $this->schemaMapper()] as $kind => $make) {
			foreach (self::rowSets() as $rowName => $rows) {
				foreach (['getIdToSlugMap', 'getSlugToIdMap'] as $method) {
					$actual["$kind $method $rowName"] = $this->record(fn () => $make()->$method(), $rows);
				}

				foreach (self::slugSets() as $slugName => $slugs) {
					$actual["$kind findIdsBySlugs $slugName $rowName"] = $this->record(fn () => $make()->findIdsBySlugs($slugs), $rows);
				}
			}
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testLookupsMatchGolden()

	private function record(callable $call, array $rows): array {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = [$rows];
		$result = $call();

		return ['result' => $result, 'queries' => $this->queryLog];
	}//end record()

	private function connection(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());

		return $db;
	}//end connection()

	private function registerMapper(): RegisterMapper {
		return new RegisterMapper(
			db: $this->connection(),
			tenancy: new TenancyServices($this->createMock(OrganisationMapper::class), $this->createMock(IUserSession::class), $this->createMock(IGroupManager::class)),
			appConfig: $this->createMock(IAppConfig::class),
			services: new RegisterMapperServices(
				schemaMapper: $this->createMock(SchemaMapper::class),
				container: $this->createMock(ContainerInterface::class),
				eventDispatcher: $this->createMock(IEventDispatcher::class),
				logger: $this->createMock(LoggerInterface::class)
			)
		);
	}//end registerMapper()

	private function schemaMapper(): SchemaMapper {
		return new SchemaMapper(
			db: $this->connection(),
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
	}//end schemaMapper()
}//end class
