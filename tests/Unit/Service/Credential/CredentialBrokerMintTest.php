<?php

/**
 * CredentialBrokerMintTest — the app-facing credential mint path.
 *
 * Pins the contract openconnector#151 consumes when it folds inline plaintext
 * source secrets into the broker:
 *   - a mint persists the metadata object into the credential-broker register/schema
 *     and writes the secret to the vault under the credential's own UUID;
 *   - the vault scope follows the credential scope (personal | organisation);
 *   - a null / empty secret mints metadata only and NEVER touches the vault;
 *   - the secret never reaches the persisted property bag;
 *   - a vault write that fails AFTER the object was saved rolls the object back,
 *     so a mint never leaves a secretless credential behind;
 *   - the whole path is SESSIONLESS — no IUserSession call is needed, so a repair
 *     step / background job mints exactly as the HTTP controller does.
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
 * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#credential-metadata-schema
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\Http\Client\IClientService;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Credential\CredentialBrokerService
 */
class CredentialBrokerMintTest extends TestCase {
	/** @var array<string, mixed>|null Captured saveObject() property bag. */
	private ?array $savedObject = null;

	/** @var array<string, mixed>|null Captured saveObject() register/schema scope. */
	private ?array $savedScope = null;

	/** @var ObjectService&\PHPUnit\Framework\MockObject\MockObject */
	private $objectService;

	/** @var CredentialStore&\PHPUnit\Framework\MockObject\MockObject */
	private $store;

	/** @var IUserSession&\PHPUnit\Framework\MockObject\MockObject */
	private $session;

	protected function setUp(): void {
		$this->savedObject = null;
		$this->savedScope = null;
		$this->objectService = $this->createMock(ObjectService::class);
		$this->store = $this->createMock(CredentialStore::class);
		$this->session = $this->createMock(IUserSession::class);
	}

	public function testMintPersistsMetadataAndStoresSecretPersonally(): void {
		$this->stubSaveObject(uuid: 'minted-uuid');
		$this->store->expects($this->once())->method('put')
			->with('minted-uuid', 'hunter2', 'personal');

		$entity = $this->makeBroker()->mint(
			name: 'My GitHub',
			provider: 'github',
			owner: 'alice',
			allowedApps: ['openconnector'],
			secret: 'hunter2'
		);

		// The mint returns the persisted entity — its uuid is the credentialRef.
		$this->assertSame('minted-uuid', $entity->getUuid());

		// The object lands in the credential-broker register/schema.
		$this->assertSame(CredentialBrokerService::REGISTER, $this->savedScope['register']);
		$this->assertSame(CredentialBrokerService::SCHEMA, $this->savedScope['schema']);

		$this->assertSame('My GitHub', $this->savedObject['name']);
		$this->assertSame('github', $this->savedObject['provider']);
		$this->assertSame('alice', $this->savedObject['owner']);
		$this->assertSame(['openconnector'], $this->savedObject['allowedApps']);
		$this->assertNotEmpty($this->savedObject['createdAt']);

		// A personal credential's property bag carries no scope/organisation (D1).
		$this->assertArrayNotHasKey('scope', $this->savedObject);
		$this->assertArrayNotHasKey('organisation', $this->savedObject);
	}

	public function testMintNeverPersistsTheSecretToTheObject(): void {
		$this->stubSaveObject(uuid: 'minted-uuid');

		$this->makeBroker()->mint(
			name: 'My GitHub',
			provider: 'github',
			owner: 'alice',
			secret: 'super-secret-value'
		);

		$this->assertArrayNotHasKey('secret', $this->savedObject);
		$this->assertStringNotContainsString(
			'super-secret-value',
			(string)json_encode($this->savedObject)
		);
	}

