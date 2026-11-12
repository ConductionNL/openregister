<?php

/**
 * Characterisation of AuditTrailPayloadHelper::revertChanges() for a field an update added:
 * reverting removes it, and the other fields keep their order.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailPayloadHelper;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * Golden revert of an added field.
 *
 * @covers \OCA\OpenRegister\Db\AuditTrailPayloadHelper
 */
final class AuditTrailRevertRemovalGoldenTest extends TestCase {

	/**
	 * A field whose old value is null is removed; reverted fields keep their place.
	 *
	 * @return void
	 */
	public function testAddedFieldIsRemoved(): void {
		$object = new ObjectEntity();
		$object->setUuid('u-1');
		$object->setObject(['a' => 1, 'added' => 'x', 'b' => 'new-b', 'c' => 3]);
		$audit = new AuditTrail();
		$audit->setAction('update');
		$audit->setChanged(['added' => ['old' => null, 'new' => 'x'], 'b' => ['old' => 'old-b', 'new' => 'new-b'], 'gone' => ['old' => 'back', 'new' => null]]);

		(new AuditTrailPayloadHelper())->revertChanges($object, $audit);

		$this->assertSame(['id' => 'u-1', 'a' => 1, 'b' => 'old-b', 'c' => 3, 'gone' => 'back'], $object->getObject());
	}//end testAddedFieldIsRemoved()
}//end class
