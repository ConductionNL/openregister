<?php

/**
 * Unit tests for TextSignatureMapper and the TextSignature row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
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

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\TextSignature;
use OCA\OpenRegister\Db\TextSignatureMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\TextSignatureMapper
 * @covers \OCA\OpenRegister\Db\TextSignature
 * @uses   \OCA\OpenRegister\Db\TypedFieldsJson
 */
class TextSignatureMapperTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private function mapper(): TextSignatureMapper {
		$this->queryLog = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		return new TextSignatureMapper($db);
	}//end mapper()

	public function testDeletingAFilesSignatureDeletesByFileId(): void {
		$this->mapper()->deleteByFileId(fileId: 42);

		$this->assertSame(
			[
				'#1 delete(openregister_text_signatures, NULL)',
				'#1 param :p1 = 42, 1',
				'#1 expr.eq(file_id, :p1, NULL)',
				'#1 where(eq(file_id, :p1, NULL))',
				'#1 executeStatement()',
			],
			$this->queryLog
		);
	}//end testDeletingAFilesSignatureDeletesByFileId()

	public function testReplacingDeletesTheOldSignatureBeforeInserting(): void {
		$row = TextSignature::fromRecord(42, ['signature' => [1, 2], 'shingleCount' => 60, 'textSha256' => 'abc'], new DateTime('2026-11-13 10:00:00'));

		$stored = $this->mapper()->replaceForFile($row);

		$this->assertSame($row, $stored);
		$this->assertSame('#1 delete(openregister_text_signatures, NULL)', $this->queryLog[0]);
		$this->assertSame('#1 where(eq(file_id, :p1, NULL))', $this->queryLog[3]);
		$this->assertSame('#1 executeStatement()', $this->queryLog[4]);
		$this->assertSame('#2 insert(openregister_text_signatures)', $this->queryLog[5]);
		$this->assertContains('#2 executeStatement()', $this->queryLog);
		$this->assertSame(
			[
				'#2 param :p2 = 42, 1',
				'#2 setValue(file_id, :p2)',
				'#2 param :p3 = [1,2], 2',
				'#2 setValue(signature, :p3)',
				'#2 param :p4 = 60, 1',
				'#2 setValue(shingle_count, :p4)',
				'#2 param :p5 = abc, 2',
				'#2 setValue(text_sha256, :p5)',
			],
			array_slice($this->queryLog, 6, 8)
		);
	}//end testReplacingDeletesTheOldSignatureBeforeInserting()

	public function testFindingByFileIdsKeysTheRowsByFileIdOnce(): void {
		$this->queuedRows = [[
			['id' => 1, 'file_id' => 7, 'signature' => '[3,4]', 'shingle_count' => 55, 'text_sha256' => 'x', 'created' => '2026-11-13 10:00:00'],
			['id' => 2, 'file_id' => 9, 'signature' => null, 'shingle_count' => 12, 'text_sha256' => 'y', 'created' => '2026-11-13 10:00:00'],
		]];

		$rows = $this->mapper()->findByFileIds([9, 7, 9]);

		$this->assertSame([7, 9], array_keys($rows));
		$this->assertSame([3, 4], $rows[7]->getSignatureValues());
		$this->assertNull($rows[9]->getSignatureValues());
		$this->assertSame(12, $rows[9]->getShingleCount());
		$this->assertContains('#1 param :p1 = [9,7], 101', $this->queryLog);
		$this->assertContains('#1 where(in(file_id, :p1, NULL))', $this->queryLog);
	}//end testFindingByFileIdsKeysTheRowsByFileIdOnce()

	public function testNoFileIdsRunsNoQuery(): void {
		$this->assertSame([], $this->mapper()->findByFileIds([]));
		$this->assertSame([], $this->queryLog);
	}//end testNoFileIdsRunsNoQuery()

	public function testARowSerialisesAndRefusesAMalformedSignature(): void {
		$row = TextSignature::fromRecord(5, ['signature' => null, 'shingleCount' => 0, 'textSha256' => 'e3'], new DateTime('2026-11-13T10:00:00+00:00'));

		$json = $row->jsonSerialize();
		$this->assertSame(5, $json['fileId']);
		$this->assertNull($json['signature']);
		$this->assertSame(0, $json['shingleCount']);
		$this->assertSame('e3', $json['textSha256']);
		$this->assertSame('2026-11-13T10:00:00+00:00', $json['created']);

		foreach (['{"a":1}', '[1,"2"]', 'not json', '3'] as $malformed) {
			$row->setSignature($malformed);
			$this->assertNull($row->getSignatureValues(), $malformed);
		}
	}//end testARowSerialisesAndRefusesAMalformedSignature()
}//end class
