<?php

/**
 * Golden test pinning the statements ApiCallRecordMapper runs to count one call.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\ApiCallKey;
use OCA\OpenRegister\Db\ApiCallRecordMapper;
use OCA\OpenRegister\Service\ApiCaller\ApiCallRecorder;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Service\ApiCaller\ApiCallObservation;

/**
 * Pins the increment, the insert and the retried increment, with their parameters.
 *
 * Driven through ApiCallRecorder::recordCall, the mapper's one caller.
 *
 * @covers \OCA\OpenRegister\Db\ApiCallRecordMapper
 * @uses \OCA\OpenRegister\Db\ApiCallKey
 * @uses \OCA\OpenRegister\Db\ApiCallKeys
 * @uses \OCA\OpenRegister\Db\ApiCallRecord
 * @uses \OCA\OpenRegister\Service\ApiCaller\ApiCallObservation
 * @uses \OCA\OpenRegister\Service\ApiCaller\ApiCallRecorder
 */
final class ApiCallRecordMapperCountGoldenTest extends TestCase {

	/**
	 * Every query-builder call, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * What each executeStatement answers, in order; an exception is thrown.
	 *
	 * @var array<int, int|RuntimeException>
	 */
	private array $outcomes = [];

	/**
	 * A known caller is counted by one increment.
	 *
	 * @return void
	 */
	public function testAKnownCallerIsIncremented(): void {
		$this->outcomes = [1];

		$this->record();

		$this->assertSame($this->incrementCalls(), $this->calls);
	}//end testAKnownCallerIsIncremented()

	/**
	 * A new caller is inserted after an increment that found nothing.
	 *
	 * @return void
	 */
	public function testANewCallerIsInserted(): void {
		$this->outcomes = [0, 1];

		$this->record();

		$this->assertSame(array_merge($this->incrementCalls(), $this->insertCalls()), $this->calls);
	}//end testANewCallerIsInserted()

	/**
	 * A lost insert race is retried as an increment.
	 *
	 * @return void
	 */
	public function testALostInsertRaceIsRetriedAsAnIncrement(): void {
		$this->outcomes = [0, new RuntimeException('duplicate'), 1];

		$this->record();

		$this->assertSame(
			array_merge($this->incrementCalls(), $this->insertCalls(), $this->incrementCalls()),
			$this->calls
		);
	}//end testALostInsertRaceIsRetriedAsAnIncrement()

	/**
	 * Several buffered calls are added by one increment, or counted by the insert.
	 *
	 * @return void
	 */
	public function testSeveralCallsAreCountedAtOnce(): void {
		$this->outcomes = [1, 0, 1];
		$mapper = $this->mapper();
		$key    = new ApiCallKey(principal: 'leverancier', route: '/apps/openregister/api/objects/zaken/melding/{id}', method: 'GET', apiVersion: '1');
		$moment = new DateTime('2026-03-04 05:06:07');

		$this->assertTrue(condition: $mapper->count(key: $key, moment: $moment, calls: 5));
		$this->assertTrue(condition: $mapper->count(key: $key, moment: $moment, calls: 3));

		$this->assertSame(
			expected: array_merge($this->incrementCalls(calls: 5), $this->incrementCalls(calls: 3), $this->insertCalls(calls: 3)),
			actual: $this->calls
		);
	}//end testSeveralCallsAreCountedAtOnce()

	/**
	 * A count below one still counts the call that asked.
	 *
	 * @return void
	 */
	public function testACountBelowOneCountsOneCall(): void {
		$this->outcomes = [1];

		$this->assertTrue(
			condition: $this->mapper()->count(
				key: new ApiCallKey(principal: 'leverancier', route: '/apps/openregister/api/objects/zaken/melding/{id}', method: 'GET', apiVersion: '1'),
				moment: new DateTime('2026-03-04 05:06:07'),
				calls: 0
			)
		);

		$this->assertSame(expected: $this->incrementCalls(), actual: $this->calls);
	}//end testACountBelowOneCountsOneCall()

	/**
	 * The mapper over a recording query builder.
	 *
	 * @return ApiCallRecordMapper The mapper.
	 */
	private function mapper(): ApiCallRecordMapper {
		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder());

