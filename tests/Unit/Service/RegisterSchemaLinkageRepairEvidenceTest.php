<?php

/**
 * RegisterSchemaLinkageRepairService: how physical evidence is read — the table
 * listing failing, upper-case column names (Oracle/MySQL), blank names, a table
 * name that cannot be counted safely, a count that fails — and how ids normalise.
 *
 * RegisterSchemaLinkageRepairServiceTest covers inspect/apply on clean evidence.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Service\RegisterSchemaLinkageRepairService;
use OCP\DB\IPreparedStatement;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\RegisterSchemaLinkageRepairService
 */
class RegisterSchemaLinkageRepairEvidenceTest extends TestCase {

	private IDBConnection&MockObject $db;
	private RegisterMapperInterface&MockObject $mapper;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->mapper = $this->createMock(RegisterMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function service(): RegisterSchemaLinkageRepairService {
		return new RegisterSchemaLinkageRepairService($this->db, $this->mapper, $this->logger);
	}

	private function statement(array $rows): IPreparedStatement {
		$stmt = $this->createMock(IPreparedStatement::class);
		$stmt->method('fetch')->willReturnCallback(static function () use (&$rows): mixed {
			return (array_shift($rows) ?? false);
		});
		return $stmt;
	}

	private static function register(int $id, mixed $schemas): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSlug("r{$id}");
		$register->setSchemas($schemas);
		return $register;
	}

	public function testATableListingThatFailsIsLoggedAndReportsNothing(): void {
		$this->db->method('prepare')->willThrowException(new RuntimeException('no information_schema'));
		$this->logger->expects($this->once())->method('warning')
			->with('[RegisterSchemaLinkageRepair] Could not list tables: no information_schema');
		$this->mapper->expects($this->never())->method('findAll');

		$this->assertSame([], $this->service()->inspect());
	}

	public function testUpperCaseColumnsAreReadAndBlankNamesSkipped(): void {
		$counted = [];
		$this->db->method('prepare')->willReturnCallback(function (string $sql) use (&$counted): IPreparedStatement {
			if (str_contains($sql, 'information_schema') === true) {
				return $this->statement([['TABLE_NAME' => 'oc_openregister_table_3_40'], ['table_name' => ''], ['other' => 'x']]);
			}

			$counted[] = $sql;
			return $this->statement([['C' => '7']]);
		});
		$this->mapper->method('findAll')->willReturn([self::register(3, [])]);

		$report = $this->service()->inspect();

		$this->assertSame([['registerId' => 3, 'registerSlug' => 'r3', 'currentIds' => [], 'recoverable' => [40 => 7]]], $report);
		$this->assertSame(['SELECT COUNT(*) AS c FROM oc_openregister_table_3_40'], $counted);
	}

	public function testATableNameThatIsNotSafeToCountIsNeverQueriedAndCountsAsUnknown(): void {
		$queries = 0;
		$this->db->method('prepare')->willReturnCallback(function (string $sql) use (&$queries): IPreparedStatement {
			if (str_contains($sql, 'information_schema') === true) {
				return $this->statement([['table_name' => 'oc-x_openregister_table_3_41']]);
			}

			$queries++;
			return $this->statement([['c' => 1]]);
		});
		$this->mapper->method('findAll')->willReturn([self::register(3, null)]);

		$report = $this->service()->inspect();

		$this->assertSame(0, $queries);
		$this->assertSame([41 => -1], $report[0]['recoverable']);
	}

	public function testACountThatFailsCountsAsUnknown(): void {
		$this->db->method('prepare')->willReturnCallback(function (string $sql): IPreparedStatement {
			if (str_contains($sql, 'information_schema') === true) {
				return $this->statement([['table_name' => 'oc_openregister_table_3_42']]);
			}

			throw new RuntimeException('table vanished');
		});
		$this->mapper->method('findAll')->willReturn([self::register(3, [])]);

		$this->assertSame([42 => -1], $this->service()->inspect()[0]['recoverable']);
	}

	public function testCurrentIdsAreNormalisedBeforeComparing(): void {
		$this->db->method('prepare')->willReturnCallback(function (string $sql): IPreparedStatement {
			if (str_contains($sql, 'information_schema') === true) {
				return $this->statement([['table_name' => 'oc_openregister_table_3_5'], ['table_name' => 'oc_openregister_table_3_6']]);
			}

			return $this->statement([['c' => 0]]);
		});
		$this->mapper->method('findAll')->willReturn([self::register(3, ['5', 'slug', 0, -2, '5'])]);

		$report = $this->service()->inspect();

		$this->assertSame([5], $report[0]['currentIds']);
		$this->assertSame([6 => 0], $report[0]['recoverable']);
	}

	public function testApplyNormalisesTheAdditionsAndLogsTheRepair(): void {
		$register = self::register(3, ['9', 2]);
		$this->mapper->expects($this->once())->method('find')->with(3)->willReturn($register);
		$this->mapper->expects($this->once())->method('update')->with($register)->willReturnArgument(0);
		$this->logger->expects($this->once())->method('warning')
			->with('[RegisterSchemaLinkageRepair] Register 3 schemas list repaired: [9,2] -> [2,4,9].');

		$this->assertSame([2, 4, 9], $this->service()->apply(3, ['4', 'x', 0, 9]));
		$this->assertSame([2, 4, 9], $register->getSchemas());
	}
}
