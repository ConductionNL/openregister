<?php

/**
 * An object's history is read by its uuid, the one identity that survives a move.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Tests\Support\AuditTrailListing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Service\Object\GetObject
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 */
class GetObjectFindLogsTest extends TestCase {

	/**
	 * The route's register and schema, and the table's numeric id, do not narrow the history.
	 *
	 * @return void
	 */
	public function testHistoryIsReadByUuidOnly(): void {
		$this->assertSame(
			['action' => 'update', 'object_uuid' => 'u-1'],
			$this->filtersFor(filters: ['register' => '2', 'schema' => '20', 'object' => '9', 'action' => 'update'])
		);
	}//end testHistoryIsReadByUuidOnly()

	/**
	 * No filters still scopes the history to the object.
	 *
	 * @return void
	 */
	public function testHistoryWithoutFiltersIsStillThisObjectsOnly(): void {
		$this->assertSame(['object_uuid' => 'u-1'], $this->filtersFor(filters: []));
	}//end testHistoryWithoutFiltersIsStillThisObjectsOnly()

	/**
	 * The whole history is read, newest first, with no search term.
	 *
	 * @return void
	 */
	public function testHistoryIsTheWholePageNewestFirst(): void {
		$seen = null;
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('findTrail')->willReturnCallback(AuditTrailListing::positional(
			static function (?int $limit, ?int $offset, ?array $filters, ?array $sort, ?string $search) use (&$seen): array {
				$seen = [$limit, $offset, $sort, $search];
				return [];
			}
		));

		$handler = (new ReflectionClass(GetObject::class))->newInstanceWithoutConstructor();
		$property = (new ReflectionClass(GetObject::class))->getProperty('auditTrailMapper');
		$property->setValue($handler, $audit);

		$object = new ObjectEntity();
		$object->setUuid('u-1');
		$handler->findLogs(object: $object, filters: ['action' => 'update']);

		$this->assertSame([null, null, ['created' => 'DESC'], null], $seen);
	}//end testHistoryIsTheWholePageNewestFirst()

	/**
	 * The filters the mapper is asked with.
	 *
	 * @param array<string, mixed> $filters The filters of the request.
	 *
	 * @return array<string, mixed>
	 */
	private function filtersFor(array $filters): array {
		$seen = null;
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('findTrail')->willReturnCallback(AuditTrailListing::positional(
			static function (?int $limit, ?int $offset, array $asked) use (&$seen): array {
				$seen = $asked;
				return [];
			}
		));

		$handler = (new ReflectionClass(GetObject::class))->newInstanceWithoutConstructor();
		$property = (new ReflectionClass(GetObject::class))->getProperty('auditTrailMapper');
		$property->setValue($handler, $audit);

		$object = new ObjectEntity();
		$object->setId(9);
		$object->setUuid('u-1');
		$handler->findLogs(object: $object, filters: $filters);

		return $seen;
	}//end filtersFor()
}//end class
