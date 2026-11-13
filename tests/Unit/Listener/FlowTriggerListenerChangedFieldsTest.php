<?php

/**
 * An update puts the names of the top-level properties it changed on the trigger context.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-trigger-transitions-and-changed-fields/specs/flow-engine/spec.md
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\FlowTriggerListener;
use OCA\OpenRegister\Service\Flow\FlowTriggerServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowTriggerSlugsInterface;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Listener\FlowTriggerListener
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Event\ObjectUpdatedEvent
 */
final class FlowTriggerListenerChangedFieldsTest extends TestCase {

	/**
	 * The context the listener fires for this update.
	 *
	 * @param ObjectEntity|null $old The object before the save.
	 * @param ObjectEntity      $new The object after the save.
	 *
	 * @return array<string, mixed>
	 */
	private function contextFired(?ObjectEntity $old, ObjectEntity $new): array {
		$fired = [];
		$triggers = $this->createMock(FlowTriggerServiceInterface::class);
		$triggers->expects($this->once())->method('fire')->willReturnCallback(
			function (string $event, array $subject, ?string $user, array $context) use (&$fired): int {
				$fired = ['event' => $event, 'context' => $context];
				return 0;
			}
		);
		$slugs = $this->createMock(FlowTriggerSlugsInterface::class);
		$slugs->method('registerSlug')->willReturnArgument(0);
		$slugs->method('schemaSlug')->willReturnArgument(0);

		(new FlowTriggerListener($triggers, $this->createMock(IUserSession::class), $slugs))
			->handle(new ObjectUpdatedEvent($new, $old));

		$this->assertSame('object.updated', $fired['event']);

		return $fired['context'];
	}//end contextFired()

	/**
	 * An object with this data.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity
	 */
	private function object(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setRegister('zaken');
		$object->setSchema('melding');
		$object->setObject($data);

		return $object;
	}//end object()

	/**
	 * Changed, added and removed top-level keys are listed; unchanged keys and @self are not.
	 *
	 * @return void
	 */
	public function testChangedAddedAndRemovedKeysAreListed(): void {
		$context = $this->contextFired(
			old: $this->object(['omschrijving' => 'oud', 'status' => 'open', 'weg' => 1, '@self' => ['updated' => 'a'], 'adres' => ['straat' => 'x']]),
			new: $this->object(['omschrijving' => 'nieuw', 'status' => 'open', 'categorie' => 'riool', '@self' => ['updated' => 'b'], 'adres' => ['straat' => 'x']])
		);

		$changed = $context['changedFields'];
		sort($changed);
		$this->assertSame(['categorie', 'omschrijving', 'weg'], $changed);
	}//end testChangedAddedAndRemovedKeysAreListed()

	/**
	 * A nested change counts as a change of its top-level property.
	 *
	 * @return void
	 */
	public function testANestedChangeNamesItsTopLevelProperty(): void {
		$context = $this->contextFired(
			old: $this->object(['adres' => ['straat' => 'x', 'nummer' => 1]]),
			new: $this->object(['adres' => ['straat' => 'x', 'nummer' => 2]])
		);

		$this->assertSame(['adres'], $context['changedFields']);
	}//end testANestedChangeNamesItsTopLevelProperty()

	/**
	 * Without the old object nothing proves a change: the list is empty.
	 *
	 * @return void
	 */
	public function testAnUpdateWithoutItsOldVersionListsNothing(): void {
		$this->assertSame(['changedFields' => []], $this->contextFired(old: null, new: $this->object(['omschrijving' => 'x'])));
	}//end testAnUpdateWithoutItsOldVersionListsNothing()
}//end class
