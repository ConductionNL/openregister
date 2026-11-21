<?php

/**
 * MappingErrorRow: how a migration-pack mapping error is reported as an import error row.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Import;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Import\MappingErrorRow;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Import\MappingErrorRow
 */
class MappingErrorRowTest extends TestCase {

	public function testAnErrorWithATransformNamesTheTargetAndTheTransform(): void {
		$this->assertSame(
			[
				'row' => 4,
				'field' => 'birthDate',
				'error' => '[migration-pack] not a date — source column "geb_datum" (transform: date)',
				'type' => 'MigrationPackMappingError',
				'original_value' => 'geb_datum',
			],
			(new MappingErrorRow())->format(['row' => 4, 'source' => 'geb_datum', 'target' => 'birthDate', 'transform' => 'date', 'message' => 'not a date'])
		);
	}

	public function testAnErrorWithoutTargetOrTransformFallsBackToTheSourceColumn(): void {
		$row = (new MappingErrorRow())->format(['row' => 1, 'source' => 'naam', 'target' => null, 'transform' => null, 'message' => 'required']);

		$this->assertSame('naam', $row['field']);
		$this->assertSame('[migration-pack] required — source column "naam"', $row['error']);
	}
}
