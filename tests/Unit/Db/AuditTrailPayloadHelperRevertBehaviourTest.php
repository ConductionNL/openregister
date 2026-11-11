<?php

/**
 * AuditTrailPayloadHelper::revertChanges() restores recorded old values only, never
 * undoes a create, and leaves metadata and malformed entries alone.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailPayloadHelper;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of revertChanges() on real AuditTrail entries.
 */
class AuditTrailPayloadHelperRevertBehaviourTest extends TestCase {

	/**
	 * An audit entry with the given action and change set.
	 *
	 * @param string     $action  The action.
	 * @param array|null $changed The change set.
	 *
	 * @return AuditTrail
	 */
	private function audit(string $action, ?array $changed): AuditTrail {
		$audit = new AuditTrail();
		$audit->setAction($action);
		$audit->setChanged($changed);
		return $audit;
	}

	/**
	 * The object's data without the `id` key ObjectEntity adds itself.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, mixed>
	 */
	private function data(ObjectEntity $object): array {
		$data = $object->getObject();
		if (($data['id'] ?? null) === null) {
			unset($data['id']);
		}

		return $data;
	}

	private function object(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setObject($data);
		return $object;
	}

	public function testAnUpdateGetsItsOldValuesBack(): void {
		$object = $this->object(['title' => 'new', 'status' => 'closed', 'other' => 'kept']);

		(new AuditTrailPayloadHelper())->revertChanges(
			$object,
			$this->audit('update', ['title' => ['old' => 'old', 'new' => 'new'], 'status' => ['old' => 'open', 'new' => 'closed']])
		);

		$this->assertSame(['title' => 'old', 'status' => 'open', 'other' => 'kept'], $this->data($object));
	}

	public function testACreateEntryIsNeverUndone(): void {
		$object = $this->object(['title' => 'new']);

		(new AuditTrailPayloadHelper())->revertChanges($object, $this->audit('create', ['title' => ['old' => null, 'new' => 'new']]));

		$this->assertSame(['title' => 'new'], $this->data($object));
	}

	public function testAnEntryWithoutAChangeSetChangesNothing(): void {
		$object = $this->object(['title' => 'new']);

		(new AuditTrailPayloadHelper())->revertChanges($object, $this->audit('update', null));

		$this->assertSame(['title' => 'new'], $this->data($object));
	}

	public function testMetadataIdAndMalformedEntriesAreSkippedWhileTheRestReverts(): void {
		$object = $this->object(['@self' => ['owner' => 'bob'], 'id' => 'u-2', 'name' => 'B', 'size' => 2, 'title' => 'new']);

		(new AuditTrailPayloadHelper())->revertChanges(
			$object,
			$this->audit(
				'update',
				[
					'@self' => ['old' => ['owner' => 'ann'], 'new' => ['owner' => 'bob']],
					'id'    => ['old' => 'u-1', 'new' => 'u-2'],
					'name'  => 'not an entry',
					'size'  => ['new' => 2],
					'title' => ['old' => 'old', 'new' => 'new'],
				]
			)
		);

		$this->assertEquals(['@self' => ['owner' => 'bob'], 'id' => 'u-2', 'name' => 'B', 'size' => 2, 'title' => 'old'], $this->data($object));
	}

	public function testAnObjectWithoutDataGetsTheOldValues(): void {
		$object = new ObjectEntity();
		$object->setObject(null);

		(new AuditTrailPayloadHelper())->revertChanges($object, $this->audit('update', ['title' => ['old' => 'old', 'new' => 'new']]));

		$this->assertSame(['title' => 'old'], $this->data($object));
	}
}
