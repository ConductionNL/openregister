<?php

/**
 * The object an audit trail row is about, stamped onto the row: register,
 * schema, object id and uuid, nulls included.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\AuditTrail
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\AuditTrail;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\AuditedObject;
use PHPUnit\Framework\TestCase;

/**
 * AuditedObject.
 *
 * @covers \OCA\OpenRegister\Db\AuditTrail\AuditedObject
 * @uses \OCA\OpenRegister\Db\AuditTrail
 */
class AuditedObjectTest extends TestCase {

	public function testTheObjectIsStampedOntoTheRow(): void {
		$row = new AuditTrail();

		(new AuditedObject(register: 2, schema: 5, object: 81, objectUuid: 'obj-81'))->stampOnto($row);

		$this->assertSame([2, 5, 81, 'obj-81'], [$row->getRegister(), $row->getSchema(), $row->getObject(), $row->getObjectUuid()]);
	}//end testTheObjectIsStampedOntoTheRow()

	public function testAnUnknownObjectClearsTheRowsFields(): void {
		$row = new AuditTrail();
		$row->setRegister(1);
		$row->setObjectUuid('stale');

		(new AuditedObject())->stampOnto($row);

		$this->assertSame([null, null, null, null], [$row->getRegister(), $row->getSchema(), $row->getObject(), $row->getObjectUuid()]);
	}//end testAnUnknownObjectClearsTheRowsFields()
}//end class
