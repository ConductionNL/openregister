<?php

/**
 * The unique uuid index repair reports duplicates and adds the index only where there are none.
 *
 * Pins that a table with duplicate uuids gets no index and is named with its
 * duplicates, that a table already carrying the index is left alone, that a
 * clean table gets the index, and that a failing table does not stop the rest.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Command\MagicTableSelection;
use OCA\OpenRegister\Command\MagicTableTarget;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Repair\AddUniqueUuidIndex;
use OCA\OpenRegister\Service\Object\UniqueUuidIndex;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class AddUniqueUuidIndexTest extends TestCase {

	private static function target(string $table): MagicTableTarget {
		$register = new Register();
		$register->setId(1);
		$register->setSlug('r');
		$schema = new Schema();
		$schema->setId(2);
		$schema->setSlug('s');

		return new MagicTableTarget(register: $register, schema: $schema, tableName: $table);
	}//end target()

	public function testDuplicatesBlockTheIndexAndAreReportedWhileCleanTablesGetIt(): void {
		$selection = $this->createMock(MagicTableSelection::class);
		$selection->method('registers')->willReturn([]);
		$selection->method('schemas')->willReturn([]);
		$selection->method('tables')->willReturn([
			self::target(table: 'has_index'),
			self::target(table: 'twins'),
			self::target(table: 'broken'),
			self::target(table: 'clean'),
		]);

		$index = $this->createMock(UniqueUuidIndex::class);
		$index->method('isUnique')->willReturnCallback(static function (string $tableName): bool {
			if ($tableName === 'broken') {
				throw new RuntimeException('gone');
			}

			return $tableName === 'has_index';
		});
		$index->method('duplicates')->willReturnCallback(
			static fn (string $tableName): array => ($tableName === 'twins') ? ['u-1' => 2] : []
		);
		$added = [];
		$index->expects($this->once())->method('add')->willReturnCallback(static function (string $tableName) use (&$added): void {
			$added[] = $tableName;
		});

		$warnings = [];
		$output = $this->createMock(IOutput::class);
		$output->method('warning')->willReturnCallback(static function (string $message) use (&$warnings): void {
			$warnings[] = $message;
		});

		(new AddUniqueUuidIndex(selection: $selection, index: $index, logger: $this->createMock(LoggerInterface::class)))->run($output);

		$this->assertSame(['clean'], $added);
		$this->assertCount(2, $warnings);
		$this->assertStringContainsString('twins', $warnings[0]);
		$this->assertStringContainsString('u-1 (2 rows)', $warnings[0]);
		$this->assertStringContainsString('broken', $warnings[1]);
	}//end testDuplicatesBlockTheIndexAndAreReportedWhileCleanTablesGetIt()
}//end class