	public function testOrganisationMintStoresUnderTheOrganisationVaultScope(): void {
		$this->stubSaveObject(uuid: 'org-cred-uuid');
		$this->store->expects($this->once())->method('put')
			->with('org-cred-uuid', 'org-secret', 'organisation');

		$this->makeBroker()->mint(
			name: 'Tender GitHub',
			provider: 'github',
			owner: 'admin-uid',
			allowedApps: [],
			secret: 'org-secret',
			scope: 'organisation',
			organisation: 'org-target'
		);

		$this->assertSame('organisation', $this->savedObject['scope']);
		$this->assertSame('org-target', $this->savedObject['organisation']);
		// The owner still records the provisioning admin for attribution.
		$this->assertSame('admin-uid', $this->savedObject['owner']);
	}

	public function testMintWithoutSecretNeverTouchesTheVault(): void {
		$this->stubSaveObject(uuid: 'metadata-only');
		$this->store->expects($this->never())->method('put');

		$entity = $this->makeBroker()->mint(
			name: 'Metadata only',
			provider: 'github',
			owner: 'alice',
			secret: null
		);

		$this->assertSame('metadata-only', $entity->getUuid());
	}

	/**
	 * A copy-pasted secret with a trailing newline is stored TRIMMED — garbage-in
	 * prevention for the header-injection failure this reproduces
	 * (credential-broker-upstream-diagnostics D3).
	 */
	public function testMintTrimsTrailingWhitespaceFromTheSecret(): void {
		$this->stubSaveObject(uuid: 'minted-uuid');
		$this->store->expects($this->once())->method('put')
			->with('minted-uuid', 'gho_hunter2', 'personal');

		$this->makeBroker()->mint(
			name: 'My GitHub',
			provider: 'github',
			owner: 'alice',
			secret: "gho_hunter2\n"
		);
	}

	/**
	 * A secret that is ENTIRELY whitespace trims to '' and is treated as no
	 * secret supplied — metadata-only mint, vault untouched.
	 */
	public function testMintWithWhitespaceOnlySecretNeverTouchesTheVault(): void {
		$this->stubSaveObject(uuid: 'metadata-only');
		$this->store->expects($this->never())->method('put');

		$this->makeBroker()->mint(
			name: 'Metadata only',
			provider: 'github',
			owner: 'alice',
			secret: "  \n\t "
		);
	}

	public function testMintWithEmptySecretNeverTouchesTheVault(): void {
		$this->stubSaveObject(uuid: 'metadata-only');
		$this->store->expects($this->never())->method('put');

		$this->makeBroker()->mint(
			name: 'Metadata only',
			provider: 'github',
			owner: 'alice',
			secret: ''
		);

		$this->assertSame('Metadata only', $this->savedObject['name']);
	}

	public function testVaultFailureRollsBackTheOrphanedObjectAndRethrows(): void {
		$this->stubSaveObject(uuid: 'doomed-uuid');
		$this->store->method('put')->willThrowException(new RuntimeException('vault down'));

		// The metadata object must not survive a vault write it has no secret for.
		$this->objectService->expects($this->once())->method('deleteObject')
			->with('doomed-uuid', CredentialBrokerService::REGISTER, CredentialBrokerService::SCHEMA)
			->willReturn(true);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('vault down');

		$this->makeBroker()->mint(
			name: 'My GitHub',
			provider: 'github',
			owner: 'alice',
			secret: 'hunter2'
		);
	}

	public function testRollbackFailureDoesNotMaskTheOriginalVaultError(): void {
		$this->stubSaveObject(uuid: 'doomed-uuid');
		$this->store->method('put')->willThrowException(new RuntimeException('vault down'));
		$this->objectService->method('deleteObject')
			->willThrowException(new RuntimeException('delete also failed'));

		// The caller sees the vault failure, not the rollback failure.
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('vault down');

		$this->makeBroker()->mint(
			name: 'My GitHub',
			provider: 'github',
			owner: 'alice',
			secret: 'hunter2'
		);
	}

