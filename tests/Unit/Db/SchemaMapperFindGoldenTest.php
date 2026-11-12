<?php

/**
 * Golden test for SchemaMapper::find().
 *
 * Records the query each lookup builds, the log lines it writes, which rows the
 * request cache answers and what it returns or throws, for numeric, slug and
 * uuid identifiers, both flags and a row that exists before or after the
 * filters. Re-record with UPDATE_GOLDEN=1.
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
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Db\SchemaMapper\SchemaMapperServices;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;

/**
 * @covers \OCA\OpenRegister\Db\SchemaMapper
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaLookup
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\WritePhaseProbe
 * @uses   \OCA\OpenRegister\Db\MultiTenancyTrait
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaComposition
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ParentReference
 * @uses \OCA\OpenRegister\Db\SchemaMapper\PropertyMerge
 * @uses \OCA\OpenRegister\Db\SchemaMapper\PropertyDelta
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ExtendedByReads
 */
class SchemaMapperFindGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/schema-mapper-find-golden.json';

	private const ROW = ['id' => 7, 'uuid' => 'UUID-Seven', 'slug' => 'Seven-Slug', 'title' => 'Seven', 'application' => 'app'];

	private const TWIN = ['id' => 9, 'uuid' => 'UUID-Nine', 'slug' => 'Seven-Slug', 'title' => 'Nine', 'application' => ''];

	private array $logs = [];

	private static function cases(): array {
		$found = [[self::ROW]];
		$cases = [];
		foreach (['7', 7, 'Seven-Slug', 'uuid-seven', '7abc'] as $id) {
			foreach ([[false, false], [true, false], [false, true], [true, true]] as [$rbac, $mt]) {
				$cases[var_export($id, true) . ' rbac ' . var_export($rbac, true) . ' mt ' . var_export($mt, true)] = [$id, $rbac, $mt, $found];
			}
		}

		$cases['missing'] = ['missing', false, false, [[]]];
		$cases['missing numeric'] = ['12', true, true, [[]]];
		$cases['missing rbac only'] = ['13', true, false, [[]]];
		$cases['ambiguous slug'] = ['Seven-Slug', false, false, [[self::ROW, self::TWIN]]];
		$cases['null slug and uuid'] = ['7', false, false, [[['id' => 7, 'uuid' => null, 'slug' => null]]]];

		return $cases;
	}//end cases()

	public function testFindMatchesGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$id, $rbac, $mt, $rows]) {
			$this->queryLog = [];
			$this->logs = [];
			$this->builderCount = 0;
			$this->parameterCount = 0;
			$this->queuedRows = $rows;
			$mapper = $this->makeMapper();
			$outcome = [];
			try {
				$schema = $mapper->find($id, [], $rbac, $mt);
				$outcome['found'] = $schema->getId();
			} catch (\Exception $e) {
				$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
			}

			$cache = (new \ReflectionProperty(SchemaMapper::class, 'findCache'))->getValue($mapper);
			$outcome['cacheKeys'] = array_keys($cache);
			$outcome['queries'] = $this->queryLog;
			$outcome['logs'] = $this->logs;

			// A second lookup by every cached key must be answered without a query.
			$this->queryLog = [];
			foreach (array_keys($cache) as $key) {
				[$cachedId, $r, $m] = explode(':', $key);
				$mapper->find($cachedId, [], $r === '1', $m === '1');
			}

			$outcome['queriesOnCacheHit'] = count($this->queryLog);
			$actual[$name] = $outcome;
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
	}//end testFindMatchesGolden()

	/**
	 * findInIds() builds the same identifier query as find(), narrowed to a set
	 * of ids. The calls are compared as a sorted list: the clause order of a
	 * query builder does not change the SQL it renders.
	 */
	public function testFindInIdsMatchesGolden(): void {
		$cases = [
			'numeric in set' => ['7', [7, 9, 'x', 0], [[self::ROW]]],
			'int in set' => [7, [7], [[self::ROW]]],
			'slug in set' => ['Seven-Slug', ['7', '9'], [[self::ROW]]],
			'no match' => ['other', [7], [[]]],
			'empty set' => ['7', ['x', -1], []],
		];
		$actual = [];
		foreach ($cases as $name => [$id, $ids, $rows]) {
			$this->queryLog = [];
			$this->logs = [];
			$this->builderCount = 0;
			$this->parameterCount = 0;
			$this->queuedRows = $rows;
			$schema = $this->makeMapper()->findInIds($id, $ids);
			$queries = $this->queryLog;
			sort($queries);
			$actual[$name] = ['found' => $schema?->getId(), 'queries' => $queries];
		}

		$fixture = __DIR__ . '/fixtures/schema-mapper-find-in-ids-golden.json';
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents($fixture, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string) file_get_contents($fixture), true), $actual);
	}//end testFindInIdsMatchesGolden()

	private function makeMapper(): SchemaMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line'], $context['error']);
					$this->logs[] = ['log' => $level, 'message' => $message, 'context' => $context];
				}
			);
		}

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
				logger: $logger
			)
		);
	}//end makeMapper()
}//end class
