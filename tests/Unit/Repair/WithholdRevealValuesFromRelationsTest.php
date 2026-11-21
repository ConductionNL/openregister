<?php

/**
 * Tests for the WithholdRevealValuesFromRelations repair step.
 *
 * Pins which stored relations indexes are rewritten (by path: the paths under
 * an explicit-reveal property go; as a list: the values the row holds in its
 * explicit-reveal columns go), that an index holding none of them is not
 * written, the paging, that only schemas with explicit-reveal properties are
 * visited, and that a failing listing or table does not abort the upgrade.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/authorization-rbac/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Command\MagicTableSelection;
use OCA\OpenRegister\Command\MagicTableTarget;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Repair\WithholdRevealValuesFromRelations;
use OCA\OpenRegister\Service\Object\SaveObject\RevealRelations;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(WithholdRevealValuesFromRelations::class)]
#[UsesClass(MagicTableTarget::class)]
#[UsesClass(Register::class)]
#[UsesClass(Schema::class)]
#[UsesClass(RevealRelations::class)]
#[UsesClass(ExplicitReveal::class)]
final class WithholdRevealValuesFromRelationsTest extends TestCase {

	/**
	 * Rows per table, in id order.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $tables = [];

	/**
	 * The pages read: [table, afterId].
	 *
	 * @var list<array{0: string, 1: int}>
	 */
	private array $reads = [];

	/**
	 * The rows written: [table, rowId, relations JSON].
	 *
	 * @var list<array{0: string, 1: int, 2: string}>
	 */
	private array $writes = [];

	/**
	 * Tables whose read fails.
	 *
	 * @var list<string>
	 */
	private array $broken = [];

	/**
	 * A schema whose `secret` and `nestedSecret` properties are explicit-reveal, or none when $explicit is false.
	 *
	 * @param bool $explicit Whether it has explicit-reveal properties.
	 *
	 * @return Schema The schema.
	 */
	private static function schema(bool $explicit = true): Schema {
		$schema = new Schema();
		$schema->setId($explicit === true ? 2 : 3);
		$schema->setSlug($explicit === true ? 'person' : 'plain');
		$properties = ['name' => ['type' => 'string'], 'friend' => ['type' => 'string', 'format' => 'uuid']];
		if ($explicit === true) {
			$properties['secret'] = ['type' => 'string', 'authorization' => ['reveal' => 'explicit']];
			$properties['nestedSecret'] = ['type' => 'object', 'authorization' => ['reveal' => 'explicit']];
		}

		$schema->setProperties($properties);
		return $schema;
	}//end schema()

	/**
	 * A table of the given schema.
	 *
	 * @param Schema $schema The schema.
	 * @param string $table  The table name.
	 *
	 * @return MagicTableTarget The target.
	 */
	private static function target(Schema $schema, string $table): MagicTableTarget {
		$register = new Register();
		$register->setId(1);
		$register->setSlug('reg');

		return new MagicTableTarget(register: $register, schema: $schema, tableName: $table);
	}//end target()

	/**
	 * A connection whose query builders read from $this->tables and record writes.
	 *
	 * @return IDBConnection The connection.
	 */
	private function db(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());
		return $db;
	}//end db()

	/**
	 * One query builder: a page read or a single-row write.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function queryBuilder(): IQueryBuilder {
		$state = ['table' => null, 'after' => null, 'id' => null, 'relations' => null, 'limit' => null];
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('gt')->willReturnCallback(static function (string $column, mixed $value) use (&$state): string {
			$state['after'] = $value;
			return $column . ' > ?';
		});
		$expr->method('eq')->willReturnCallback(static function (string $column, mixed $value) use (&$state): string {
			$state['id'] = $value;
			return $column . ' = ?';
		});
		$expr->method('isNotNull')->willReturnCallback(static fn (string $column): string => $column . ' IS NOT NULL');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(static fn (mixed $value): mixed => $value);
		$qb->method('select')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('from')->willReturnCallback(static function (string $table) use (&$state, $qb): IQueryBuilder {
			$state['table'] = $table;
			return $qb;
		});
		$qb->method('update')->willReturnCallback(static function (string $table) use (&$state, $qb): IQueryBuilder {
			$state['table'] = $table;
			return $qb;
		});
		$qb->method('set')->willReturnCallback(static function (string $column, mixed $value) use (&$state, $qb): IQueryBuilder {
			$state['relations'] = [$column, $value];
			return $qb;
		});
		$qb->method('setMaxResults')->willReturnCallback(static function (?int $limit) use (&$state, $qb): IQueryBuilder {
			$state['limit'] = $limit;
			return $qb;
		});
		$qb->method('executeQuery')->willReturnCallback(function () use (&$state): IResult {
			if (in_array($state['table'], $this->broken, true) === true) {
				throw new RuntimeException('table is gone');
			}

			$this->reads[] = [$state['table'], $state['after']];
			$rows = array_values(array_filter(
				($this->tables[$state['table']] ?? []),
				static fn (array $row): bool => $row['_id'] > $state['after'] && $row['_relations'] !== null
			));
			$result = $this->createMock(IResult::class);
			$result->method('fetchAll')->willReturn(array_slice($rows, 0, $state['limit']));
			$result->expects($this->once())->method('closeCursor');
			return $result;
		});
		$qb->method('executeStatement')->willReturnCallback(function () use (&$state): int {
			$this->assertSame('_relations', $state['relations'][0]);
			$this->writes[] = [$state['table'], $state['id'], $state['relations'][1]];
			return 1;
		});

		return $qb;
	}//end queryBuilder()

	/**
	 * A selection listing the given schemas and the given tables.
	 *
	 * @param list<MagicTableTarget> $tables       The tables listed.
	 * @param list<Schema>|null      $schemasSeen  Filled with the schemas the step asked tables for.
	 *
	 * @return MagicTableSelection The selection.
	 */
	private function selection(array $tables, ?array &$schemasSeen = null): MagicTableSelection {
		$selection = $this->createMock(MagicTableSelection::class);
		$selection->method('schemas')->with(null)->willReturn([self::schema(), self::schema(explicit: false)]);
		$selection->method('registers')->with(null)->willReturn(['registers']);
		$selection->method('tables')->willReturnCallback(static function (array $registers, array $schemas) use ($tables, &$schemasSeen): array {
			$schemasSeen = $schemas;
			return $tables;
		});
		return $selection;
	}//end selection()

	/**
	 * An output collecting info and warnings.
	 *
	 * @param list<string> $lines Filled with "info: …" / "warning: …".
	 *
	 * @return IOutput The output.
	 */
	private function recordingOutput(array &$lines): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(static function (string $message) use (&$lines): void {
			$lines[] = 'info: ' . $message;
		});
		$output->method('warning')->willReturnCallback(static function (string $message) use (&$lines): void {
			$lines[] = 'warning: ' . $message;
		});
		return $output;
	}//end recordingOutput()

	public function testTheNameSaysWhatItDoes(): void {
		$step = new WithholdRevealValuesFromRelations($this->createMock(MagicTableSelection::class), $this->db(), $this->createMock(LoggerInterface::class));

		$this->assertSame('Take the values of explicit-reveal properties out of the stored relations index', $step->getName());
	}//end testTheNameSaysWhatItDoes()

	public function testOnlyIndexesHoldingRevealValuesAreRewritten(): void {
		$this->tables['t_person'] = [
			// By path: the paths under an explicit-reveal property go.
			['_id' => 1, '_relations' => json_encode(['friend' => 'f-1', 'secret' => 's-1', 'nestedSecret.owner' => 'o-1']), 'secret' => 's-1', 'nested_secret' => null],
			// As a list: the values the row holds in its reveal columns go, nested and JSON-encoded ones included.
			['_id' => 2, '_relations' => json_encode(['f-2', 's-2', 'o-2', 'deep-2']), 'secret' => 's-2', 'nested_secret' => json_encode(['owner' => 'o-2', 'more' => ['deep-2', '', 7]])],
			// Holds none of them: not written.
			['_id' => 3, '_relations' => json_encode(['friend' => 'f-3']), 'secret' => 's-3', 'nested_secret' => null],
			['_id' => 4, '_relations' => json_encode(['f-4']), 'secret' => 's-4', 'nested_secret' => ['already' => 'decoded']],
			// An empty, unreadable or scalar index: not written.
			['_id' => 5, '_relations' => '', 'secret' => 's-5'],
			['_id' => 6, '_relations' => 'not json', 'secret' => 's-6'],
			['_id' => 7, '_relations' => '"just a string"', 'secret' => 's-7'],
			// A list holding a reveal value given as a decoded array column.
			['_id' => 8, '_relations' => ['f-8', 'x-8'], 'secret' => '', 'nested_secret' => ['x-8']],
			// No index at all: not read.
			['_id' => 9, '_relations' => null, 'secret' => 's-9'],
		];
		$lines = [];
		$schemasSeen = null;
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		(new WithholdRevealValuesFromRelations($this->selection([self::target(self::schema(), 't_person')], $schemasSeen), $this->db(), $logger))
			->run($this->recordingOutput($lines));

		$this->assertSame(
			[
				['t_person', 1, json_encode(['friend' => 'f-1'])],
				['t_person', 2, json_encode(['f-2'])],
				['t_person', 8, json_encode(['f-8'])],
			],
			$this->writes
		);
		$this->assertSame(['info: Rebuilt the relations index of 3 stored object(s).'], $lines);
		$this->assertSame([['t_person', 0]], $this->reads);
		$this->assertSame(['person'], array_values(array_map(static fn (Schema $schema): ?string => $schema->getSlug(), $schemasSeen)));
	}//end testOnlyIndexesHoldingRevealValuesAreRewritten()

	public function testATableIsReadPageByPageAfterTheLastId(): void {
		$rows = [];
		for ($id = 1; $id <= 501; $id++) {
			$rows[] = ['_id' => $id, '_relations' => json_encode(['secret' => 's', 'friend' => 'f']), 'secret' => 's'];
		}

		$this->tables['t_person'] = $rows;
		$lines = [];

		(new WithholdRevealValuesFromRelations($this->selection([self::target(self::schema(), 't_person')]), $this->db(), $this->createMock(LoggerInterface::class)))
			->run($this->recordingOutput($lines));

		$this->assertSame([['t_person', 0], ['t_person', 500]], $this->reads);
		$this->assertCount(501, $this->writes);
		$this->assertSame(['t_person', 501, json_encode(['friend' => 'f'])], $this->writes[500]);
		$this->assertSame(['info: Rebuilt the relations index of 501 stored object(s).'], $lines);
	}//end testATableIsReadPageByPageAfterTheLastId()

	public function testAFailingTableIsSkippedAndTheOthersStillRun(): void {
		$this->broken = ['t_broken'];
		$this->tables['t_person'] = [['_id' => 4, '_relations' => json_encode(['secret' => 's']), 'secret' => 's']];
		$lines = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[WithholdRevealValuesFromRelations] Skipped a table',
			['table' => 't_broken', 'exception' => 'table is gone']
		);

		(new WithholdRevealValuesFromRelations(
			$this->selection([self::target(self::schema(), 't_broken'), self::target(self::schema(), 't_person')]),
			$this->db(),
			$logger
		))->run($this->recordingOutput($lines));

		$this->assertSame([['t_person', 4, '[]']], $this->writes);
		$this->assertSame(
			['warning: reg/person (t_broken): skipped, table is gone', 'info: Rebuilt the relations index of 1 stored object(s).'],
			$lines
		);
	}//end testAFailingTableIsSkippedAndTheOthersStillRun()

	public function testAFailingListingWarnsAndStops(): void {
		$selection = $this->createMock(MagicTableSelection::class);
		$selection->method('schemas')->willThrowException(new RuntimeException('no catalogue'));
		$selection->expects($this->never())->method('tables');
		$lines = [];

		(new WithholdRevealValuesFromRelations($selection, $this->db(), $this->createMock(LoggerInterface::class)))->run($this->recordingOutput($lines));

		$this->assertSame(['warning: Could not list the object tables: no catalogue'], $lines);
		$this->assertSame([], $this->reads);
	}//end testAFailingListingWarnsAndStops()
}//end class
