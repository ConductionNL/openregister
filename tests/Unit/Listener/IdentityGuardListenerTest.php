<?php

/**
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\IdentityGuardListener;
use OCA\OpenRegister\Service\Schemas\IdentityRewriteScope;
use OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * An update may not change an identity value, except inside the migration's rewrite scope.
 *
 * @covers \OCA\OpenRegister\Listener\IdentityGuardListener
 * @uses \OCA\OpenRegister\Service\Schemas\IdentityRewriteScope
 * @uses \OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Event\ObjectCreatingEvent
 * @uses \OCA\OpenRegister\Event\ObjectUpdatingEvent
 */
final class IdentityGuardListenerTest extends TestCase {

	private function listener(bool $withIdentity = true): IdentityGuardListener {
		$schema = new Schema();
		$schema->setId(7);
		$constraint = ['name' => 'zaaksleutel', 'properties' => ['gemeentecode', 'zaaknummer'], 'action' => 'refuse'];
		if ($withIdentity === true) {
			$constraint['identity'] = true;
		}

		$schema->setConfiguration(['uniqueConstraints' => [$constraint]]);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		return new IdentityGuardListener(evaluator: new UniqueConstraintEvaluator(), schemas: $schemas);
	}//end listener()

	/**
	 * A case object with the given data.
	 *
	 * @param array<string,mixed> $data The object data.
	 *
	 * @return ObjectEntity
	 */
	private static function zaak(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('zaak-uuid');
		$object->setRegister('3');
		$object->setSchema('7');
		$object->setObject($data);

		return $object;
	}//end zaak()

	private static function renumbering(): ObjectUpdatingEvent {
		return new ObjectUpdatingEvent(
			newObject: self::zaak(['gemeentecode' => '0363', 'zaaknummer' => 'Z-2026-0043', 'titel' => 'b']),
			oldObject: self::zaak(['gemeentecode' => '0363', 'zaaknummer' => 'Z-2026-0042', 'titel' => 'a'])
		);
	}//end renumbering()

	public function testChangingAnIdentityValueIsRefusedNamingTheProperty(): void {
		$event = self::renumbering();

		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$errors = $event->getErrors();
		$this->assertSame(IdentityGuardListener::ERROR_CODE, $errors['code']);
		$this->assertSame('zaaknummer', $errors['property']);
		$this->assertSame('zaaksleutel', $errors['constraint']);
		$this->assertStringContainsString('zaaknummer', $errors['message']);
	}//end testChangingAnIdentityValueIsRefusedNamingTheProperty()

	public function testChangingOtherPropertiesIsAllowed(): void {
		$event = new ObjectUpdatingEvent(
			newObject: self::zaak(['gemeentecode' => 363, 'zaaknummer' => 'Z-1', 'titel' => 'b']),
			oldObject: self::zaak(['gemeentecode' => '363', 'zaaknummer' => 'Z-1', 'titel' => 'a'])
		);

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testChangingOtherPropertiesIsAllowed()

	public function testAValueTheStoredObjectLacksMayBeFilledIn(): void {
		$event = new ObjectUpdatingEvent(
			newObject: self::zaak(['gemeentecode' => '0363', 'zaaknummer' => 'Z-1']),
			oldObject: self::zaak(['gemeentecode' => '0363'])
		);

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAValueTheStoredObjectLacksMayBeFilledIn()

	public function testRemovingAStoredValueIsRefused(): void {
		$event = new ObjectUpdatingEvent(
			newObject: self::zaak(['gemeentecode' => '0363']),
			oldObject: self::zaak(['gemeentecode' => '0363', 'zaaknummer' => 'Z-1'])
		);

		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('zaaknummer', $event->getErrors()['property']);
	}//end testRemovingAStoredValueIsRefused()

	public function testTheMigrationsRewriteScopeMayChangeIt(): void {
		$event = self::renumbering();

		IdentityRewriteScope::run(fn () => $this->listener()->handle($event));

		$this->assertFalse($event->isPropagationStopped());
		$this->assertFalse(IdentityRewriteScope::isActive());
	}//end testTheMigrationsRewriteScopeMayChangeIt()

	public function testASchemaWithoutAnIdentityIsNotGuarded(): void {
		$event = self::renumbering();

		$this->listener(withIdentity: false)->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testASchemaWithoutAnIdentityIsNotGuarded()

	public function testASoftDeleteAndACreateAreNotGuarded(): void {
		$delete = self::renumbering();
		$delete->getNewObject()->setDeleted(['deleted' => '2026-11-13T12:00:00+00:00', 'deletedBy' => 'admin']);
		$create = new ObjectCreatingEvent(object: self::zaak(['zaaknummer' => 'Z-1']));

		$this->listener()->handle($delete);
		$this->listener()->handle($create);

		$this->assertFalse($delete->isPropagationStopped());
		$this->assertFalse($create->isPropagationStopped());
	}//end testASoftDeleteAndACreateAreNotGuarded()
}//end class
