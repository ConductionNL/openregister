<?php

/**
 * FileDigestMapper closes a file's open digest records, and FileDigest serialises them.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-files-digest-is-recorded-and-sealed-when-it-becomes-public-req-pfd-001
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\FileDigest;
use OCA\OpenRegister\Db\FileDigestMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\FileDigestMapper
 * @covers \OCA\OpenRegister\Db\FileDigest
 * @uses   \OCA\OpenRegister\Db\TypedFieldsJson
 */
class FileDigestMapperTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private function mapper(): FileDigestMapper {
		$this->queryLog = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		return new FileDigestMapper($db);
	}//end mapper()

	public function testPublishingAgainClosesEveryOpenRecordButTheNewOne(): void {
		$closed = $this->mapper()->closeOpenForFile(fileId: 42, at: new DateTime('2026-11-13 10:00:00'), keepId: 9);

		$this->assertSame(1, $closed);
		$this->assertSame(
			[
				'#1 update(openregister_file_digests, NULL)',
				'#1 param :p1 = DateTime, datetime',
				'#1 set(unpublished_at, :p1)',
				'#1 param :p2 = 42, 1',
				'#1 expr.eq(file_id, :p2, NULL)',
				'#1 where(eq(file_id, :p2, NULL))',
				'#1 expr.isNull(unpublished_at)',
				'#1 andWhere(isNull(unpublished_at))',
				'#1 param :p3 = 9, 1',
				'#1 expr.neq(id, :p3, NULL)',
				'#1 andWhere(neq(id, :p3, NULL))',
				'#1 executeStatement()',
			],
			$this->queryLog
		);
	}//end testPublishingAgainClosesEveryOpenRecordButTheNewOne()

	public function testUnpublishingClosesEveryOpenRecord(): void {
		$this->mapper()->closeOpenForFile(fileId: 42, at: new DateTime());

		$this->assertNotContains('#1 expr.neq(id, :p3, NULL)', $this->queryLog);
		$this->assertSame('#1 andWhere(isNull(unpublished_at))', $this->queryLog[7]);
		$this->assertSame('#1 executeStatement()', $this->queryLog[8]);
		$this->assertCount(9, $this->queryLog);
	}//end testUnpublishingClosesEveryOpenRecord()

	public function testARecordSerialisesItsDigestAndDates(): void {
		$record = new FileDigest();
		$record->setFileId(42);
		$record->setSha256(hash('sha256', 'x'));
		$record->setSize(1);
		$record->setPublishedAt(new DateTime('2026-11-13T10:00:00+00:00'));

		$json = $record->jsonSerialize();

		$this->assertSame(42, $json['fileId']);
		$this->assertSame(hash('sha256', 'x'), $json['sha256']);
		$this->assertSame(1, $json['size']);
		$this->assertSame('2026-11-13T10:00:00+00:00', $json['publishedAt']);
		$this->assertNull($json['unpublishedAt']);
	}//end testARecordSerialisesItsDigestAndDates()
}//end class
