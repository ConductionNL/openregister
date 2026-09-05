<?php

/**
 * CredentialOAuth2MintTest — what a mint may write, and what it must refuse.
 *
 * Two properties, and the second is the one that would be quiet if it broke. The
 * metadata a caller supplies passes through an ALLOW-LIST rather than a filter of
 * forbidden keys, because the failure mode of a deny-list is a key nobody thought
 * of. And `instanceBaseUrl` is validated HERE, at the one moment it may be written,
 * because from then on it is the credential's host-lock rather than a setting.
 *
 * The no-leak assertion belongs in this file rather than in a suite of its own: the
 * mint is where a token would reach an object if it ever did.
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
 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-per-account-host-is-pinned-at-mint-and-immutable-afterwards
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\OAuth2TokenSet;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\Http\Client\IClientService;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Credential\CredentialBrokerService
 */
class CredentialOAuth2MintTest extends TestCase {
	/** @var array<string, mixed>|null The property bag that reached saveObject(). */
	private ?array $savedObject = null;

	/** @var array<string, string> What was written to the custody leaf. */
	private array $vault = [];

	protected function setUp(): void {
		$this->savedObject = null;
		$this->vault = [];
	}

	public function testAnOAuth2MintWritesConnectionMetadataAndNoToken(): void {
		$broker = $this->makeBroker();
		$tokenSet = OAuth2TokenSet::fromTokenResponse(
			response: [
				'access_token' => 'ACCESS_TOKEN_HERE',
				'refresh_token' => 'REFRESH_TOKEN_HERE',
				'expires_in' => 3600,
				'scope' => 'read:accounts write:statuses',
			]
		)->withAccount(id: '42', handle: '@example@mastodon.example', displayName: 'Example');

		$broker->mint(
			name: 'Mastodon company account',
			provider: 'mastodon',
			owner: 'alice',
			allowedApps: ['pipelinq'],
			secret: $tokenSet->toStoredJson(),
			metadata: [
				'kind' => 'oauth2-token-set',
				'status' => 'active',
				'instanceBaseUrl' => 'https://mastodon.example/',
				'clientId' => 'CLIENT_ID_HERE',
			]
		);

		$this->assertSame('oauth2-token-set', $this->savedObject['kind']);
		$this->assertSame('active', $this->savedObject['status']);
		$this->assertSame('https://mastodon.example', $this->savedObject['instanceBaseUrl'], 'the host is normalised on the way in');
		$this->assertSame('CLIENT_ID_HERE', $this->savedObject['clientId']);

		$serialisedObject = (string)json_encode($this->savedObject);
		$this->assertStringNotContainsString('ACCESS_TOKEN_HERE', $serialisedObject);
		$this->assertStringNotContainsString('REFRESH_TOKEN_HERE', $serialisedObject);

		$this->assertArrayHasKey('minted-uuid', $this->vault, 'the whole token set goes to the custody leaf');
	}

	public function testMetadataOutsideTheAllowListIsDropped(): void {
		$broker = $this->makeBroker();

		$broker->mint(
			name: 'Mastodon company account',
			provider: 'mastodon',
			owner: 'alice',
			metadata: [
				'kind' => 'oauth2-token-set',
				'accessToken' => 'SMUGGLED_TOKEN_VALUE',
				'sharedUsers' => ['mallory'],
				'authorization' => ['read' => []],
			]
		);

		$this->assertArrayNotHasKey('accessToken', $this->savedObject);
		$this->assertArrayNotHasKey('sharedUsers', $this->savedObject, 'a derived access-control field is never client-writable');
		$this->assertArrayNotHasKey('authorization', $this->savedObject);
	}

	public function testAMintWithAnUnsafeInstanceHostIsRefusedAndCreatesNothing(): void {
		$broker = $this->makeBroker();

		$this->expectException(InvalidArgumentException::class);

		try {
			$broker->mint(
				name: 'Mastodon company account',
				provider: 'mastodon',
				owner: 'alice',
				metadata: ['kind' => 'oauth2-token-set', 'instanceBaseUrl' => 'https://192.168.1.10'],
			);
		} finally {
			$this->assertNull($this->savedObject, 'a refused host must not leave a credential object behind');
			$this->assertSame([], $this->vault);
		}
	}

	public function testAClassicMintIsUnchangedByTheNewParameter(): void {
		$broker = $this->makeBroker();

		$broker->mint(name: 'My GitHub', provider: 'github', owner: 'alice', allowedApps: ['hermiq'], secret: 'hunter2');

		$this->assertSame(
			['name', 'provider', 'owner', 'allowedApps', 'createdAt'],
			array_keys($this->savedObject),
			'a credential minted without connection metadata keeps exactly the property bag it always had'
		);
	}

	/**
	 * Wire a broker whose object save and custody leaf are captured.
	 *
	 * @return CredentialBrokerService The broker under test.
	 */
	private function makeBroker(): CredentialBrokerService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (array | ObjectEntity $object): ObjectEntity {
				$this->savedObject = (is_array($object) === true ? $object : $object->getObject());
				$saved = new ObjectEntity();
				$saved->setUuid('minted-uuid');
				$saved->setObject($this->savedObject);
				return $saved;
			}
		);

		$store = $this->createMock(CredentialStore::class);
		$store->method('put')->willReturnCallback(
			function (string $uuid, string $secret): void {
				$this->vault[$uuid] = $secret;
			}
		);

		return new CredentialBrokerService(
			$objectService,
			$store,
			$this->createMock(ProviderCatalogue::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IClientService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(OrganisationService::class)
		);
	}
}
