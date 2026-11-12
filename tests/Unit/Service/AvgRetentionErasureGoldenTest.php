<?php

/**
 * AvgRetentionErasureGoldenTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\AvgRetentionService;
use OCA\OpenRegister\Service\Deletion\DestructionRefusedException;
use OCA\OpenRegister\Service\Deletion\RetentionClockService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden: the overdue-object query and the erasure pass of the AVG retention run.
 *
 * Records every query-builder, find, update and log call of a case, plus the
 * returned value, and compares them with fixtures/avg-retention-erasure-golden.json.
 * The erasure cases pin the allowed outcome (soft-deleted and counted) and every
 * refused one: unloadable, missing, failing to load, withheld by the archival
 * guard, withheld because the retention clocks disagree, and an update that
 * fails. Timestamps are recorded as `<time>`. Set UPDATE_GOLDEN=1 to rewrite.
 */
class AvgRetentionErasureGoldenTest extends TestCase {

	/**
	 * Recorded calls of the current case.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	public static function cases(): array {
		return [
			'overdue rows' => [[
				'call' => 'overdue',
				'rows' => [
					['object' => '7', 'object_uuid' => 'u-7', 'register' => '1', 'schema' => '42'],
					['object_uuid' => null, 'register' => null, 'schema' => null],
					['object' => 9],
				],
			]],
			'overdue none' => [['call' => 'overdue', 'rows' => []]],
			'overdue query fails' => [['call' => 'overdue', 'queryFails' => true]],
			'erase every outcome' => [[
				'call' => 'erase',
				'clocks' => true,
				'candidates' => [
					['object' => 0, 'object_uuid' => ''],
					['object' => 11, 'object_uuid' => 'gone'],
					['object' => 12, 'object_uuid' => 'broken'],
					['object' => 13, 'object_uuid' => 'archived'],
					['object' => 14, 'object_uuid' => 'clocks'],
					['object' => 15, 'object_uuid' => 'readonly'],
					['object' => 16, 'object_uuid' => 'ok'],
					['object' => 17],
				],
			]],
			'erase without clock service' => [[
				'call' => 'erase',
				'candidates' => [['object' => 14, 'object_uuid' => 'clocks'], ['object' => 16, 'object_uuid' => 'ok']],
			]],
			'erase nothing' => [['call' => 'erase', 'candidates' => []]],
		];
	}//end cases()

	#[DataProvider('cases')]
	public function testRetentionStepsMatchGolden(array $case): void {
		$this->log = [];
		$service = $this->service($case);
		$activity = new Verwerkingsactiviteit();
		$activity->setUuid('act-1');
		$activity->setRetentionPeriod('P1Y');

		$result = match ($case['call']) {
			'overdue' => $this->call($service, 'findOverdueObjectsForActivity', ['activityUuid' => 'act-1', 'cutoff' => new DateTime('2020-01-01T00:00:00+00:00')]),
			default => $this->call($service, 'erasePastRetention', ['candidates' => $case['candidates'], 'activity' => $activity]),
		};

		$json = (string)json_encode(['result' => $result, 'log' => $this->log], JSON_UNESCAPED_SLASHES);
		$json = (string)preg_replace('/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d/', '<time>', $json);
		$actual = json_decode($json, true);
		$file = __DIR__ . '/fixtures/avg-retention-erasure-golden.json';
		$name = $this->dataName();
		$golden = is_file($file) === true ? json_decode((string)file_get_contents($file), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents($file, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame($golden[$name], $actual);
	}//end testRetentionStepsMatchGolden()

	/**
	 * Call a private method by its named arguments.
	 *
	 * @param AvgRetentionService  $service   The service.
	 * @param string               $method    The method.
	 * @param array<string, mixed> $arguments Its named arguments.
	 *
	 * @return mixed The result.
	 */
	private function call(AvgRetentionService $service, string $method, array $arguments): mixed {
		return (new ReflectionMethod(AvgRetentionService::class, $method))->invoke($service, ...$arguments);
	}

	private function service(array $case): AvgRetentionService {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->queryBuilder($case));

		$objects = $this->createMock(MagicMapper::class);
		$objects->method('find')->willReturnCallback(
			function ($identifier, ...$rest) {
				$this->log[] = ['find', $identifier, $rest];
				if ($identifier === 'gone') {
					throw new DoesNotExistException('none');
				}

				if ($identifier === 'broken') {
					throw new RuntimeException('db down');
				}

				$object = new ObjectEntity();
				$object->setUuid(is_string($identifier) === true ? $identifier : 'by-id-' . $identifier);
				$object->setSchema('42');
				return $object;
			}
		);
		$objects->method('update')->willReturnCallback(
			function (ObjectEntity $entity) {
				$this->log[] = ['update', $entity->getUuid(), $entity->getDeleted(), $entity->getProcessingActivityId()];
				if ($entity->getUuid() === 'readonly') {
					throw new RuntimeException('read only');
				}

				return $entity;
			}
		);

		$guard = $this->createMock(ArchivalRetentionGuard::class);
		$guard->method('erasureRefusal')->willReturnCallback(
			fn (ObjectEntity $object) => $object->getUuid() === 'archived' ? ['uuid' => 'archived', 'ground' => 'ARCHIVAL'] : null
		);

		$clocks = null;
		if (($case['clocks'] ?? false) === true) {
			$clocks = $this->createMock(RetentionClockService::class);
			$clocks->method('refusalFor')->willReturnCallback(
				function (ObjectEntity $object) {
					if ($object->getUuid() !== 'clocks') {
						return null;
					}

					$refusal = $this->createMock(DestructionRefusedException::class);
					$refusal->method('toResponseBody')->willReturn(['message' => 'keep', 'ground' => 'SELECTIELIJST']);
					return $refusal;
				}
			);
		}

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level, $message, $context];
				}
			);
		}

		return new AvgRetentionService($db, $this->createMock(VerwerkingsactiviteitMapper::class), $objects, $logger, $guard, $clocks);
	}//end service()

	private function queryBuilder(array $case): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(fn (...$args) => 'eq(' . implode(',', array_map('strval', array_filter($args, fn ($a) => $a !== null))) . ')');
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('max')->willReturnCallback(
			function (...$args): IQueryFunction {
				$this->log[] = ['func.max', $args];
				return $this->createMock(IQueryFunction::class);
			}
		);

		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type = null) {
				$this->log[] = ['qb.createNamedParameter', $value instanceof DateTime ? $value->format(DateTime::ATOM) : $value, $type];
				return ':p';
			}
		);
		foreach (['select', 'selectAlias', 'from', 'where', 'groupBy', 'having'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (...$args) use ($qb, $method) {
					$this->log[] = ['qb.' . $method, array_map(fn ($a) => $a instanceof IQueryFunction ? 'IQueryFunction' : $a, $args)];
					return $qb;
				}
			);
		}

		$qb->method('executeQuery')->willReturnCallback(
			function () use ($case): IResult {
				$this->log[] = ['executeQuery'];
				if (($case['queryFails'] ?? false) === true) {
					throw new RuntimeException('no table');
				}

				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($case['rows'] ?? []);
				$result->method('closeCursor')->willReturnCallback(
					function (): bool {
						$this->log[] = ['closeCursor'];
						return true;
					}
				);
				return $result;
			}
		);

		return $qb;
	}//end queryBuilder()
}//end class
