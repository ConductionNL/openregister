<?php

/**
 * Unit tests for the text signature table migration.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261113200000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Version1Date20261113200000: the text signature table, one row per file.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261113200000
 */
class Version1Date20261113200000Test extends TestCase {
	use SchemaTableMockTrait;

	public function testTheTableIsCreatedWithAUniqueFileId(): void {
		$table = $this->createTableMock();
		$added = [];
		$table->method('addColumn')->willReturnCallback(
			function (string $name, string $type, array $options) use (&$added) {
				$added[$name] = [$type, $options['notnull']];
				return $this->createColumnMock();
			}
		);
		$table->expects($this->once())->method('setPrimaryKey')->with(['id']);
		$table->expects($this->once())->method('addUniqueIndex')->with(['file_id'], 'idx_or_tsig_file');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_text_signatures')->willReturn(false);
		$schema->expects($this->once())->method('createTable')->with('openregister_text_signatures')->willReturn($table);

		$this->assertSame($schema, $this->change(schema: $schema));
		$this->assertSame(
			[
				'id'            => [Types::BIGINT, true],
				'file_id'       => [Types::BIGINT, true],
				'signature'     => [Types::TEXT, false],
				'shingle_count' => [Types::INTEGER, true],
				'text_sha256'   => [Types::STRING, true],
				'created'       => [Types::DATETIME, true],
			],
			$added
		);
	}

	public function testAnExistingTableIsLeftAlone(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_text_signatures')->willReturn(true);
		$schema->expects($this->never())->method('createTable');

		$this->assertSame($schema, $this->change(schema: $schema));
	}

	private function change(ISchemaWrapper $schema): ?ISchemaWrapper {
		return (new Version1Date20261113200000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
	}
}//end class
