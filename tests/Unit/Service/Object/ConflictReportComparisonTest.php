<?php

/**
 * Unit tests for how the conflict report compares null and structured values.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\ConflictReport;
use OCA\OpenRegister\Service\PropertyRbacHandlerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Null against a value, arrays in another key order, nested differences and odd audit deltas.
 *
 * @covers \OCA\OpenRegister\Service\Object\ConflictReport
 */
class ConflictReportComparisonTest extends TestCase {

	/**
	 * Build a report for a stored body, a sent body and one intervening change.
	 *
	 * @param array<string, mixed> $stored  The stored values.
	 * @param array<string, mixed> $sent    The sent values.
	 * @param array<string, mixed> $changed The intervening audit delta.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function report(array $stored, array $sent, array $changed): array {
		$rbac = $this->createMock(PropertyRbacHandlerInterface::class);
		$rbac->method('canReadProperty')->willReturn(true);

		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setObject($stored);

		$entry = new AuditTrail();
		$entry->setChanged($changed);
		$entry->setUser('bram');
		$entry->setCreated(new DateTime('2026-09-18T12:00:00+00:00'));

		return (new ConflictReport($rbac))->build($object, new Schema(), $sent, [$entry]);

	}//end report()

	/**
	 * Sending null over a value another writer changed is a conflict.
	 *
	 * @return void
	 */
	public function testNullOverAChangedValueConflicts(): void {
		$body = $this->report(['note' => 'theirs'], ['note' => null], ['note' => ['old' => 'mine', 'new' => 'theirs']]);

		$this->assertSame(
			['status' => 'conflict', 'sent' => null, 'read' => 'mine', 'stored' => 'theirs'],
			$body['conflicts']['note']
		);

	}//end testNullOverAChangedValueConflicts()

	/**
	 * Sending null back where the store holds null is agreement.
	 *
	 * @return void
	 */
	public function testNullAgainstNullIsAgreement(): void {
		$body = $this->report(['tags' => null], ['tags' => null], ['tags' => ['old' => ['a'], 'new' => null]]);

		$this->assertSame([], $body['conflicts']);

	}//end testNullAgainstNullIsAgreement()

	/**
	 * The same structure in another key order is agreement, nested levels included.
	 *
	 * @return void
	 */
	public function testKeyOrderIsNotAConflict(): void {
		$body = $this->report(
			['address' => ['street' => 'Dam', 'geo' => ['lat' => 1, 'lon' => 2]]],
			['address' => ['geo' => ['lon' => 2, 'lat' => 1], 'street' => 'Dam']],
			['address' => ['old' => [], 'new' => []]]
		);

		$this->assertSame([], $body['conflicts']);
		$this->assertSame('This object changed since you read it. Re-read it and try again.', $body['message']);

	}//end testKeyOrderIsNotAConflict()

	/**
	 * A nested difference in a structure another writer changed is a conflict.
	 *
	 * @return void
	 */
	public function testANestedDifferenceConflicts(): void {
		$body = $this->report(
			['address' => ['street' => 'Dam', 'geo' => ['lat' => 1]]],
			['address' => ['geo' => ['lat' => 9], 'street' => 'Dam']],
			['address' => ['old' => ['street' => 'Rokin'], 'new' => []]]
		);

		$this->assertSame(['street' => 'Rokin'], $body['conflicts']['address']['read']);
		$this->assertSame('This object changed since you read it, and somebody else wrote the same field.', $body['message']);

	}//end testANestedDifferenceConflicts()

	/**
	 * An audit delta that is no old/new pair records the property with no read value.
	 *
	 * @return void
	 */
	public function testAShapelessDeltaReadsAsNull(): void {
		$body = $this->report(['status' => 'b'], ['status' => 'c'], ['status' => 'b']);

		$this->assertSame(
			['status' => 'conflict', 'sent' => 'c', 'read' => null, 'stored' => 'b'],
			$body['conflicts']['status']
		);
		$this->assertSame('bram', $body['changedBy'], 'without a display name the uid names the actor');

	}//end testAShapelessDeltaReadsAsNull()

	/**
	 * Without an intervening change nobody is named and no moment is given.
	 *
	 * @return void
	 */
	public function testNoInterveningChangeNamesNobody(): void {
		$rbac = $this->createMock(PropertyRbacHandlerInterface::class);
		$object = new ObjectEntity();
		$object->setObject(['status' => 'a']);

		$body = (new ConflictReport($rbac))->build($object, new Schema(), ['status' => 'b'], []);

		$this->assertNull($body['changedBy']);
		$this->assertNull($body['changedAt']);
		$this->assertSame([], $body['conflicts'], 'nobody else changed status, so it is not a conflict');

	}//end testNoInterveningChangeNamesNobody()
}//end class
