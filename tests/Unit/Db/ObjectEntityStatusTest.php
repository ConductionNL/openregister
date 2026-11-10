<?php

/**
 * An object's explicit lifecycle status lives in `@self.status`; without it nothing changes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * The legacy read control, and the draft round trip.
 *
 * @covers \OCA\OpenRegister\Db\ObjectEntity
 */
class ObjectEntityStatusTest extends TestCase {

	/**
	 * Spec scenario: an object without the field reads as today. No status, not a draft.
	 */
	public function testAnObjectWithoutTheFieldReadsAsToday(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('u');

		$this->assertNull($entity->getStatus());
		$this->assertFalse($entity->isDraft());
		$this->assertNull($entity->getObjectArray()['status']);
	}//end testAnObjectWithoutTheFieldReadsAsToday()

	/**
	 * A draft says so in `@self.status`.
	 */
	public function testADraftSaysSoInSelf(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('u');
		$entity->setStatus(ObjectEntity::STATUS_DRAFT);

		$this->assertTrue($entity->isDraft());
		$this->assertSame('draft', $entity->getObjectArray()['status']);
		$this->assertSame('draft', $entity->jsonSerialize()['@self']['status']);
	}//end testADraftSaysSoInSelf()
}//end class
