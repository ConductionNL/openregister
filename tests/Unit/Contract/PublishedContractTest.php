<?php

/**
 * The published contract is satisfied by the classes that claim it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Contract;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * ADR-084. Sixteen apps type-hint these interfaces; ten of them used to
 * hand-roll a double instead, declaring between 0 and 13 methods against a real
 * class of 88. The whole value of publishing a contract is that it cannot
 * quietly stop being true.
 *
 * PHP already refuses to declare a class whose signatures do not satisfy its
 * interface, so most of this is enforced at autoload. What is NOT enforced is
 * the part that matters to a consumer: that the contract getters are DECLARED
 * methods rather than magic ones. `ObjectEntity` extends Nextcloud's `Entity`,
 * whose `__call` answers any `getX()` — so a getter could be deleted from the
 * class and every caller would still work, right up until someone tries to
 * `implements` the interface or mock it with `onlyMethods()`.
 *
 * That is not hypothetical: these five getters WERE magic, and making them
 * explicit is what ADR-084 required. This test is what stops them drifting back.
 */
class PublishedContractTest extends TestCase {

	/**
	 * The concrete classes declare the published interfaces.
	 *
	 * @return void
	 */
	public function testTheImplementationsDeclareTheContract(): void {
		$this->assertInstanceOf(
			ObjectEntityInterface::class,
			new ObjectEntity(),
			'ObjectEntity must satisfy the contract consuming apps type-hint.'
		);

		$this->assertContains(
			ObjectServiceInterface::class,
			class_implements(ObjectService::class),
			'ObjectService must satisfy the contract consuming apps type-hint.'
		);
	}//end testTheImplementationsDeclareTheContract()

	/**
	 * Every contract method is DECLARED, not answered by `__call`.
	 *
	 * `method_exists()` is false for a magic method, which is exactly the
	 * property being asserted — and the reason `implements` would have been a
	 * fatal error while these getters were annotations.
	 *
	 * @return void
	 */
	public function testTheContractGettersAreDeclaredNotMagic(): void {
		$entity = new ReflectionClass(ObjectEntity::class);

		foreach ((new ReflectionClass(ObjectEntityInterface::class))->getMethods() as $method) {
			$name = $method->getName();
			if ($name === 'jsonSerialize') {
				continue;
			}

			$this->assertTrue(
				$entity->hasMethod($name),
				"ObjectEntity::{$name}() must be declared. An @method annotation over "
				. 'Entity::__call satisfies callers but NOT the interface, and PHPUnit '
				. 'cannot mock it with onlyMethods() either.'
			);
		}
	}//end testTheContractGettersAreDeclaredNotMagic()

	/**
	 * The contract getters return what was stored.
	 *
	 * Cheap, and it pins the one thing the type system cannot: that the explicit
	 * getters read the property they claim to, rather than each other.
	 *
	 * @return void
	 */
	public function testTheContractGettersReadTheirOwnProperty(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('uuid-1');
		$entity->setRegister('register-1');
		$entity->setSchema('schema-1');
		$entity->setOwner('owner-1');
		$entity->setOrganisation('organisation-1');
		$entity->setObject(['title' => 'Test']);

		$this->assertSame('uuid-1', $entity->getUuid());
		$this->assertSame('register-1', $entity->getRegister());
		$this->assertSame('schema-1', $entity->getSchema());
		$this->assertSame('owner-1', $entity->getOwner());
		$this->assertSame('organisation-1', $entity->getOrganisation());

		// getObject() injects the UUID as `id` — the one contract getter that
		// is not a plain property read.
		$this->assertSame(
			['id' => 'uuid-1', 'title' => 'Test'],
			$entity->getObject()
		);
	}//end testTheContractGettersReadTheirOwnProperty()
}//end class
