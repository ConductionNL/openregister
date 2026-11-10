<?php

/**
 * Behaviour test for ErasurePreviewMapper, ErasurePreview and AccessLinkMapper.
 *
 * Asserts which filters the lookups bind, that a missing or ambiguous access
 * link reads as null, the defaults an erasure preview is created with, and that
 * only an approved, unconsumed preview may run.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\AccessLinkMapper;
use OCA\OpenRegister\Db\ErasurePreview;
use OCA\OpenRegister\Db\ErasurePreviewMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class ErasurePreviewAndAccessLinkMapperBehaviourTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private function db(array $rows): IDBConnection {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $rows;
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		return $db;
	}

	private function utc(string $time): DateTime {
		return new DateTime($time, new DateTimeZone('UTC'));
	}

	public function testAccessLinkFindByAnchorReturnsTheMatch(): void {
		$mapper = new AccessLinkMapper($this->db([[['id' => 4, 'uuid' => 'l4', 'anchor' => 'abc']]]));
		$this->assertSame('l4', $mapper->findByAnchor('abc')?->getUuid());
		$this->assertContains('#1 param :p1 = abc, 2', $this->queryLog);
		$this->assertContains('#1 expr.eq(anchor, :p1, NULL)', $this->queryLog);
	}

	public function testAccessLinkLookupsReadAMissAsNull(): void {
		$this->assertNull((new AccessLinkMapper($this->db([[]])))->findByAnchor('x'));
		$this->assertNull((new AccessLinkMapper($this->db([[]])))->findByUuid('x'));
		$this->assertNull((new AccessLinkMapper($this->db([[]])))->findById(3));
	}

	public function testAccessLinkLookupsReadAnAmbiguousMatchAsNull(): void {
		$rows = [['id' => 1, 'uuid' => 'dup'], ['id' => 2, 'uuid' => 'dup']];
		$this->assertNull((new AccessLinkMapper($this->db([$rows])))->findByUuid('dup'));
	}

	public function testAccessLinkFindByUuidAndByIdBindTheirKey(): void {
		$mapper = new AccessLinkMapper($this->db([[['id' => 5, 'uuid' => 'l5']], [['id' => 6, 'uuid' => 'l6']]]));
		$this->assertSame(5, $mapper->findByUuid('l5')?->getId());
		$this->assertContains('#1 expr.eq(uuid, :p1, NULL)', $this->queryLog);
		$this->assertSame('l6', $mapper->findById(6)?->getUuid());
		$this->assertContains('#2 param :p2 = 6, 1', $this->queryLog);
		$this->assertContains('#2 expr.eq(id, :p2, NULL)', $this->queryLog);
	}

	public function testAccessLinkFindByCreatorAndBySubjectFilterAndOrder(): void {
		$rows = [['id' => 9, 'uuid' => 'a'], ['id' => 8, 'uuid' => 'b']];
		$mapper = new AccessLinkMapper($this->db([$rows, $rows]));

		$byCreator = $mapper->findByCreator('alice');
		$this->assertSame(['a', 'b'], array_map(static fn (AccessLink $l): string => $l->getUuid(), $byCreator));
		$this->assertContains('#1 expr.eq(created_by, :p1, NULL)', $this->queryLog);
		$this->assertContains('#1 orderBy(id, DESC)', $this->queryLog);

		$this->assertCount(2, $mapper->findBySubject('object', 'o-1'));
		$this->assertContains('#2 param :p2 = object, 2', $this->queryLog);
		$this->assertContains('#2 param :p3 = o-1, 2', $this->queryLog);
		$this->assertContains('#2 expr.eq(subject_id, :p3, NULL)', $this->queryLog);
	}

	public function testErasurePreviewFindAndFindByUuidBindTheirKey(): void {
		$mapper = new ErasurePreviewMapper($this->db([[['id' => 2, 'uuid' => 'p2']], [['id' => 3, 'uuid' => 'p3']]]));
		$this->assertSame('p2', $mapper->find(2)->getUuid());
		$this->assertContains('#1 param :p1 = 2, 1', $this->queryLog);
		$this->assertSame(3, $mapper->findByUuid('p3')->getId());
		$this->assertContains('#2 expr.eq(uuid, :p2, NULL)', $this->queryLog);
	}

	public function testErasurePreviewFindThrowsWhenAbsent(): void {
		$mapper = new ErasurePreviewMapper($this->db([[]]));
		$this->expectException(DoesNotExistException::class);
		$mapper->find(99);
	}

	public function testErasurePreviewFindBySubjectWithoutStatusDoesNotFilterOnStatus(): void {
		$mapper = new ErasurePreviewMapper($this->db([[['id' => 1, 'uuid' => 'p1']]]));
		$this->assertCount(1, $mapper->findBySubject('alice'));
		$this->assertContains('#1 expr.eq(subject, :p1, NULL)', $this->queryLog);
		$this->assertSame(1, $this->parameterCount);
	}

	public function testErasurePreviewFindBySubjectWithStatusAddsTheFilter(): void {
		$mapper = new ErasurePreviewMapper($this->db([[]]));
		$this->assertSame([], $mapper->findBySubject('alice', 'approved'));
		$this->assertContains('#1 param :p2 = approved, 2', $this->queryLog);
		$this->assertContains('#1 andWhere(eq(status, :p2, NULL))', $this->queryLog);
	}

	public function testCreateFromArrayFillsUuidPendingStatusAndTimestamps(): void {
		$before = new DateTime();
		$mapper = new ErasurePreviewMapper($this->db([]));
		$preview = $mapper->createFromArray(['subject' => 'alice', 'eraseMode' => 'hard']);

		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $preview->getUuid());
		$this->assertSame(ErasurePreview::STATUS_PENDING, $preview->getStatus());
		$this->assertSame('alice', $preview->getSubject());
		$this->assertGreaterThanOrEqual($before, $preview->getCreated());
		$this->assertSame($preview->getCreated(), $preview->getUpdated());
		$this->assertContains('#1 insert(openregister_erasure_previews)', $this->queryLog);
	}

	public function testCreateFromArrayKeepsAGivenUuidStatusAndCreated(): void {
		$created = $this->utc('2026-01-01 10:00:00');
		$mapper = new ErasurePreviewMapper($this->db([]));
		$preview = $mapper->createFromArray(['uuid' => 'fixed', 'status' => 'approved', 'created' => $created]);

		$this->assertSame('fixed', $preview->getUuid());
		$this->assertSame('approved', $preview->getStatus());
		$this->assertSame('2026-01-01 10:00:00', $preview->getCreated()->format('Y-m-d H:i:s'));
		$this->assertNotSame($preview->getCreated(), $preview->getUpdated());
	}

	public function testSaveStampsUpdatedAndWrites(): void {
		$preview = new ErasurePreview();
		$preview->setId(5);
		$preview->setStatus('approved');
		$preview->setUpdated($this->utc('2000-01-01'));
		$saved = (new ErasurePreviewMapper($this->db([])))->save($preview);

		$this->assertGreaterThan($this->utc('2020-01-01'), $saved->getUpdated());
		$this->assertContains('#1 update(openregister_erasure_previews, NULL)', $this->queryLog);
		$this->assertContains('#1 executeStatement()', $this->queryLog);
	}

	public function testOnlyAnApprovedUnconsumedPreviewIsRunnable(): void {
		$preview = new ErasurePreview();
		$this->assertFalse($preview->isRunnable());

		$preview->setStatus(ErasurePreview::STATUS_APPROVED);
		$this->assertTrue($preview->isRunnable());

		$preview->setConsumedAt($this->utc('2026-01-01'));
		$this->assertFalse($preview->isRunnable());
	}

	public function testAPreviewSerialisesEveryFieldWithAtomDates(): void {
		$preview = new ErasurePreview();
		$preview->setId(7);
		$preview->setUuid('u7');
		$preview->setSubject('alice');
		$preview->setSubjectType('user');
		$preview->setEraseMode('hard');
		$preview->setRequestId('req-1');
		$preview->setDigest('sha');
		$preview->setReport(['objects' => 2]);
		$preview->setOutcome(['erased' => 2]);
		$preview->setStatus('consumed');
		$preview->setCreatedBy('dpo');
		$preview->setApprovedBy('boss');
		$preview->setApprovedAt($this->utc('2026-01-02 00:00:00'));
		$preview->setConsumedAt($this->utc('2026-01-03 00:00:00'));
		$preview->setCreated($this->utc('2026-01-01 00:00:00'));
		$preview->setUpdated($this->utc('2026-01-04 00:00:00'));

		$this->assertSame(
			[
				'id' => 7,
				'uuid' => 'u7',
				'subject' => 'alice',
				'subjectType' => 'user',
				'eraseMode' => 'hard',
				'requestId' => 'req-1',
				'digest' => 'sha',
				'report' => ['objects' => 2],
				'outcome' => ['erased' => 2],
				'status' => 'consumed',
				'createdBy' => 'dpo',
				'approvedBy' => 'boss',
				'approvedAt' => '2026-01-02T00:00:00+00:00',
				'consumedAt' => '2026-01-03T00:00:00+00:00',
				'created' => '2026-01-01T00:00:00+00:00',
				'updated' => '2026-01-04T00:00:00+00:00',
			],
			$preview->jsonSerialize()
		);
	}

	public function testAnEmptyPreviewSerialisesEmptyReportsAndNullDates(): void {
		$json = (new ErasurePreview())->jsonSerialize();
		$this->assertSame([], $json['report']);
		$this->assertSame([], $json['outcome']);
		$this->assertSame('pending', $json['status']);
		$this->assertNull($json['approvedAt']);
	}
}
