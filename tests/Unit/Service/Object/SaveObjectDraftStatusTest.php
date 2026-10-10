<?php

/**
 * The save path applies the draft rules: setSelfMetadata() stores the status the policy allows.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\DraftStatusPolicy;
use OCA\OpenRegister\Service\Object\SaveObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Asserted from the caller, so the policy cannot be a guard with no call site.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Exception\ValidationException
 */
class SaveObjectDraftStatusTest extends TestCase {

	/**
	 * Call the private setSelfMetadata() on a SaveObject built without its many collaborators.
	 *
	 * @param array<string, mixed> $selfData The @self payload.
	 */
	private function apply(ObjectEntity $entity, array $selfData): void {
		$save = (new ReflectionClass(SaveObject::class))->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod(SaveObject::class, 'setSelfMetadata');
		$method->invoke($save, $entity, $selfData, [], null);
	}//end apply()

	protected function setUp(): void {
		DraftStatusPolicy::resetPromotions();
	}//end setUp()

	/**
	 * A create asking for draft stores draft.
	 */
	public function testACreateAskingForDraftStoresDraft(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('u');

		$this->apply(entity: $entity, selfData: ['status' => 'draft']);

		$this->assertSame('draft', $entity->getStatus());
	}//end testACreateAskingForDraftStoresDraft()

	/**
	 * An ordinary update of a draft keeps it a draft; leaving it without the submit is refused.
	 */
	public function testAnUpdateKeepsTheDraftAndCannotLeaveIt(): void {
		$entity = new ObjectEntity();
		$entity->setId(3);
		$entity->setUuid('u');
		$entity->setStatus('draft');

		$this->apply(entity: $entity, selfData: []);
		$this->assertSame('draft', $entity->getStatus());

		$this->expectException(ValidationException::class);
		$this->apply(entity: $entity, selfData: ['status' => 'active']);
	}//end testAnUpdateKeepsTheDraftAndCannotLeaveIt()
}//end class
