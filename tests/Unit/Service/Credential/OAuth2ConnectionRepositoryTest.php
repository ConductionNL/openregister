<?php

/**
 * OAuth2ConnectionRepositoryTest — the organisation gate and the minted-client discard.
 *
 * The connect start answers a refusal with the status of its cause, so the TYPE a
 * gate throws is the contract: a non-admin connecting a shared account is refused
 * (403), while a caller with no active organisation sent a request that cannot be
 * served (400). The controller tests mock this gate, so these pin the types here.
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
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\OAuth2ConnectionRepository;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Credential\OAuth2ConnectionRepository
 * @uses \OCA\OpenRegister\Db\Organisation
 */
class OAuth2ConnectionRepositoryTest extends TestCase {
	/** @var array<int, string> The order the discard touched custody and the object store. */
	private array $calls = [];

	protected function setUp(): void {
		$this->calls = [];
	}

	public function testAPersonalConnectNeedsNoOrganisation(): void {
		$repository = $this->makeRepository(activeOrganisation: null, isAdmin: false);

		$this->assertNull($repository->gatedOrganisation(uid: 'alice', requestedScope: 'personal'));
	}

	public function testAnOrganisationAdministratorGetsTheirOrganisation(): void {
		$repository = $this->makeRepository(activeOrganisation: 'org-1', isAdmin: true);

		$this->assertSame('org-1', $repository->gatedOrganisation(uid: 'alice', requestedScope: 'organisation'));
	}

	public function testANonAdministratorConnectingASharedAccountIsRefused(): void {
		$repository = $this->makeRepository(activeOrganisation: 'org-1', isAdmin: false);

		$this->expectException(CredentialAccessDeniedException::class);

		$repository->gatedOrganisation(uid: 'bob', requestedScope: 'organisation');
	}

	public function testAnOrganisationConnectWithNoActiveOrganisationIsAnInvalidRequest(): void {
		$repository = $this->makeRepository(activeOrganisation: null, isAdmin: true);

		$this->expectException(InvalidArgumentException::class);

		$repository->gatedOrganisation(uid: 'alice', requestedScope: 'organisation');
	}

	public function testADiscardDeletesTheSecretBeforeTheObject(): void {
		$this->makeRepository(activeOrganisation: null, isAdmin: false)->discard(credentialId: 'cred-1', scope: 'personal');

		// Secret first: a failure halfway must leave an object holding nothing, never a
		// secret nothing points at.
		$this->assertSame(['custody:cred-1:personal', 'object:cred-1'], $this->calls);
	}

	public function testADiscardWhoseSecretCannotBeDeletedKeepsTheObject(): void {
		$repository = $this->makeRepository(activeOrganisation: null, isAdmin: false, custodyFails: true);

		try {
			$repository->discard(credentialId: 'cred-1', scope: 'organisation');
			$this->fail('a custody failure must reach the caller');
		} catch (RuntimeException $failure) {
			$this->assertSame('the vault is down', $failure->getMessage());
		}

		// The object stays, so the secret it names is still findable and removable.
		$this->assertSame(['custody:cred-1:organisation'], $this->calls);
	}

	/**
	 * Build the repository over a scripted organisation service, store and object service.
	 *
	 * @param string|null $activeOrganisation The caller's active organisation uuid, or null.
	 * @param bool $isAdmin Whether the caller administers it.
	 * @param bool $custodyFails Whether deleting the secret from custody fails.
	 *
	 * @return OAuth2ConnectionRepository The repository under test.
	 */
	private function makeRepository(?string $activeOrganisation, bool $isAdmin, bool $custodyFails = false): OAuth2ConnectionRepository {
		$organisations = $this->createMock(OrganisationService::class);
		if ($activeOrganisation === null) {
			$organisations->method('getActiveOrganisation')->willReturn(null);
		} else {
			$organisation = new Organisation();
			$organisation->setUuid($activeOrganisation);
			$organisations->method('getActiveOrganisation')->willReturn($organisation);
		}

		$organisations->method('isOrganisationAdmin')->willReturn($isAdmin);

		$store = $this->createMock(CredentialStore::class);
		$store->method('delete')->willReturnCallback(
			function (string $uuid, string $scope) use ($custodyFails): void {
				$this->calls[] = 'custody:' . $uuid . ':' . $scope;
				if ($custodyFails === true) {
					throw new RuntimeException('the vault is down');
				}
			}
		);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('deleteObject')->willReturnCallback(
			function (string $uuid, $register, $schema): bool {
				$this->assertSame(CredentialBrokerService::REGISTER, $register);
				$this->assertSame(CredentialBrokerService::SCHEMA, $schema);
				$this->calls[] = 'object:' . $uuid;
				return true;
			}
		);

		return new OAuth2ConnectionRepository(
			objectService: $objectService,
			credentialStore: $store,
			organisationService: $organisations,
		);
	}
}
