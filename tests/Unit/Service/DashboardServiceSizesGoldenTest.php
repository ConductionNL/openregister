<?php

/**
 * Golden test for DashboardService's size recalculation and register listing.
 *
 * Records, per case, every mapper call with its arguments, every log line and
 * the result or exception: pair walks narrowed either way, unresolvable pairs,
 * failing saves, log recalculation, the combined totals, the size response and
 * a register that cannot be listed, by Exception and by Error. Re-record with
 * UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\MagicMapper\StatisticsScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Search\Service\DashboardService;
use OCA\OpenRegister\Search\Service\DashboardSizes;
use OCA\OpenRegister\Search\Service\RegisterSchemaLookup;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Search\Service\DashboardService
 * @covers \OCA\OpenRegister\Search\Service\DashboardSizes
 * @covers \OCA\OpenRegister\Search\Service\RegisterSchemaLookup
 * @uses   \OCA\OpenRegister\Search\Service\DashboardErrorLog
 * @uses   \OCA\OpenRegister\Search\Service\DashboardScope
 * @uses   \OCA\OpenRegister\Search\Service\RegisterSchemaPair
 * @uses   \OCA\OpenRegister\Search\Service\SizeTally
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class DashboardServiceSizesGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/Golden/dashboard-service-sizes.json';

	private const PAIRS = [
		['registerId' => '1', 'schemaId' => '10'],
		['registerId' => '1', 'schemaId' => '11'],
		['registerId' => '2', 'schemaId' => '10'],
		['registerId' => '404', 'schemaId' => '10'],
	];

	private array $log = [];

	private bool $pairsFail = false;

	/**
	 * What listing register 3's schemas throws.
	 *
	 * @var class-string<\Throwable>
	 */
	private string $schemaConflict = Exception::class;

	public function testSizesAndListingMatchGolden(): void {
		$actual = [
			'sizes all'             => $this->runCase(fn (DashboardService $s) => $s->sizes()->recalculateSizes()),
			'sizes register'        => $this->runCase(fn (DashboardService $s) => $s->sizes()->recalculateSizes(2)),
			'sizes schema'          => $this->runCase(fn (DashboardService $s) => $s->sizes()->recalculateSizes(null, 11)),
			'sizes both'            => $this->runCase(fn (DashboardService $s) => $s->sizes()->recalculateSizes(1, 10)),
			'sizes pairs fail'      => $this->runCase(fn (DashboardService $s) => $s->sizes()->recalculateSizes(), true),
			'log sizes all'         => $this->runCase(fn (DashboardService $s) => $s->sizes()->recalculateLogSizes()),
			'log sizes both'        => $this->runCase(fn (DashboardService $s) => $s->sizes()->recalculateLogSizes(1, 10)),
			'log sizes register'    => $this->runCase(fn (DashboardService $s) => $s->sizes()->recalculateLogSizes(1)),
			'log sizes schema'      => $this->runCase(fn (DashboardService $s) => $s->sizes()->recalculateLogSizes(null, 10)),
			'all sizes'             => $this->runCase(fn (DashboardService $s) => $s->sizes()->recalculateAllSizes(1)),
			'all sizes fail'        => $this->runCase(fn (DashboardService $s) => $s->sizes()->recalculateAllSizes(), true),
			'calculate all'         => $this->runCase(fn (DashboardService $s) => $s->sizes()->calculate()),
			'calculate both'        => $this->runCase(fn (DashboardService $s) => $s->sizes()->calculate(1, 10)),
			'calculate foreign'     => $this->runCase(fn (DashboardService $s) => $s->sizes()->calculate(1, 99)),
			'calculate no register' => $this->runCase(fn (DashboardService $s) => $s->sizes()->calculate(404)),
			'calculate no schema'   => $this->runCase(fn (DashboardService $s) => $s->sizes()->calculate(null, 404)),
			'listing all'           => $this->runCase(fn (DashboardService $s) => $s->getRegistersWithSchemas()),
			'listing narrowed'      => $this->runCase(fn (DashboardService $s) => $s->getRegistersWithSchemas(1, 11)),
			'listing with an Error' => $this->runCase(
				function (DashboardService $s): array {
					$this->schemaConflict = \TypeError::class;
					return $s->getRegistersWithSchemas();
				}
			),
		];

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testSizesAndListingMatchGolden()

	private function runCase(callable $act, bool $pairsFail = false): array {
		$this->log = [];
		$this->pairsFail = $pairsFail;
		$this->schemaConflict = Exception::class;
		$outcome = [];
		try {
			$outcome['result'] = $act($this->service());
			unset($outcome['result']['timestamp']);
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['calls'] = $this->log;
		return $outcome;
	}//end runCase()

	private function service(): DashboardService {
		$webhooks = $this->createMock(WebhookLogMapper::class);
		$webhooks->method('getStatistics')->willReturn(['total' => 31]);

		$objects = $this->objects();
		$audit = $this->audit();
		$registers = $this->registers();
		$logger = $this->logger();
		$sizes = new DashboardSizes($objects, $audit, new RegisterSchemaLookup($registers, $this->schemas()), $logger);

		return new DashboardService(new \OCA\OpenRegister\Search\Service\DashboardMappers(new \OCA\OpenRegister\Db\SchemaCatalog(registers: $registers, schemas: $this->createMock(\OCA\OpenRegister\Db\SchemaMapperInterface::class), tables: $objects), $audit, $webhooks), $sizes, $logger);
	}//end service()

	private function note(string $call, array $args): void {
		$render = static fn (mixed $arg): mixed => is_object($arg) === true ? get_class($arg) . '#' . $arg->getId() : $arg;
		$this->log[] = $call . ' ' . json_encode(array_map($render, $args));
	}//end note()

	private function objects(): MagicMapperInterface {
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('getAllRegisterSchemaPairs')->willReturnCallback(
			function (): array {
				if ($this->pairsFail === true) {
					throw new Exception('no pairs');
				}

				return self::PAIRS;
			}
		);
		$mapper->method('findAllInRegisterSchemaTable')->willReturnCallback(
			function (mixed ...$args): array {
				$this->note('objects.findAllInRegisterSchemaTable', $args);
				return [$this->entity(ObjectEntity::class, 100), $this->entity(ObjectEntity::class, 666)];
			}
		);
		$mapper->method('update')->willReturnCallback(
			function (mixed ...$args): ObjectEntity {
				$this->note('objects.update', $args);
				if ($args[0]->getId() === 666) {
					throw new Exception('no save');
				}

				return $args[0];
			}
		);
		$mapper->method('getStatistics')->willReturnCallback(
			function (StatisticsScope $scope): array {
				$args = [$scope->registerId, $scope->schemaId, $scope->exclude];
				$this->note('objects.getStatistics', $args);
				return ['total' => 11, 'size' => 12, 'invalid' => 13, 'deleted' => 14, 'locked' => 15, 'published' => 16];
			}
		);
		return $mapper;
	}//end objects()

	private function audit(): AuditTrailMapperInterface {
		$mapper = $this->createMock(AuditTrailMapperInterface::class);
		$mapper->method('findAll')->willReturnCallback(
			function (mixed ...$args): array {
				$this->note('audit.findAll', $args);
				if ($this->pairsFail === true) {
					throw new Exception('no logs');
				}

				return [$this->entity(AuditTrail::class, 200), $this->entity(AuditTrail::class, 666)];
			}
		);
		$mapper->method('update')->willReturnCallback(
			function (AuditTrail $log): AuditTrail {
				$this->note('audit.update', [$log]);
				if ($log->getId() === 666) {
					throw new Exception('no log save');
				}

				return $log;
			}
		);
		$mapper->method('statisticsTotals')->willReturn(['total' => 21, 'size' => 22]);
		return $mapper;
	}//end audit()

	private function registers(): RegisterMapperInterface {
		$mapper = $this->createMock(RegisterMapperInterface::class);
		$mapper->method('find')->willReturnCallback(
			function (mixed ...$args): Register {
				$this->note('registers.find', $args);
				if ($args[0] === 404) {
					throw new Exception('no register');
				}

				return $this->register(id: (int)$args[0]);
			}
		);
		$mapper->method('findAll')->willReturnCallback(
			function (mixed ...$args): array {
				$this->note('registers.findAll', $args);
				return [$this->register(id: 1), $this->register(id: 3)];
			}
		);
		$mapper->method('getSchemasByRegisterId')->willReturnCallback(
			function (mixed ...$args): array {
				$this->note('registers.getSchemasByRegisterId', $args);
				if ($args[0] === 3) {
					throw new ($this->schemaConflict)('schema conflict');
				}

				return [$this->schema(id: 10), $this->schema(id: 11)];
			}
		);
		return $mapper;
	}//end registers()

	private function schemas(): SchemaMapperInterface {
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->method('find')->willReturnCallback(
			function (mixed ...$args): Schema {
				$this->note('schemas.find', $args);
				if ($args[0] === 404) {
					throw new Exception('no schema');
				}

				return $this->schema(id: (int)$args[0]);
			}
		);
		return $mapper;
	}//end schemas()

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$record = function (string $message, array $context = []): void {
			unset($context['line'], $context['file']);
			$this->log[] = 'log ' . $message . ' ' . json_encode($context);
		};
		$logger->method('error')->willReturnCallback($record);
		$logger->method('warning')->willReturnCallback($record);
		return $logger;
	}//end logger()

	private function entity(string $class, int $id): object {
		$entity = new $class();
		$entity->setId($id);
		return $entity;
	}//end entity()

	private function register(int $id): Register {
		$register = new Register();
		$register->setId($id);
		$register->setTitle('Register ' . $id);
		$register->setSchemas([10, 11]);
		return $register;
	}//end register()

	private function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setTitle('Schema ' . $id);
		return $schema;
	}//end schema()
}//end class
