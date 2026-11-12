<?php

/**
 * SharedMasterDataService resolution golden tests
 *
 * Pins how declarations are parsed (holder, consumers, title, id), which
 * holders a reader is widened by per register+schema pair, which ids are
 * shared, and when a write is refused (with the exact exception) or allowed.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Exception\SharedMasterDataWriteException;
use OCA\OpenRegister\Service\DeclaredRow;
use OCA\OpenRegister\Service\DeclaredPair;
use OCA\OpenRegister\Service\SharedMasterDataService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for SharedMasterDataService.
 */
class SharedMasterDataResolutionGoldenTest extends TestCase {

	/**
	 * How many declaration reads hit the database.
	 *
	 * @var int
	 */
	private int $reads = 0;

	/**
	 * The service over the given rows; the organisation name lookup answers $name.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsByTable Rows per table
	 * @param string|false|null                               $name        Name lookup result (null: lookup throws)
	 *
	 * @return SharedMasterDataService
	 */
	private function service(array $rowsByTable, string|false|null $name=false): SharedMasterDataService {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use ($rowsByTable, $name): IQueryBuilder {
				$table = '';
				$expr  = $this->createMock(IExpressionBuilder::class);
				$qb    = $this->createMock(IQueryBuilder::class);
				$qb->method('expr')->willReturn($expr);
				foreach (['select', 'where', 'setMaxResults'] as $fluent) {
					$qb->method($fluent)->willReturnSelf();
				}

				$qb->method('from')->willReturnCallback(
					function (string $from) use (&$table, $qb): IQueryBuilder {
						$table = $from;
						return $qb;
					}
				);
				$qb->method('executeQuery')->willReturnCallback(
					function () use (&$table, $rowsByTable, $name): IResult {
						if ($table === 'openregister_organisations' && $name === null) {
							throw new \RuntimeException('no table');
						}

						if ($table !== 'openregister_organisations') {
							$this->reads++;
						}

						$rows   = ($rowsByTable[$table] ?? []);
						$result = $this->createMock(IResult::class);
						$result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
						$result->method('fetchOne')->willReturn($name);
						return $result;
					}
				);
				return $qb;
			}
		);
		return new SharedMasterDataService($db);
	}//end service()

	/**
	 * A varied declaration set.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function rows(): array {
		return [
			SharedMasterDataService::REGISTERS => [
				['id' => '7', 'title' => 'Code lists', 'organisation' => 'A', 'shared_with' => '["B", "C", "B", "A", "", 5, null]'],
				['id' => 8, 'title' => 42, 'organisation' => 'A', 'shared_with' => '["D"]'],
				['id' => 9, 'title' => 'No holder', 'organisation' => '', 'shared_with' => '["B"]'],
				['id' => 10, 'title' => 'Null holder', 'shared_with' => '["B"]'],
				['id' => 11, 'title' => 'Self only', 'organisation' => 'A', 'shared_with' => '["A"]'],
				['id' => 12, 'title' => 'Blank', 'organisation' => 'A', 'shared_with' => '   '],
				['id' => 13, 'title' => 'Object', 'organisation' => 'A', 'shared_with' => '{"x":"B"}'],
				['id' => 14, 'title' => 'Scalar', 'organisation' => 'A', 'shared_with' => '"B"'],
				['id' => 15, 'title' => 'Bad json', 'organisation' => 'A', 'shared_with' => '[B'],
				['id' => 16, 'title' => 'Held by E', 'organisation' => 'E', 'shared_with' => '["B"]'],
			],
			SharedMasterDataService::SCHEMAS   => [
				['id' => 7, 'title' => 'Party', 'organisation' => 'F', 'shared_with' => '["B"]'],
				['title' => 'No id', 'organisation' => 'G', 'shared_with' => '["B"]'],
			],
		];
	}//end rows()

	/**
	 * Read widening per pair and the shared ids.
	 *
	 * @return void
	 */
	public function testResolution(): void {
		$service = $this->service($this->rows());
		$this->assertSame([], $service->holdersForResource(new DeclaredPair(7, 7), []));
		$this->assertSame([], $service->holdersForResource(new DeclaredPair(null, null), ['B']));
		$this->assertSame(0, $this->reads);

		$this->assertSame(['A', 'F'], $service->holdersForResource(new DeclaredPair(7, 7), ['B']));
		$this->assertSame(['A'], $service->holdersForResource(new DeclaredPair(7, null), ['C', 'X']));
		$this->assertSame(['F'], $service->holdersForResource(new DeclaredPair(null, 7), ['B']));
		$this->assertSame(['A'], $service->holdersForResource(new DeclaredPair(7, 7), ['C']));
		$this->assertSame([], $service->holdersForResource(new DeclaredPair(7, 7), ['B', 'A', 'F']));
		$this->assertSame(['F'], $service->holdersForResource(new DeclaredPair(7, 7), ['B', 'A']));
		$this->assertSame(['A'], $service->holdersForResource(new DeclaredPair(8, 99), ['D']));
		$this->assertSame(['E', 'G'], $service->holdersForResource(new DeclaredPair(16, 0), ['B']));
		foreach ([9, 10, 11, 12, 13, 14, 15] as $nothing) {
			$this->assertSame([], $service->holdersForResource(new DeclaredPair($nothing, null), ['A', 'B']), 'register '.$nothing);
		}

		// A JSON object is read for its values, so register 13 counts as shared with B.
		$this->assertSame([7, 13, 16], $service->sharedIds(SharedMasterDataService::REGISTERS, ['B']));
		$this->assertSame([], $service->sharedIds(SharedMasterDataService::REGISTERS, ['C', 'A']));
		$this->assertSame([7], $service->sharedIds(SharedMasterDataService::REGISTERS, ['C', 'E']));
		$this->assertSame([7, 0], $service->sharedIds(SharedMasterDataService::SCHEMAS, ['B']));
		$this->assertSame([], $service->sharedIds(SharedMasterDataService::SCHEMAS, []));
		$this->assertSame([], $service->sharedIds('other', ['B']));
		// Two tables, read once for the whole request.
		$this->assertSame(2, $this->reads);
	}//end testResolution()

	/**
	 * Writes: allowed for holders, strangers and undeclared rows; refused for consumers.
	 *
	 * @return void
	 */
	public function testWriteRefusals(): void {
		$service = $this->service($this->rows(), 'Gemeente A');
		foreach ([[7, ['A']], [7, ['X']], [7, []], [null, ['B']], [99, ['B']], [11, ['A']], [9, ['B']], [7, ['B', 'A']]] as [$id, $orgs]) {
			$service->assertWritable(new DeclaredRow(SharedMasterDataService::REGISTERS, $id), $orgs);
		}

		$cases = [
			[SharedMasterDataService::REGISTERS, 7, ['B'], 'A', 'Gemeente A', 'register', 'This register "Code lists" is shared master data held by Gemeente A. Your organisation reads it and cannot change it. Ask Gemeente A to make the change.'],
			[SharedMasterDataService::REGISTERS, 8, ['D'], 'A', 'Gemeente A', 'register', 'This register is shared master data held by Gemeente A. Your organisation reads it and cannot change it. Ask Gemeente A to make the change.'],
			[SharedMasterDataService::SCHEMAS, 7, ['X', 'B'], 'F', 'Gemeente A', 'schema', 'This schema "Party" is shared master data held by Gemeente A. Your organisation reads it and cannot change it. Ask Gemeente A to make the change.'],
			['other_table', 7, ['B'], null, null, null, null],
		];
		foreach ($cases as [$table, $id, $orgs, $holder, $name, $type, $message]) {
			try {
				$service->assertWritable(new DeclaredRow($table, $id), $orgs);
				$this->assertNull($holder, 'expected a refusal for '.$table.' '.$id);
			} catch (SharedMasterDataWriteException $e) {
				$this->assertSame(
					[$holder, $name, $type, $message, 403],
					[$e->getHolderUuid(), $e->getHolderName(), $e->getResourceType(), $e->getMessage(), $e->getCode()]
				);
			}
		}
	}//end testWriteRefusals()

	/**
	 * The refusal names the holder by UUID when its name cannot be read.
	 *
	 * @return void
	 */
	public function testRefusalFallsBackToTheHolderUuid(): void {
		foreach ([null, false, ''] as $name) {
			try {
				$this->service($this->rows(), $name)->assertWritable(new DeclaredRow(SharedMasterDataService::SCHEMAS, 7), ['B']);
				$this->fail('expected a refusal');
			} catch (SharedMasterDataWriteException $e) {
				$this->assertSame([null, 'schema'], [$e->getHolderName(), $e->getResourceType()]);
				$this->assertStringContainsString('held by F.', $e->getMessage());
			}
		}
	}//end testRefusalFallsBackToTheHolderUuid()
}//end class
