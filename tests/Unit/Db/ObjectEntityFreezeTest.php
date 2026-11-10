<?php

/**
 * ObjectEntity::freeze() stamps who froze the object, why and in which state, and refuses without a user.
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

use Exception;
use OCA\OpenRegister\Db\FreezeTerms;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Allowed and refused outcomes of freezing an object.
 */
class ObjectEntityFreezeTest extends TestCase {

	/**
	 * A session for a user, or an anonymous one.
	 *
	 * @param string|null $uid The user id, or null for no user.
	 *
	 * @return IUserSession The session.
	 */
	private function session(?string $uid): IUserSession {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}

	public function testAPersonsFreezeRecordsTheUserAndTheReasonWithoutAState(): void {
		$object = new ObjectEntity();
		$object->freeze(userSession: $this->session(uid: 'anna'), reason: 'bezwaar');

		$frozen = $object->getFrozen();
		$this->assertTrue($object->isFrozen());
		$this->assertSame('anna', $frozen['by']);
		$this->assertSame('bezwaar', $frozen['reason']);
		$this->assertNull($frozen['state']);
		$this->assertIsString($frozen['at']);
	}

	public function testAFreezeWithoutAReasonRecordsNone(): void {
		$object = new ObjectEntity();
		$object->freeze(userSession: $this->session(uid: 'anna'));

		$this->assertNull($object->getFrozen()['reason']);
	}

	public function testAFreezeWithoutAUserIsRefusedAndLeavesTheObjectUnfrozen(): void {
		$object = new ObjectEntity();

		try {
			$object->freeze(userSession: $this->session(uid: null), reason: 'bezwaar');
			$this->fail('A freeze without a user must be refused.');
		} catch (Exception $e) {
			$this->assertSame('No user logged in', $e->getMessage());
		}

		$this->assertFalse($object->isFrozen());
	}

	public function testAStateFreezeRecordsTheDeclaringState(): void {
		$object = new ObjectEntity();
		$object->freezeWith(userSession: $this->session(uid: 'bert'), terms: new FreezeTerms(reason: 'fase afgesloten', state: 'in_bezwaar'));

		$frozen = $object->getFrozen();
		$this->assertSame('bert', $frozen['by']);
		$this->assertSame('fase afgesloten', $frozen['reason']);
		$this->assertSame('in_bezwaar', $frozen['state']);
	}

	public function testAStateFreezeWithoutAUserIsRefused(): void {
		$object = new ObjectEntity();

		$this->expectExceptionMessage('No user logged in');
		$object->freezeWith(userSession: $this->session(uid: null), terms: new FreezeTerms(state: 'in_bezwaar'));
	}
}
