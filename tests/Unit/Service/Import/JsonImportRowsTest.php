<?php

/**
 * Unit tests for JsonImportRows — resolving JSON import rows to a uuid and a body.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Import;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Import\JsonImportRows;
use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class JsonImportRowsTest extends TestCase {

	private MappingEngine&MockObject $engine;

	protected function setUp(): void {
		$this->engine = $this->createMock(MappingEngine::class);
	}

	private function plain(array $properties): JsonImportRows {
		return new JsonImportRows($this->engine, null, array_flip($properties));
	}

	public function testAPlainRowKeepsOnlyDeclaredPropertiesAndReadsEmptyStringAsNull(): void {
		$this->engine->expects($this->never())->method('mapRow');

		$result = $this->plain(['title', 'note'])->resolve(
			[['id' => 'u-1', 'title' => 'A', 'note' => '', 'name' => 'Row A']]
		);

		$this->assertSame(
			[['uuid' => 'u-1', 'body' => ['title' => 'A', 'note' => null], 'name' => 'Row A']],
			$result['rows']
		);
		$this->assertSame(1, $result['summary']['found']);
		$this->assertSame([], $result['summary']['errors']);
		// The display name is read but, being undeclared, is still reported as dropped from the body.
		$this->assertSame(['Row 1: dropped 1 property the schema does not declare: name.'], $result['summary']['warnings']);
	}

	public function testThePlainUuidPrefersSelfIdThenIdThenUuid(): void {
		$rows = $this->plain(['t'])->resolve(
			[
				['@self' => ['id' => 'self'], 'id' => 'id', 'uuid' => 'uuid'],
				['id' => 'id', 'uuid' => 'uuid'],
				['uuid' => 'uuid'],
				['t' => 'x'],
				['id' => 12],
			]
		)['rows'];

		$this->assertSame(['self', 'id', 'uuid', null, '12'], array_column($rows, 'uuid'));
	}

	public function testTheNameFallsBackToTheUuid(): void {
		$rows = $this->plain([])->resolve([['id' => 'abc']])['rows'];

		$this->assertSame('abc', $rows[0]['name']);
	}

	public function testUndeclaredUserDataIsDroppedWithAWarningButMetadataIsNot(): void {
		$result = $this->plain(['title'])->resolve(
			[
				['title' => 'ok'],
				['title' => 'x', 'colour' => 'red', '@self' => [], '_meta' => 1, 'uuid' => 'q', 'id' => 'q'],
				['title' => 'y', 'colour' => 'red', 'size' => 3],
			]
		);

		$this->assertSame(
			[
				'Row 2: dropped 1 property the schema does not declare: colour.',
				'Row 3: dropped 2 properties the schema does not declare: colour, size.',
			],
			$result['summary']['warnings']
		);
	}

	public function testEntriesThatAreNotArraysAreLeftOutButStillCountedAsFound(): void {
		$result = $this->plain(['t'])->resolve(['scalar', ['t' => 1], null]);

		$this->assertSame(3, $result['summary']['found']);
		$this->assertCount(1, $result['rows']);
		$this->assertSame(['created' => [], 'updated' => [], 'unchanged' => []], array_intersect_key($result['summary'], array_flip(['created', 'updated', 'unchanged'])));
	}

	public function testAPackMapsEachRowWithItsOneBasedNumberAndLiftsTheId(): void {
		$pack = ['fields' => []];
		$rows = new JsonImportRows($this->engine, $pack, []);

		$this->engine->method('isRowSkipped')->willReturn(false);
		$this->engine->expects($this->exactly(2))->method('mapRow')
			->willReturnCallback(
				function (array $p, array $sourceRow, int $rowNumber) use ($pack): array {
					$this->assertSame($pack, $p);
					return ['data' => ['id' => 'm-'.$rowNumber, 'mapped' => $sourceRow['src']], 'errors' => []];
				}
			);

		$result = $rows->resolve([['src' => 'a', 'name' => 'First'], ['src' => 'b']]);

		$this->assertSame(
			[
				['uuid' => 'm-1', 'body' => ['mapped' => 'a'], 'name' => 'First'],
				['uuid' => 'm-2', 'body' => ['mapped' => 'b'], 'name' => 'm-2'],
			],
			$result['rows']
		);
	}

	public function testASkippedRowIsNeitherMappedNorReported(): void {
		$rows = new JsonImportRows($this->engine, ['skip' => [1]], []);

		$this->engine->expects($this->once())->method('isRowSkipped')
			->with(['skip' => [1]], 1)->willReturn(true);
		$this->engine->expects($this->never())->method('mapRow');

		$result = $rows->resolve([['src' => 'a']]);

		$this->assertSame([], $result['rows']);
		$this->assertSame([], $result['summary']['errors']);
	}

	public function testMappingErrorsAreFormattedIntoTheSummaryAndTheRowIsRefused(): void {
		$rows = new JsonImportRows($this->engine, [], []);
		$this->engine->method('isRowSkipped')->willReturn(false);
		$this->engine->method('mapRow')->willReturn(
			[
				'data' => [],
				'errors' => [
					['row' => 1, 'source' => 'dob', 'target' => 'birthDate', 'transform' => 'date', 'message' => 'not a date'],
				],
			]
		);

		$result = $rows->resolve([['dob' => 'x']]);

		$this->assertSame([], $result['rows']);
		$this->assertSame(
			[
				[
					'row' => 1,
					'field' => 'birthDate',
					'error' => '[migration-pack] not a date — source column "dob" (transform: date)',
					'type' => 'MigrationPackMappingError',
					'original_value' => 'dob',
				],
			],
			$result['summary']['errors']
		);
	}
}
