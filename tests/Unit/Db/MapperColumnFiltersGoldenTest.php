<?php

/**
 * Golden test for the column filters of the list reads of eight mappers.
 *
 * Records every query-builder call of findAll() (and findLocalTenants()) with
 * an IS NOT NULL, an IS NULL, an integer and a string filter, with no filters
 * and with null filters. A ListQuery read expresses "no filter set" (the null
 * set) as a ListQuery without filters. Re-record with UPDATE_GOLDEN=1.
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

use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\ConsumerMapper;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * @covers \OCA\OpenRegister\Db\ConfigurationMapper
 * @covers \OCA\OpenRegister\Db\ConsumerMapper
 * @covers \OCA\OpenRegister\Db\OrganisationMapper
 * @covers \OCA\OpenRegister\Db\RegisterMapper
 * @covers \OCA\OpenRegister\Db\SchemaMapper
 * @covers \OCA\OpenRegister\Db\SourceMapper
 * @covers \OCA\OpenRegister\Db\WebhookMapper
 * @uses   \OCA\OpenRegister\Db\ListQuery
 * @uses   \OCA\OpenRegister\Db\QueryPage
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 */
class MapperColumnFiltersGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/mapper-column-filters-golden.json';

	private static function sites(): array {
		return [
			'webhook findAll' => [WebhookMapper::class, 'findAll', ['query' => ListQuery::class]],
			'register findAll' => [RegisterMapper::class, 'findAll', ['_rbac' => false, '_multitenancy' => false]],
			'organisation findAll' => [OrganisationMapper::class, 'findAll', []],
			'organisation findLocalTenants' => [OrganisationMapper::class, 'findLocalTenants', []],
			'schema findAll' => [SchemaMapper::class, 'findAll', ['_rbac' => false, '_multitenancy' => false]],
			'source findAll' => [SourceMapper::class, 'findAll', []],
			'configuration findAll' => [ConfigurationMapper::class, 'findAll', ['_multitenancy' => false]],
			'consumer findAll' => [ConsumerMapper::class, 'findAll', []],
		];
	}//end sites()

	private static function filterSets(): array {
		return [
			'mixed' => ['a' => 'IS NOT NULL', 'b' => 'IS NULL', 'c' => 5, 'd' => 'x'],
			'none' => [],
			'null' => null,
		];
	}//end filterSets()

	public function testColumnFiltersMatchGolden(): void {
		$actual = [];
		foreach (self::sites() as $site => [$class, $method, $extra]) {
			foreach (self::filterSets() as $setName => $filters) {
				if ($filters === null && $class === ConsumerMapper::class) {
					continue;
				}

				$actual["{$site} / {$setName}"] = $this->read(class: $class, method: $method, args: self::argsFor(filters: $filters, extra: $extra));
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
	}//end testColumnFiltersMatchGolden()

	/**
	 * Every mixed-filter read reaches the filters, so the golden pins them.
	 */
	public function testEveryMixedReadAppliesTheFilters(): void {
		foreach (self::sites() as $site => [$class, $method, $extra]) {
			$outcome = $this->read(class: $class, method: $method, args: self::argsFor(filters: self::filterSets()['mixed'], extra: $extra));
			$calls = preg_replace('/^#\d+ /', '', $outcome['queries']);
			$this->assertContains('andWhere(isNotNull(a))', $calls, $site);
			$this->assertContains('andWhere(isNull(b))', $calls, $site);
		}
	}//end testEveryMixedReadAppliesTheFilters()

	/**
	 * The call arguments for a filter set; a ListQuery read takes the filters as one unbounded ListQuery.
	 */
	private static function argsFor(?array $filters, array $extra): array {
		if (($extra['query'] ?? null) === ListQuery::class) {
			return ['query' => ListQuery::unbounded(filters: ($filters ?? []))];
		}

		return ['filters' => $filters] + $extra;
	}//end argsFor()

	private function read(string $class, string $method, array $args): array {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = [];

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		$db->method('tableExists')->willReturn(true);

		$mapper = $this->mapper(class: $class, db: $db);
		$outcome = [];
		try {
			$outcome['count'] = count($mapper->$method(...$args));
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end read()

	private function mapper(string $class, IDBConnection $db): object {
		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $param) {
			$type = $param->getType();
			if ($param->getName() === 'db') {
				$args[] = $db;
				continue;
			}

			if ($type instanceof ReflectionNamedType && $type->isBuiltin() === false && (new ReflectionClass($type->getName()))->isFinal() === true) {
				// A final service bundle cannot be doubled: build it from doubles of its own services.
				$args[] = $this->mapper(class: $type->getName(), db: $db);
				continue;
			}

			if ($type instanceof ReflectionNamedType && $type->isBuiltin() === false) {
				$args[] = $this->createMock($type->getName());
				continue;
			}

			$args[] = $param->getDefaultValue();
		}

		return new $class(...$args);
	}//end mapper()
}//end class
