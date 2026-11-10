<?php

/**
 * OAuth2ConnectionLookupGoldenTest — pins who may manage a connection, and which client is reused.
 *
 * Covers the two credential lookups the connect flow shares before they are folded
 * into one: {@see \OCA\OpenRegister\Service\Credential\OAuth2ConnectionRepository::findManageable()}
 * (an access decision) and the pinned-client reuse in
 * {@see \OCA\OpenRegister\Service\Credential\OAuth2InstanceClient::ensure()}.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\OAuth2ConnectionRepository;
use OCA\OpenRegister\Service\Credential\OAuth2InstanceClient;
use OCA\OpenRegister\Service\Credential\OAuth2RegistrationFailedException;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;

/**
 * Golden outcomes of the shared credential lookups.
 */
class OAuth2ConnectionLookupGoldenTest extends TestCase {
	/** @var array<int, array<string, mixed>> Every find() call's arguments. */
	private array $finds = [];

	/**
	 * @return array<string, array{0: array<string, mixed>|null|\Throwable, 1: string, 2: bool, 3: bool}>
	 */
	public static function manageableCases(): array {
		return [
			'personal, owner' => [['scope' => 'personal'], 'alice', false, true],
			'personal by default, owner' => [[], 'alice', false, true],
			'personal, someone else' => [['scope' => 'personal'], 'mallory', true, false],
			'organisation, admin' => [['scope' => 'organisation', 'organisation' => 'org-1'], 'admin', true, true],
			'organisation, not admin' => [['scope' => 'organisation', 'organisation' => 'org-1'], 'alice', false, false],
			'organisation, none named' => [['scope' => 'organisation'], 'admin', true, false],
			'gone' => [null, 'alice', true, false],
			'lookup fails' => [new \RuntimeException('boom'), 'alice', true, false],
		];
	}

	/**
	 * @dataProvider manageableCases
	 *
	 * @param array<string, mixed>|null|\Throwable $stored What the object store holds or throws.
	 * @param string $uid The caller.
	 * @param bool $isAdmin Whether the caller administers org-1.
	 * @param bool $manageable Whether the caller may manage it.
	 */
	public function testFindManageable(array|null|\Throwable $stored, string $uid, bool $isAdmin, bool $manageable): void {
		$organisations = $this->createMock(OrganisationServiceInterface::class);
		$organisations->method('isOrganisationAdmin')->willReturnCallback(
			static fn (string $org, string $user): bool => $org === 'org-1' && $user === 'admin' && $isAdmin
		);

		$repository = new OAuth2ConnectionRepository(
			objectService: $this->objects(stored: $stored),
			credentialStore: $this->createMock(CredentialStore::class),
			organisationService: $organisations
		);

		$found = $repository->findManageable(credentialId: 'cred-1', uid: $uid);

		$this->assertSame($manageable, $found !== null);
		$this->assertSame(
			[['id' => 'cred-1', 'register' => CredentialBrokerService::REGISTER, 'schema' => CredentialBrokerService::SCHEMA, '_rbac' => false, '_multitenancy' => false, '_render' => false]],
			$this->finds
		);
	}

