<?php

/**
 * Unowned-write attribution.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Who a created object is attributed to.
 *
 * THE CASE THIS EXISTS FOR. An application can accept a genuinely anonymous
 * submission — a citizen reporting a problem on a public portal, with no
 * account — and the row was still stamped with whatever Nextcloud user
 * happened to hold a session in that browser. Measured on a portaliq portal:
 * the same anonymous form stored `__system__` when posted by `curl` and
 * `admin` when posted from a browser holding an admin cookie, while the page
 * told the visitor the submission was not linked to an account.
 *
 * The caller could not fix it, because attribution overrides a caller-set
 * owner whenever a session exists. `$_unowned` is the opt-out, and these tests
 * pin all three branches so the default cannot drift.
 */
class UnownedWriteTest extends TestCase {

	/**
	 * Invoke the private attribution step directly.
	 *
	 * Reflection rather than a full save: attribution is one decision with
	 * three branches, and driving it through `saveObject()` would need a
	 * register, a schema, a mapper and a database to observe a single setter.
	 *
	 * @param SaveObject   $handler The handler under test.
	 * @param ObjectEntity $entity  The entity being prepared.
	 * @param bool         $unowned Whether this is an unowned write.
	 *
	 * @return void
	 */
	private function attribute(SaveObject $handler, ObjectEntity $entity, bool $unowned): void {
		$method = new ReflectionMethod(SaveObject::class, 'applyOwnerAttribution');
		$method->setAccessible(true);
		$method->invoke($handler, $entity, $unowned);
	}


	/**
	 * A handler whose session holds the given user, and whose system identity
	 * is a known string.
	 *
	 * @param string|null $uid The signed-in user, or null for no session.
	 *
	 * @return SaveObject The handler.
	 */
	private function handlerFor(?string $uid): SaveObject {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		// The REAL class, mocked — the property is typed, so a stand-in of any
		// other type fails on assignment rather than in the assertion.
		$organisation = $this->createMock(\OCA\OpenRegister\Service\OrganisationService::class);
		$organisation->method('getSystemUserId')->willReturn('__system__');

		// Only the two collaborators attribution touches are wired; the rest of
		// the handler's dependencies are irrelevant to this decision and giving
		// them real values would make the test about construction instead.
		$handler = (new \ReflectionClass(SaveObject::class))->newInstanceWithoutConstructor();
		$this->setPrivate($handler, 'userSession', $session);
		$this->setPrivate($handler, 'organisationService', $organisation);

		return $handler;
	}


	/**
	 * Set a private property on an instance.
	 *
	 * @param object $instance The instance.
	 * @param string $name     The property.
	 * @param mixed  $value    The value.
	 *
	 * @return void
	 */
	private function setPrivate(object $instance, string $name, mixed $value): void {
		$property = new \ReflectionProperty(SaveObject::class, $name);
		$property->setAccessible(true);
		$property->setValue($instance, $value);
	}


	/**
	 * THE DEFECT: a session user is stamped on a write the caller meant to be
	 * anonymous.
	 *
	 * @return void
	 */
	public function testWithoutTheFlagASessionUserIsStamped(): void {
		$entity = new ObjectEntity();
		$this->attribute($this->handlerFor('admin'), $entity, false);

		$this->assertSame('admin', $entity->getOwner());
	}


	/**
	 * THE FIX: an unowned write is attributed to the system, session or not.
	 *
	 * @return void
	 */
	public function testAnUnownedWriteIsAttributedToTheSystemDespiteASession(): void {
		$entity = new ObjectEntity();
		$this->attribute($this->handlerFor('admin'), $entity, true);

		$this->assertSame('__system__', $entity->getOwner());
	}


	/**
	 * The system identity, NOT an empty owner.
	 *
	 * Rows persisted with an empty `_owner` are invisible to the REST list
	 * path's RBAC filter even for admins (openregister#1617), so "no owner"
	 * has to mean the system rather than nobody — otherwise anonymising a
	 * submission would also hide it from the people who have to act on it.
	 *
	 * @return void
	 */
	public function testAnUnownedWriteIsNeverLeftWithAnEmptyOwner(): void {
		$entity = new ObjectEntity();
		$this->attribute($this->handlerFor(null), $entity, true);

		$this->assertNotSame('', $entity->getOwner());
		$this->assertNotNull($entity->getOwner());
	}


	/**
	 * With no session and no flag, today's behaviour is unchanged.
	 *
	 * The regression guard for every existing caller: the default must keep
	 * falling back to the system identity exactly as it did.
	 *
	 * @return void
	 */
	public function testWithNoSessionTheSystemIdentityIsStillTheFallback(): void {
		$entity = new ObjectEntity();
		$this->attribute($this->handlerFor(null), $entity, false);

		$this->assertSame('__system__', $entity->getOwner());
	}


	/**
	 * An explicitly set owner still survives a no-session write.
	 *
	 * @return void
	 */
	public function testAnExplicitOwnerIsPreservedWithoutASession(): void {
		$entity = new ObjectEntity();
		$entity->setOwner('someone');
		$this->attribute($this->handlerFor(null), $entity, false);

		$this->assertSame('someone', $entity->getOwner());
	}


}//end class
