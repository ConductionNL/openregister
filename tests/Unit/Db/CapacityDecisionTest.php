<?php

/**
 * An unconfirmed capacity suggestion does not release an occurrence (REQ-DKP-002, fail closed).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\Registers\EntityEventPublisher;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CapacityDecisionTest extends TestCase {

	/**
	 * The conditions the query was built with, as `column = value` strings.
	 *
	 * @var list<string>
	 */
	private array $conditions = [];

	/**
	 * Every column the query names.
	 *
	 * @var list<string>
	 */
	private array $columns = [];

	private function mapper(array $rows): EntityRelationMapper {
		$parameters = [];
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			static function (string $column, mixed $value) use (&$parameters): string {
				return $column . ' = ' . var_export($parameters[$value] ?? $value, true);
			}
		);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			static function (mixed $value) use (&$parameters): string {
				$name = ':p' . count($parameters);
				$parameters[$name] = $value;
				return $name;
			}
		);
		$qb->method('select')->willReturnCallback(
			function (string ...$columns) use ($qb): IQueryBuilder {
				array_push($this->columns, ...$columns);
				return $qb;
			}
		);
		$qb->method('addSelect')->willReturnCallback(
			function (string ...$columns) use ($qb): IQueryBuilder {
				array_push($this->columns, ...$columns);
				return $qb;
			}
		);
		foreach (['where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (string $condition) use ($qb): IQueryBuilder {
					$this->conditions[] = $condition;
					return $qb;
				}
			);
		}

		foreach (['from', 'innerJoin', 'orderBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return new EntityRelationMapper(
			$db,
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(IUserSession::class),
			new EntityEventPublisher(dispatcher: $this->createMock(IEventDispatcher::class)),
			$this->createMock(LoggerInterface::class)
		);
	}//end mapper()

	public function testAnUnconfirmedReleaseSuggestionIsAnonymised(): void {
		// A relation detected with a public-office suggestion keeps
		// skip_anonymization false until a reviewer confirms it.
		$row = ['relation_id' => 5, 'entity_type' => 'PERSON', 'entity_value' => 'P. de Vries', 'bases' => null];

		$rows = $this->mapper(rows: [$row])->findEntitiesForAnonymization(fileId: 42);

		$this->assertSame([$row], $rows);
		$this->assertSame(["r.file_id = 42", "r.skip_anonymization = false"], $this->conditions);
		// Neither the suggestion nor an unconfirmed capacity takes part in the choice.
		foreach (array_merge($this->conditions, $this->columns) as $fragment) {
			$this->assertStringNotContainsString('capacity', $fragment);
		}
	}//end testAnUnconfirmedReleaseSuggestionIsAnonymised()
}//end class
