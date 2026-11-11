<?php

/**
 * Golden of the slug, id-set and register reads of SchemaMapper.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaMapper\SchemaReadTrace;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use OCA\OpenRegister\Db\SchemaMapper\SchemaMapperServices;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;

/**
 * @covers \OCA\OpenRegister\Db\SchemaMapper
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaLookup
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaComposition
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\ParentReference
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\PropertyMerge
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\PropertyDelta
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaReadTrace
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaRegisterReads
 */
class SchemaMapperReadsGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/schema-mapper-reads-golden.json';

	private const ROW = ['id' => 7, 'uuid' => 'uuid-7', 'slug' => 'Seven', 'title' => 'Seven', 'application' => 'app'];

	private const TWIN = ['id' => 9, 'uuid' => 'uuid-9', 'slug' => 'seven', 'title' => 'Nine', 'application' => 'app'];

	private array $logs = [];

	/**
	 * The reads under test: name => [rows served, the call].
	 *
	 * @return array<string, array{0: array, 1: callable}>
	 */
	private static function cases(): array {
		$bySlug = static fn (SchemaMapper $mapper) => $mapper->findByApplicationAndSlug('SEVEN', 'app');
		$allBySlug = static fn (SchemaMapper $mapper) => $mapper->findAllByApplicationAndSlug('SEVEN', 'app');
		$inIds = static fn (SchemaMapper $mapper) => $mapper->findBySlugInIds('SEVEN', [7, '9', 'x', 0, -1, 1.5]);
		$optimized = static fn (SchemaMapper $mapper) => $mapper->findMultipleOptimized([7, 9]);
		$manyIds = static fn (SchemaMapper $mapper) => $mapper->findMultipleOptimized(range(1, 1001));

		return [
			'application slug hit' => [[[self::ROW]], $bySlug],
			'application slug miss' => [[[]], $bySlug],
			'all application slug two' => [[[self::ROW, self::TWIN]], $allBySlug],
			'all application slug none' => [[[]], $allBySlug],
			'slug in ids hit' => [[[self::ROW]], $inIds],
			'slug in ids miss' => [[[]], $inIds],
			'slug in ids empty set' => [[], static fn (SchemaMapper $mapper) => $mapper->findBySlugInIds('SEVEN', ['x', 0])],
			'count by slug' => [[[['slug_count' => '3']]], static fn (SchemaMapper $mapper) => $mapper->countBySlug('SEVEN')],
			'count by slug no row' => [[[]], static fn (SchemaMapper $mapper) => $mapper->countBySlug('SEVEN')],
			'optimized empty' => [[], static fn (SchemaMapper $mapper) => $mapper->findMultipleOptimized([])],
			'optimized one chunk' => [[[self::ROW, self::TWIN]], $optimized],
			'optimized two chunks' => [[[self::ROW], [self::TWIN]], $manyIds],
			'slug page' => [[[self::ROW, self::TWIN]], static fn (SchemaMapper $mapper) => $mapper->findBySlug('seven', new ListQuery(limit: 2, offset: 4))],
			'slug without tenancy' => [[[self::ROW]], static fn (SchemaMapper $mapper) => $mapper->findBySlug('seven', new ListQuery(limit: 10), _multitenancy: false)],
			'slug none' => [[[]], static fn (SchemaMapper $mapper) => $mapper->findBySlug('seven', new ListQuery(limit: 10))],
			'all defaults' => [[[self::ROW, self::TWIN]], static fn (SchemaMapper $mapper) => $mapper->findAll()],
			'all without tenancy' => [[[self::ROW]], static fn (SchemaMapper $mapper) => $mapper->findAll(_multitenancy: false)],
			'all filtered and searched' => [
				[[self::ROW]],
				static fn (SchemaMapper $mapper) => $mapper->findAll(3, 6, ['a' => 'IS NULL', 'b' => 'IS NOT NULL', 'c' => 1], ['x LIKE :q', 'y LIKE :q'], ['q' => '%s%', 'r' => 2]),
			],
			'all conditions without params' => [[[]], static fn (SchemaMapper $mapper) => $mapper->findAll(null, null, null, ['x = 1'], null)],
			'all params without conditions' => [[[]], static fn (SchemaMapper $mapper) => $mapper->findAll(searchParams: ['q' => 1])],
			'related' => [
				[[self::ROW + ['properties' => '{"a":{"$ref":"#/components/schemas/three"}}'], ['id' => 3, 'slug' => 'three', 'uuid' => 'uuid-3', 'properties' => '{"a":{"$ref":"#/components/schemas/three"}}'], self::TWIN + ['properties' => '{"b":{"type":"string"}}']]],
				static fn (SchemaMapper $mapper) => $mapper->getRelated(self::target()),
			],
			'register counts' => [
				[[['id' => 1, 'schemas' => '[7, "9"]'], ['id' => 2, 'schemas' => '[7]'], ['id' => 3, 'schemas' => 'false']]],
				static fn (SchemaMapper $mapper) => $mapper->getRegisterCountPerSchema(),
			],
		];
	}//end cases()

	/**
	 * The schema the related read looks for.
	 */
	private static function target(): Schema {
		return Schema::fromRow(['id' => 3, 'uuid' => 'uuid-3', 'slug' => 'three']);
	}//end target()

	public function testReadsMatchGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$rows, $read]) {
			$this->queryLog = [];
			$this->builderCount = 0;
			$this->parameterCount = 0;
			$this->queuedRows = $rows;
			$outcome = $read($this->makeMapper());
			$actual[$name] = ['outcome' => $this->render($outcome), 'queries' => $this->compact($this->queryLog)];
		}

		$actual['attached objects'] = $this->attachedObjects();
		$actual['read trace'] = $this->readTrace();

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertNotEmpty($actual['slug in ids hit']['queries']);
		$this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), $actual);
	}//end testReadsMatchGolden()

	/**
	 * Which schema ids an outcome carries, or the scalar or map itself.
	 */
	private function render(mixed $outcome): mixed {
		if ($outcome instanceof Schema) {
			return 'schema ' . $outcome->getId();
		}

		if (is_array($outcome) === true) {
			return array_map(fn (mixed $item): mixed => $this->render($item), $outcome);
		}

		return $outcome;
	}//end render()

	/**
	 * The query log with long id lists cut down, so the chunk size still shows.
	 *
	 * @param array<int, string> $log The log.
	 *
	 * @return array<int, string>
	 */
	private function compact(array $log): array {
		return array_map(
			static fn (string $line): string => strlen($line) > 200 ? substr($line, 0, 80) . ' ... ' . strlen($line) : $line,
			$log
		);
	}//end compact()

	/**
	 * The private count of the delete guard, over one table that exists and one that does not.
	 *
	 * @return array<string, mixed>
	 */
	private function attachedObjects(): array {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = [
			[['id' => 1, 'schemas' => '["7", 8]'], ['id' => 2, 'schemas' => '[8]'], ['id' => 3, 'schemas' => null], ['id' => 4, 'schemas' => '[7]'], ['id' => 5, 'schemas' => '{bad']],
			[['COUNT(*)' => 5]],
		];
		$mapper = $this->makeMapper(tables: ['openregister_table_1_7']);
		$reflection = new ReflectionClass($mapper);
		$count = $reflection->getMethod('countAttachedObjects')->invoke($mapper, 7);

		return ['outcome' => $count, 'queries' => $this->queryLog];
	}//end attachedObjects()

	/**
	 * The attribution counters a traced read leaves behind.
	 *
	 * @return array<int, string>
	 */
	private function readTrace(): array {
		$holder = new ReflectionClass(SchemaReadTrace::class);
		$holder->setStaticPropertyValue('perfTrace', true);
		$holder->setStaticPropertyValue('perfCounts', []);
		$this->queuedRows = [[]];
		$this->makeMapper()->countBySlug('x');
		$this->queuedRows = [[]];
		$this->makeMapper()->countBySlug('x');
		$counts = $holder->getStaticPropertyValue('perfCounts');
		$holder->setStaticPropertyValue('perfTrace', null);
		$holder->setStaticPropertyValue('perfCounts', []);

		$lines = [];
		foreach ($counts as $key => $count) {
			[$method, $callers] = explode('|', (string) $key);
			$first = explode('<', $callers);
			$lines[] = $method . ' x' . $count . ' frames ' . count($first) . ' first ' . $first[0];
		}

		return $lines;
	}//end readTrace()

	private function makeMapper(array $tables = []): SchemaMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		$db->method('tableExists')->willReturnCallback(
			static fn (string $table): bool => in_array($table, $tables, true)
		);

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
