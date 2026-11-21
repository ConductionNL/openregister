<?php

/**
 * Unit tests for three small mappers: SubjectExportMapper, FeedbackMapper
 * and SchemaChangelogMapper — their reads, the defaults their writes fill
 * in, and the timestamps they stamp.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\Feedback;
use OCA\OpenRegister\Db\FeedbackMapper;
use OCA\OpenRegister\Db\HydrateBySettersTrait;
use OCA\OpenRegister\Db\HydrateEmptyJsonAsNullTrait;
use OCA\OpenRegister\Db\JsonFieldsTrait;
use OCA\OpenRegister\Db\QueryPage;
use OCA\OpenRegister\Db\SchemaChangelog;
use OCA\OpenRegister\Db\SchemaChangelogMapper;
use OCA\OpenRegister\Db\SubjectExport;
use OCA\OpenRegister\Db\SubjectExportMapper;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the subject export, feedback and schema changelog mappers.
 */
#[CoversClass(SubjectExportMapper::class)]
#[CoversClass(FeedbackMapper::class)]
#[CoversClass(SchemaChangelogMapper::class)]
#[UsesClass(SubjectExport::class)]
#[UsesClass(Feedback::class)]
#[UsesClass(SchemaChangelog::class)]
#[UsesClass(HydrateBySettersTrait::class)]
#[UsesClass(HydrateEmptyJsonAsNullTrait::class)]
#[UsesClass(JsonFieldsTrait::class)]
#[UsesClass(QueryPage::class)]
#[UsesClass(ListQuery::class)]
class SmallMappersTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * A connection that hands out recording builders.
	 *
	 * @return IDBConnection The connection.
	 */
	private function db(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());

		return $db;
	}//end db()

	/**
	 * An export is read by id or uuid, and a missing one throws.
	 *
	 * @return void
	 */
	public function testSubjectExportReads(): void {
		$this->queuedRows[] = [['id' => 3, 'uuid' => 'u-3', 'status' => 'ready']];
		$mapper = new SubjectExportMapper($this->db());

		$this->assertSame('u-3', $mapper->find(3)->getUuid());
		$this->assertContains('#1 param :p1 = 3, 1', $this->queryLog);
		$this->assertContains('#1 where(eq(id, :p1, NULL))', $this->queryLog);

		$this->expectException(DoesNotExistException::class);
		$mapper->findByUuid('u-none');
	}//end testSubjectExportReads()

	/**
	 * A new export is pending, has a uuid and both timestamps; a given status is kept.
	 *
	 * @return void
	 */
	public function testSubjectExportCreateFillsDefaults(): void {
		$mapper = new SubjectExportMapper($this->db());

		$export = $mapper->createFromArray(['subject' => 'bsn:123', 'status' => null]);
		$this->assertSame(SubjectExport::STATUS_PENDING, $export->getStatus());
		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $export->getUuid());
		$this->assertSame($export->getCreated(), $export->getUpdated());
		$this->assertContains('#1 insert(openregister_subject_exports)', $this->queryLog);

		$ready = $mapper->createFromArray(['uuid' => 'u-1', 'status' => SubjectExport::STATUS_READY]);
		$this->assertSame('u-1', $ready->getUuid());
		$this->assertSame(SubjectExport::STATUS_READY, $ready->getStatus());
	}//end testSubjectExportCreateFillsDefaults()

	/**
	 * Saving an export stamps a new updated time.
	 *
	 * @return void
	 */
	public function testSubjectExportSaveStampsUpdated(): void {
		$export = new SubjectExport();
		$export->setId(3);
		$export->setUpdated(new DateTime('2020-01-01T00:00:00+00:00'));
		$export->resetUpdatedFields();

		$saved = (new SubjectExportMapper($this->db()))->save($export);

		$this->assertGreaterThan(new DateTime('2021-01-01'), $saved->getUpdated());
		$this->assertContains('#1 update(openregister_subject_exports, NULL)', $this->queryLog);
	}//end testSubjectExportSaveStampsUpdated()

	/**
	 * Inserted feedback gets a uuid and timestamps; an update stamps updated only.
	 *
	 * @return void
	 */
	public function testFeedbackWritesStampTimes(): void {
		$mapper   = new FeedbackMapper($this->db());
		$created  = new DateTime('2020-01-01T00:00:00+00:00');
		$feedback = new Feedback();
		$feedback->setCreated($created);

		$inserted = $mapper->insert($feedback);
		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $inserted->getUuid());
		$this->assertSame($created, $inserted->getCreated());
		$this->assertGreaterThan($created, $inserted->getUpdated());

		$kept = new Feedback();
		$kept->setUuid('fixed');
		$this->assertSame('fixed', $mapper->insert($kept)->getUuid());
		$this->assertNotNull($kept->getCreated());

		$kept->setId(9);
		$kept->setUpdated($created);
		$kept->resetUpdatedFields();
		$this->assertGreaterThan($created, $mapper->update($kept)->getUpdated());
		$this->assertContains('#3 update(openregister_feedback, NULL)', $this->queryLog);
	}//end testFeedbackWritesStampTimes()

	/**
	 * Feedback is found per message and user; none reads null; a conversation's feedback is deleted.
	 *
	 * @return void
	 */
	public function testFeedbackReadsAndDeletes(): void {
		$this->queuedRows[] = [['id' => 1, 'uuid' => 'f-1', 'message_id' => 5, 'user_id' => 'anna', 'type' => 'positive']];
		$mapper = new FeedbackMapper($this->db());

		$this->assertSame('positive', $mapper->findByMessage(5, 'anna')?->getType());
		$this->assertContains('#1 where(eq(message_id, :p1, NULL))', $this->queryLog);
		$this->assertContains('#1 andWhere(eq(user_id, :p2, NULL))', $this->queryLog);
		$this->assertNull($mapper->findByMessage(6, 'anna'));

		$mapper->deleteByConversation(12);
		$this->assertContains('#3 delete(openregister_feedback, NULL)', $this->queryLog);
		$this->assertContains('#3 param :p5 = 12, 1', $this->queryLog);
		$this->assertContains('#3 where(eq(conversation_id, :p5, NULL))', $this->queryLog);
		$this->assertContains('#3 executeStatement()', $this->queryLog);
	}//end testFeedbackReadsAndDeletes()

	/**
	 * A schema's changelog is newest first and paged; an unbounded query is not limited.
	 *
	 * @return void
	 */
	public function testChangelogIsNewestFirstAndPaged(): void {
		$mapper = new SchemaChangelogMapper($this->db());

		$mapper->findBySchema(4, new ListQuery(limit: 10, offset: 20));
		$this->assertContains('#1 where(eq(schema_id, :p1, NULL))', $this->queryLog);
		$this->assertContains('#1 orderBy(id, DESC)', $this->queryLog);
		$this->assertContains('#1 setMaxResults(10)', $this->queryLog);
		$this->assertContains('#1 setFirstResult(20)', $this->queryLog);

		$mapper->findBySchema(4, ListQuery::unbounded());
		$this->assertSame([], array_values(array_filter($this->queryLog, static fn (string $l): bool => str_starts_with($l, '#2 set'))));
	}//end testChangelogIsNewestFirstAndPaged()

	/**
	 * A changelog entry is created with a creation time, an empty change list stored as null.
	 *
	 * @return void
	 */
	public function testChangelogCreate(): void {
		$this->queuedRows[] = [['id' => 2, 'schema_id' => 4, 'version' => '1.1.0']];
		$mapper = new SchemaChangelogMapper($this->db());

		$this->assertSame('1.1.0', $mapper->find(2)->getVersion());

		$entry = $mapper->createFromArray(['schemaId' => 4, 'version' => '1.2.0', 'changes' => []]);
		$this->assertNull($entry->getChanges());
		$this->assertInstanceOf(DateTime::class, $entry->getCreated());
		$this->assertContains('#2 insert(openregister_schema_changelog)', $this->queryLog);
	}//end testChangelogCreate()
}//end class
