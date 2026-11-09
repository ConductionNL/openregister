<?php

/**
 * A reader's history of an object is not narrowed to the address the route names.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Audit;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Audit\HistoryEntryRedactor;
use OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders;
use OCA\OpenRegister\Service\Audit\ObjectHistoryServices;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Audit\ReadEventVisibility;
use OCA\OpenRegister\Service\Object\ObjectReadAccess;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
final class ObjectHistoryAfterAMoveTest extends TestCase {

	/**
	 * The route's register and schema go, the caller's own filters stay.
	 *
	 * @return void
	 */
	public function testTheRouteAddressDoesNotNarrowTheHistory(): void {
		$seen = null;
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('findAll')->willReturnCallback(
			static function (?int $limit, ?int $offset, array $filters) use (&$seen): array {
				$seen = $filters;
				return [];
			}
		);

		$this->history(audit: $audit)->page(
			register: '5',
			schema: '7',
			id: 'u-1',
			params: ['filters' => ['register' => '5', 'schema' => '7', 'action' => 'update']]
		);

		$this->assertSame(['action' => 'update', 'object_uuid' => 'u-1'], $seen);
	}//end testTheRouteAddressDoesNotNarrowTheHistory()

	/**
	 * The reader path over one readable object.
	 *
	 * @param AuditTrailMapper $audit The audit trail.
	 *
	 * @return ObjectHistoryForReaders
	 */
	private function history(AuditTrailMapper $audit): ObjectHistoryForReaders {
		$object = new ObjectEntity();
		$object->setUuid('u-1');
		$object->setRegister('5');
		$object->setSchema('7');

		$schema = new Schema();
		$schema->setId(7);
		$access = $this->createMock(ObjectReadAccess::class);
		$access->method('readableOrAmbiguous')->willReturn($object);
		$access->method('readable')->willReturn($object);
		$access->method('schemaOf')->willReturn($schema);

		$register = new Register();
		$register->setId(5);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);

		$redactor = $this->createMock(HistoryEntryRedactor::class);
		$redactor->method('redact')->willReturn([]);

		return new ObjectHistoryForReaders(
			$access,
			new ObjectHistoryServices(
				$audit,
				$registers
			),
			$redactor,
			$this->createMock(PurposeGuard::class),
			$this->createMock(ReadEventVisibility::class)
		);
	}//end history()
}//end class
