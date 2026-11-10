<?php

/**
 * ObjectRelationMapper finds, creates, saves and deletes relation edges.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\ObjectRelation;
use OCA\OpenRegister\Db\ObjectRelationMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\ObjectRelationMapper
 * @uses   \OCA\OpenRegister\Db\ObjectRelation
 * @uses   \OCA\OpenRegister\Db\HydrateBySettersTrait
 * @uses   \OCA\OpenRegister\Db\TypedFieldsJsonTrait
 */
class ObjectRelationMapperBehaviourTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * A mapper over the recording query builder.
	 *
	 * @return ObjectRelationMapper
	 */
	private function mapper(): ObjectRelationMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		return new ObjectRelationMapper($db);
	}//end mapper()

	/**
	 * The whole query log as one string.
	 *
	 * @return string
	 */
	private function log(): string {
		return implode("\n", $this->queryLog);
	}//end log()

	/**
	 * findBySource with a kind narrows to that kind and hydrates the rows.
	 *
	 * @return void
	 */
	public function testFindBySourceNarrowsToTheKind(): void {
		$this->queuedRows = [[['id' => 1, 'source_uuid' => 's-1', 'target_uuid' => 't-1', 'kind' => 'object']]];

		$rows = $this->mapper()->findBySource('s-1', 'object');

		$this->assertCount(1, $rows);
		$this->assertSame('t-1', $rows[0]->getTargetUuid());
		$log = $this->log();
		$this->assertStringContainsString('eq(source_uuid', $log);
		$this->assertStringContainsString('eq(kind', $log);
		$this->assertStringContainsString('param :p2 = object, 2', $log);
		$this->assertStringContainsString('orderBy(id, ASC)', $log);
	}//end testFindBySourceNarrowsToTheKind()

	/**
	 * findBySource without a kind returns every kind.
	 *
	 * @return void
	 */
	public function testFindBySourceWithoutKindIsNotNarrowed(): void {
		$this->queuedRows = [[]];

		$this->assertSame([], $this->mapper()->findBySource('s-1'));
		$this->assertStringNotContainsString('kind', $this->log());
	}//end testFindBySourceWithoutKindIsNotNarrowed()

	/**
	 * findByTarget matches on the target uuid.
	 *
	 * @return void
	 */
	public function testFindByTargetMatchesTheTarget(): void {
		$this->queuedRows = [[['id' => 2, 'source_uuid' => 's-2', 'target_uuid' => 't-2']]];

		$rows = $this->mapper()->findByTarget('t-2');

		$this->assertSame('s-2', $rows[0]->getSourceUuid());
		$this->assertStringContainsString('eq(target_uuid, :p1', $this->log());
		$this->assertStringContainsString('param :p1 = t-2, 2', $this->log());
	}//end testFindByTargetMatchesTheTarget()

	/**
	 * findTouching matches either end against the one uuid list.
	 *
	 * @return void
	 */
	public function testFindTouchingMatchesEitherEnd(): void {
		$this->queuedRows = [[['id' => 3], ['id' => 4]]];

		$rows = $this->mapper()->findTouching(['a', 'b']);

		$this->assertCount(2, $rows);
		$log = $this->log();
		$this->assertStringContainsString('in(source_uuid, :p1', $log);
		$this->assertStringContainsString('in(target_uuid, :p1', $log);
		$this->assertStringContainsString('orX(', $log);
	}//end testFindTouchingMatchesEitherEnd()

	/**
	 * findTouching of nothing queries nothing.
	 *
	 * @return void
	 */
	public function testFindTouchingOfNothingDoesNotQuery(): void {
		$this->assertSame([], $this->mapper()->findTouching([]));
		$this->assertSame([], $this->queryLog);
	}//end testFindTouchingOfNothingDoesNotQuery()

	/**
	 * findByUuid returns the one edge.
	 *
	 * @return void
	 */
	public function testFindByUuidReturnsTheEdge(): void {
		$this->queuedRows = [[['id' => 5, 'uuid' => 'e-5', 'label' => 'owns']]];

		$edge = $this->mapper()->findByUuid('e-5');

		$this->assertSame('owns', $edge->getLabel());
		$this->assertStringContainsString('eq(uuid', $this->log());
	}//end testFindByUuidReturnsTheEdge()

	/**
	 * findByUuid of an unknown edge throws.
	 *
	 * @return void
	 */
	public function testFindByUuidOfAnUnknownEdgeThrows(): void {
		$this->queuedRows = [[]];

		$this->expectException(DoesNotExistException::class);

		$this->mapper()->findByUuid('missing');
	}//end testFindByUuidOfAnUnknownEdgeThrows()

	/**
	 * findByAnchor reads only prose-origin edges at the anchor.
	 *
	 * @return void
	 */
	public function testFindByAnchorReadsOnlyProseEdges(): void {
		$this->queuedRows = [[['id' => 6, 'anchor' => 'p1']]];

		$rows = $this->mapper()->findByAnchor('s-1', 'p1');

		$this->assertSame('p1', $rows[0]->getAnchor());
		$log = $this->log();
		$this->assertStringContainsString('eq(anchor', $log);
		$this->assertStringContainsString('eq(origin', $log);
		$this->assertStringContainsString('param :p3 = prose, 2', $log);
	}//end testFindByAnchorReadsOnlyProseEdges()

	/**
	 * A new edge gets a uuid, and created and updated from one clock reading.
	 *
	 * @return void
	 */
	public function testCreateFromArrayStampsANewEdge(): void {
		$edge = $this->mapper()->createFromArray(['sourceUuid' => 's-1', 'targetUuid' => 't-1']);

		$this->assertSame('s-1', $edge->getSourceUuid());
		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string)$edge->getUuid());
		$this->assertInstanceOf(DateTime::class, $edge->getCreated());
		$this->assertSame($edge->getCreated(), $edge->getUpdated());
		$this->assertStringContainsString('insert(openregister_object_relations', $this->log());
	}//end testCreateFromArrayStampsANewEdge()

	/**
	 * A given uuid and created are kept; updated is still stamped now.
	 *
	 * @return void
	 */
	public function testCreateFromArrayKeepsGivenUuidAndCreated(): void {
		$created = new DateTime('2025-01-02T03:04:05+00:00');

		$edge = $this->mapper()->createFromArray(['uuid' => 'given', 'created' => $created]);

		$this->assertSame('given', $edge->getUuid());
		$this->assertSame($created, $edge->getCreated());
		$this->assertNotSame($created, $edge->getUpdated());
		$this->assertGreaterThan($created, $edge->getUpdated());
	}//end testCreateFromArrayKeepsGivenUuidAndCreated()

	/**
	 * save stamps updated and writes the changed edge.
	 *
	 * @return void
	 */
	public function testSaveStampsUpdated(): void {
		$edge = new ObjectRelation();
		$edge->setId(7);
		$edge->resetUpdatedFields();

		$saved = $this->mapper()->save($edge);

		$this->assertInstanceOf(DateTime::class, $saved->getUpdated());
		$this->assertStringContainsString('update(openregister_object_relations', $this->log());
	}//end testSaveStampsUpdated()

	/**
	 * deleteByAnchor deletes only prose edges at the anchor.
	 *
	 * @return void
	 */
	public function testDeleteByAnchorDeletesOnlyProseEdges(): void {
		$this->assertSame(1, $this->mapper()->deleteByAnchor('s-1', 'p1'));

		$log = $this->log();
		$this->assertStringContainsString('delete(openregister_object_relations', $log);
		$this->assertStringContainsString('eq(anchor', $log);
		$this->assertStringContainsString('param :p3 = prose, 2', $log);
	}//end testDeleteByAnchorDeletesOnlyProseEdges()

	/**
	 * deleteByObject deletes edges at either end of the object.
	 *
	 * @return void
	 */
	public function testDeleteByObjectDeletesEitherEnd(): void {
		$this->assertSame(1, $this->mapper()->deleteByObject('o-1'));

		$log = $this->log();
		$this->assertStringContainsString('eq(source_uuid', $log);
		$this->assertStringContainsString('eq(target_uuid', $log);
		$this->assertStringContainsString('where(orX(', $log);
	}//end testDeleteByObjectDeletesEitherEnd()
}//end class
