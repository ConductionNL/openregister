<?php

/**
 * MailHeaderMapperTest
 *
 * One row per file: storing replaces the earlier row inside a transaction,
 * and a failed insert leaves the earlier row in place.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Db;

use OCA\OpenRegister\Files\Db\MailHeader;
use OCA\OpenRegister\Files\Db\MailHeaderMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordsQueries;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Files\Db\MailHeaderMapper
 * @uses   \OCA\OpenRegister\Files\Db\MailHeader
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-collected-mail-files-carry-their-thread-headers-req-rth-010
 */
class MailHeaderMapperTest extends TestCase {
	use RecordsQueries;

	/**
	 * The recording connection.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * The mapper under test.
	 *
	 * @var MailHeaderMapper
	 */
	private MailHeaderMapper $mapper;

	/**
	 * Build the mapper over a recording connection.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->db     = $this->recordingDb();
		$this->mapper = new MailHeaderMapper($this->db);
	}//end setUp()

	/**
	 * Storing deletes the file's earlier row, then inserts, in one transaction.
	 *
	 * @return void
	 */
	public function testStoringReplacesTheFilesRow(): void {
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$stored = $this->mapper->replaceForFile(
			MailHeader::fromHeaders(fileId: 9, headers: ['messageId' => 'a2@x', 'inReplyTo' => 'a1@x', 'references' => ['a1@x']])
		);

		$this->assertSame(42, $stored->getId());
		$this->assertSame('delete(openregister_mail_headers) where(eq(file_id, :p0)) executeStatement()', $this->sql(0));
		$this->assertSame([9, IQueryBuilder::PARAM_INT], $this->params[':p0']);
		$this->assertStringStartsWith('insert(openregister_mail_headers)', $this->sql(1));
		$this->assertContains('a2@x', $this->boundValues());
		$this->assertContains('a1@x', $this->boundValues());
		$this->assertContains('["a1@x"]', $this->boundValues());
	}//end testStoringReplacesTheFilesRow()

	/**
	 * A failed insert rolls back, so the earlier row is not lost.
	 *
	 * @return void
	 */
	public function testAFailedInsertRollsBack(): void {
		$this->outcomes = [1, new RuntimeException('insert failed')];
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->expectExceptionMessage('insert failed');

		$this->mapper->replaceForFile(MailHeader::fromHeaders(fileId: 9, headers: ['messageId' => 'a2@x']));
	}//end testAFailedInsertRollsBack()

	/**
	 * Deleting a file's row filters on its id and answers the count.
	 *
	 * @return void
	 */
	public function testDeleteByFileIdFiltersOnTheFile(): void {
		$this->affected = 1;

		$this->assertSame(1, $this->mapper->deleteByFileId(11));
		$this->assertSame('delete(openregister_mail_headers) where(eq(file_id, :p0)) executeStatement()', $this->sql());
		$this->assertSame([11, IQueryBuilder::PARAM_INT], $this->params[':p0']);
	}//end testDeleteByFileIdFiltersOnTheFile()

	/**
	 * The rows of several files come back as entities, ids deduplicated.
	 *
	 * @return void
	 */
	public function testFindByFileIdsReadsTheRows(): void {
		$this->results[] = [['id' => 1, 'file_id' => 3, 'message_id' => 'a@x'], ['id' => 2, 'file_id' => 5, 'message_id' => 'b@x']];

		$rows = $this->mapper->findByFileIds([5, 3, 5]);

		$this->assertSame(['a@x', 'b@x'], array_map(static fn (MailHeader $row): string => $row->getMessageId(), $rows));
		$this->assertSame(
			'select(*) from(openregister_mail_headers) where(in(file_id, :p0)) orderBy(file_id, ASC) executeQuery()',
			$this->sql()
		);
		$this->assertSame([[5, 3], IQueryBuilder::PARAM_INT_ARRAY], $this->params[':p0']);
	}//end testFindByFileIdsReadsTheRows()

	/**
	 * No files, no query.
	 *
	 * @return void
	 */
	public function testNoFilesIsNoQuery(): void {
		$this->assertSame([], $this->mapper->findByFileIds([]));
		$this->assertSame([], $this->queries);
	}//end testNoFilesIsNoQuery()
}//end class
