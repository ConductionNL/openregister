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

/**
 * Pins the increment, the insert and the retried increment, with their parameters.
 *
 * Driven through ApiCallRecorder::record, the mapper's one caller.
 *
 * @covers \OCA\OpenRegister\Db\ApiCallRecordMapper
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

		$recorder->record(
			path: '/apps/openregister/api/objects/zaken/melding/3f2504e0-4f89-41d3-9a0c-0305e82c3301',
			method: 'get',
			apiVersion: '1',
			moment: new DateTime('2026-03-04 05:06:07'),
		);
	}//end record()

	/**
	 * The calls one increment makes.
	 *
	 * @return array<int, string> The calls.
	 */
	private function incrementCalls(): array {
		return [
			'update(openregister_api_calls,)',
			'getColumnName(call_count)',
			'createFunction(`call_count` + 1)',
			'set(call_count,FN[`call_count` + 1])',
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
			'executeStatement',
		];
	}//end incrementCalls()

	/**
	 * The calls the insert makes.
	 *
	 * The method and version equal the entity defaults, so the entity does not
	 * mark them updated and the insert leaves them to the column defaults.
	 *
	 * @return array<int, string> The calls.
	 */
	private function insertCalls(): array {
		return [
			'insert(openregister_api_calls)',
			'param(leverancier,2)',
			'setValue(principal,:param)',
			'param(/apps/openregister/api/objects/zaken/melding/{id},2)',
			'setValue(route,:param)',
			'param(1,1)',
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
