<?php

/**
 * Unit test for ExampleSetChangeReadBack's revert of set fields: an old value
 * is put back, a new field goes, and a map the change made on the way to its
 * field goes too, but only while nothing else has filled it since.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetChangeFields;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetChangeReadBack;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetPaths;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetStore;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetWrittenValue;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetChangeReadBack
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetPaths
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetWrittenValue
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetProof
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetProofPlace
 */
class ExampleSetChangeReadBackRevertTest extends TestCase {

	/**
	 * Revert one change on the given row and return the outcome and the data written.
	 *
	 * @param array<string, mixed>             $row The stored row.
	 * @param array<int, array<string, mixed>> $set The change's set rows.
	 *
	 * @return array{0: array{ok: bool, left: array<int, string>}, 1: array<string, mixed>|null}
	 */
	private function revert(array $row, array $set): array {
		$store = $this->createMock(ExampleSetStore::class);
		$store->method('readRow')->with(new ObjectRef(register: 'cases', schema: 'case', id: 'c-1'))->willReturn(['row' => $row, 'error' => '']);
		$fields  = $this->createMock(ExampleSetChangeFields::class);
		$fields->method('textFields')->willReturn([]);
		$written = null;
		$fields->method('write')->willReturnCallback(
			function (array $object, array $data) use (&$written): string {
				$written = $data;
				return '';
			}
		);
		$readBack = new ExampleSetChangeReadBack($store, new ExampleSetPaths(), $fields, new ExampleSetWrittenValue());

		$outcome = $readBack->revert(entry: ['register' => 'cases', 'schema' => 'case', 'id' => 'c-1', 'set' => $set, 'append' => []]);
		return [$outcome, $written];
	}//end revert()

	/**
	 * A new field goes, and the map the change made on the way to it goes too when it is left empty.
	 *
	 * @return void
	 */
	public function testAMapTheChangeMadeGoesWhenLeftEmpty(): void {
		[$outcome, $written] = $this->revert(
			row: ['id' => 'c-1', 'meta' => ['labels' => ['urgent' => 'yes']], 'title' => 'Zaak'],
			set: [['path' => 'meta.labels.urgent', 'had' => false, 'old' => null, 'new' => 'yes', 'from' => 'meta.labels']]
		);

		$this->assertSame(['ok' => true, 'left' => []], $outcome);
		$this->assertSame(['id' => 'c-1', 'meta' => [], 'title' => 'Zaak'], $written);
	}//end testAMapTheChangeMadeGoesWhenLeftEmpty()

	/**
	 * A map something else filled since the change stays, without the change's field.
	 *
	 * @return void
	 */
	public function testAMapFilledSinceStays(): void {
		[, $written] = $this->revert(
			row: ['id' => 'c-1', 'meta' => ['labels' => ['urgent' => 'yes', 'owner' => 'anna']]],
			set: [['path' => 'meta.labels.urgent', 'had' => false, 'old' => null, 'new' => 'yes', 'from' => 'meta.labels']]
		);

		$this->assertSame(['id' => 'c-1', 'meta' => ['labels' => ['owner' => 'anna']]], $written);
	}//end testAMapFilledSinceStays()

	/**
	 * A field the object had gets its old value back; a field changed since is left and named.
	 *
	 * @return void
	 */
	public function testAnOldValueIsPutBackAndAChangedOneLeft(): void {
		[$outcome, $written] = $this->revert(
			row: ['id' => 'c-1', 'status' => 'open', 'title' => 'Edited by hand'],
			set: [
				['path' => 'status', 'had' => true, 'old' => 'new', 'new' => 'open'],
				['path' => 'title', 'had' => true, 'old' => 'Zaak', 'new' => 'Zaak 1'],
			]
		);

		$this->assertSame(['ok' => true, 'left' => ['title']], $outcome);
		$this->assertSame(['id' => 'c-1', 'title' => 'Edited by hand', 'status' => 'new'], $written);
	}//end testAnOldValueIsPutBackAndAChangedOneLeft()
}//end class
