<?php

/**
 * The credential broker over a credential whose secret lives in an outside vault
 * (credential-outside-vault-reference tasks 1.2 and 2.2).
 *
 * Real broker, real OutsideCustody, real OutsideVaultReader. The object store,
 * the custody leaf, the catalogue and the HTTP client are doubles; the HTTP
 * double records every request so a test can prove the vault was NOT
 * contacted.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Credential;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialMint;
use OCA\OpenRegister\Service\Credential\CredentialMintOptions;
use OCA\OpenRegister\Service\Credential\CredentialOutbound;
use OCA\OpenRegister\Service\Credential\CredentialPrincipals;
use OCA\OpenRegister\Service\Credential\CredentialSources;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\CredentialUpstreamException;
use OCA\OpenRegister\Service\Credential\OutsideCustody;
use OCA\OpenRegister\Service\Credential\OutsideVaultConnection;
use OCA\OpenRegister\Service\Credential\OutsideVaultReader;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\OpenRegister\Service\Credential\VaultReference;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(OutsideCustody::class)]
#[CoversClass(CredentialBrokerService::class)]
#[UsesClass(OutsideVaultReader::class)]
#[UsesClass(OutsideVaultConnection::class)]
#[UsesClass(VaultReference::class)]
#[UsesClass(CredentialOutbound::class)]
#[UsesClass(CredentialSources::class)]
#[UsesClass(CredentialPrincipals::class)]
#[UsesClass(CredentialMint::class)]
#[UsesClass(CredentialMintOptions::class)]
class CredentialBrokerOutsideVaultTest extends TestCase {

	/**
	 * The value the vault holds at integriq/zaaksysteem#password.
	 */
	private const VAULT_VALUE = 'vault-held-password';

	/**
	 * The vault connection's login token, held in the custody leaf.
	 */
	private const VAULT_TOKEN = 'vault-login-token';

	/**
	 * Stored credentials by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $objects = [];

	/**
	 * Every HTTP request: method and url.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $http = [];

	/**
	 * Objects saved, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Lines logged, with their context.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * The vault's status for the read.
	 *
	 * @var int
	 */
	private int $vaultStatus = 200;

	/**
	 * The custody leaf double.
	 *
	 * @var CredentialStore&\PHPUnit\Framework\MockObject\MockObject
	 */
	private CredentialStore $store;

	/**
	 * Store the vault connection and an outside credential, both alice's.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = [
			'conn-1' => [
				'name' => 'Vault',
				'provider' => 'outside-vault',
				'owner' => 'alice',
				'allowedApps' => ['integriq'],
				'instanceBaseUrl' => 'https://vault.example',
				'vaultAuthMethod' => 'token',
				'vaultMount' => 'secret',
			],
			'conn-bob' => [
				'name' => 'Bob vault',
				'provider' => 'outside-vault',
				'owner' => 'bob',
				'instanceBaseUrl' => 'https://vault.example',
				'vaultAuthMethod' => 'token',
			],
			'not-a-vault' => [
				'name' => 'GitHub',
				'provider' => 'github',
				'owner' => 'alice',
			],
			'cred-inject' => [
				'name' => 'Zaaksysteem',
				'provider' => 'generic-bearer',
				'owner' => 'alice',
				'allowedApps' => ['integriq'],
				'custody' => 'outside',
				'vaultRef' => ['connection' => 'conn-1', 'path' => 'integriq/zaaksysteem', 'key' => 'password'],
			],
			'cred-proxy' => [
				'name' => 'GitHub from the vault',
				'provider' => 'github',
				'owner' => 'alice',
				'allowedApps' => ['integriq'],
				'custody' => 'outside',
				'vaultRef' => ['connection' => 'conn-1', 'path' => 'integriq/zaaksysteem', 'key' => 'password'],
			],
		];

		$this->store = $this->createMock(CredentialStore::class);
		$this->store->method('get')->willReturnCallback(
			static fn (string $uuid, string $scope): ?string => ($uuid === 'conn-1' || $uuid === 'conn-bob') ? self::VAULT_TOKEN : null
		);
	}

	/**
	 * The broker, signed in as the given user.
	 *
	 * @param string $sessionUid The signed-in user.
	 * @param bool $withCustody Whether the outside reader is wired in.
	 *
	 * @return CredentialBrokerService
	 */
	private function broker(string $sessionUid = 'alice', bool $withCustody = true): CredentialBrokerService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($sessionUid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (string|int $id): ?ObjectEntity {
				$data = ($this->objects[(string)$id] ?? null);
				if ($data === null) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setOwner((string)$data['owner']);
				$entity->setObject($data);
				return $entity;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions()): ObjectEntity {
				$this->saved[] = (array)$object;
				$entity = new ObjectEntity();
				$entity->setUuid($options->uuid ?? 'minted');
				$entity->setObject((array)$object);
				return $entity;
			}
		);

		$catalogue = $this->createMock(ProviderCatalogue::class);
		$catalogue->method('get')->willReturnCallback(
			static fn (string $id): ?array => match ($id) {
				'outside-vault' => ['identifier' => 'outside-vault', 'kind' => 'outside-vault', 'inject_only' => true, 'authScheme' => ['header' => 'X-Vault-Token', 'template' => '{secret}']],
				'generic-bearer' => ['identifier' => 'generic-bearer', 'inject_only' => true, 'authScheme' => ['header' => 'Authorization', 'template' => 'Bearer {secret}']],
				'github' => [
					'identifier' => 'github',
					'baseUrl' => 'https://api.github.com',
					'authScheme' => ['header' => 'Authorization', 'template' => 'token {secret}'],
					'allowRules' => [['method' => 'GET', 'pathPattern' => '/user/repos']],
				],
				default => null,
			}
		);

		$clientService = $this->clientService();
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'notice', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []): void {
					$this->logged[] = $message . ' ' . json_encode($context);
				}
			);
		}

		$sources = new CredentialSources(objectService: $objectService, credentialStore: $this->store, catalogue: $catalogue);
		$custody = null;
		if ($withCustody === true) {
			$custody = new OutsideCustody(sources: $sources, reader: new OutsideVaultReader(clientService: $clientService, logger: $logger), logger: $logger);
		}

		return new CredentialBrokerService(
			sources: $sources,
			principals: new CredentialPrincipals(userSession: $session, organisationService: $this->createMock(OrganisationService::class)),
			outbound: new CredentialOutbound(clientService: $clientService, logger: $logger, outsideCustody: $custody)
		);
	}

	/**
	 * An HTTP client that answers the vault read and the proxied call, and records both.
	 *
	 * @return IClientService
	 */
	private function clientService(): IClientService {
		$respond = function (string $method, string $url, array $options): IResponse {
			$this->http[] = [$method, $url, $options['headers'] ?? []];
			$response = $this->createMock(IResponse::class);
			if (str_starts_with($url, 'https://vault.example/')) {
				$response->method('getStatusCode')->willReturn($this->vaultStatus);
				$response->method('getBody')->willReturn(
					(string)json_encode(['data' => ['data' => ['password' => self::VAULT_VALUE]], 'errors' => ['internal vault detail']])
				);
				return $response;
			}

			$response->method('getStatusCode')->willReturn(200);
			$response->method('getHeaders')->willReturn([]);
			$response->method('getBody')->willReturn('[]');
			return $response;
		};

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(fn (string $url, array $options = []): IResponse => $respond('GET', $url, $options));
		$client->method('post')->willReturnCallback(fn (string $url, array $options = []): IResponse => $respond('POST', $url, $options));
		$client->method('request')->willReturnCallback(fn (string $method, string $url, array $options = []): IResponse => $respond($method, $url, $options));

		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);
		return $service;
	}

	/**
	 * The vault requests made.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function vaultRequests(): array {
		return array_values(array_filter($this->http, static fn (array $call): bool => str_starts_with($call[1], 'https://vault.example/')));
	}

	public function testAnAllowedAppGetsTheValueReadFromTheVaultNow(): void {
		$secret = $this->broker()->resolveInjectable(credentialId: 'cred-inject', appId: 'integriq');

		$this->assertSame(self::VAULT_VALUE, $secret);
		$this->assertSame(
			[['GET', 'https://vault.example/v1/secret/data/integriq/zaaksysteem', ['X-Vault-Token' => self::VAULT_TOKEN, 'Accept' => 'application/json']]],
			$this->vaultRequests()
		);
		$this->assertSame([], $this->saved);
	}

	public function testAnAppThatIsNotAllowedGetsNothingAndTheVaultIsNotContacted(): void {
		try {
			$this->broker()->resolveInjectable(credentialId: 'cred-inject', appId: 'other-app');
			$this->fail('The broker resolved an outside credential for an app it does not allow.');
		} catch (CredentialAccessDeniedException $e) {
			$this->assertSame([], $this->http);
		}
	}

	public function testAnotherUserGetsNothingAndTheVaultIsNotContacted(): void {
		$this->expectException(CredentialAccessDeniedException::class);
		try {
			$this->broker(sessionUid: 'mallory')->resolveInjectable(credentialId: 'cred-inject', appId: 'integriq');
		} finally {
			$this->assertSame([], $this->http);
		}
	}

	public function testTheProxyInjectsTheVaultValueAfterItsGuards(): void {
		$answer = $this->broker()->request(credentialId: 'cred-proxy', appId: 'integriq', method: 'GET', path: '/user/repos');

		$this->assertSame(200, $answer['status']);
		$this->assertCount(1, $this->vaultRequests());
		$proxied = end($this->http);
		$this->assertSame('https://api.github.com/user/repos', $proxied[1]);
		$this->assertSame('token ' . self::VAULT_VALUE, $proxied[2]['Authorization']);

		$this->http = [];
		try {
			$this->broker()->request(credentialId: 'cred-proxy', appId: 'integriq', method: 'DELETE', path: '/user/repos');
			$this->fail('A call outside the allow-rules was brokered.');
		} catch (CredentialAccessDeniedException $e) {
			$this->assertSame([], $this->http);
		}
	}

	public function testAVaultConnectionIsNeverProxiedOrResolved(): void {
		foreach (['resolve', 'proxy'] as $path) {
			try {
				if ($path === 'resolve') {
					$this->broker()->resolveInjectable(credentialId: 'conn-1', appId: 'integriq');
				} else {
					$this->broker()->request(credentialId: 'conn-1', appId: 'integriq', method: 'GET', path: '/v1/sys/health');
				}

				$this->fail('The vault login left the broker through ' . $path . '.');
			} catch (CredentialAccessDeniedException $e) {
				$this->assertSame([], $this->http);
			}
		}
	}

	public function testAReferenceToAConnectionItMayNotUseIsRefusedWithoutContactingAVault(): void {
		foreach (['conn-bob', 'not-a-vault', 'missing'] as $connection) {
			$this->objects['cred-inject']['vaultRef']['connection'] = $connection;
			try {
				$this->broker()->resolveInjectable(credentialId: 'cred-inject', appId: 'integriq');
				$this->fail('The broker read through connection ' . $connection . '.');
			} catch (CredentialAccessDeniedException $e) {
				$this->assertSame([], $this->http);
			}
		}
	}

	public function testARefusingVaultNamesTheConnectionAndLeavesAFixedLastError(): void {
		$this->vaultStatus = 403;

		try {
			$this->broker()->resolveInjectable(credentialId: 'cred-inject', appId: 'integriq');
			$this->fail('A refused read returned a secret.');
		} catch (CredentialUpstreamException $e) {
			$this->assertStringContainsString('conn-1', $e->getMessage());
			$this->assertStringNotContainsString('internal vault detail', $e->getMessage());
		}

		$this->assertCount(1, $this->saved);
		$this->assertSame(OutsideCustody::READ_FAILED, $this->saved[0]['lastError']);
		$this->assertSame('Zaaksysteem', $this->saved[0]['name']);
		$this->assertStringNotContainsString('internal vault detail', implode("\n", $this->logged));
	}

	public function testNoSecretReachesALogOrASavedObject(): void {
		$this->broker()->resolveInjectable(credentialId: 'cred-inject', appId: 'integriq');
		$this->broker()->request(credentialId: 'cred-proxy', appId: 'integriq', method: 'GET', path: '/user/repos');
		$this->vaultStatus = 500;
		try {
			$this->broker()->resolveInjectable(credentialId: 'cred-inject', appId: 'integriq');
		} catch (CredentialUpstreamException $e) {
			$this->assertStringNotContainsString(self::VAULT_TOKEN, $e->getMessage());
		}

		$everything = implode("\n", $this->logged) . json_encode($this->saved);
		$this->assertStringNotContainsString(self::VAULT_VALUE, $everything);
		$this->assertStringNotContainsString(self::VAULT_TOKEN, $everything);
	}

	public function testWithoutTheReaderAnOutsideCredentialIsRefused(): void {
		$this->expectException(CredentialAccessDeniedException::class);
		try {
			$this->broker(withCustody: false)->resolveInjectable(credentialId: 'cred-inject', appId: 'integriq');
		} finally {
			$this->assertSame([], $this->http);
		}
	}

	public function testMintRefusesASecretOnAnOutsideCredential(): void {
		$this->store->expects($this->never())->method('put');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('takes no secret');
		$this->broker()->mintCredential(
			mint: new CredentialMint(
				name: 'Zaaksysteem',
				provider: 'generic-bearer',
				owner: 'alice',
				metadata: ['custody' => 'outside', 'vaultRef' => ['connection' => 'conn-1', 'path' => 'integriq/zaaksysteem', 'key' => 'password']]
			),
			options: new CredentialMintOptions(allowedApps: ['integriq'], secret: 'pasted-anyway')
		);
	}

	public function testMintRefusesAReferenceToAConnectionTheOwnerMayNotUse(): void {
		$this->store->expects($this->never())->method('put');

		foreach ([['connection' => 'conn-bob', 'path' => 'p', 'key' => 'k'], ['connection' => 'not-a-vault', 'path' => 'p', 'key' => 'k'], ['connection' => 'conn-1', 'path' => '../etc', 'key' => 'k'], []] as $vaultRef) {
			try {
				$this->broker()->mintCredential(
					mint: new CredentialMint(name: 'Zaaksysteem', provider: 'generic-bearer', owner: 'alice', metadata: ['custody' => 'outside', 'vaultRef' => $vaultRef]),
					options: new CredentialMintOptions(allowedApps: ['integriq'])
				);
				$this->fail('Minted an outside credential with vaultRef ' . json_encode($vaultRef) . '.');
			} catch (InvalidArgumentException $e) {
				$this->assertSame([], $this->saved);
			}
		}
	}

	public function testMintStoresTheReferenceAndNoSecret(): void {
		$this->store->expects($this->never())->method('put');

		$this->broker()->mintCredential(
			mint: new CredentialMint(
				name: 'Zaaksysteem',
				provider: 'generic-bearer',
				owner: 'alice',
				metadata: ['custody' => 'outside', 'vaultRef' => ['connection' => 'conn-1', 'path' => 'integriq/zaaksysteem', 'key' => 'password']]
			),
			options: new CredentialMintOptions(allowedApps: ['integriq'])
		);

		$this->assertSame('outside', $this->saved[0]['custody']);
		$this->assertSame(['connection' => 'conn-1', 'path' => 'integriq/zaaksysteem', 'key' => 'password'], $this->saved[0]['vaultRef']);
		$this->assertSame([], $this->http);
	}
}