	public function testMintIsSessionless(): void {
		// A repair step / background job has no session: the mint must never consult one.
		$this->session->expects($this->never())->method('getUser');
		$this->stubSaveObject(uuid: 'job-minted-uuid');
		$this->store->expects($this->once())->method('put')
			->with('job-minted-uuid', 'migrated-secret', 'personal');

		$entity = $this->makeBroker()->mint(
			name: 'Migrated source secret',
			provider: 'generic-api-key',
			owner: 'asserted-owner',
			allowedApps: ['openconnector'],
			secret: 'migrated-secret'
		);

		$this->assertSame('job-minted-uuid', $entity->getUuid());
		$this->assertSame('asserted-owner', $this->savedObject['owner']);
	}

	/**
	 * Mint persists the credential object in SYSTEM context (`_rbac: false`).
	 *
	 * Mint's contract is "authorization is the caller's" — the create must NOT be
	 * re-gated by RBAC, or a sessionless caller (an occ/repair migration folding an
	 * inline source secret into the broker) fails with NotAuthorizedException because
	 * the write ran as the anonymous principal. Live-verified against a real instance,
	 * where the pre-fix create denied the anonymous occ context. The stub previously
	 * ignored saveObject()'s arguments, which is why the gap shipped — assert the
	 * argument, not just the return.
	 */
	public function testMintCreatesInSystemContextBypassingRbac(): void {
		$this->stubSaveObject(uuid: 'sys-minted-uuid');
		$this->store->expects($this->once())->method('put');

		$this->makeBroker()->mint(
			name: 'Sessionless mint',
			provider: 'generic-api-key',
			owner: 'asserted-owner',
			allowedApps: ['openconnector'],
			secret: 'a-secret',
			scope: 'organisation',
			organisation: 'org-uuid'
		);

		$this->assertFalse($this->savedScope['_rbac'], 'mint() must create the credential object with _rbac:false');
		$this->assertFalse($this->savedScope['_multitenancy'], 'mint() must create with _multitenancy:false');
	}

	public function testEmptyNameIsRejectedBeforeAnythingIsPersisted(): void {
		$this->objectService->expects($this->never())->method('saveObject');
		$this->store->expects($this->never())->method('put');

		$this->expectException(InvalidArgumentException::class);

		$this->makeBroker()->mint(name: '   ', provider: 'github', owner: 'alice', secret: 'x');
	}

	public function testOrganisationScopeWithoutOrganisationIsRejected(): void {
		$this->objectService->expects($this->never())->method('saveObject');
		$this->store->expects($this->never())->method('put');

		$this->expectException(InvalidArgumentException::class);

		$this->makeBroker()->mint(
			name: 'Tender GitHub',
			provider: 'github',
			owner: 'admin-uid',
			allowedApps: [],
			secret: 'x',
			scope: 'organisation',
			organisation: null
		);
	}

	/**
	 * Stub saveObject() to capture the payload + scope and return an entity with the uuid.
	 *
	 * @param string $uuid The uuid the saved entity reports.
	 */
	private function stubSaveObject(string $uuid): void {
		$this->objectService->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				$register = null,
				$schema = null,
				?string $incomingUuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			) use ($uuid): ObjectEntity {
				$this->savedObject = $object;
				$this->savedScope = [
					'register' => $register,
					'schema' => $schema,
					'_rbac' => $_rbac,
					'_multitenancy' => $_multitenancy,
				];
				$entity = new ObjectEntity();
				$entity->setUuid($uuid);
				$entity->setObject($object);
				return $entity;
			}
		);
	}

	/**
	 * Build a broker over the mocked collaborators.
	 */
	private function makeBroker(): CredentialBrokerService {
		return new CredentialBrokerService(
			$this->objectService,
			$this->store,
			$this->createMock(ProviderCatalogue::class),
			$this->session,
			$this->createMock(IClientService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(OrganisationService::class)
		);
	}
}//end class
