<?php

/**
 * An action history entry without a run links nowhere, and a created value
 * that is not a date is passed through as stored.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionGate;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionHistory;
use PHPUnit\Framework\TestCase;

/**
 * Entry-shape tests for {@see ObjectActionHistory}.
 */
class ObjectActionHistoryEntryTest extends TestCase {

	/**
	 * A history over the given audit rows, readable.
	 *
	 * @param array<int, AuditTrail> $rows The audit rows.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 */
	private function entries(array $rows): array {
		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->method('findAll')->willReturn($rows);
		$gate = $this->createMock(ObjectActionGate::class);
		$gate->method('holds')->willReturn(true);
		$object = new ObjectEntity();
		$object->setUuid('o-1');

		return (new ObjectActionHistory(auditTrail: $audit, gate: $gate))->forObject(object: $object, schema: new Schema());
	}//end entries()

	/**
	 * An audit row of a declared action run.
	 *
	 * @param mixed $run The run reference.
	 *
	 * @return AuditTrail The row.
	 */
	private static function row(mixed $run): AuditTrail {
		$row = new AuditTrail();
		$row->setUuid('a-1');
		$row->setAction('action.run');
		$row->setChanged(['declaredAction' => 'close', 'kind' => 'transition', 'run' => $run, 'outcome' => 'done']);

		return $row;
	}//end row()

	/**
	 * Without a run, or with an empty one, the entry links nowhere; with a
	 * run it links to the flow run. An unset created stays null.
	 *
	 * @return void
	 */
	public function testAnEntryLinksToItsRunOnlyWhenItHasOne(): void {
		[$none, $empty, $linked] = $this->entries(rows: [self::row(run: null), self::row(run: ''), self::row(run: 'r-9')]);

		$this->assertSame([], $none['links']);
		$this->assertSame([], $empty['links']);
		$this->assertSame(['run' => '/api/flow-runs/r-9'], $linked['links']);
		$this->assertNull($none['created']);
		$this->assertSame('transition', $none['kind']);
	}//end testAnEntryLinksToItsRunOnlyWhenItHasOne()
}//end class