		return new ApiCallRecordMapper(db: $db);
	}//end mapper()

	/**
	 * Record one fixed call through the recorder.
	 *
	 * @return void
	 */
	private function record(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder());

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(true);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('leverancier');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$recorder = new ApiCallRecorder(
			mapper: new ApiCallRecordMapper(db: $db),
			userSession: $session,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);

		$recorder->recordCall(
			call: new ApiCallObservation(
				path: '/apps/openregister/api/objects/zaken/melding/3f2504e0-4f89-41d3-9a0c-0305e82c3301',
				method: 'get',
				apiVersion: '1',
				moment: new DateTime('2026-03-04 05:06:07'),
			)
		);
	}//end record()

	/**
	 * The calls one increment makes.
	 *
	 * @param int $calls The calls it adds.
	 *
	 * @return array<int, string> The calls.
	 */
	private function incrementCalls(int $calls=1): array {
		return [
			'update(openregister_api_calls,)',
			'getColumnName(call_count)',
			'param('.$calls.',1)',
			'createFunction(`call_count` + :param)',
			'set(call_count,FN[`call_count` + :param])',
			'param(2026-03-04T05:06:07,datetime)',
			'set(last_seen,:param)',
			'param(leverancier,2)',
			'expr.eq(principal,:param)',
			'where(eq:principal)',
			'param(/apps/openregister/api/objects/zaken/melding/{id},2)',
			'expr.eq(route,:param)',
			'andWhere(eq:route)',
			'param(GET,2)',
			'expr.eq(method,:param)',
			'andWhere(eq:method)',
			'param(1,2)',
			'expr.eq(api_version,:param)',
			'andWhere(eq:api_version)',
			'param(,2)',
			'expr.eq(client,:param)',
			'andWhere(eq:client)',
			'executeStatement',
		];
	}//end incrementCalls()

	/**
	 * The calls the insert makes.
	 *
	 * The method and version equal the entity defaults, so the entity does not
	 * mark them updated and the insert leaves them to the column defaults.
	 *
	 * @param int $calls The calls the new row counts.
	 *
	 * @return array<int, string> The calls.
	 */
	private function insertCalls(int $calls=1): array {
		return [
			'insert(openregister_api_calls)',
			'param(leverancier,2)',
			'setValue(principal,:param)',
			'param(/apps/openregister/api/objects/zaken/melding/{id},2)',
			'setValue(route,:param)',
			'param('.$calls.',1)',
			'setValue(call_count,:param)',
			'param(2026-03-04T05:06:07,datetime)',
			'setValue(first_seen,:param)',
			'param(2026-03-04T05:06:07,datetime)',
			'setValue(last_seen,:param)',
			'executeStatement',
		];
	}//end insertCalls()

	/**
	 * A query builder that records what it is asked.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function builder(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['update', 'set', 'where', 'andWhere', 'insert', 'setValue'] as $name) {
			$qb->method($name)->willReturnCallback(
				function (mixed ...$args) use ($qb, $name): IQueryBuilder {
					$this->calls[] = $name.'('.implode(',', array_map(static fn (mixed $arg): string => (string)$arg, $args)).')';
					return $qb;
				}
			);
		}

		$qb->method('getColumnName')->willReturnCallback(
			function (string $column): string {
				$this->calls[] = 'getColumnName('.$column.')';
				return '`'.$column.'`';
			}
		);
		$qb->method('createFunction')->willReturnCallback(
			function (string $sql): string {
				$this->calls[] = 'createFunction('.$sql.')';
				return 'FN['.$sql.']';
			}
		);
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value, mixed $type = IQueryBuilder::PARAM_STR): string {
				$shown = $value instanceof DateTime ? $value->format('Y-m-d\TH:i:s') : (string)$value;
				$this->calls[] = 'param('.$shown.','.(string)$type.')';
				return ':param';
			}
		);
		$qb->method('expr')->willReturn($this->expressions());
		$qb->method('executeStatement')->willReturnCallback(
			function (): int {
				$this->calls[] = 'executeStatement';
				$outcome = array_shift($this->outcomes);
				if ($outcome instanceof RuntimeException) {
					throw $outcome;
				}

				return (int)$outcome;
			}
		);
		$qb->method('getLastInsertId')->willReturn(7);

		return $qb;
	}//end builder()

	/**
	 * An expression builder that records each comparison.
	 *
	 * @return IExpressionBuilder The expression builder.
	 */
	private function expressions(): IExpressionBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			function (string $column, string $param): string {
				$this->calls[] = 'expr.eq('.$column.','.$param.')';
				return 'eq:'.$column;
			}
		);

		return $expr;
	}//end expressions()
}//end class
