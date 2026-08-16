<?php

/**
 * The streaming bulk-upsert loop reports what the save actually did.
 *
 * `saveObjectsStreaming()` used to classify each row from the SHAPE OF THE INPUT
 * — "the row carried a uuid, so call it an update" — and said so in a comment
 * that also declared the `unchanged` bucket a future enhancement needing "a deep
 * diff against the previous state". Both halves were wrong. The guess misreports
 * a create whenever a client supplies the uuid for a new object, and no diff was
 * ever needed: `handleObjectUpdate()` already clones the pre-update state for the
 * audit trail, and `handleObjectCreation()` already reads the mapper's write
 * action. The verdict existed; nothing carried it to the caller.
 *
 * That left `BatchOperationStatus::recordUnchanged()` implemented, tested by
 * calling it directly, and reachable from no production path — gate-57's
 * orphaned-write-capability, and the fleet's most common orphan shape: not dead
 * code, a missing branch on a consumer that was already wired.
 *
 * These tests pin the routing table without a database, by stubbing `saveObject`
 * and having it publish a verdict the way the real terminal paths do.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\SaveObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Row-outcome classification in the streaming bulk-upsert primitive.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject
 */
class SaveObjectStreamingOutcomeTest extends TestCase {

	/**
	 * A SaveObject whose `saveObject()` is stubbed to publish a given verdict.
	 *
	 * @param array<int, string|null> $verdicts One verdict per row, in order;
	 *                                          null leaves the slot unset so the
	 *                                          fallback path is exercised.
	 *
	 * @return SaveObject The instrumented handler.
	 */
	private function handlerYielding(array $verdicts): SaveObject {
		// A partial mock does not run the real constructor, which is what makes
		// this reachable without the twenty collaborators a real SaveObject
		// needs. The loop under test touches only the status object, the
		// reference cache and this one method.
		$handler = $this->createPartialMock(SaveObject::class, ['saveObject']);

		$slot = new ReflectionProperty(SaveObject::class, 'lastSaveOutcome');
		$slot->setAccessible(true);

		$call = 0;
		$handler->method('saveObject')->willReturnCallback(
			function () use ($handler, $slot, $verdicts, &$call): ObjectEntity {
				$verdict = ($verdicts[$call] ?? null);
				$call++;

				// The real terminal paths write the slot at the END of the save;
				// the stub does the same so the loop sees it exactly as it would
				// in production.
				if ($verdict !== null) {
					$slot->setValue($handler, $verdict);
				}

				$entity = new ObjectEntity();
				$entity->setUuid('uuid-' . $call);

				return $entity;
			}
		);

		return $handler;
	}//end handlerYielding()

	/**
	 * THE MISSING BRANCH. A row the save path resolved as `unchanged` lands in
	 * the unchanged bucket — the first production caller
	 * `BatchOperationStatus::recordUnchanged()` has ever had.
	 *
	 * @return void
	 */
	public function testAnUnchangedRowIsRecordedAsUnchanged(): void {
		$handler = $this->handlerYielding(verdicts: ['unchanged']);

		$status = $handler->saveObjectsStreaming(
			register: 1,
			schema: 1,
			rows: [['id' => 'uuid-1', 'title' => 'same as stored']]
		);

		$this->assertSame(['uuid-1'], $status->getUnchanged());
		$this->assertSame([], $status->getUpdated());
		$this->assertSame([], $status->getCreated());

	}//end testAnUnchangedRowIsRecordedAsUnchanged()

	/**
	 * THE MISREPORT the old heuristic produced: a row that SUPPLIES a uuid for
	 * an object that does not exist yet is a create. Shape-of-input said
	 * "update"; the save path says "created", and the save path is right.
	 *
	 * @return void
	 */
	public function testARowSupplyingAUuidForANewObjectIsRecordedAsCreated(): void {
		$handler = $this->handlerYielding(verdicts: ['created']);

		$status = $handler->saveObjectsStreaming(
			register: 1,
			schema: 1,
			rows: [['id' => 'client-chosen-uuid', 'title' => 'brand new']]
		);

		$this->assertSame(['uuid-1'], $status->getCreated());
		$this->assertSame([], $status->getUpdated());

	}//end testARowSupplyingAUuidForANewObjectIsRecordedAsCreated()

	/**
	 * The positive control for the update bucket, so the two tests above are not
	 * satisfied by a loop that never records an update at all.
	 *
	 * @return void
	 */
	public function testAGenuineUpdateIsStillRecordedAsUpdated(): void {
		$handler = $this->handlerYielding(verdicts: ['updated']);

		$status = $handler->saveObjectsStreaming(
			register: 1,
			schema: 1,
			rows: [['id' => 'uuid-1', 'title' => 'changed']]
		);

		$this->assertSame(['uuid-1'], $status->getUpdated());
		$this->assertSame([], $status->getUnchanged());

	}//end testAGenuineUpdateIsStillRecordedAsUpdated()

	/**
	 * All three buckets in one batch. A per-row slot that leaked between
	 * iterations would show up here as three identical verdicts.
	 *
	 * @return void
	 */
	public function testEachRowIsClassifiedIndependently(): void {
		$handler = $this->handlerYielding(verdicts: ['created', 'unchanged', 'updated']);

		$status = $handler->saveObjectsStreaming(
			register: 1,
			schema: 1,
			rows: [['title' => 'a'], ['id' => 'b'], ['id' => 'c']]
		);

		$this->assertSame(['uuid-1'], $status->getCreated());
		$this->assertSame(['uuid-2'], $status->getUnchanged());
		$this->assertSame(['uuid-3'], $status->getUpdated());

	}//end testEachRowIsClassifiedIndependently()

	/**
	 * THE STALE-SLOT GUARD. Row 1 publishes `unchanged`; row 2 publishes
	 * nothing. Row 2 must fall back to a verdict derived from ITS OWN input
	 * (it carries a uuid, so `updated`) rather than inheriting row 1's answer.
	 * Remove the `lastSaveOutcome = null` reset at the top of the loop and this
	 * is the test that goes red.
	 *
	 * @return void
	 */
	public function testAnUnsetVerdictFallsBackInsteadOfInheritingThePreviousRow(): void {
		$handler = $this->handlerYielding(verdicts: ['unchanged', null]);

		$status = $handler->saveObjectsStreaming(
			register: 1,
			schema: 1,
			rows: [['id' => 'a'], ['id' => 'b']]
		);

		$this->assertSame(['uuid-1'], $status->getUnchanged());
		$this->assertSame(['uuid-2'], $status->getUpdated());

	}//end testAnUnsetVerdictFallsBackInsteadOfInheritingThePreviousRow()
}//end class
