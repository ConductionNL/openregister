<?php

/**
 * A share is judged as its creator, and refused without one.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Federation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Federation;

use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Service\Federation\ShareCreatorCheck;
use OCA\OpenRegister\Service\Federation\ShareGrantorInterface;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Federation\ShareCreatorCheck
 * @uses   \OCA\OpenRegister\Db\FederatedShare
 */
class ShareCreatorCheckTest extends TestCase {

	/**
	 * The active user as the session double holds it.
	 *
	 * @var IUser|null
	 */
	private ?IUser $current = null;

	/**
	 * Uid the grantor saw as active, per call.
	 *
	 * @var array<int, string|null>
	 */
	private array $judgedAs = [];

	/**
	 * A user double.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser The user.
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * A share with the given owner.
	 *
	 * @param string|null $owner The owner uid.
	 *
	 * @return FederatedShare The share.
	 */
	private function share(?string $owner): FederatedShare {
		$share = new FederatedShare();
		$share->setScope('schema');
		$share->setPermissions('read');
		$share->setOwner($owner);
		return $share;
	}//end share()

	/**
	 * The check over doubles; the grantor refuses everyone but `alice`.
	 *
	 * @param bool $grantorThrows Whether the grantor throws instead of answering.
	 *
	 * @return ShareCreatorCheck The check.
	 */
	private function check(bool $grantorThrows = false): ShareCreatorCheck {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnMap([['alice', $this->user('alice')], ['bob', $this->user('bob')], ['gone', null]]);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->current);
		$session->method('setVolatileActiveUser')->willReturnCallback(function (?IUser $user): void {
			$this->current = $user;
		});
		$session->expects($this->never())->method('setUser');

		$grantor = $this->createMock(ShareGrantorInterface::class);
		$grantor->method('refusal')->willReturnCallback(function () use ($grantorThrows): ?string {
			$this->judgedAs[] = $this->current?->getUID();
			if ($grantorThrows === true) {
				throw new RuntimeException('lookup failed');
			}

			return ($this->current?->getUID() === 'alice' ? null : 'This share needs the right to manage its schema');
		});

		return new ShareCreatorCheck($grantor, $users, $session);
	}//end check()

	public function testACreatorWhoHoldsTheRightsPassesAndTheCallerIsRestored(): void {
		$this->current = null;

		$this->assertNull($this->check()->refusal($this->share('alice')));
		$this->assertSame(['alice'], $this->judgedAs);
		$this->assertNull($this->current);
	}

	public function testACreatorWhoLostTheRightsIsRefused(): void {
		$this->assertSame('This share needs the right to manage its schema', $this->check()->refusal($this->share('bob')));
		$this->assertSame(['bob'], $this->judgedAs);
	}

	public function testAShareWithoutARecordedCreatorIsRefused(): void {
		$this->assertStringContainsString('creator not recorded', (string)$this->check()->refusal($this->share(null)));
		$this->assertStringContainsString('creator not recorded', (string)$this->check()->refusal($this->share('')));
		$this->assertSame([], $this->judgedAs);
	}

	public function testAShareWhoseCreatorIsGoneIsRefused(): void {
		$this->assertSame('creator no longer exists', $this->check()->refusal($this->share('gone')));
		$this->assertSame([], $this->judgedAs);
	}

	public function testAFailingCheckRefusesAndRestoresTheCaller(): void {
		$caller = $this->user('carol');
		$this->current = $caller;

		$this->assertNotNull($this->check(grantorThrows: true)->refusal($this->share('alice')));
		$this->assertSame($caller, $this->current);
	}
}