	public function testDisableAndDiscardWriteCustodyFirstAndPastAccessControl(): void {
		$calls = [];
		$store = $this->createMock(CredentialStore::class);
		$store->method('delete')->willReturnCallback(function (string $uuid, string $scope) use (&$calls): void {
			$calls[] = ['custody', $uuid, $scope];
		});
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true) use (&$calls): ObjectEntity {
				$calls[] = ['save', $object, $register, $schema, $uuid, $_rbac, $_multitenancy];
				return new ObjectEntity();
			}
		);
		$objects->method('deleteObject')->willReturnCallback(
			function (string $uuid, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true) use (&$calls): bool {
				$calls[] = ['delete', $uuid, $register, $schema, $_rbac, $_multitenancy];
				return true;
			}
		);

		$repository = new OAuth2ConnectionRepository(
			objectService: $objects,
			credentialStore: $store,
			organisationService: $this->createMock(OrganisationServiceInterface::class)
		);
		$repository->disable(connection: new \OCA\OpenRegister\Service\Credential\OAuth2ConnectionRecord(credentialId: 'cred-1', data: ['scope' => 'organisation', 'status' => 'active', 'name' => 'n']), lastError: 'revoke_failed');
		$repository->disable(connection: new \OCA\OpenRegister\Service\Credential\OAuth2ConnectionRecord(credentialId: 'cred-2', data: []), lastError: '');
		$repository->discard(credentialId: 'cred-3', scope: 'personal');

		$this->assertSame(
			[
				['custody', 'cred-1', 'organisation'],
				['save', ['scope' => 'organisation', 'status' => 'disabled', 'name' => 'n', 'lastError' => 'revoke_failed', 'expiresAt' => null], 'credential-broker', 'brokeredcredential', 'cred-1', false, false],
				['custody', 'cred-2', 'personal'],
				['save', ['status' => 'disabled', 'lastError' => '', 'expiresAt' => null], 'credential-broker', 'brokeredcredential', 'cred-2', false, false],
				['custody', 'cred-3', 'personal'],
				['delete', 'cred-3', 'credential-broker', 'brokeredcredential', false, false],
			],
			$calls
		);
	}

	public function testAReconnectReusesThePinnedClient(): void {
		$claims = $this->instanceClient(stored: ['clientId' => ' CID ', 'clientCredentialRef' => ' REF '], registers: false)
			->ensure(registration: new \OCA\OpenRegister\Service\Credential\OAuth2ClientRegistration(provider: $this->mastodon(), claims: ['cid' => ' cred-1 ', 'h' => 'https://m.example'], redirectUri: 'R'));

		$this->assertSame(['cid' => ' cred-1 ', 'h' => 'https://m.example', 'cl' => 'CID', 'cr' => 'REF'], $claims);
		$this->assertSame('cred-1', $this->finds[0]['id']);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>|null|\Throwable}>
	 */
	public static function nothingPinned(): array {
		return [
			'gone' => [null],
			'lookup fails' => [new \RuntimeException('boom')],
			'no client id' => [['clientId' => ' ', 'clientCredentialRef' => 'REF']],
		];
	}

	/**
	 * @dataProvider nothingPinned
	 *
	 * @param array<string, mixed>|null|\Throwable $stored What the object store holds or throws.
	 */
	public function testAReconnectWithNothingPinnedRegistersAnew(array|null|\Throwable $stored): void {
		$claims = $this->instanceClient(stored: $stored, registers: true)
			->ensure(registration: new \OCA\OpenRegister\Service\Credential\OAuth2ClientRegistration(provider: $this->mastodon(), claims: ['cid' => 'cred-1', 'h' => 'https://m.example', 'u' => 'alice'], redirectUri: 'R'));

		$this->assertSame('NEW', $claims['cl']);
		$this->assertSame('minted', $claims[OAuth2InstanceClient::MINTED_KEY]);
	}

	public function testAFirstConnectDoesNotLookAnythingUp(): void {
		$this->instanceClient(stored: null, registers: true)
			->ensure(registration: new \OCA\OpenRegister\Service\Credential\OAuth2ClientRegistration(provider: $this->mastodon(), claims: ['h' => 'https://m.example'], redirectUri: 'R'));

		$this->assertSame([], $this->finds);
	}

	public function testRegistrationRequestAndRefusal(): void {
		$posts = [];
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(function (string $url, array $options) use (&$posts): IResponse {
			$posts[] = [$url, $options];
			$response = $this->createMock(IResponse::class);
			$response->method('getBody')->willReturn('{"client_secret":"S"}');
			return $response;
		});
		$http = $this->createMock(IClientService::class);
		$http->method('newClient')->willReturn($client);
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->expects($this->never())->method('mint');

		$service = new OAuth2InstanceClient(broker: $broker, objectService: $this->objects(stored: null), clientService: $http);

		try {
			$service->ensure(registration: new \OCA\OpenRegister\Service\Credential\OAuth2ClientRegistration(provider: $this->mastodon(), claims: ['h' => 'https://m.example', 'sc' => ['read', 'write']], redirectUri: 'R'));
			$this->fail('registration without client id accepted');
		} catch (OAuth2RegistrationFailedException $refused) {
			$this->assertSame('application registration returned no client id', $refused->getMessage());
		}

		$this->assertSame(
			[['https://m.example/api/v1/apps', ['body' => ['client_name' => 'Nextcloud OpenRegister', 'redirect_uris' => 'R', 'scopes' => 'read write'], 'headers' => ['Accept' => 'application/json'], 'timeout' => 20]]],
			$posts
		);
	}

	/**
	 * A per-instance provider that registers its client.
	 *
	 * @return array<string, mixed>
	 */
	private function mastodon(): array {
		return ['identifier' => 'mastodon', 'oauth2' => ['registrationEndpoint' => '/api/v1/apps', 'defaultScopes' => ['read']]];
	}

	/**
	 * An instance client whose registration either succeeds or must not happen.
	 *
	 * @param array<string, mixed>|null|\Throwable $stored What the object store holds or throws.
	 * @param bool $registers Whether a registration is expected.
	 *
	 * @return OAuth2InstanceClient
	 */
	private function instanceClient(array|null|\Throwable $stored, bool $registers): OAuth2InstanceClient {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"client_id":"NEW","client_secret":"S"}');
		$client = $this->createMock(IClient::class);
		$client->expects($registers ? $this->once() : $this->never())->method('post')->willReturn($response);
		$http = $this->createMock(IClientService::class);
		$http->method('newClient')->willReturn($client);

		$minted = new ObjectEntity();
		$minted->setUuid('minted');
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('mint')->willReturn($minted);

		return new OAuth2InstanceClient(broker: $broker, objectService: $this->objects(stored: $stored), clientService: $http);
	}

	/**
	 * An object store holding one credential, nothing, or failing.
	 *
	 * @param array<string, mixed>|null|\Throwable $stored What find() yields.
	 *
	 * @return ObjectOperationsInterface
	 */
	private function objects(array|null|\Throwable $stored): ObjectOperationsInterface {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willReturnCallback(
			function (string|int $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true, bool $_render = true) use ($stored): ?ObjectEntity {
				$this->finds[] = ['id' => $id, 'register' => $register, 'schema' => $schema, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy, '_render' => $_render];
				if ($stored instanceof \Throwable) {
					throw $stored;
				}

				if ($stored === null) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setOwner('alice');
				$entity->setObject($stored);
				return $entity;
			}
		);

		return $objects;
	}
}
