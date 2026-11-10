<?php

/**
 * NextcloudVaultCredentialStoreTest — round-trip tests over a mocked vault.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
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

namespace Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\NextcloudVaultCredentialStore;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Credential\NextcloudVaultCredentialStore
 */
class NextcloudVaultCredentialStoreTest extends TestCase {
	/** @var ICredentialsManager&\PHPUnit\Framework\MockObject\MockObject */
	private $vault;

	private NextcloudVaultCredentialStore $store;

	protected function setUp(): void {
		$this->vault = $this->createMock(ICredentialsManager::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->store = new NextcloudVaultCredentialStore($this->vault, $session);
	}

	public function testPutStoresUnderPerUserNamespacedKey(): void {
		$this->vault->expects($this->once())
			->method('store')
			->with('alice', 'openregister/credential/uuid-1', 'sekret');

		$this->store->put('uuid-1', 'sekret');
	}

	public function testGetReturnsStoredSecret(): void {
		$this->vault->method('retrieve')
			->with('alice', 'openregister/credential/uuid-1')
			->willReturn('sekret');

		$this->assertSame('sekret', $this->store->get('uuid-1'));
	}

	public function testGetReturnsNullWhenAbsent(): void {
		$this->vault->method('retrieve')->willReturn(null);
		$this->assertNull($this->store->get('uuid-1'));
	}

	public function testDeleteRemovesKey(): void {
		$this->vault->expects($this->once())
			->method('delete')
			->with('alice', 'openregister/credential/uuid-1')
			->willReturn(1);

		$this->store->delete('uuid-1');
	}

	public function testPersonalScopeStoresUnderOwningUser(): void {
		$this->vault->expects($this->once())
			->method('store')
			->with('alice', 'openregister/credential/uuid-1', 'sekret');

		// Explicit 'personal' scope resolves to the current user — identical to the default.
		$this->store->put('uuid-1', 'sekret', 'personal');
	}

	public function testOrganisationScopePutStoresUnderSystemIdentity(): void {
		// The reserved system identity is the empty-string user — never 'alice'.
		$this->vault->expects($this->once())
			->method('store')
			->with('', 'openregister/credential/uuid-1', 'sekret');

		$this->store->put('uuid-1', 'sekret', 'organisation');
	}

	public function testOrganisationScopeGetReadsSystemIdentity(): void {
		$this->vault->method('retrieve')
			->with('', 'openregister/credential/uuid-1')
			->willReturn('sekret');

		$this->assertSame('sekret', $this->store->get('uuid-1', 'organisation'));
	}

	public function testOrganisationScopeDeleteRemovesSystemIdentityKey(): void {
		$this->vault->expects($this->once())
			->method('delete')
			->with('', 'openregister/credential/uuid-1')
			->willReturn(1);

		$this->store->delete('uuid-1', 'organisation');
	}
}//end class
