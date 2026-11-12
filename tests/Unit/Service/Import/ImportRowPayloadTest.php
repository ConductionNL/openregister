<?php

/**
 * Unit tests for ImportRowPayload — the payload helpers of an import preview row.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Import;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ImportPreviewRow;
use OCA\OpenRegister\Service\Import\ImportRowPayload;
use PHPUnit\Framework\TestCase;

final class ImportRowPayloadTest extends TestCase {

	private function row(?array $payload): ImportPreviewRow {
		$row = new ImportPreviewRow();
		$row->setPayload($payload);
		return $row;
	}

	public function testARefusalCarriesItsReasonAndPayload(): void {
		$this->assertSame(
			[
				'decision' => ImportPreviewRow::DECISION_REFUSE,
				'reason' => 'two matches',
				'targetUuid' => null,
				'candidates' => [],
				'payload' => ['a' => 1],
			],
			ImportRowPayload::refusal('two matches', ['a' => 1])
		);
		$this->assertSame([], ImportRowPayload::refusal('x')['payload']);
	}

	public function testAMappingRefusalNamesEveryColumn(): void {
		$this->assertSame(
			'The column mapping refused this row. source column "a": bad; source column "b": worse',
			ImportRowPayload::mappingRefusal(
				[
					['source' => 'a', 'message' => 'bad'],
					['source' => 'b', 'message' => 'worse'],
				]
			)
		);
	}

	public function testAnUpdateTargetsTheMatchedUuid(): void {
		$object = ImportRowPayload::withTargetId(
			['title' => 't'],
			['decision' => ImportPreviewRow::DECISION_UPDATE, 'reason' => null, 'targetUuid' => 'match-1']
		);

		$this->assertSame(['title' => 't', '@self' => ['id' => 'match-1']], $object);
	}

	public function testACreateGetsAFreshUuidUpFront(): void {
		$decision = ['decision' => ImportPreviewRow::DECISION_CREATE, 'reason' => null, 'targetUuid' => null];
		$first    = ImportRowPayload::withTargetId(['title' => 't'], $decision);
		$second   = ImportRowPayload::withTargetId(['title' => 't'], $decision);

		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first['@self']['id']);
		$this->assertNotSame($first['@self']['id'], $second['@self']['id']);
	}

	public function testASkipOrRefusalLeavesTheObjectUntouched(): void {
		foreach ([ImportPreviewRow::DECISION_SKIP, ImportPreviewRow::DECISION_REFUSE] as $decision) {
			$this->assertSame(
				['title' => 't'],
				ImportRowPayload::withTargetId(['title' => 't'], ['decision' => $decision, 'reason' => 'r', 'targetUuid' => 'x'])
			);
		}
	}

	public function testPayloadsAreReadInBatchOrderWithNullAsEmpty(): void {
		$this->assertSame(
			[['id' => 'a'], [], ['id' => 'c']],
			ImportRowPayload::payloadsOf([$this->row(['id' => 'a']), $this->row(null), $this->row(['id' => 'c'])])
		);
	}

	public function testTheBatchKeyIsFoundByAnyIdShape(): void {
		$batch = [
			5 => $this->row(['@self' => ['id' => 'one']]),
			9 => $this->row(['uuid' => 'two']),
			11 => $this->row(['id' => 'three']),
		];

		$this->assertSame(5, ImportRowPayload::batchKeyOf($batch, ['id' => 'one']));
		$this->assertSame(9, ImportRowPayload::batchKeyOf($batch, ['@self' => ['id' => 'two']]));
		$this->assertSame(11, ImportRowPayload::batchKeyOf($batch, ['uuid' => 'three']));
	}

	public function testAnObjectWithoutIdOrUnknownIdHasNoBatchKey(): void {
		$batch = [0 => $this->row([]), 1 => $this->row(['id' => 'a'])];

		$this->assertNull(ImportRowPayload::batchKeyOf($batch, ['title' => 'no id']));
		$this->assertNull(ImportRowPayload::batchKeyOf($batch, ['id' => 'b']));
	}
}
